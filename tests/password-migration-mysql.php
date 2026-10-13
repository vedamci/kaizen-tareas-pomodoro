<?php
declare(strict_types=1);
require __DIR__ . '/../api/password-reset.php';
$dsn=getenv('KAIZEN_TEST_DSN')?:'';
if(!preg_match('/^mysql:host=127\.0\.0\.1;(?:port=\d+;)?dbname=kaizen_test_[a-z0-9_]+$/D',$dsn))throw new RuntimeException('Set a loopback kaizen_test_ database DSN.');
$pdo=new PDO($dsn,getenv('KAIZEN_TEST_DB_USER')?:'root',getenv('KAIZEN_TEST_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($pdo->query('SHOW TABLES')->fetchColumn()!==false)throw new RuntimeException('The disposable test database must be empty.');
try {
    // Representative populated pre-PR7 tables. No production config or data.
    $pdo->exec('CREATE TABLE users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,email VARCHAR(190) NOT NULL UNIQUE,name VARCHAR(120) NOT NULL,password_hash VARCHAR(255) NOT NULL,role ENUM("user","admin","super_admin") NOT NULL DEFAULT "user",created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE tasks (id VARCHAR(32) PRIMARY KEY,owner_id INT UNSIGNED NOT NULL,title VARCHAR(255) NOT NULL,payload_json LONGTEXT NOT NULL,CONSTRAINT fk_old_task_owner FOREIGN KEY(owner_id) REFERENCES users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $hash=password_hash('disposable-legacy-password',PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO users(id,email,name,password_hash) VALUES(?,?,?,?)')->execute([1,'legacy@example.test','Legacy Fixture',$hash]);
    $payload='{"due":"2026-10-05","seconds":1500}';
    $pdo->prepare('INSERT INTO tasks(id,owner_id,title,payload_json) VALUES(?,?,?,?)')->execute(['old-task',1,'Existing fixture task',$payload]);
    $beforeUser=$pdo->query('SELECT * FROM users WHERE id=1')->fetch();
    $beforeTask=$pdo->query("SELECT * FROM tasks WHERE id='old-task'")->fetch();
    ensurePasswordResetTables($pdo);
    ensurePasswordResetTables($pdo);
    if($pdo->query('SELECT * FROM users WHERE id=1')->fetch()!==$beforeUser)throw new RuntimeException('Migration changed existing fixture user.');
    if($pdo->query("SELECT * FROM tasks WHERE id='old-task'")->fetch()!==$beforeTask)throw new RuntimeException('Migration changed existing fixture task or dates.');
    foreach(['password_reset_tokens','password_reset_mail_queue','password_reset_limits'] as $table)if($pdo->query("SHOW TABLES LIKE '$table'")->fetchColumn()===false)throw new RuntimeException('Missing additive table.');
    echo "PASS additive password migration preserves populated users and tasks\n";
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$pdo->exec('DROP TABLE `'.str_replace('`','``',$table).'`');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}
