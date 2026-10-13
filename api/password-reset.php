<?php
declare(strict_types=1);

const PASSWORD_RESET_MESSAGE = 'Si existe una cuenta con ese correo, recibirás instrucciones para restablecer la contraseña.';

function ensurePasswordResetTables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (token_hash CHAR(64) PRIMARY KEY, user_id INT UNSIGNED NOT NULL, expires_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, INDEX idx_password_reset_user (user_id), INDEX idx_password_reset_expiry (expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_mail_queue (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NULL, token_cipher VARCHAR(256) NOT NULL, expires_at DATETIME NOT NULL, claimed_at DATETIME NULL, attempts TINYINT UNSIGNED NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_password_mail_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, INDEX idx_password_mail_pending (claimed_at,expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_limits (scope VARCHAR(20) NOT NULL, subject_hash CHAR(64) NOT NULL, window_start DATETIME NOT NULL, attempts INT UNSIGNED NOT NULL DEFAULT 1, PRIMARY KEY (scope,subject_hash,window_start), INDEX idx_password_reset_window (window_start)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function passwordResetConfigured(array $config): bool {
    $url = (string)($config['password_reset_url'] ?? '');
    $from = (string)($config['password_reset_from_email'] ?? '');
    return ($config['password_reset_enabled'] ?? false) === true
        && filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, 'https://')
        && filter_var($from, FILTER_VALIDATE_EMAIL)
        && preg_match('/^[^\r\n]+$/', $from);
}

function passwordResetMail(string $to, string $link, array $config, ?callable $sender = null): bool {
    $subject = 'Restablecer contraseña de Kaizen';
    $message = "Solicitaste restablecer tu contraseña de Kaizen.\n\nEste enlace vence 30 minutos después de la solicitud:\n".$link."\n\nSi no solicitaste el cambio, ignora este mensaje.\n";
    if ($sender !== null) return $sender($to, $subject, $message) === true;
    $from = (string)$config['password_reset_from_email'];
    return mail($to, $subject, $message, ['From' => $from, 'Content-Type' => 'text/plain; charset=UTF-8']);
}

function passwordResetQueueEncrypt(string $token, string $secret): string {
    $key = hash_hkdf('sha256', $secret, 32, 'kaizen-password-reset-mail-v1');
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($token, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) throw new RuntimeException('Password reset queue encryption failed.');
    return base64_encode($nonce . $tag . $ciphertext);
}

function passwordResetQueueDecrypt(string $payload, string $secret): ?string {
    $binary = base64_decode($payload, true);
    if ($binary === false || strlen($binary) < 29) return null;
    $key = hash_hkdf('sha256', $secret, 32, 'kaizen-password-reset-mail-v1');
    $token = openssl_decrypt(substr($binary, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($binary, 0, 12), substr($binary, 12, 16));
    return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) ? $token : null;
}

function passwordResetRateAllowed(PDO $pdo, string $scope, string $subject, int $limit, string $secret): bool {
    $hash = hash_hmac('sha256', $subject, $secret);
    $window = gmdate('Y-m-d H:00:00');
    $q = $pdo->prepare('INSERT INTO password_reset_limits(scope,subject_hash,window_start,attempts) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE attempts=attempts+1');
    $q->execute([$scope,$hash,$window]);
    $q = $pdo->prepare('SELECT attempts FROM password_reset_limits WHERE scope=? AND subject_hash=? AND window_start=?');
    $q->execute([$scope,$hash,$window]);
    return (int)$q->fetchColumn() <= $limit;
}

function passwordResetRequest(PDO $pdo, string $email, string $ip, array $config, ?callable $afterUserLock = null): void {
    if (!passwordResetConfigured($config)) throw new RuntimeException('Password reset mail is not configured.');
    $secret = (string)($config['password_reset_rate_secret'] ?? '');
    if (strlen($secret) < 32) throw new RuntimeException('Password reset rate key is not configured.');
    $pdo->exec('DELETE FROM password_reset_limits WHERE window_start<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)');
    $email = strtolower(trim($email));
    $ip = trim($ip) ?: 'unknown';
    $ipAllowed = passwordResetRateAllowed($pdo, 'ip', $ip, 12, $secret);
    if (!$ipAllowed) return; // Do not create unbounded email rows behind a blocked IP.
    $emailAllowed = passwordResetRateAllowed($pdo, 'email', $email, 3, $secret);
    if (!$emailAllowed || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) return;
    $q = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
    $q->execute([$email]);
    $id = $q->fetchColumn();
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $ciphertext = passwordResetQueueEncrypt($token, $secret);
    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            $pdo->beginTransaction();
            if ($id) {
                // Match reset/change/login lock order: users before tokens.
                $lock = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
                $lock->execute([(int)$id]);
                if (!$lock->fetchColumn()) $id = null;
                else {
                    if ($afterUserLock !== null) $afterUserLock(); // Race test barrier.
                    $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=?')->execute([(int)$id]);
                    $pdo->prepare('INSERT INTO password_reset_tokens(token_hash,user_id,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE))')->execute([$hash,(int)$id]);
                }
            }
            // Both known and unknown addresses enqueue the same shaped job.
            // The separate worker alone performs variable-duration mail delivery.
            $pdo->prepare('INSERT INTO password_reset_mail_queue(user_id,token_cipher,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE))')->execute([$id ? (int)$id : null,$ciphertext]);
            $pdo->commit();
            return;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $driverCode = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0;
            if (!in_array($driverCode, [1205, 1213], true)) throw $e;
            if ($attempt === 2) { error_log('Kaizen password reset request deferred by database contention.'); return; }
            usleep(random_int(10000, 50000));
        }
    }
}

function passwordResetProcessQueue(PDO $pdo, array $config, ?callable $sender = null): int {
    if (!passwordResetConfigured($config) || strlen((string)($config['password_reset_rate_secret'] ?? '')) < 32) throw new RuntimeException('Password reset mail is not configured.');
    $secret = (string)$config['password_reset_rate_secret'];
    $pdo->exec('DELETE FROM password_reset_mail_queue WHERE expires_at<=UTC_TIMESTAMP() OR (attempts>=3 AND claimed_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR))');
    $ids = $pdo->query('SELECT id FROM password_reset_mail_queue WHERE expires_at>UTC_TIMESTAMP() AND attempts<3 AND (claimed_at IS NULL OR claimed_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)) ORDER BY id LIMIT 20')->fetchAll(PDO::FETCH_COLUMN);
    $delivered = 0;
    foreach ($ids as $id) {
        $claim = $pdo->prepare('UPDATE password_reset_mail_queue SET claimed_at=UTC_TIMESTAMP(),attempts=attempts+1 WHERE id=? AND expires_at>UTC_TIMESTAMP() AND attempts<3 AND (claimed_at IS NULL OR claimed_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE))');
        $claim->execute([(int)$id]);
        if ($claim->rowCount() !== 1) continue;
        $q = $pdo->prepare('SELECT q.user_id,q.token_cipher,u.email FROM password_reset_mail_queue q LEFT JOIN users u ON u.id=q.user_id WHERE q.id=?');
        $q->execute([(int)$id]);
        $job = $q->fetch();
        if (!$job) continue;
        $token = passwordResetQueueDecrypt((string)$job['token_cipher'], $secret);
        if (!$job['user_id'] || !$job['email'] || !$token) {
            $pdo->prepare('DELETE FROM password_reset_mail_queue WHERE id=?')->execute([(int)$id]);
            continue;
        }
        $valid = $pdo->prepare('SELECT 1 FROM password_reset_tokens WHERE token_hash=? AND user_id=? AND expires_at>UTC_TIMESTAMP()');
        $valid->execute([hash('sha256', $token),(int)$job['user_id']]);
        if (!$valid->fetchColumn()) {
            $pdo->prepare('DELETE FROM password_reset_mail_queue WHERE id=?')->execute([(int)$id]);
            continue;
        }
        // The fragment is not sent to the web server when the recipient opens the link.
        $link = rtrim((string)$config['password_reset_url'], '/') . '/#reset=' . $token;
        try {
            if (passwordResetMail((string)$job['email'], $link, $config, $sender)) {
                $pdo->prepare('DELETE FROM password_reset_mail_queue WHERE id=?')->execute([(int)$id]);
                $delivered++;
            } else error_log('Kaizen password reset mail delivery failed.');
        } catch (Throwable $e) { error_log('Kaizen password reset mail delivery failed.'); }
    }
    return $delivered;
}

function passwordLogin(PDO $pdo, string $email, string $password, ?callable $afterVerify = null): ?array {
    try {
        $pdo->beginTransaction();
        $q = $pdo->prepare('SELECT * FROM users WHERE email=? FOR UPDATE');
        $q->execute([$email]);
        $user = $q->fetch();
        if (!$user || !password_verify($password, (string)$user['password_hash'])) { $pdo->rollBack(); return null; }
        if ($afterVerify !== null) $afterVerify(); // Deterministic race test hook while the user row is locked.
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO device_sessions(token_hash,user_id,expires_at) VALUES(?,?,DATE_ADD(NOW(), INTERVAL 365 DAY))')->execute([hash('sha256', $token),(int)$user['id']]);
        $pdo->commit();
        return ['user'=>$user,'remember_token'=>$token];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function passwordResetConfirm(PDO $pdo, string $token, string $password, ?callable $afterLock = null): bool {
    if (!preg_match('/^[a-f0-9]{64}$/D', $token) || strlen($password) < 12 || strlen($password) > 1024) return false;
    try {
        $pdo->beginTransaction();
        $q = $pdo->prepare('SELECT user_id FROM password_reset_tokens WHERE token_hash=? AND expires_at>UTC_TIMESTAMP()');
        $q->execute([hash('sha256', $token)]);
        $id = $q->fetchColumn();
        if (!$id) { $pdo->rollBack(); return false; }
        $lock = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
        $lock->execute([(int)$id]);
        if (!$lock->fetchColumn()) { $pdo->rollBack(); return false; }
        $q = $pdo->prepare('SELECT user_id FROM password_reset_tokens WHERE token_hash=? AND user_id=? AND expires_at>UTC_TIMESTAMP() FOR UPDATE');
        $q->execute([hash('sha256', $token),(int)$id]);
        if (!$q->fetchColumn()) { $pdo->rollBack(); return false; }
        if ($afterLock !== null) $afterLock(); // Deterministic race test hook before revocation.
        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT),(int)$id]);
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM password_reset_mail_queue WHERE user_id=?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM device_sessions WHERE user_id=?')->execute([(int)$id]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function passwordChange(PDO $pdo, int $userId, string $currentPassword, string $newPassword, string $confirmation, string $ip, ?callable $afterLock = null): string {
    if ($newPassword !== $confirmation) return 'Las contraseñas nuevas no coinciden.';
    if (strlen($newPassword) < 12 || strlen($newPassword) > 1024) return 'La nueva contraseña debe tener entre 12 y 1024 caracteres.';
    $pdo->exec('DELETE FROM password_reset_limits WHERE window_start<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)');
    $allowedUser = passwordResetRateAllowed($pdo, 'change-user', (string)$userId, 5, 'kaizen-password-change');
    $allowedIp = passwordResetRateAllowed($pdo, 'change-ip', $ip, 20, 'kaizen-password-change');
    if (!$allowedUser || !$allowedIp) return 'Demasiados intentos. Inténtalo más tarde.';
    try {
        $pdo->beginTransaction();
        $q = $pdo->prepare('SELECT password_hash FROM users WHERE id=? FOR UPDATE');
        $q->execute([$userId]);
        $hash = $q->fetchColumn();
        if (!$hash || !password_verify($currentPassword, (string)$hash)) { $pdo->rollBack(); return 'La contraseña actual es incorrecta.'; }
        if ($afterLock !== null) $afterLock(); // Deterministic race test hook before revocation.
        if (password_verify($newPassword, (string)$hash)) { $pdo->rollBack(); return 'Elige una contraseña nueva diferente.'; }
        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($newPassword,PASSWORD_DEFAULT),$userId]);
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=?')->execute([$userId]);
        $pdo->prepare('DELETE FROM password_reset_mail_queue WHERE user_id=?')->execute([$userId]);
        $pdo->prepare('DELETE FROM device_sessions WHERE user_id=?')->execute([$userId]);
        $pdo->commit();
        return '';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
