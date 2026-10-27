<?php
declare(strict_types=1);
// Run only from a private CLI scheduler after mail delivery is approved.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/password-reset.php';
$config = require __DIR__ . '/config.php';
if (!passwordResetConfigured($config) || strlen((string)($config['password_reset_rate_secret'] ?? '')) < 32) {
    fwrite(STDERR, "Kaizen password recovery mail is not configured.\n");
    exit(1);
}
$pdo = new PDO(
    'mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4',
    $config['db_user'],
    $config['db_pass'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
);
$count = passwordResetProcessQueue($pdo, $config, $config['password_reset_mailer'] ?? null);
fwrite(STDOUT, "Kaizen password recovery mail delivered: $count\n");
