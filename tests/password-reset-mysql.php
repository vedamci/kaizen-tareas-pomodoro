<?php
declare(strict_types=1);
require __DIR__ . '/../api/password-reset.php';

// Uses only an empty, disposable database on loopback and a fake mail sender.
$dsn=getenv('KAIZEN_TEST_DSN')?:'';
if(!preg_match('/^mysql:host=127\.0\.0\.1;(?:port=\d+;)?dbname=kaizen_test_[a-z0-9_]+$/D',$dsn))throw new RuntimeException('Set a loopback kaizen_test_ database DSN.');
$pdo=new PDO($dsn,getenv('KAIZEN_TEST_DB_USER')?:'root',getenv('KAIZEN_TEST_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($pdo->query('SHOW TABLES')->fetchColumn()!==false)throw new RuntimeException('The disposable test database must be empty.');
$count=0;
function verifyReset(bool $ok,string $name):void {global $count;if(!$ok)throw new RuntimeException($name);$count++;echo "PASS $name\n";}
$outbox=[];
$sender=static function(string $to,string $subject,string $message)use(&$outbox):bool {$outbox[]=['to'=>$to,'subject'=>$subject,'message'=>$message];return true;};
$settings=['password_reset_enabled'=>true,'password_reset_url'=>'https://example.test/kaizen/','password_reset_from_email'=>'noreply@example.test','password_reset_rate_secret'=>'disposable-fixture-rate-key-only-for-tests'];
try {
    $pdo->exec(file_get_contents(__DIR__.'/../api/schema.sql'));
    // Existing production table is created by db() at runtime, not schema.sql.
    $pdo->exec('CREATE TABLE device_sessions (token_hash CHAR(64) PRIMARY KEY, user_id INT UNSIGNED NOT NULL, expires_at DATETIME NOT NULL, CONSTRAINT fk_device_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->prepare('INSERT INTO users(id,email,name,password_hash) VALUES(?,?,?,?),(?,?,?,?)')->execute([1,'one@example.test','One',password_hash('old-fixture-password-1',PASSWORD_DEFAULT),2,'two@example.test','Two',password_hash('old-fixture-password-2',PASSWORD_DEFAULT)]);
    $pdo->prepare('INSERT INTO device_sessions(token_hash,user_id,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))')->execute([hash('sha256','fake-device-token'),1]);
    verifyReset(!passwordResetConfigured(array_replace($settings,['password_reset_enabled'=>false])),'reset stays disabled without approval and configuration');
    passwordResetRequest($pdo,'absent@example.test','127.0.0.1',$settings);
    verifyReset(count($outbox)===0 && passwordResetProcessQueue($pdo,$settings,$sender)===0,'unknown address enqueues but sends no mail');
    passwordResetRequest($pdo,'one@example.test','127.0.0.1',$settings);
    verifyReset(count($outbox)===0,'HTTP request does not send synchronously');
    $queued=$pdo->query('SELECT token_cipher FROM password_reset_mail_queue WHERE user_id=1')->fetchColumn();
    verifyReset($queued && !str_contains($queued,'#reset=') && !preg_match('/^[a-f0-9]{64}$/D',$queued),'queued link token is encrypted');
    verifyReset(passwordResetProcessQueue($pdo,$settings,$sender)===1 && count($outbox)===1 && $outbox[0]['to']==='one@example.test','worker sends only existing fixture account');
    verifyReset(str_contains($outbox[0]['message'],'30 minutos después de la solicitud'),'mail states expiry from request time');
    preg_match('/#reset=([a-f0-9]{64})/', $outbox[0]['message'], $matches);
    $token=$matches[1]??'';
    $stored=$pdo->query('SELECT token_hash,expires_at FROM password_reset_tokens WHERE user_id=1')->fetch();
    verifyReset(strlen($token)===64 && $stored['token_hash']===hash('sha256',$token) && $stored['token_hash']!==$token,'database stores only reset token digest');
    verifyReset(strtotime($stored['expires_at'].' UTC')>time() && strtotime($stored['expires_at'].' UTC')<=time()+1800,'reset token expires within 30 minutes');
    verifyReset(!passwordResetConfirm($pdo,$token,'too-short'),'short password rejected without consuming token');
    verifyReset(passwordResetConfirm($pdo,$token,'new-fixture-password-1'),'valid token changes fixture password');
    verifyReset(!passwordResetConfirm($pdo,$token,'another-fixture-password-1'),'token cannot be reused');
    verifyReset((int)$pdo->query('SELECT COUNT(*) FROM device_sessions WHERE user_id=1')->fetchColumn()===0,'successful reset revokes device sessions');
    $hash=$pdo->query('SELECT password_hash FROM users WHERE id=1')->fetchColumn();
    verifyReset(password_verify('new-fixture-password-1',$hash) && !password_verify('old-fixture-password-1',$hash),'reset changes only targeted password hash');
    verifyReset(password_verify('old-fixture-password-2',$pdo->query('SELECT password_hash FROM users WHERE id=2')->fetchColumn()),'other fixture account remains unchanged');
    passwordResetRequest($pdo,'two@example.test','127.0.0.2',$settings);
    verifyReset(passwordResetProcessQueue($pdo,$settings,$sender)===1,'worker delivers second fixture link');
    preg_match('/#reset=([a-f0-9]{64})/', $outbox[1]['message'], $matches);
    $expired=$matches[1]??'';
    $pdo->prepare('UPDATE password_reset_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE token_hash=?')->execute([hash('sha256',$expired)]);
    verifyReset(!passwordResetConfirm($pdo,$expired,'expired-fixture-password'),'expired token is rejected');
    $before=count($outbox);
    for($i=0;$i<4;$i++)passwordResetRequest($pdo,'two@example.test','127.0.0.3',$settings);
    passwordResetProcessQueue($pdo,$settings,$sender);
    verifyReset(count($outbox)===$before+1,'email limit and superseded links leave only latest queued delivery');
    $pdo->exec("INSERT INTO password_reset_limits(scope,subject_hash,window_start,attempts) VALUES('stale','".str_repeat('a',64)."',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 3 DAY),1)");
    for($i=0;$i<13;$i++)passwordResetRequest($pdo,"blocked$i@example.test",'127.0.0.99',$settings);
    $rows=(int)$pdo->query("SELECT COUNT(*) FROM password_reset_limits WHERE scope='email'")->fetchColumn();
    $jobs=(int)$pdo->query('SELECT COUNT(*) FROM password_reset_mail_queue')->fetchColumn();
    for($i=13;$i<30;$i++)passwordResetRequest($pdo,"blocked$i@example.test",'127.0.0.99',$settings);
    verifyReset((int)$pdo->query("SELECT COUNT(*) FROM password_reset_limits WHERE scope='email'")->fetchColumn()===$rows && (int)$pdo->query('SELECT COUNT(*) FROM password_reset_mail_queue')->fetchColumn()===$jobs,'blocked IP cannot create email-limit rows or queue jobs');
    verifyReset((int)$pdo->query("SELECT COUNT(*) FROM password_reset_limits WHERE scope='stale'")->fetchColumn()===0,'old rate-limit windows are purged');
    verifyReset(passwordChange($pdo,2,'wrong','changed-fixture-password-2','changed-fixture-password-2','127.0.0.4')!=='','incorrect current password rejected');
    verifyReset(passwordChange($pdo,2,'old-fixture-password-2','changed-fixture-password-2','changed-fixture-password-2','127.0.0.4')==='','authenticated fixture changes own password');
    verifyReset(password_verify('changed-fixture-password-2',$pdo->query('SELECT password_hash FROM users WHERE id=2')->fetchColumn()),'change saves a password hash');
    $cipher=passwordResetQueueEncrypt(str_repeat('a',64),$settings['password_reset_rate_secret']);
    $tampered=base64_decode($cipher,true);$tampered[30]=chr(ord($tampered[30])^1);
    verifyReset(passwordResetQueueDecrypt(base64_encode($tampered),$settings['password_reset_rate_secret'])===null,'tampered queue ciphertext fails authentication');
    verifyReset(passwordResetQueueDecrypt($cipher,str_repeat('x',40))===null,'wrong queue secret cannot decrypt token');
    $insert=$pdo->prepare('INSERT INTO users(id,email,name,password_hash) VALUES(?,?,?,?)');
    foreach([3,4,5] as $id)$insert->execute([$id,"queue$id@example.test","Queue $id",password_hash("queue-fixture-password-$id",PASSWORD_DEFAULT)]);
    passwordResetProcessQueue($pdo,$settings,$sender); // Discard earlier unknown-address dummy jobs.
    passwordResetRequest($pdo,'queue3@example.test','127.0.0.13',$settings);
    $attempts=0;
    $flaky=static function(string $to,string $subject,string $message)use(&$attempts,&$outbox):bool {++$attempts;if($attempts===1)return false;$outbox[]=['to'=>$to,'subject'=>$subject,'message'=>$message];return true;};
    verifyReset(passwordResetProcessQueue($pdo,$settings,$flaky)===0 && $attempts===1,'failed fake delivery remains queued for retry');
    $pdo->exec('UPDATE password_reset_mail_queue SET claimed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 6 MINUTE) WHERE user_id=3');
    verifyReset(passwordResetProcessQueue($pdo,$settings,$flaky)===1 && $attempts===2 && (int)$pdo->query('SELECT COUNT(*) FROM password_reset_mail_queue WHERE user_id=3')->fetchColumn()===0,'retry delivers once and removes job');
    passwordResetRequest($pdo,'queue4@example.test','127.0.0.14',$settings);
    $before=count($outbox);
    $wrongSettings=array_replace($settings,['password_reset_rate_secret'=>str_repeat('x',40)]);
    verifyReset(passwordResetProcessQueue($pdo,$wrongSettings,$sender)===0 && count($outbox)===$before && (int)$pdo->query('SELECT COUNT(*) FROM password_reset_mail_queue WHERE user_id=4')->fetchColumn()===0,'worker with wrong secret discards job without sending');
    passwordResetRequest($pdo,'queue5@example.test','127.0.0.15',$settings);
    $pdo->exec('UPDATE password_reset_mail_queue SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE user_id=5');
    verifyReset(passwordResetProcessQueue($pdo,$settings,$sender)===0 && (int)$pdo->query('SELECT COUNT(*) FROM password_reset_mail_queue WHERE user_id=5')->fetchColumn()===0,'expired queued job is discarded without sending');
    echo "$count password reset MySQL checks passed\n";
} finally {
    if($pdo->inTransaction())$pdo->rollBack();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$pdo->exec('DROP TABLE `'.str_replace('`','``',$table).'`');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}
