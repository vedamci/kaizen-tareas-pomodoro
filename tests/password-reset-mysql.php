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
    $pdo->prepare('INSERT INTO users(id,email,name,password_hash) VALUES(?,?,?,?),(?,?,?,?)')->execute([1,'one@example.test','One',password_hash('old-fixture-password-1',PASSWORD_DEFAULT),2,'two@example.test','Two',password_hash('old-fixture-password-2',PASSWORD_DEFAULT)]);
    $pdo->prepare('INSERT INTO device_sessions(token_hash,user_id,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))')->execute([hash('sha256','fake-device-token'),1]);
    verifyReset(!passwordResetConfigured(array_replace($settings,['password_reset_enabled'=>false])),'reset stays disabled without approval and configuration');
    passwordResetRequest($pdo,'absent@example.test','127.0.0.1',$settings,$sender);
    verifyReset(count($outbox)===0,'unknown address sends no mail');
    passwordResetRequest($pdo,'one@example.test','127.0.0.1',$settings,$sender);
    verifyReset(count($outbox)===1 && $outbox[0]['to']==='one@example.test','fake sender receives only existing fixture account');
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
    passwordResetRequest($pdo,'two@example.test','127.0.0.2',$settings,$sender);
    preg_match('/#reset=([a-f0-9]{64})/', $outbox[1]['message'], $matches);
    $expired=$matches[1]??'';
    $pdo->prepare('UPDATE password_reset_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE token_hash=?')->execute([hash('sha256',$expired)]);
    verifyReset(!passwordResetConfirm($pdo,$expired,'expired-fixture-password'),'expired token is rejected');
    $before=count($outbox);
    for($i=0;$i<4;$i++)passwordResetRequest($pdo,'two@example.test','127.0.0.3',$settings,$sender);
    verifyReset(count($outbox)===$before+2,'email rate limit caps fixture mail delivery at three per hour');
    verifyReset(passwordChange($pdo,2,'wrong','changed-fixture-password-2','changed-fixture-password-2','127.0.0.4')!=='','incorrect current password rejected');
    verifyReset(passwordChange($pdo,2,'old-fixture-password-2','changed-fixture-password-2','changed-fixture-password-2','127.0.0.4')==='','authenticated fixture changes own password');
    verifyReset(password_verify('changed-fixture-password-2',$pdo->query('SELECT password_hash FROM users WHERE id=2')->fetchColumn()),'change saves a password hash');
    echo "$count password reset MySQL checks passed\n";
} finally {
    if($pdo->inTransaction())$pdo->rollBack();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$pdo->exec('DROP TABLE `'.str_replace('`','``',$table).'`');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}
