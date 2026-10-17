<?php
declare(strict_types=1);

// OAuth storage and policy. Tables are installed only during approved rollout.
function mcpOAuthDb(array $config): PDO {
    return new PDO('mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4',
        $config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}
function mcpOAuthConfig(array $config): void {
    foreach (['mcp_resource','mcp_issuer','mcp_oauth_client_id','mcp_oauth_redirect_uri','mcp_oauth_authorization_url','mcp_oauth_token_url','mcp_resource_metadata_url'] as $key)
        if (!is_string($config[$key]??null) || $config[$key]==='') throw new RuntimeException('MCP OAuth configuration incomplete');
    if (empty($config['mcp_allow_insecure_test'])) foreach (['mcp_resource','mcp_issuer','mcp_oauth_client_id','mcp_oauth_redirect_uri','mcp_oauth_authorization_url','mcp_oauth_token_url','mcp_resource_metadata_url'] as $key)
        if (!str_starts_with($config[$key],'https://')) throw new RuntimeException('MCP OAuth requires HTTPS');
    if (strlen($config['mcp_resource'])>255 || strlen($config['mcp_oauth_client_id'])>255 ||
        strlen($config['mcp_oauth_redirect_uri'])>512) throw new RuntimeException('MCP OAuth URL too long');
    if (!in_array($config['mcp_max_scope']??null,['kaizen:read','kaizen:write'],true) ||
        !is_array($config['mcp_allowed_user_ids']??null) || !$config['mcp_allowed_user_ids'])
        throw new RuntimeException('MCP OAuth access policy incomplete');
    foreach ($config['mcp_allowed_user_ids'] as $id)
        if (!is_int($id) || $id<1) throw new RuntimeException('MCP OAuth user policy invalid');
}
const KAIZEN_CODEX_CLIENT_ID = 'https://chatgpt.com/oauth/codex/client.json';
function mcpOAuthAllowedClients(array $config): array {
    return array_values(array_unique([$config['mcp_oauth_client_id'],KAIZEN_CODEX_CLIENT_ID]));
}
function mcpOAuthClientAllowed(array $config, $clientId, $redirectUri): bool {
    if (!is_string($clientId) || !is_string($redirectUri)) return false;
    if ($clientId === $config['mcp_oauth_client_id']) return $redirectUri === $config['mcp_oauth_redirect_uri'];
    if ($clientId !== KAIZEN_CODEX_CLIENT_ID) return false;
    $parts=parse_url($redirectUri);
    if (!is_array($parts) || ($parts['scheme']??null)!=='http' ||
        !in_array($parts['host']??null,['127.0.0.1','localhost'],true) ||
        ($parts['path']??null)!=='/callback' ||
        isset($parts['user']) || isset($parts['pass']) ||
        isset($parts['query']) || isset($parts['fragment'])) return false;
    return !isset($parts['port']) || ($parts['port']>=1 && $parts['port']<=65535);
}
function mcpOAuthSupportedScopes(array $config): array {
    $scopes=['kaizen:read'];
    if (!empty($config['mcp_assistant_runs_write_enabled'])) $scopes[]='kaizen:assistant_runs:write';
    if ($config['mcp_max_scope']==='kaizen:write') $scopes[]='kaizen:write';
    return $scopes;
}
function mcpOAuthAllowedUser(array $config,int $userId): bool {
    return in_array($userId,$config['mcp_allowed_user_ids'],true);
}
function mcpOAuthScopeAllowed(array $config,string $scope): bool {
    try {$requested=mcpOAuthScopes($scope);} catch (DomainException $e) {return false;}
    return !array_diff($requested,mcpOAuthSupportedScopes($config));
}
function mcpOAuthToken(): string {return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');}
function mcpOAuthHash(string $value): string {return hash('sha256',$value);}
function mcpOAuthScopes($value): array {
    if (!is_string($value) || strlen($value)>80) throw new DomainException('invalid_scope',400);
    $scopes=array_values(array_unique(array_filter(explode(' ',trim($value)),'strlen')));
    if (!$scopes || array_diff($scopes,['kaizen:read','kaizen:write','kaizen:assistant_runs:write']) ||
        ((in_array('kaizen:write',$scopes,true) || in_array('kaizen:assistant_runs:write',$scopes,true)) && !in_array('kaizen:read',$scopes,true))) throw new DomainException('invalid_scope',400);
    sort($scopes);return $scopes;
}
function mcpOAuthValidateClientMetadata(array $config,string $clientId,string $redirectUri): void {
    $testFile=$clientId===KAIZEN_CODEX_CLIENT_ID
        ? ($config['mcp_oauth_codex_client_metadata_test_file']??null)
        : ($config['mcp_oauth_client_metadata_test_file']??null);
    if (!empty($config['mcp_allow_insecure_test']) && is_string($testFile)) {
        $raw=file_get_contents($testFile);
    } else {
        $ch=curl_init($clientId);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_TIMEOUT=>5,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['Accept: application/json']]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if ($status!==200) $raw=false;
    }
    if (!is_string($raw) || strlen($raw)>16384) throw new RuntimeException('OAuth client metadata unavailable');
    $doc=json_decode($raw,true);
    $registeredRedirect=$clientId===KAIZEN_CODEX_CLIENT_ID
        ? preg_replace('/:[0-9]+(?=\/callback$)/','',$redirectUri) : $redirectUri;
    if (!is_array($doc) || ($doc['client_id']??null)!==$clientId ||
        !in_array($registeredRedirect,$doc['redirect_uris']??[],true) ||
        !in_array('authorization_code',$doc['grant_types']??[],true) ||
        !in_array('code',$doc['response_types']??[],true) ||
        !in_array('none',$doc['token_endpoint_auth_methods_supported']??[$doc['token_endpoint_auth_method']??''],true))
        throw new RuntimeException('OAuth client metadata mismatch');
}
function mcpOAuthValidAuthorization(array $a, array $config): array {
    if (($a['response_type']??null)!=='code' ||
        !mcpOAuthClientAllowed($config,$a['client_id']??null,$a['redirect_uri']??null) ||
        ($a['resource']??null)!==$config['mcp_resource']) throw new DomainException('invalid_request',400);
    $challenge=$a['code_challenge']??null;
    if (($a['code_challenge_method']??null)!=='S256' || !is_string($challenge) || !preg_match('/^[A-Za-z0-9_-]{43}$/D',$challenge)) throw new DomainException('invalid_request',400);
    $state=$a['state']??'';
    if (!is_string($state) || strlen($state)>512 || preg_match('/[\x00-\x1F\x7F]/',$state)) throw new DomainException('invalid_request',400);
    mcpOAuthValidateClientMetadata($config,$a['client_id'],$a['redirect_uri']);
    $scope=implode(' ',mcpOAuthScopes($a['scope']??null));
    if (!mcpOAuthScopeAllowed($config,$scope)) throw new DomainException('invalid_scope',400);
    return ['client_id'=>$a['client_id'],'redirect_uri'=>$a['redirect_uri'],'resource'=>$a['resource'],
        'scope'=>$scope,'code_challenge'=>$challenge,'state'=>$state];
}
function mcpOAuthGrantAndCode(PDO $pdo, int $userId, array $request): string {
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT scopes,generation,revoked_at FROM mcp_oauth_grants WHERE user_id=? AND client_id=? AND resource=? FOR UPDATE');
        $q->execute([$userId,$request['client_id'],$request['resource']]);$prior=$q->fetch();
        if ($prior && $prior['revoked_at']===null) {
            $generation=$prior['generation'];
        } else $generation=bin2hex(random_bytes(16));
        // A new consent replaces earlier permissions; it never silently keeps
        // a prior write grant when the person authorizes read-only access.
        $scope=$request['scope'];
        $pdo->prepare('INSERT INTO mcp_oauth_grants(user_id,client_id,resource,scopes,generation,revoked_at) VALUES(?,?,?,?,?,NULL) ON DUPLICATE KEY UPDATE scopes=VALUES(scopes),generation=VALUES(generation),revoked_at=NULL')
            ->execute([$userId,$request['client_id'],$request['resource'],$scope,$generation]);
        $code=mcpOAuthToken();
        $pdo->prepare('INSERT INTO mcp_oauth_codes(code_hash,user_id,client_id,redirect_uri,resource,scopes,code_challenge,generation,expires_at) VALUES(?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 5 MINUTE))')
            ->execute([mcpOAuthHash($code),$userId,$request['client_id'],$request['redirect_uri'],$request['resource'],$request['scope'],$request['code_challenge'],$generation]);
        $pdo->commit();return $code;
    } catch (Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function mcpOAuthVerifyAccess(PDO $pdo, array $config, string $token): ?array {
    if (strlen($token)<32 || strlen($token)>200) return null;
    $q=$pdo->prepare('SELECT t.user_id,t.scopes,t.resource,t.issuer,t.expires_at,g.scopes AS grant_scopes FROM mcp_oauth_access_tokens t JOIN mcp_oauth_grants g ON g.user_id=t.user_id AND g.client_id=t.client_id AND g.resource=t.resource AND g.generation=t.generation WHERE t.token_hash=? AND t.revoked_at IS NULL AND t.expires_at>NOW() AND g.revoked_at IS NULL LIMIT 1');
    $q->execute([mcpOAuthHash($token)]);$row=$q->fetch();
    if (!$row || $row['issuer']!==$config['mcp_issuer'] || $row['resource']!==$config['mcp_resource'] ||
        !mcpOAuthAllowedUser($config,(int)$row['user_id']) || !mcpOAuthScopeAllowed($config,$row['scopes'])) return null;
    $scopes=explode(' ',$row['scopes']);
    if (array_diff($scopes,explode(' ',$row['grant_scopes']))) return null;
    return ['user_id'=>(int)$row['user_id'],'scopes'=>$scopes,'audience'=>$row['resource']];
}
function mcpOAuthIssueTokens(PDO $pdo, array $config, array $record, ?string $family=null): array {
    $access=mcpOAuthToken();$refresh=mcpOAuthToken();$family??=bin2hex(random_bytes(16));
    $common=[$record['user_id'],$record['client_id'],$record['resource'],$config['mcp_issuer'],$record['scopes'],$record['generation'],$family];
    $pdo->prepare('INSERT INTO mcp_oauth_access_tokens(token_hash,user_id,client_id,resource,issuer,scopes,generation,family_id,expires_at) VALUES(?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 1 HOUR))')
        ->execute(array_merge([mcpOAuthHash($access)],$common));
    $pdo->prepare('INSERT INTO mcp_oauth_refresh_tokens(token_hash,user_id,client_id,resource,issuer,scopes,generation,family_id,expires_at) VALUES(?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 30 DAY))')
        ->execute(array_merge([mcpOAuthHash($refresh)],$common));
    return ['access_token'=>$access,'token_type'=>'Bearer','expires_in'=>3600,'refresh_token'=>$refresh,'scope'=>$record['scopes']];
}
function mcpOAuthExchangeCode(PDO $pdo, array $config, array $body): array {
    $code=$body['code']??null;$verifier=$body['code_verifier']??null;
    if (!is_string($code)||!is_string($verifier)||!preg_match('/^[A-Za-z0-9._~-]{43,128}$/D',$verifier)) throw new DomainException('invalid_grant',400);
    if (!mcpOAuthClientAllowed($config,$body['client_id']??null,$body['redirect_uri']??null) || ($body['resource']??null)!==$config['mcp_resource']) throw new DomainException('invalid_grant',400);
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT c.*,g.scopes AS grant_scopes FROM mcp_oauth_codes c JOIN mcp_oauth_grants g ON g.user_id=c.user_id AND g.client_id=c.client_id AND g.resource=c.resource AND g.generation=c.generation AND g.revoked_at IS NULL WHERE c.code_hash=? AND c.expires_at>NOW() FOR UPDATE');
        $q->execute([mcpOAuthHash($code)]);$record=$q->fetch();
        $expected=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
        if (!$record || $record['client_id']!==$body['client_id'] || $record['redirect_uri']!==$body['redirect_uri'] ||
            !mcpOAuthAllowedUser($config,(int)$record['user_id']) || !mcpOAuthScopeAllowed($config,$record['scopes']) ||
            !hash_equals($record['code_challenge'],$expected) || array_diff(explode(' ',$record['scopes']),explode(' ',$record['grant_scopes']))) throw new DomainException('invalid_grant',400);
        $pdo->prepare('DELETE FROM mcp_oauth_codes WHERE code_hash=?')->execute([mcpOAuthHash($code)]);
        $tokens=mcpOAuthIssueTokens($pdo,$config,$record);
        $pdo->commit();return $tokens;
    } catch (Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function mcpOAuthRefresh(PDO $pdo, array $config, array $body): array {
    $token=$body['refresh_token']??null;
    if (!is_string($token)||strlen($token)<32||strlen($token)>200 ||
        !in_array($body['client_id']??null,mcpOAuthAllowedClients($config),true) ||
        ($body['resource']??null)!==$config['mcp_resource']) throw new DomainException('invalid_grant',400);
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT r.*,g.scopes AS grant_scopes,g.revoked_at AS grant_revoked,NOW() AS db_now FROM mcp_oauth_refresh_tokens r LEFT JOIN mcp_oauth_grants g ON g.user_id=r.user_id AND g.client_id=r.client_id AND g.resource=r.resource AND g.generation=r.generation WHERE r.token_hash=? FOR UPDATE');
        $q->execute([mcpOAuthHash($token)]);$record=$q->fetch();
        if (!$record || $record['client_id']!==$body['client_id'] ||
            $record['issuer']!==$config['mcp_issuer'] || $record['resource']!==$config['mcp_resource']) throw new DomainException('invalid_grant',400);
        if ($record['used_at']!==null) {
            $pdo->prepare('UPDATE mcp_oauth_refresh_tokens SET revoked_at=NOW() WHERE family_id=?')->execute([$record['family_id']]);
            $pdo->prepare('UPDATE mcp_oauth_access_tokens SET revoked_at=NOW() WHERE family_id=?')->execute([$record['family_id']]);
            $pdo->commit();throw new DomainException('invalid_grant',400);
        }
        if ($record['revoked_at']!==null || $record['grant_scopes']===null || $record['grant_revoked']!==null ||
            !mcpOAuthAllowedUser($config,(int)$record['user_id']) || !mcpOAuthScopeAllowed($config,$record['scopes']) ||
            $record['expires_at']<=$record['db_now'] || array_diff(explode(' ',$record['scopes']),explode(' ',$record['grant_scopes']))) throw new DomainException('invalid_grant',400);
        $pdo->prepare('UPDATE mcp_oauth_refresh_tokens SET used_at=NOW() WHERE token_hash=?')->execute([mcpOAuthHash($token)]);
        $tokens=mcpOAuthIssueTokens($pdo,$config,$record,$record['family_id']);
        $pdo->commit();return $tokens;
    } catch (Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function mcpOAuthRevokeGrant(PDO $pdo, int $userId, string $clientId, string $resource): void {
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE mcp_oauth_grants SET revoked_at=NOW() WHERE user_id=? AND client_id=? AND resource=?')->execute([$userId,$clientId,$resource]);
        $pdo->prepare('UPDATE mcp_oauth_access_tokens SET revoked_at=NOW() WHERE user_id=? AND client_id=? AND resource=?')->execute([$userId,$clientId,$resource]);
        $pdo->prepare('UPDATE mcp_oauth_refresh_tokens SET revoked_at=NOW() WHERE user_id=? AND client_id=? AND resource=?')->execute([$userId,$clientId,$resource]);
        $pdo->commit();
    } catch (Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
