<?php
declare(strict_types=1);
require __DIR__ . '/../api/password-reset.php';
$dsn=getenv('KAIZEN_TEST_DSN')?:'';
if(!preg_match('/^mysql:host=127\.0\.0\.1;(?:port=\d+;)?dbname=kaizen_test_[a-z0-9_]+$/D',$dsn))throw new RuntimeException('Set a loopback kaizen_test_ database DSN.');
$connect=static fn()=>new PDO($dsn,getenv('KAIZEN_TEST_DB_USER')?:'root',getenv('KAIZEN_TEST_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo=$connect();
function awaitMarker(string $file):void {
    for($i=0;$i<1000;$i++){if(is_file($file))return;usleep(10000);}
    throw new RuntimeException('Timed out waiting for disposable race barrier.');
}
if(($argv[1]??'')==='child'){
    [$script,$ignored,$mode,$id,$dir,$pause]=$argv;
    $id=(int)$id;
    touch($dir.'/started');
    $barrier=$pause==='yes'?static function()use($dir):void {touch($dir.'/locked');awaitMarker($dir.'/release');}:null;
    try {
        if($mode==='login')$ok=passwordLogin($pdo,"race$id@example.test","old-fixture-password-$id",$barrier)!==null;
        elseif($mode==='change')$ok=passwordChange($pdo,$id,"old-fixture-password-$id","new-fixture-password-$id","new-fixture-password-$id",'127.0.0.9',$barrier)==='';
        elseif($mode==='reset')$ok=passwordResetConfirm($pdo,str_repeat((string)$id,64),"new-fixture-password-$id",$barrier);
        else throw new RuntimeException('Invalid test mode.');
        file_put_contents($dir.'/result',$ok?'success':'denied');
    } catch(Throwable $e){file_put_contents($dir.'/result','error: '.$e->getMessage());}
    exit;
}
if($pdo->query('SHOW TABLES')->fetchColumn()!==false)throw new RuntimeException('The disposable test database must be empty.');
$count=0;
function checkRace(bool $condition,string $label):void {global $count;if(!$condition)throw new RuntimeException($label);$count++;echo "PASS $label\n";}
function startRaceChild(string $mode,int $id,string $dir,bool $pause):array {
    mkdir($dir,0700,true);
    $pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'child',$mode,(string)$id,$dir,$pause?'yes':'no'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($proc))throw new RuntimeException('Cannot start disposable race worker.');
    fclose($pipes[0]);
    return [$proc,$pipes];
}
function finishRaceChild(array $child,string $dir):string {
    awaitMarker($dir.'/result');
    [$proc,$pipes]=$child;
    $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($proc)!==0)throw new RuntimeException('Race worker failed: '.$stdout.$stderr);
    return trim(file_get_contents($dir.'/result'));
}
try {
    $pdo->exec(file_get_contents(__DIR__.'/../api/schema.sql'));
    $pdo->exec('CREATE TABLE device_sessions (token_hash CHAR(64) PRIMARY KEY, user_id INT UNSIGNED NOT NULL, expires_at DATETIME NOT NULL, CONSTRAINT fk_device_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $insert=$pdo->prepare('INSERT INTO users(id,email,name,password_hash) VALUES(?,?,?,?)');
    for($id=1;$id<=4;$id++){
        $insert->execute([$id,"race$id@example.test","Race $id",password_hash("old-fixture-password-$id",PASSWORD_DEFAULT)]);
        if($id>=3)$pdo->prepare('INSERT INTO password_reset_tokens(token_hash,user_id,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))')->execute([hash('sha256',str_repeat((string)$id,64)),$id]);
    }
    foreach([['login','change',1],['change','login',2],['login','reset',3],['reset','login',4]] as [$firstMode,$secondMode,$id]){
        $root=sys_get_temp_dir().'/kaizen-race-'.bin2hex(random_bytes(8));
        $firstDir=$root.'/first';$secondDir=$root.'/second';
        $first=startRaceChild($firstMode,$id,$firstDir,true);
        awaitMarker($firstDir.'/locked');
        $second=startRaceChild($secondMode,$id,$secondDir,false);
        awaitMarker($secondDir.'/started');
        touch($firstDir.'/release');
        $firstResult=finishRaceChild($first,$firstDir);
        $secondResult=finishRaceChild($second,$secondDir);
        checkRace($firstResult==='success' && $secondResult===($secondMode==='login'?'denied':'success'),"$firstMode before $secondMode serializes under user lock");
        $q=$pdo->prepare('SELECT COUNT(*) FROM device_sessions WHERE user_id=?');$q->execute([$id]);
        checkRace((int)$q->fetchColumn()===0,"$firstMode/$secondMode leaves no persistent device token");
        $q=$pdo->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$id]);
        checkRace(password_verify("new-fixture-password-$id",(string)$q->fetchColumn()),"$firstMode/$secondMode commits new password");
        foreach([$firstDir,$secondDir] as $dir)foreach(glob($dir.'/*')?:[] as $file)unlink($file);
        rmdir($firstDir);rmdir($secondDir);rmdir($root);
    }
    echo "$count concurrent password checks passed\n";
} finally {
    if($pdo->inTransaction())$pdo->rollBack();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$pdo->exec('DROP TABLE `'.str_replace('`','``',$table).'`');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}
