<?php
declare(strict_types=1);
$root=$argv[1]??'';
if (!is_dir($root.'/api') || !str_contains((string)(getenv('KAIZEN_TEST_DSN')?:''),'dbname=kaizen_test_mcp')) throw new RuntimeException('Disposable MCP fixture only.');
require __DIR__.'/http-fixture.php';
$config=require $root.'/api/config.php';
$config+=['mcp_enabled'=>true,'mcp_oauth_enabled'=>true,
    'mcp_resource'=>'http://127.0.0.1:4181/api/mcp.php',
    'mcp_issuer'=>'http://127.0.0.1:4181',
    'mcp_oauth_client_id'=>'https://chatgpt.com/oauth/client.json',
    'mcp_oauth_redirect_uri'=>'https://chatgpt.com/connector_platform_oauth_redirect',
    'mcp_oauth_authorization_url'=>'http://127.0.0.1:4181/api/oauth.php?action=authorize',
    'mcp_oauth_token_url'=>'http://127.0.0.1:4181/api/oauth.php?action=token',
    'mcp_resource_metadata_url'=>'http://127.0.0.1:4181/api/oauth.php?action=resource-metadata',
    'mcp_oauth_client_metadata_test_file'=>$root.'/client-metadata.json',
    'mcp_oauth_codex_client_metadata_test_file'=>$root.'/codex-client-metadata.json',
    'mcp_allowed_user_ids'=>[1,2,4,5], 'mcp_max_scope'=>'kaizen:write',
    'mcp_assistant_runs_write_enabled'=>true,
    'mcp_allow_insecure_test'=>true,'mcp_allowed_origins'=>[]];
file_put_contents($root.'/test-policy.json','{}');
$mcpConfig=array_filter($config,static fn($key)=>str_starts_with($key,'mcp_'),ARRAY_FILTER_USE_KEY);
file_put_contents($root.'/api/mcp-local.php','<?php return array_replace('.var_export($mcpConfig,true).',json_decode(file_get_contents(__DIR__."/../test-policy.json"),true)?:[]);');
file_put_contents($root.'/client-metadata.json',json_encode([
    'client_id'=>$config['mcp_oauth_client_id'],'redirect_uris'=>[$config['mcp_oauth_redirect_uri']],
    'grant_types'=>['authorization_code','refresh_token'],'response_types'=>['code'],
    'token_endpoint_auth_methods_supported'=>['none','private_key_jwt']]));
file_put_contents($root.'/codex-client-metadata.json',json_encode([
    'client_id'=>'https://chatgpt.com/oauth/codex/client.json',
    'redirect_uris'=>['http://127.0.0.1/callback','http://localhost/callback'],
    'grant_types'=>['authorization_code','refresh_token'],'response_types'=>['code'],
    'token_endpoint_auth_methods_supported'=>['none']]));
$pdo=new PDO(getenv('KAIZEN_TEST_DSN'),getenv('KAIZEN_TEST_DB_USER')?:'root',getenv('KAIZEN_TEST_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec(file_get_contents(__DIR__.'/../api/mcp-schema.sql'));
