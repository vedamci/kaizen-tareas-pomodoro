<?php
declare(strict_types=1);
$root=$argv[1]??'';
if (!is_dir($root.'/api') || !str_contains((string)(getenv('KAIZEN_TEST_DSN')?:''),'dbname=kaizen_test_mcp')) throw new RuntimeException('Disposable MCP fixture only.');
require __DIR__.'/http-fixture.php';
$config=require $root.'/api/config.php';
$config+=['mcp_enabled'=>true,'mcp_resource'=>'http://127.0.0.1:4181/api/mcp.php','mcp_auth_file'=>$root.'/mcp-verifier.php','mcp_allow_insecure_test'=>true,'mcp_allowed_origins'=>[]];
file_put_contents($root.'/api/config.php','<?php return '.var_export($config,true).';');
file_put_contents($root.'/mcp-verifier.php', <<<'PHP'
<?php
return static function (string $token): ?array {
    $map=[
        'fixture-owner-read-00000001'=>[1,['kaizen:read']],
        'fixture-owner-write-0000001'=>[1,['kaizen:read','kaizen:write']],
        'fixture-member-read-0000001'=>[2,['kaizen:read']],
        'fixture-member-write-000001'=>[2,['kaizen:read','kaizen:write']],
        'fixture-admin-read-00000001'=>[4,['kaizen:read']],
        'fixture-outsider-read-00001'=>[5,['kaizen:read']],
    ];
    if (!isset($map[$token])) return null;
    return ['user_id'=>$map[$token][0],'scopes'=>$map[$token][1],
        'audience'=>'http://127.0.0.1:4181/api/mcp.php','expires_at'=>time()+120];
};
PHP);
