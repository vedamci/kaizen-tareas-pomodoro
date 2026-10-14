<?php
declare(strict_types=1);

// This endpoint is inert until an approved OAuth rollout. Existing browser and
// device tokens are never accepted as MCP credentials.
$config = require __DIR__ . '/mcp-config.php';
if (empty($config['mcp_enabled']) || empty($config['mcp_oauth_enabled'])) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/mcp-core.php';
require_once __DIR__ . '/mcp-oauth.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function mcpHttpError(int $status): never { http_response_code($status); exit; }
function mcpResponse($id, ?array $result=null, ?array $error=null, int $status=200): never {
    http_response_code($status);header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['jsonrpc'=>'2.0','id'=>$id]+($error===null?['result'=>$result]:['error'=>$error]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function mcpError($id, int $code, string $message, int $status=200): never {mcpResponse($id,null,['code'=>$code,'message'=>$message],$status);}
function mcpChallenge(array $config,string $error=''): never {
    $parts=['Bearer resource_metadata="'.$config['mcp_resource_metadata_url'].'"'];
    if ($error!=='') $parts[]='error="'.$error.'"';
    header('WWW-Authenticate: '.implode(', ',$parts));mcpHttpError(401);
}

try {
    mcpOAuthConfig($config);
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {header('Allow: POST');mcpHttpError(405);}
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off') {
        if (empty($config['mcp_allow_insecure_test']) || !in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)) mcpHttpError(403);
    }
    $origin=$_SERVER['HTTP_ORIGIN']??null;
    if ($origin!==null && !in_array($origin,$config['mcp_allowed_origins']??[],true)) mcpHttpError(403);
    $contentType=strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]));
    if ($contentType!=='application/json') mcpHttpError(415);
    $accept=$_SERVER['HTTP_ACCEPT']??'';
    if (!str_contains($accept,'application/json') || !str_contains($accept,'text/event-stream')) mcpHttpError(406);
    $raw=file_get_contents('php://input');
    if ($raw===false || strlen($raw)>65536) mcpHttpError(413);
    $request=json_decode($raw,true);
    if (!is_array($request) || array_is_list($request) || ($request['jsonrpc']??null)!=='2.0' || !is_string($request['method']??null)) mcpError(null,-32600,'Invalid Request',400);
    $id=$request['id']??null;
    if (array_key_exists('id',$request) && !is_int($id) && !is_string($id)) mcpError(null,-32600,'Invalid Request',400);
    $version=$_SERVER['HTTP_MCP_PROTOCOL_VERSION']??null;
    if ($version!==null && $version!==KAIZEN_MCP_VERSION) mcpError($id,-32600,'Unsupported protocol version',400);
    if ($version===null && $request['method']!=='initialize') mcpError($id,-32600,'Missing protocol version',400);

    $header=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
    if (!preg_match('/^Bearer ([A-Za-z0-9._~+\/-]{20,4096})$/D',$header,$match)) {
        mcpChallenge($config);
    }
    $pdo=mcpOAuthDb($config);
    $claims=mcpOAuthVerifyAccess($pdo,$config,$match[1]);
    if (!$claims) mcpChallenge($config,'invalid_token');
    $q=$pdo->prepare('SELECT id,name,email,role FROM users WHERE id=?');$q->execute([$claims['user_id']]);$u=$q->fetch();
    if (!$u) mcpChallenge($config,'invalid_token');
    $scopes=$claims['scopes'];
    if (!in_array('kaizen:read',$scopes,true) && !in_array('kaizen:write',$scopes,true)) mcpHttpError(403);

    if (!array_key_exists('id',$request)) {http_response_code(202);exit;}
    $method=$request['method'];$params=$request['params']??[];
    if (!is_array($params) || array_is_list($params) && $params!==[]) mcpError($id,-32602,'Invalid params');
    if ($method==='initialize') mcpResponse($id,['protocolVersion'=>KAIZEN_MCP_VERSION,'capabilities'=>['tools'=>(object)[]],'serverInfo'=>['name'=>'kaizen','version'=>'0.1.0']]);
    if ($method==='ping') mcpResponse($id,[]);
    $writeEnabled=$config['mcp_max_scope']==='kaizen:write';
    if ($method==='tools/list') mcpResponse($id,['tools'=>mcpTools($writeEnabled)]);
    if ($method==='tools/call') {
        $name=$params['name']??null;$arguments=$params['arguments']??[];
        if (!is_string($name) || !is_array($arguments) || (array_is_list($arguments)&&$arguments!==[])) mcpError($id,-32602,'Invalid params');
        if (!in_array($name,array_column(mcpTools($writeEnabled),'name'),true)) mcpError($id,-32601,'Method not found');
        $result=mcpExecute($pdo,$u,$scopes,$name,$arguments);
        mcpResponse($id,['content'=>[['type'=>'text','text'=>json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]],'structuredContent'=>$result]);
    }
    mcpError($id,-32601,'Method not found');
} catch (DomainException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($e->getMessage()==='Permiso MCP insuficiente.') {
        $challenge='Bearer resource_metadata="'.$config['mcp_resource_metadata_url'].'", error="insufficient_scope", error_description="This tool requires kaizen:write"';
        mcpResponse($id??null,['content'=>[['type'=>'text','text'=>'Se requiere permiso de escritura.']],
            '_meta'=>['mcp/www_authenticate'=>[$challenge]],'isError'=>true]);
    }
    $status=in_array($e->getCode(),[403,404,409,422],true)?$e->getCode():422;
    mcpResponse($id??null,['content'=>[['type'=>'text','text'=>$e->getMessage()]],'isError'=>true],null,$status===422?200:$status);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Kaizen MCP: '.get_class($e));
    mcpError($id??null,-32603,'Internal error',500);
}
