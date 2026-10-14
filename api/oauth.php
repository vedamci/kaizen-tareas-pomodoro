<?php
declare(strict_types=1);

// Disabled unless a separately approved deployment installs the OAuth tables,
// discovery route and private configuration. No browser or device token is an
// OAuth bearer token.
$config=require __DIR__.'/config.php';
if (empty($config['mcp_enabled']) || empty($config['mcp_oauth_enabled'])) {http_response_code(404);exit;}
require_once __DIR__.'/mcp-oauth.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {mcpOAuthConfig($config);} catch (Throwable $e) {error_log('Kaizen OAuth configuration unavailable');http_response_code(503);exit;}
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off') {
    if (empty($config['mcp_allow_insecure_test']) || !in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)) {http_response_code(403);exit;}
}

function oauthJson(array $data,int $status=200): never {
    http_response_code($status);header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}
function oauthHtml(string $body,int $status=200): never {
    http_response_code($status);header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; form-action 'self'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Conectar Kaizen</title><style>body{font:16px system-ui;max-width:38rem;margin:4rem auto;padding:0 1rem;line-height:1.5}button{padding:.7rem 1rem;margin:.5rem}li{margin:.5rem 0}</style><main>'.$body.'</main></html>';exit;
}
function oauthEscape(string $value): string {return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function oauthSession(array $config): void {
    session_name($config['session_name']??'tareas_session');
    $ttl=60*60*24*30;
    ini_set('session.gc_maxlifetime',(string)$ttl);
    session_set_cookie_params(['lifetime'=>$ttl,'path'=>'/','httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']),'samesite'=>'Lax']);
    session_start();
}
function oauthUser(PDO $pdo): ?array {
    $id=(int)($_SESSION['user']['id']??0);
    if ($id<1) return null;
    $q=$pdo->prepare('SELECT id,name,email FROM users WHERE id=?');$q->execute([$id]);
    return $q->fetch()?:null;
}
function oauthRedirect(array $request,array $config,?string $code,string $error=''): never {
    $parts=$code!==null?['code'=>$code]:['error'=>$error];
    $parts['state']=$request['state'];$parts['iss']=$config['mcp_issuer'];
    $uri=$request['redirect_uri'];$separator=str_contains($uri,'?')?'&':'?';
    header('Location: '.$uri.$separator.http_build_query($parts,'','&',PHP_QUERY_RFC3986),true,302);exit;
}
function oauthFormFields(array $request): string {
    $html='';foreach ($request as $key=>$value) $html.='<input type="hidden" name="'.oauthEscape($key).'" value="'.oauthEscape($value).'">';return $html;
}
try {
    $action=$_GET['action']??'';
    if ($action==='metadata' && ($_SERVER['REQUEST_METHOD']??'')==='GET') {
        // The discovery URL /.well-known/oauth-authorization-server must route here.
        oauthJson(['issuer'=>$config['mcp_issuer'],
            'authorization_endpoint'=>$config['mcp_oauth_authorization_url'],
            'token_endpoint'=>$config['mcp_oauth_token_url'],
            'response_types_supported'=>['code'],'grant_types_supported'=>['authorization_code','refresh_token'],
            'code_challenge_methods_supported'=>['S256'],
            'token_endpoint_auth_methods_supported'=>['none'],
            'scopes_supported'=>['kaizen:read','kaizen:write'],
            'client_id_metadata_document_supported'=>true]);
    }
    if ($action==='resource-metadata' && ($_SERVER['REQUEST_METHOD']??'')==='GET') {
        oauthJson(['resource'=>$config['mcp_resource'],
            'authorization_servers'=>[$config['mcp_issuer']],
            'scopes_supported'=>['kaizen:read','kaizen:write'],
            'bearer_methods_supported'=>['header']]);
    }
    if ($action==='token') {
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {header('Allow: POST');http_response_code(405);exit;}
        if (strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/x-www-form-urlencoded' || (int)($_SERVER['CONTENT_LENGTH']??0)>8192) oauthJson(['error'=>'invalid_request'],400);
        if (isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) oauthJson(['error'=>'invalid_client'],401);
        $pdo=mcpOAuthDb($config);
        $grant=$_POST['grant_type']??null;
        if ($grant==='authorization_code') $tokens=mcpOAuthExchangeCode($pdo,$config,$_POST);
        elseif ($grant==='refresh_token') $tokens=mcpOAuthRefresh($pdo,$config,$_POST);
        else oauthJson(['error'=>'unsupported_grant_type'],400);
        header('Pragma: no-cache');oauthJson($tokens);
    }
    if ($action==='authorize' || $action==='connections') {
        if (!in_array($_SERVER['REQUEST_METHOD']??'', ['GET','POST'],true)) {header('Allow: GET, POST');http_response_code(405);exit;}
        oauthSession($config);$pdo=mcpOAuthDb($config);$user=oauthUser($pdo);
        if (!$user) oauthHtml('<h1>Inicia sesión en Kaizen</h1><p>Abre <a href="/tareas-pomodoro/">Kaizen</a> en esta misma navegación, inicia sesión y vuelve a cargar esta página.</p>',401);
        if (empty($_SESSION['mcp_oauth_csrf'])) $_SESSION['mcp_oauth_csrf']=mcpOAuthToken();
        if ($action==='connections') {
            if (($_SERVER['REQUEST_METHOD']??'')==='POST') {
                if (!hash_equals($_SESSION['mcp_oauth_csrf'],(string)($_POST['csrf']??''))) oauthHtml('<h1>Solicitud inválida</h1>',403);
                mcpOAuthRevokeGrant($pdo,(int)$user['id'],$config['mcp_oauth_client_id'],$config['mcp_resource']);
                oauthHtml('<h1>Conexión revocada</h1><p>Los tokens de Kaizen MCP dejaron de ser válidos.</p>');
            }
            $q=$pdo->prepare('SELECT scopes FROM mcp_oauth_grants WHERE user_id=? AND client_id=? AND resource=? AND revoked_at IS NULL');
            $q->execute([(int)$user['id'],$config['mcp_oauth_client_id'],$config['mcp_resource']]);$scopes=$q->fetchColumn();
            $details=$scopes?'<p>Permisos activos: '.oauthEscape($scopes).'</p><form method="post"><input type="hidden" name="csrf" value="'.oauthEscape($_SESSION['mcp_oauth_csrf']).'"><button type="submit">Revocar conexión</button></form>':'<p>No hay una conexión activa.</p>';
            oauthHtml('<h1>Conexión MCP de Kaizen</h1>'.$details);
        }
        $request=mcpOAuthValidAuthorization(($_SERVER['REQUEST_METHOD']??'')==='POST'?$_POST:$_GET,$config);
        if (($_SERVER['REQUEST_METHOD']??'')==='POST') {
            if (!hash_equals($_SESSION['mcp_oauth_csrf'],(string)($_POST['csrf']??''))) oauthHtml('<h1>Solicitud inválida</h1>',403);
            if (($_POST['decision']??'')==='deny') oauthRedirect($request,$config,null,'access_denied');
            if (($_POST['decision']??'')!=='approve') oauthHtml('<h1>Solicitud inválida</h1>',400);
            $code=mcpOAuthGrantAndCode($pdo,(int)$user['id'],$request);
            oauthRedirect($request,$config,$code);
        }
        $items='<li>Leer tareas, proyectos y agenda de los espacios a los que tienes acceso.</li>';
        if (str_contains($request['scope'],'kaizen:write')) $items.='<li>Crear y modificar tareas, bloques de agenda y proyectos con tus permisos actuales.</li>';
        oauthHtml('<h1>Conectar Kaizen</h1><p>'.oauthEscape($user['name']).', ChatGPT solicita estos permisos para tu cuenta:</p><ul>'.$items.'</ul><p>Podrás revocarlos desde Kaizen. La conexión no amplía tus permisos de espacios.</p><form method="post">'.oauthFormFields($request).'<input type="hidden" name="csrf" value="'.oauthEscape($_SESSION['mcp_oauth_csrf']).'"><button name="decision" value="approve">Autorizar</button><button name="decision" value="deny">Cancelar</button></form>');
    }
    http_response_code(404);
} catch (DomainException $e) {
    $status=in_array($e->getCode(),[400,401,403],true)?$e->getCode():400;
    if (($_GET['action']??'')==='token') oauthJson(['error'=>$e->getMessage()],$status);
    oauthHtml('<h1>Solicitud OAuth inválida</h1>',$status);
} catch (Throwable $e) {
    error_log('Kaizen OAuth: '.get_class($e));
    if (($_GET['action']??'')==='token') oauthJson(['error'=>'server_error'],500);
    oauthHtml('<h1>No se pudo completar la conexión</h1>',500);
}
