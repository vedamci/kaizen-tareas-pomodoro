<?php
declare(strict_types=1);
require_once __DIR__ . '/project-policy.php';

function workspaceProjectAdmin(int $wid, array $u, ?array $access): bool {
    return ($u['role'] ?? '') === 'super_admin' || ($access['member_role'] ?? '') === 'admin' || (int)($access['created_by'] ?? 0) === (int)$u['id'];
}
function workspaceProjects(PDO $pdo, int $wid, bool $lock = false): array {
    if ($lock) $pdo->prepare('INSERT IGNORE INTO workspace_data(workspace_id,projects_json) VALUES(?,?)')->execute([$wid, '[]']);
    $q = $pdo->prepare('SELECT projects_json FROM workspace_data WHERE workspace_id=?' . ($lock ? ' FOR UPDATE' : ''));
    $q->execute([$wid]);
    $raw = $q->fetchColumn();
    if ($raw === false || $raw === '') return [];
    $projects = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($projects)) throw new RuntimeException('Invalid stored projects');
    return $projects;
}
function workspaceShares(PDO $pdo, int $wid): array {
    $q = $pdo->prepare('SELECT task_id,user_id,shared_at FROM task_shares WHERE workspace_id=?');
    $q->execute([$wid]); $map = [];
    foreach ($q as $row) $map[$row['task_id']][] = $row;
    return $map;
}
function sharedIds(array $shares, string $taskId): array { return array_map(fn($row) => (int)$row['user_id'], $shares[$taskId] ?? []); }
function decorateProject(array $p, array $u, bool $admin): array {
    $p['_legacy'] = !projectIsShared($p);
    $p['_can_manage'] = projectManage($p, $u, $admin);
    $p['_can_assign'] = !projectIsShared($p) || projectMember($p, (int)$u['id']);
    return $p;
}
function saveWorkspaceProject(PDO $pdo, int $wid, array $u, bool $admin, array $data): array {
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 120) throw new DomainException('Escribe un nombre de proyecto de hasta 120 caracteres.', 422);
    $id = (string)($data['project_id'] ?? '');
    if ($id !== '' && !preg_match('/^[a-zA-Z0-9_-]{1,32}$/D', $id)) throw new DomainException('Proyecto no válido.', 422);
    $requested = $data['member_ids'] ?? [];
    if (!is_array($requested) || count($requested) > 500) throw new DomainException('Selecciona miembros válidos del equipo.', 422);
    $pdo->beginTransaction();
    try {
        $projects = workspaceProjects($pdo, $wid, true);
        $existing = $id !== '' ? projectFind($projects, $id) : null;
        if ($id !== '' && !$existing) throw new DomainException('Proyecto no encontrado.', 404);
        if ($existing && !projectManage($existing, $u, $admin)) throw new DomainException('Solo el creador del proyecto o un administrador puede cambiar sus miembros.', 403);
        $owner = (int)($existing['owner_id'] ?? $u['id']);
        $ids = array_values(array_unique(array_merge([$owner], array_map('intval', $requested))));
        $q = $pdo->prepare('SELECT user_id FROM workspace_members WHERE workspace_id=?'); $q->execute([$wid]);
        $team = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        foreach ($ids as $member) if (!in_array($member, $team, true)) throw new DomainException('Todos los miembros deben pertenecer al equipo de este espacio.', 422);
        $project = array_merge($existing ?? [], ['id' => $id ?: bin2hex(random_bytes(12)), 'name' => $name, 'owner_id' => $owner, 'member_ids' => $ids]);
        foreach (array_keys($project) as $key) if (str_starts_with((string)$key, '_')) unset($project[$key]);
        if ($existing) { foreach ($projects as &$p) if (($p['id'] ?? '') === $id) $p = $project; unset($p); }
        else $projects[] = $project;
        $pdo->prepare('UPDATE workspace_data SET projects_json=? WHERE workspace_id=?')->execute([json_encode($projects, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $wid]);
        $pdo->commit();
        return decorateProject($project, $u, $admin);
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function decorateTaskRead(array $row, array $projects, array $shares, array $u, bool $admin, string $scope): array {
    $item=json_decode($row['payload_json'],true)?:[]; $item['id']=$row['id']; $item['_owner_id']=(int)$row['owner_id']; $item['_revision']=hash('sha256',$row['payload_json']);
    $ids=sharedIds($shares,$row['id']);
    $item['_shared_user_ids']=$ids;
    $item['_delegated_at']=!empty($shares[$row['id']])?min(array_column($shares[$row['id']],'shared_at')):null;
    $project=projectFind($projects,(string)($item['project']??''));
    $item['_project_member']=$project && projectMember($project,(int)$u['id']);
    $item['_dashboard_visible']=taskPersonalAccess($item,(int)$u['id'],$ids) && projectTaskAccess($item,$projects,(int)$u['id'],$ids);
    $item['_can_move']=$scope!=='team' && ((int)$row['owner_id']===(int)$u['id']||$admin);
    $item['_team_read_only']=$scope==='team';
    return $item;
}
function loadWorkspaceTasks(PDO $pdo, int $wid, array $u, bool $admin, string $scope = 'projects'): array {
        validateTaskReadScope($scope,$admin);
        $projects=workspaceProjects($pdo,$wid); $shares=workspaceShares($pdo,$wid); 
        $q=$pdo->prepare('SELECT id,owner_id,payload_json FROM tasks WHERE workspace_id=? ORDER BY updated_at DESC'); $q->execute([$wid]); $tasks=[];
        foreach($q as $row){
            $item=json_decode($row['payload_json'],true)?:[]; $item['id']=$row['id']; $item['_owner_id']=(int)$row['owner_id']; $item['_revision']=hash('sha256',$row['payload_json']);
            $ids=sharedIds($shares,$row['id']);
            if(!taskReadAccess($item,$projects,(int)$u['id'],$ids,$scope,$admin))continue;
            $tasks[]=decorateTaskRead($row,$projects,$shares,$u,$admin,$scope);
        }
        $visible=[]; foreach($projects as $project) if($scope==='team'||!projectIsShared($project)||projectMember($project,(int)$u['id'])||projectManage($project,$u,$admin)) $visible[]=decorateProject($project,$u,$admin);
    return ['tasks'=>$tasks,'projects'=>$visible];
}
function loadWorkspaceTaskById(PDO $pdo, int $wid, string $id, array $u, bool $admin, string $scope = 'personal'): array {
    validateTaskReadScope($scope,$admin);
    $q=$pdo->prepare('SELECT id,owner_id,payload_json FROM tasks WHERE workspace_id=? AND id=?');$q->execute([$wid,$id]);$row=$q->fetch();
    if(!$row)throw new DomainException('Tarea no encontrada.',404);
    $projects=workspaceProjects($pdo,$wid);$shares=workspaceShares($pdo,$wid);
    $task=json_decode($row['payload_json'],true)?:[];$task['_owner_id']=(int)$row['owner_id'];
    if(!taskReadAccess($task,$projects,(int)$u['id'],sharedIds($shares,$id),$scope,$admin))throw new DomainException('No tienes acceso a esta tarea.',403);
    return decorateTaskRead($row,$projects,$shares,$u,$admin,$scope);
}
function syncWorkspaceTasks(PDO $pdo, int $wid, array $u, bool $admin, array $tasks): array {
    try {
        $pdo->beginTransaction();
        // Every write and membership change locks the same workspace row. A
        // stale browser cannot overwrite project membership via bulk sync.
        $projects=workspaceProjects($pdo,$wid,true); $shares=workspaceShares($pdo,$wid); 
        $stmt=$pdo->prepare('INSERT INTO tasks(id,workspace_id,owner_id,title,done,payload_json) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),done=VALUES(done),payload_json=VALUES(payload_json)');
        $existingQ=$pdo->prepare('SELECT workspace_id,owner_id,payload_json FROM tasks WHERE id=? FOR UPDATE'); $revisions=[];
        foreach($tasks as $t){
            if(!is_array($t)||!preg_match('/^[a-zA-Z0-9_-]{1,32}$/D',(string)($t['id']??'')))throw new DomainException('Identificador de tarea no válido.',422);
            $id=(string)$t['id']; $existingQ->execute([$id]); $existing=$existingQ->fetch(); $previous=null;
            if($existing){
                if((int)$existing['workspace_id']!==$wid)throw new DomainException('La tarea pertenece a otro espacio.',403);
                $previous=json_decode($existing['payload_json'],true)?:[]; $previous['_owner_id']=(int)$existing['owner_id'];
            }
            projectTaskWrite($t,$previous,$projects,$u,$admin,sharedIds($shares,$id));
            if($existing)validateTaskRevision($existing['payload_json'],$t);
            $ownerId=$existing?(int)$existing['owner_id']:(int)$u['id'];
            foreach(array_keys($t) as $key)if(str_starts_with((string)$key,'_'))unset($t[$key]);
            $json=json_encode($t,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $stmt->execute([$id,$wid,$ownerId,substr((string)($t['title']??''),0,255),!empty($t['done'])?1:0,$json]); $revisions[$id]=hash('sha256',$json);
        }
        // Project definitions are saved only through project_save. This also
        // preserves legacy definitions and projects hidden from this member.
        $pdo->commit(); return $revisions;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
