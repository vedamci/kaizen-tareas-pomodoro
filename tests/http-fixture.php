<?php
declare(strict_types=1);
// Test-only, empty, loopback database; never load the real app configuration.
$dsn=getenv('KAIZEN_TEST_DSN')?:'';
$root=$argv[1]??'';
if(!preg_match('/^mysql:host=127\.0\.0\.1;(?:port=\d+;)?dbname=(kaizen_test_[a-z0-9_]+)$/D',$dsn,$match)||!is_dir($root.'/api'))throw new RuntimeException('Isolated HTTP fixture required.');
$pdo=new PDO($dsn,getenv('KAIZEN_TEST_DB_USER')?:'root',getenv('KAIZEN_TEST_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($pdo->query('SHOW TABLES')->fetchColumn()!==false)throw new RuntimeException('HTTP test database must be empty.');
$pdo->exec(file_get_contents(__DIR__.'/../api/schema.sql'));
$insert=$pdo->prepare('INSERT INTO users(id,email,name,password_hash,role) VALUES(?,?,?,?,?)');
foreach([1,2,3,4,5] as $id)$insert->execute([$id,"fixture$id@example.test","Fixture $id",password_hash('disposable-http-fixture',PASSWORD_DEFAULT),'user']);
$pdo->exec("INSERT INTO workspaces(id,name,created_by) VALUES(1,'HTTP Fixture',1),(2,'Other Fixture',5)");
$pdo->exec("INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(1,1,'admin'),(1,2,'member'),(1,3,'member'),(1,4,'admin'),(2,5,'admin')");
$settings=['db_host'=>'127.0.0.1','db_name'=>$match[1],'db_user'=>getenv('KAIZEN_TEST_DB_USER')?:'root','db_pass'=>getenv('KAIZEN_TEST_DB_PASSWORD')?:'','session_name'=>'kaizen_http_fixture','password_reset_enabled'=>true,'password_reset_url'=>'https://example.test/kaizen/','password_reset_from_email'=>'noreply@example.test','password_reset_rate_secret'=>'disposable-fixture-rate-key-only-for-tests'];
$outbox=$root.'/mail-outbox.jsonl';
file_put_contents($root.'/api/config.php','<?php $settings='.var_export($settings,true).'; $settings["password_reset_mailer"]=static function(string $to,string $subject,string $message): bool { file_put_contents('.var_export($outbox,true).',json_encode(["to"=>$to,"subject"=>$subject,"message"=>$message])."\n",FILE_APPEND|LOCK_EX); return true; }; return $settings;');
echo "Disposable HTTP fixture prepared\n";
