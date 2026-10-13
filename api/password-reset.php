<?php
declare(strict_types=1);

const PASSWORD_RESET_MESSAGE = 'Si existe una cuenta con ese correo, recibirás instrucciones para restablecer la contraseña.';

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
    $message = "Solicitaste restablecer tu contraseña de Kaizen.\n\nAbre este enlace dentro de 30 minutos:\n".$link."\n\nSi no solicitaste el cambio, ignora este mensaje.\n";
    if ($sender !== null) return $sender($to, $subject, $message) === true;
    $from = (string)$config['password_reset_from_email'];
    return mail($to, $subject, $message, ['From' => $from, 'Content-Type' => 'text/plain; charset=UTF-8']);
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

function passwordResetRequest(PDO $pdo, string $email, string $ip, array $config, ?callable $sender = null): void {
    if (!passwordResetConfigured($config)) throw new RuntimeException('Password reset mail is not configured.');
    $secret = (string)($config['password_reset_rate_secret'] ?? '');
    if (strlen($secret) < 32) throw new RuntimeException('Password reset rate key is not configured.');
    $email = strtolower(trim($email));
    $ip = trim($ip) ?: 'unknown';
    $ipAllowed = passwordResetRateAllowed($pdo, 'ip', $ip, 12, $secret);
    $emailAllowed = passwordResetRateAllowed($pdo, 'email', $email, 3, $secret);
    if (!$ipAllowed || !$emailAllowed || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) return;
    $q = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
    $q->execute([$email]);
    $id = $q->fetchColumn();
    if (!$id) return;
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=?')->execute([(int)$id]);
    $pdo->prepare('INSERT INTO password_reset_tokens(token_hash,user_id,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE))')->execute([$hash,(int)$id]);
    // The token is in the URL fragment, which is never sent in HTTP requests or access logs.
    $link = rtrim((string)$config['password_reset_url'], '/') . '/#reset=' . $token;
    try {
        if (!passwordResetMail($email, $link, $config, $sender)) {
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE token_hash=?')->execute([$hash]);
            error_log('Kaizen password reset mail delivery failed.');
        }
    } catch (Throwable $e) {
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE token_hash=?')->execute([$hash]);
        error_log('Kaizen password reset mail delivery failed.');
    }
}

function passwordResetConfirm(PDO $pdo, string $token, string $password): bool {
    if (!preg_match('/^[a-f0-9]{64}$/D', $token) || strlen($password) < 12 || strlen($password) > 1024) return false;
    try {
        $pdo->beginTransaction();
        $q = $pdo->prepare('SELECT user_id FROM password_reset_tokens WHERE token_hash=? AND expires_at>UTC_TIMESTAMP() FOR UPDATE');
        $q->execute([hash('sha256', $token)]);
        $id = $q->fetchColumn();
        if (!$id) { $pdo->rollBack(); return false; }
        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT),(int)$id]);
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=?')->execute([(int)$id]);
        $pdo->prepare('DELETE FROM device_sessions WHERE user_id=?')->execute([(int)$id]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function passwordChange(PDO $pdo, int $userId, string $currentPassword, string $newPassword, string $confirmation, string $ip): string {
    if ($newPassword !== $confirmation) return 'Las contraseñas nuevas no coinciden.';
    if (strlen($newPassword) < 12 || strlen($newPassword) > 1024) return 'La nueva contraseña debe tener entre 12 y 1024 caracteres.';
    $allowedUser = passwordResetRateAllowed($pdo, 'change-user', (string)$userId, 5, 'kaizen-password-change');
    $allowedIp = passwordResetRateAllowed($pdo, 'change-ip', $ip, 20, 'kaizen-password-change');
    if (!$allowedUser || !$allowedIp) return 'Demasiados intentos. Inténtalo más tarde.';
    try {
        $pdo->beginTransaction();
        $q = $pdo->prepare('SELECT password_hash FROM users WHERE id=? FOR UPDATE');
        $q->execute([$userId]);
        $hash = $q->fetchColumn();
        if (!$hash || !password_verify($currentPassword, (string)$hash)) { $pdo->rollBack(); return 'La contraseña actual es incorrecta.'; }
        if (password_verify($newPassword, (string)$hash)) { $pdo->rollBack(); return 'Elige una contraseña nueva diferente.'; }
        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($newPassword,PASSWORD_DEFAULT),$userId]);
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=?')->execute([$userId]);
        $pdo->prepare('DELETE FROM device_sessions WHERE user_id=?')->execute([$userId]);
        $pdo->commit();
        return '';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
