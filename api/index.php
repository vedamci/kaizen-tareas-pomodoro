<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
session_name($config['session_name'] ?? 'tareas_session');
// Mantener la sesión web en este dispositivo durante 30 días. Además, cada
// inicio de sesión entrega un token persistente independiente para que el
// acceso se conserve en cada dispositivo (por ejemplo, iPhone y Mac).
$sessionLifetime = 60 * 60 * 24 * 30;
ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']),
    'samesite' => 'Lax'
]);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function db(): PDO {
    static $pdo;
    global $config;
    if (!$pdo) {
        $pdo = new PDO(
            'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
            $config['db_user'], $config['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->exec("CREATE TABLE IF NOT EXISTS workspace_data (workspace_id INT UNSIGNED PRIMARY KEY, projects_json LONGTEXT NOT NULL, improvement_checklist_json LONGTEXT NULL, CONSTRAINT fk_workspace_data_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try { $pdo->exec("ALTER TABLE workspace_data ADD COLUMN improvement_checklist_json LONGTEXT NULL AFTER projects_json"); } catch (Throwable $e) {}
        $pdo->exec("CREATE TABLE IF NOT EXISTS task_shares (task_id VARCHAR(32) NOT NULL, workspace_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, shared_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (task_id,user_id), CONSTRAINT fk_task_shares_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE, CONSTRAINT fk_task_shares_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, CONSTRAINT fk_task_shares_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, INDEX idx_task_shares_workspace_user (workspace_id,user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS workspace_invites (token CHAR(32) PRIMARY KEY, workspace_id INT UNSIGNED NOT NULL, role ENUM('member','admin') NOT NULL DEFAULT 'member', created_by INT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, active TINYINT(1) NOT NULL DEFAULT 1, CONSTRAINT fk_invites_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, CONSTRAINT fk_invites_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE, INDEX idx_invites_workspace (workspace_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS suggestions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, workspace_id INT UNSIGNED NOT NULL, title VARCHAR(180) NOT NULL, body TEXT NOT NULL, status ENUM('open','reviewed','done') NOT NULL DEFAULT 'open', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_suggestions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, CONSTRAINT fk_suggestions_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, INDEX idx_suggestions_workspace (workspace_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, endpoint_hash CHAR(64) NOT NULL, endpoint TEXT NOT NULL, p256dh VARCHAR(255) NOT NULL, auth VARCHAR(255) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, CONSTRAINT fk_push_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, UNIQUE KEY uq_push_endpoint (endpoint_hash), INDEX idx_push_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try { $pdo->exec("ALTER TABLE push_subscriptions ADD COLUMN device_label VARCHAR(80) NOT NULL DEFAULT 'Navegador' AFTER auth"); } catch (Throwable $e) {}
        $pdo->exec("CREATE TABLE IF NOT EXISTS push_jobs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, job_key VARCHAR(80) NOT NULL, fire_at INT UNSIGNED NOT NULL, title VARCHAR(180) NOT NULL, body TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_push_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, UNIQUE KEY uq_push_job (user_id,job_key), INDEX idx_push_due (fire_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS device_sessions (token_hash CHAR(64) PRIMARY KEY, user_id INT UNSIGNED NOT NULL, expires_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_device_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, INDEX idx_device_sessions_expiry (expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    return $pdo;
}
function body(): array { $raw = file_get_contents('php://input'); return $raw ? (json_decode($raw, true) ?: []) : []; }
function out(array $data, int $status = 200): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function user(): ?array {
    if (!empty($_SESSION['user'])) return $_SESSION['user'];
    $token = trim((string)($_SERVER['HTTP_X_REMEMBER_TOKEN'] ?? ''));
    if ($token === '' || strlen($token) > 200) return null;
    $q = db()->prepare('SELECT u.* FROM device_sessions ds JOIN users u ON u.id=ds.user_id WHERE ds.token_hash=? AND ds.expires_at>NOW() LIMIT 1');
    $q->execute([hash('sha256', $token)]); $u = $q->fetch();
    if (!$u) return null;
    // Renovar la sesión persistente mientras el dispositivo siga utilizándolo.
    // Así no se expira aunque la persona use la app con frecuencia durante meses.
    db()->prepare('UPDATE device_sessions SET expires_at=DATE_ADD(NOW(), INTERVAL 365 DAY) WHERE token_hash=?')->execute([hash('sha256', $token)]);
    $_SESSION['user'] = currentUserPayload($u);
    return $_SESSION['user'];
}
function requireUser(): array { $u = user(); if (!$u) out(['ok'=>false,'error'=>'No has iniciado sesión.'], 401); return $u; }
function cleanEmail(string $email): string { return strtolower(trim($email)); }
function workspaceAccess(int $workspaceId, int $userId): ?array {
    $q = db()->prepare('SELECT w.*, wm.role AS member_role FROM workspaces w LEFT JOIN workspace_members wm ON wm.workspace_id=w.id AND wm.user_id=? WHERE w.id=? AND (w.created_by=? OR wm.user_id=?)');
    $q->execute([$userId, $workspaceId, $userId, $userId]); return $q->fetch() ?: null;
}
function currentUserPayload(array $u): array { unset($u['password_hash']); return $u; }
function issueDeviceToken(int $userId): string {
    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO device_sessions(token_hash,user_id,expires_at) VALUES(?,?,DATE_ADD(NOW(), INTERVAL 365 DAY))')->execute([hash('sha256', $token), $userId]);
    return $token;
}
function revokeDeviceToken(): void {
    $token = trim((string)($_SERVER['HTTP_X_REMEMBER_TOKEN'] ?? ''));
    if ($token !== '') db()->prepare('DELETE FROM device_sessions WHERE token_hash=?')->execute([hash('sha256', $token)]);
}
function canAdminWorkspace(int $workspaceId, array $u): bool { if (($u['role'] ?? '') === 'super_admin') return true; $access=workspaceAccess($workspaceId,(int)$u['id']); return $access && ($access['member_role'] ?? '') === 'admin'; }
function b64urlEncode(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
function b64urlDecode(string $value): string { $value=strtr($value, '-_', '+/'); return base64_decode($value . str_repeat('=', (4 - strlen($value) % 4) % 4), true) ?: ''; }
function hkdfExtract(string $salt, string $ikm): string { return hash_hmac('sha256', $ikm, $salt, true); }
function hkdfExpand(string $prk, string $info, int $length): string { $out=''; $last=''; for($i=1;strlen($out)<$length;$i++){ $last=hash_hmac('sha256',$last.$info.chr($i),$prk,true); $out.=$last; } return substr($out,0,$length); }
function rawP256ToPem(string $raw): string { $der=hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200').$raw; return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PUBLIC KEY-----\n"; }
function derSignatureToJose(string $der, int $partLength=32): string { $pos=0; if(strlen($der)<8||ord($der[$pos++])!==0x30)return ''; $seqLen=ord($der[$pos++]); if($seqLen&0x80){$n=$seqLen&0x7f;$seqLen=0;for($i=0;$i<$n;$i++)$seqLen=($seqLen<<8)|ord($der[$pos++]);} if(ord($der[$pos++])!==0x02)return ''; $rLen=ord($der[$pos++]); if($rLen&0x80){$n=$rLen&0x7f;$rLen=0;for($i=0;$i<$n;$i++)$rLen=($rLen<<8)|ord($der[$pos++]);}$r=substr($der,$pos,$rLen);$pos+=$rLen; if(ord($der[$pos++])!==0x02)return ''; $sLen=ord($der[$pos++]); if($sLen&0x80){$n=$sLen&0x7f;$sLen=0;for($i=0;$i<$n;$i++)$sLen=($sLen<<8)|ord($der[$pos++]);}$s=substr($der,$pos,$sLen); $r=ltrim($r,"\0");$s=ltrim($s,"\0"); return str_pad($r,$partLength,"\0",STR_PAD_LEFT).str_pad($s,$partLength,"\0",STR_PAD_LEFT); }
function sendWebPush(array $subscription, string $payload, array $config): array {
    $url=(string)$subscription['endpoint']; $parts=parse_url($url); if(!$parts||empty($parts['scheme'])||empty($parts['host']))return ['ok'=>false,'code'=>0,'error'=>'Endpoint inválido'];
    $aud=$parts['scheme'].'://'.$parts['host'].(!empty($parts['port'])?':'.$parts['port']:''); $header=b64urlEncode(json_encode(['typ'=>'JWT','alg'=>'ES256'],JSON_UNESCAPED_SLASHES)); $claims=b64urlEncode(json_encode(['aud'=>$aud,'exp'=>time()+43200,'sub'=>$config['vapid_subject']],JSON_UNESCAPED_SLASHES)); $unsigned=$header.'.'.$claims; $signature=''; if(!openssl_sign($unsigned,$signature,$config['vapid_private_key'],OPENSSL_ALGO_SHA256))return ['ok'=>false,'code'=>0,'error'=>'No se pudo firmar VAPID']; $signature=derSignatureToJose($signature); if(strlen($signature)!==64)return ['ok'=>false,'code'=>0,'error'=>'Firma VAPID inválida']; $jwt=$unsigned.'.'.b64urlEncode($signature);
    $serverKey=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']); $details=openssl_pkey_get_details($serverKey); $ec=$details['ec']??[]; if(!$serverKey||empty($ec['x'])||empty($ec['y']))return ['ok'=>false,'code'=>0,'error'=>'No se pudo crear la clave de cifrado']; $serverPublic="\x04".$ec['x'].$ec['y']; $clientPublic=b64urlDecode((string)$subscription['p256dh']); $shared=openssl_pkey_derive(rawP256ToPem($clientPublic),$serverKey,32); if(!$shared)return ['ok'=>false,'code'=>0,'error'=>'No se pudo derivar el secreto'];
    $auth=b64urlDecode((string)$subscription['auth']); $prkKey=hkdfExtract($auth,$shared); $ikm=hkdfExpand($prkKey,"WebPush: info\0".$clientPublic.$serverPublic,32); $salt=random_bytes(16); $prk=hkdfExtract($salt,$ikm); $cek=hkdfExpand($prk,"Content-Encoding: aes128gcm\0",16); $nonce=hkdfExpand($prk,"Content-Encoding: nonce\0",12); $tag=''; $cipher=openssl_encrypt($payload."\x02",'aes-128-gcm',$cek,OPENSSL_RAW_DATA,$nonce,$tag); if($cipher===false)return ['ok'=>false,'code'=>0,'error'=>'No se pudo cifrar']; $body=$salt.pack('N',4096).chr(strlen($serverPublic)).$serverPublic.$cipher.$tag;
    $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['TTL: 86400','Content-Type: application/octet-stream','Content-Encoding: aes128gcm','Authorization: vapid t='.$jwt.', k='.$config['vapid_public_key']]]); $response=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $error=curl_error($ch); curl_close($ch); return ['ok'=>$code>=200&&$code<300,'code'=>$code,'error'=>$error?:substr((string)$response,0,200)];
}

try {
    $action = $_GET['action'] ?? '';
    $data = body();
    if ($action === 'me') out(['ok'=>true, 'user'=>user(), 'workspaces'=>user() ? workspacesFor(user()) : []]);
    if ($action === 'push_config') out(['ok'=>true,'public_key'=>$config['vapid_public_key']??'']);
    if ($action === 'push_run') {
        $secret=(string)($_GET['secret']??$data['secret']??''); if(empty($config['push_cron_secret'])||!hash_equals($config['push_cron_secret'],$secret))out(['ok'=>false,'error'=>'No autorizado.'],403);
        $pdo=db(); $jobs=$pdo->query('SELECT * FROM push_jobs WHERE fire_at<=UNIX_TIMESTAMP() ORDER BY fire_at ASC LIMIT 100')->fetchAll(); $sent=0; $failed=0;
        foreach($jobs as $job){$q=$pdo->prepare('SELECT * FROM push_subscriptions WHERE user_id=?');$q->execute([(int)$job['user_id']]);$payload=json_encode(['title'=>$job['title'],'body'=>$job['body'],'url'=>'./'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);foreach($q as $sub){$result=sendWebPush($sub,$payload,$config);if($result['ok'])$sent++;else{$failed++;if(in_array((int)$result['code'],[404,410],true))$pdo->prepare('DELETE FROM push_subscriptions WHERE id=?')->execute([$sub['id']]);}}$pdo->prepare('DELETE FROM push_jobs WHERE id=?')->execute([$job['id']]);}
        out(['ok'=>true,'processed'=>count($jobs),'sent'=>$sent,'failed'=>$failed]);
    }
    if ($action === 'invite_info') {
        $token = trim((string)($data['token'] ?? '')); $q = db()->prepare('SELECT wi.role,w.name FROM workspace_invites wi JOIN workspaces w ON w.id=wi.workspace_id WHERE wi.token=? AND wi.active=1'); $q->execute([$token]); $invite=$q->fetch();
        if (!$invite) out(['ok'=>false,'error'=>'La liga no es válida o ya fue desactivada.'],404); out(['ok'=>true,'workspace'=>$invite['name'],'role'=>$invite['role']]);
    }
    if ($action === 'register') {
        $email = cleanEmail((string)($data['email'] ?? '')); $name = trim((string)($data['name'] ?? '')); $password = (string)($data['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($name) < 2 || strlen($password) < 8) out(['ok'=>false,'error'=>'Escribe un nombre, correo válido y una contraseña de al menos 8 caracteres.'], 422);
        $pdo = db(); $pdo->beginTransaction();
        $inviteToken = trim((string)($data['invite_token'] ?? '')); $invite = null;
        if ($inviteToken !== '') { $iq=$pdo->prepare('SELECT workspace_id,role FROM workspace_invites WHERE token=? AND active=1'); $iq->execute([$inviteToken]); $invite=$iq->fetch(); if (!$invite) { $pdo->rollBack(); out(['ok'=>false,'error'=>'La liga de invitación no es válida o ya fue desactivada.'],422); } }
        $first = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
        $q = $pdo->prepare('INSERT INTO users(email,name,password_hash,role) VALUES(?,?,?,?)'); $q->execute([$email,$name,password_hash($password,PASSWORD_DEFAULT),$first?'super_admin':'user']); $id=(int)$pdo->lastInsertId();
        if ($invite) { $wid=(int)$invite['workspace_id']; $pdo->prepare('INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(?,?,?)')->execute([$wid,$id,$invite['role']]); }
        else { $q = $pdo->prepare('INSERT INTO workspaces(name,created_by) VALUES(?,?)'); $q->execute(['Espacio de ' . $name, $id]); $wid=(int)$pdo->lastInsertId(); $pdo->prepare('INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(?,?,?)')->execute([$wid,$id,'admin']); }
        $pdo->commit();
        session_regenerate_id(true);
        $q=$pdo->prepare('SELECT * FROM users WHERE id=?'); $q->execute([$id]); $_SESSION['user']=currentUserPayload($q->fetch()); out(['ok'=>true,'user'=>$_SESSION['user'],'workspaces'=>workspacesFor($_SESSION['user']),'remember_token'=>issueDeviceToken($id)],201);
    }
    if ($action === 'login') {
        $email=cleanEmail((string)($data['email']??'')); $q=db()->prepare('SELECT * FROM users WHERE email=?'); $q->execute([$email]); $u=$q->fetch();
        if (!$u || !password_verify((string)($data['password']??''),$u['password_hash'])) out(['ok'=>false,'error'=>'Correo o contraseña incorrectos.'],401);
        session_regenerate_id(true);
        $_SESSION['user']=currentUserPayload($u); out(['ok'=>true,'user'=>$_SESSION['user'],'workspaces'=>workspacesFor($_SESSION['user']),'remember_token'=>issueDeviceToken((int)$u['id'])]);
    }
    if ($action === 'logout') { revokeDeviceToken(); $_SESSION=[]; session_destroy(); out(['ok'=>true]); }
    $u=requireUser();
    if ($action === 'push_subscribe') {
        $endpoint=trim((string)($data['endpoint']??''));$keys=is_array($data['keys']??null)?$data['keys']:[];$p256dh=trim((string)($keys['p256dh']??''));$auth=trim((string)($keys['auth']??''));$deviceLabel=substr(trim((string)($data['device_label']??'Navegador')),0,80) ?: 'Navegador';if($endpoint===''||$p256dh===''||$auth==='')out(['ok'=>false,'error'=>'Suscripción de push incompleta.'],422);$pdo=db();$q=$pdo->prepare('INSERT INTO push_subscriptions(user_id,endpoint_hash,endpoint,p256dh,auth,device_label) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),endpoint=VALUES(endpoint),p256dh=VALUES(p256dh),auth=VALUES(auth),device_label=VALUES(device_label)');$q->execute([(int)$u['id'],hash('sha256',$endpoint),$endpoint,$p256dh,$auth,$deviceLabel]);$c=$pdo->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id=?');$c->execute([(int)$u['id']]);out(['ok'=>true,'registered'=>(int)$c->fetchColumn()]);
    }
    if ($action === 'push_schedule') {
        $jobKey=preg_replace('/[^a-zA-Z0-9_-]/','',substr((string)($data['job_id']??''),0,80));$fireAt=(int)($data['fire_at']??0);$title=trim((string)($data['title']??'Pomodoro terminado'));$bodyText=trim((string)($data['body']??'Terminaste un ciclo de enfoque.'));if($jobKey===''||$fireAt<time()-60)out(['ok'=>false,'error'=>'Programación de push inválida.'],422);db()->prepare('INSERT INTO push_jobs(user_id,job_key,fire_at,title,body) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE fire_at=VALUES(fire_at),title=VALUES(title),body=VALUES(body)')->execute([(int)$u['id'],$jobKey,$fireAt,substr($title,0,180),$bodyText]);out(['ok'=>true]);
    }
    if ($action === 'push_cancel') { $jobKey=preg_replace('/[^a-zA-Z0-9_-]/','',substr((string)($data['job_id']??''),0,80));if($jobKey!=='')db()->prepare('DELETE FROM push_jobs WHERE user_id=? AND job_key=?')->execute([(int)$u['id'],$jobKey]);out(['ok'=>true]); }
    if ($action === 'push_test') {
        $q=db()->prepare('SELECT * FROM push_subscriptions WHERE user_id=?'); $q->execute([(int)$u['id']]); $subs=$q->fetchAll();
        $payload=json_encode(['title'=>'Aviso de prueba de Kaizen','body'=>'Las notificaciones push están activas en este dispositivo.','url'=>'./'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $sent=0; $failed=0; $lastError=''; $sentDevices=[]; $failedDevices=[];
        foreach($subs as $sub){
            $r=sendWebPush($sub,$payload,$config); $label=(string)($sub['device_label']??'Navegador');
            if($r['ok']) { $sent++; $sentDevices[]=$label; }
            else { $failed++; $failedDevices[]=$label; $lastError='HTTP '.($r['code']??0).': '.($r['error']??'error desconocido'); if(in_array((int)($r['code']??0),[404,410],true))db()->prepare('DELETE FROM push_subscriptions WHERE id=?')->execute([$sub['id']]); }
        }
        out(['ok'=>$sent>0,'registered'=>count($subs),'sent'=>$sent,'failed'=>$failed,'sent_devices'=>$sentDevices,'failed_devices'=>$failedDevices,'error'=>$sent?'':(count($subs)?'No se pudo enviar el push: '.$lastError:'No hay un dispositivo suscrito.')]);
    }
    if ($action === 'workspace_create') {
        if (!in_array($u['role'],['admin','super_admin'],true)) out(['ok'=>false,'error'=>'No tienes permisos.'],403);
        $name=trim((string)($data['name']??'')); if ($name==='') out(['ok'=>false,'error'=>'Escribe un nombre para el espacio.'],422);
        $pdo=db(); $pdo->beginTransaction(); $q=$pdo->prepare('INSERT INTO workspaces(name,created_by) VALUES(?,?)'); $q->execute([$name,$u['id']]); $wid=(int)$pdo->lastInsertId(); $pdo->prepare('INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(?,?,?)')->execute([$wid,$u['id'],'admin']); $pdo->commit(); out(['ok'=>true,'workspaces'=>workspacesFor($u)]);
    }
    if ($action === 'workspace_members') {
        $wid=(int)($data['workspace_id']??0); $access=workspaceAccess($wid,(int)$u['id']); if (!$access && $u['role']!=='super_admin') out(['ok'=>false,'error'=>'Sin acceso a este espacio.'],403);
        $q=db()->prepare('SELECT u.id,u.name,u.email,u.role,wm.role AS workspace_role FROM users u JOIN workspace_members wm ON wm.user_id=u.id WHERE wm.workspace_id=? ORDER BY u.name'); $q->execute([$wid]); out(['ok'=>true,'members'=>$q->fetchAll()]);
    }
    if ($action === 'member_add') {
        $wid=(int)($data['workspace_id']??0); $access=workspaceAccess($wid,(int)$u['id']); if ($u['role']!=='super_admin' && (!$access || $access['member_role']!=='admin')) out(['ok'=>false,'error'=>'Solo un administrador puede agregar miembros.'],403);
        $email=cleanEmail((string)($data['email']??'')); $role=($data['role']??'member')==='admin'?'admin':'member'; $q=db()->prepare('SELECT id FROM users WHERE email=?');$q->execute([$email]);$member=$q->fetch(); if(!$member) out(['ok'=>false,'error'=>'No existe una cuenta con ese correo.'],404); db()->prepare('INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role)')->execute([$wid,$member['id'],$role]); out(['ok'=>true]);
    }
    if ($action === 'invite_create') {
        $wid=(int)($data['workspace_id']??0); $access=workspaceAccess($wid,(int)$u['id']); if ($u['role']!=='super_admin' && (!$access || $access['member_role']!=='admin')) out(['ok'=>false,'error'=>'Solo un administrador puede crear ligas.'],403);
        $role=($data['role']??'member')==='admin'?'admin':'member'; $token=bin2hex(random_bytes(16)); db()->prepare('INSERT INTO workspace_invites(token,workspace_id,role,created_by) VALUES(?,?,?,?)')->execute([$token,$wid,$role,$u['id']]); out(['ok'=>true,'token'=>$token,'role'=>$role]);
    }
    if ($action === 'suggestion_add') {
        $wid=(int)($data['workspace_id']??0); if(!workspaceAccess($wid,(int)$u['id'])) out(['ok'=>false,'error'=>'Sin acceso a este espacio.'],403); $title=trim((string)($data['title']??'')); $bodyText=trim((string)($data['body']??'')); if($title===''||$bodyText==='') out(['ok'=>false,'error'=>'Escribe un título y una descripción.'],422); db()->prepare('INSERT INTO suggestions(user_id,workspace_id,title,body) VALUES(?,?,?,?)')->execute([$u['id'],$wid,substr($title,0,180),$bodyText]); out(['ok'=>true]);
    }
    if ($action === 'suggestions') {
        $wid=(int)($data['workspace_id']??0); $access=workspaceAccess($wid,(int)$u['id']); if(!$access) out(['ok'=>false,'error'=>'Sin acceso a este espacio.'],403); $q=db()->prepare('SELECT s.id,s.title,s.body,s.status,s.created_at,u.name FROM suggestions s JOIN users u ON u.id=s.user_id WHERE s.workspace_id=? ORDER BY s.created_at DESC'); $q->execute([$wid]); out(['ok'=>true,'suggestions'=>$q->fetchAll()]);
    }
    if ($action === 'checklist') {
        $wid=(int)($data['workspace_id']??0); if(!workspaceAccess($wid,(int)$u['id']) && $u['role']!=='super_admin') out(['ok'=>false,'error'=>'Sin acceso a este espacio.'],403);
        $q=db()->prepare('SELECT improvement_checklist_json FROM workspace_data WHERE workspace_id=?'); $q->execute([$wid]); $raw=$q->fetchColumn(); $items=json_decode((string)$raw,true); out(['ok'=>true,'items'=>is_array($items)?$items:[]]);
    }
    if ($action === 'checklist_sync') {
        $wid=(int)($data['workspace_id']??0); if(!workspaceAccess($wid,(int)$u['id']) && $u['role']!=='super_admin') out(['ok'=>false,'error'=>'Sin acceso a este espacio.'],403);
        $items=is_array($data['items']??null)?array_slice($data['items'],0,200):[]; $clean=[];
        foreach($items as $item){
            if(!is_array($item))continue;
            $text=trim((string)($item['text']??'')); if($text==='')continue;
            $clean[]=['id'=>substr(preg_replace('/[^a-zA-Z0-9_-]/','',(string)($item['id']??uniqid())),0,32),'text'=>substr($text,0,255),'done'=>!empty($item['done']),'createdAt'=>(int)($item['createdAt']??time()*1000)];
        }
        $pdo=db(); $json=json_encode($clean,JSON_UNESCAPED_UNICODE); $pdo->prepare('INSERT INTO workspace_data(workspace_id,projects_json,improvement_checklist_json) VALUES(?,?,?) ON DUPLICATE KEY UPDATE improvement_checklist_json=VALUES(improvement_checklist_json)')->execute([$wid,'[]',$json]); out(['ok'=>true,'items'=>$clean]);
    }
    if ($action === 'task_members') {
        $wid=(int)($data['workspace_id']??0); $access=workspaceAccess($wid,(int)$u['id']); if($u['role']!=='super_admin'&&!$access) out(['ok'=>false,'error'=>'Sin acceso a este espacio.'],403);
        $taskId=substr(preg_replace('/[^a-zA-Z0-9_-]/','',(string)($data['task_id']??'')),0,32); $tq=db()->prepare('SELECT owner_id FROM tasks WHERE id=? AND workspace_id=?'); $tq->execute([$taskId,$wid]); $task=$tq->fetch(); if(!$task)out(['ok'=>false,'error'=>'Tarea no encontrada.'],404);
        $canSeeAll=$u['role']==='super_admin'||(($access['member_role']??'')==='admin'); if(!$canSeeAll&&(int)$task['owner_id']!==(int)$u['id']){$sq=db()->prepare('SELECT 1 FROM task_shares WHERE task_id=? AND workspace_id=? AND user_id=?');$sq->execute([$taskId,$wid,$u['id']]);if($sq->fetchColumn()===false)out(['ok'=>false,'error'=>'No tienes acceso a esta tarea.'],403);}
        $q=db()->prepare('SELECT u.id,u.name,u.email,wm.role AS workspace_role FROM users u JOIN workspace_members wm ON wm.user_id=u.id WHERE wm.workspace_id=? ORDER BY u.name'); $q->execute([$wid]); $sq=db()->prepare('SELECT user_id FROM task_shares WHERE task_id=? AND workspace_id=?'); $sq->execute([$taskId,$wid]); $shared=array_map('intval',$sq->fetchAll(PDO::FETCH_COLUMN)); out(['ok'=>true,'members'=>$q->fetchAll(),'shared_user_ids'=>$shared]);
    }
    if ($action === 'task_share') {
        $wid=(int)($data['workspace_id']??0); $access=workspaceAccess($wid,(int)$u['id']); if($u['role']!=='super_admin'&&!$access) out(['ok'=>false,'error'=>'Sin acceso a este espacio.'],403);
        $taskId=substr(preg_replace('/[^a-zA-Z0-9_-]/','',(string)($data['task_id']??'')),0,32); $tq=db()->prepare('SELECT owner_id FROM tasks WHERE id=? AND workspace_id=?'); $tq->execute([$taskId,$wid]); $task=$tq->fetch(); if(!$task)out(['ok'=>false,'error'=>'Tarea no encontrada.'],404);
        $canAdmin=$u['role']==='super_admin'||(($access['member_role']??'')==='admin'); if(!$canAdmin&&(int)$task['owner_id']!==(int)$u['id'])out(['ok'=>false,'error'=>'Solo quien creó la tarea puede compartirla.'],403);
        $requested=is_array($data['user_ids']??null)?$data['user_ids']:[]; $requested=array_values(array_unique(array_filter(array_map('intval',$requested),fn($id)=>$id>0&&(int)$id!==(int)$task['owner_id'])));
        $valid=[]; if($requested){$placeholders=implode(',',array_fill(0,count($requested),'?'));$mq=db()->prepare('SELECT user_id FROM workspace_members WHERE workspace_id=? AND user_id IN ('.$placeholders.')');$mq->execute(array_merge([$wid],$requested));$valid=array_map('intval',$mq->fetchAll(PDO::FETCH_COLUMN));}
        $pdo=db();$pdo->beginTransaction();$pdo->prepare('DELETE FROM task_shares WHERE task_id=? AND workspace_id=?')->execute([$taskId,$wid]);$ins=$pdo->prepare('INSERT INTO task_shares(task_id,workspace_id,user_id) VALUES(?,?,?)');foreach($valid as $id)$ins->execute([$taskId,$wid,$id]);$pdo->commit();out(['ok'=>true,'shared_user_ids'=>$valid]);
    }
    if ($action === 'workspace_tasks_clear') {
        if (($u['role'] ?? '') !== 'super_admin') out(['ok'=>false,'error'=>'Solo el superadministrador puede borrar todas las tareas de un espacio.'],403);
        $wid=(int)($data['workspace_id']??0); $q=db()->prepare('SELECT id,name FROM workspaces WHERE id=?'); $q->execute([$wid]); $workspace=$q->fetch(); if(!$workspace) out(['ok'=>false,'error'=>'Espacio de trabajo no encontrado.'],404);
        $del=db()->prepare('DELETE FROM tasks WHERE workspace_id=?'); $del->execute([$wid]); out(['ok'=>true,'workspace'=>$workspace['name'],'deleted'=>(int)$del->rowCount()]);
    }
    if ($action === 'tasks') {
        $wid=(int)($data['workspace_id']??0); $access=workspaceAccess($wid,(int)$u['id']); if($u['role']!=='super_admin'&&!$access) out(['ok'=>false,'error'=>'Sin acceso a este espacio.'],403);
        $canSeeAll=$u['role']==='super_admin'||(($access['member_role']??'')==='admin');
        $sql=$canSeeAll ? 'SELECT id,owner_id,payload_json,updated_at FROM tasks WHERE workspace_id=? ORDER BY updated_at DESC' : 'SELECT t.id,t.owner_id,t.payload_json,t.updated_at FROM tasks t WHERE t.workspace_id=? AND (t.owner_id=? OR EXISTS (SELECT 1 FROM task_shares ts WHERE ts.task_id=t.id AND ts.workspace_id=t.workspace_id AND ts.user_id=?)) ORDER BY t.updated_at DESC';
        $q=db()->prepare($sql); $canSeeAll ? $q->execute([$wid]) : $q->execute([$wid,$u['id'],$u['id']]); $tasks=[]; foreach($q as $r){$item=json_decode($r['payload_json'],true)?:[];$item['id']=$r['id'];$item['_owner_id']=$r['owner_id'];$tasks[]=$item;}
        if($tasks){$ids=array_column($tasks,'id');$placeholders=implode(',',array_fill(0,count($ids),'?'));$sq=db()->prepare('SELECT task_id,user_id FROM task_shares WHERE workspace_id=? AND task_id IN ('.$placeholders.')');$sq->execute(array_merge([$wid],$ids));$sharedBy=[];foreach($sq as $share)$sharedBy[$share['task_id']][]=(int)$share['user_id'];foreach($tasks as &$item)$item['_shared_user_ids']=$sharedBy[$item['id']]??[];unset($item);}
        $p=db()->prepare('SELECT projects_json FROM workspace_data WHERE workspace_id=?');$p->execute([$wid]);$projects=$p->fetchColumn(); out(['ok'=>true,'tasks'=>$tasks,'projects'=>$projects?json_decode($projects,true):[]]);
    }
    if ($action === 'tasks_sync') {
        $wid=(int)($data['workspace_id']??0); $access=workspaceAccess($wid,(int)$u['id']); if($u['role']!=='super_admin'&&!$access) out(['ok'=>false,'error'=>'Sin acceso a este espacio.'],403); $canSeeAll=$u['role']==='super_admin'||(($access['member_role']??'')==='admin'); $tasks=is_array($data['tasks']??null)?$data['tasks']:[]; $projects=is_array($data['projects']??null)?$data['projects']:[]; $pdo=db(); $pdo->beginTransaction(); $stmt=$pdo->prepare('INSERT INTO tasks(id,workspace_id,owner_id,title,done,payload_json) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),done=VALUES(done),payload_json=VALUES(payload_json)'); $ownerCheck=$pdo->prepare('SELECT owner_id FROM tasks WHERE id=? AND workspace_id=?'); $shareCheck=$pdo->prepare('SELECT 1 FROM task_shares WHERE task_id=? AND workspace_id=? AND user_id=? LIMIT 1'); foreach($tasks as $t){$id=preg_replace('/[^a-zA-Z0-9_-]/','',substr((string)($t['id']??uniqid()),0,32)); if(!$canSeeAll){$ownerCheck->execute([$id,$wid]);$existing=$ownerCheck->fetchColumn();if($existing!==false&&(int)$existing!==(int)$u['id']){$shareCheck->execute([$id,$wid,$u['id']]);if($shareCheck->fetchColumn()===false)continue;}} $stmt->execute([$id,$wid,$u['id'],substr((string)($t['title']??''),0,255),!empty($t['done'])?1:0,json_encode($t,JSON_UNESCAPED_UNICODE)]);} $pdo->prepare('INSERT INTO workspace_data(workspace_id,projects_json) VALUES(?,?) ON DUPLICATE KEY UPDATE projects_json=VALUES(projects_json)')->execute([$wid,json_encode($projects,JSON_UNESCAPED_UNICODE)]); $pdo->commit(); out(['ok'=>true]);
    }
    if ($action === 'admin_overview') {
        $pdo=db(); if($u['role']==='super_admin') { $where=''; $params=[]; } else { $where=' WHERE w.created_by=? OR (wm.user_id=? AND wm.role=?)'; $params=[$u['id'],$u['id'],'admin']; } $q=$pdo->prepare('SELECT w.id,w.name,COUNT(DISTINCT wm.user_id) members,COUNT(DISTINCT t.id) tasks FROM workspaces w LEFT JOIN workspace_members wm ON wm.workspace_id=w.id LEFT JOIN tasks t ON t.workspace_id=w.id'.$where.' GROUP BY w.id ORDER BY w.name');$q->execute($params); out(['ok'=>true,'workspaces'=>$q->fetchAll()]);
    }
    out(['ok'=>false,'error'=>'Acción no reconocida.'],404);
} catch (Throwable $e) { error_log((string)$e); out(['ok'=>false,'error'=>'No se pudo completar la operación.'],500); }

function workspacesFor(array $u): array {
    $pdo=db(); if($u['role']==='super_admin'){return $pdo->query('SELECT id,name,created_by FROM workspaces ORDER BY name')->fetchAll();}
    $q=$pdo->prepare('SELECT w.id,w.name,w.created_by,wm.role AS member_role FROM workspaces w JOIN workspace_members wm ON wm.workspace_id=w.id WHERE wm.user_id=? ORDER BY w.name');$q->execute([$u['id']]);return $q->fetchAll();
}
