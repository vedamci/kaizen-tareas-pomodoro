<?php
declare(strict_types=1);

require_once __DIR__ . '/projects.php';
require_once __DIR__ . '/assistant-runs.php';

const KAIZEN_MCP_VERSION = '2025-11-25';

function mcpTools(bool $write): array {
    $wid = ['type'=>'integer', 'minimum'=>1];
    $id = ['type'=>'string', 'pattern'=>'^[A-Za-z0-9_-]{1,32}$'];
    $date = ['type'=>'string', 'pattern'=>'^\\d{4}-\\d{2}-\\d{2}$'];
    $tools = [
        ['name'=>'get_profile','description'=>'Identificar la cuenta Kaizen autenticada.',
            'inputSchema'=>['type'=>'object','properties'=>new stdClass(),'additionalProperties'=>false],
            'outputSchema'=>['type'=>'object','properties'=>['id'=>['type'=>'string'],'name'=>['type'=>'string'],'email'=>['type'=>'string']],'required'=>['id'],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>true], '_meta'=>['openai/profile'=>true]],
        ['name'=>'list_workspaces', 'description'=>'Listar los espacios a los que pertenece la persona.', 'inputSchema'=>['type'=>'object','properties'=>new stdClass()]],
        ['name'=>'list_projects', 'description'=>'Listar los proyectos visibles de un espacio.', 'inputSchema'=>['type'=>'object','properties'=>['workspace_id'=>$wid],'required'=>['workspace_id']]],
        ['name'=>'list_tasks', 'description'=>'Listar tareas personales o de proyectos accesibles. La vista team requiere ser administrador.', 'inputSchema'=>['type'=>'object','properties'=>['workspace_id'=>$wid,'scope'=>['type'=>'string','enum'=>['personal','projects','team']]],'required'=>['workspace_id']]],
        ['name'=>'get_task', 'description'=>'Consultar una tarea accesible por ID y revisión.', 'inputSchema'=>['type'=>'object','properties'=>['workspace_id'=>$wid,'task_id'=>$id,'scope'=>['type'=>'string','enum'=>['personal','projects','team']]],'required'=>['workspace_id','task_id']]],
        ['name'=>'day_agenda', 'description'=>'Consultar bloques horarios de trabajo y tareas fechadas de un día, en UTC. El cliente puede convertirlos a su zona horaria.', 'inputSchema'=>['type'=>'object','properties'=>['workspace_id'=>$wid,'date'=>$date],'required'=>['workspace_id','date']]],
        ['name'=>'assistant_runs', 'description'=>'Consultar ejecuciones del asistente del espacio. Requiere ser administrador, creador o superadministrador; devuelve solo estados verificados, avance, bloqueos, próximos pasos, entregables y cronología, nunca notas de tareas de Kaizen.', 'inputSchema'=>['type'=>'object','properties'=>['workspace_id'=>$wid],'required'=>['workspace_id'],'additionalProperties'=>false], 'annotations'=>['readOnlyHint'=>true]],
    ];
    if ($write) array_push($tools,
        ['name'=>'create_task', 'description'=>'Crear una tarea. client_id estable permite reintentos sin duplicarla.', 'inputSchema'=>['type'=>'object','properties'=>['workspace_id'=>$wid,'client_id'=>$id,'title'=>['type'=>'string','minLength'=>1,'maxLength'=>255],'project_id'=>$id],'required'=>['workspace_id','client_id','title']], 'annotations'=>['readOnlyHint'=>false]],
        ['name'=>'update_task', 'description'=>'Editar título, notas, fecha límite o proyecto de una tarea existente. Requiere la revisión leída.', 'inputSchema'=>['type'=>'object','properties'=>['workspace_id'=>$wid,'task_id'=>$id,'revision'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],'changes'=>['type'=>'object','properties'=>['title'=>['type'=>'string'],'notes'=>['type'=>'string'],'due'=>['type'=>'string'],'project_id'=>['type'=>'string']],'minProperties'=>1]],'required'=>['workspace_id','task_id','revision','changes']], 'annotations'=>['readOnlyHint'=>false]],
        ['name'=>'set_task_done', 'description'=>'Marcar hecha o pendiente una tarea existente. Requiere la revisión leída; repetir el mismo estado es seguro.', 'inputSchema'=>['type'=>'object','properties'=>['workspace_id'=>$wid,'task_id'=>$id,'revision'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],'done'=>['type'=>'boolean']],'required'=>['workspace_id','task_id','revision','done']], 'annotations'=>['readOnlyHint'=>false,'idempotentHint'=>true]],
        ['name'=>'set_work_block', 'description'=>'Crear o actualizar un bloque horario de una tarea. Requiere revisión. Horas UTC ISO con milisegundos y zona IANA.', 'inputSchema'=>['type'=>'object','properties'=>['workspace_id'=>$wid,'task_id'=>$id,'revision'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],'block_id'=>$id,'start'=>['type'=>'string'],'end'=>['type'=>'string'],'time_zone'=>['type'=>'string']],'required'=>['workspace_id','task_id','revision','block_id','start','end','time_zone']], 'annotations'=>['readOnlyHint'=>false,'idempotentHint'=>true]],
        ['name'=>'remove_work_block', 'description'=>'Quitar un bloque horario sin alterar el cronograma ni el tiempo Pomodoro. Repetir la retirada es seguro.', 'inputSchema'=>['type'=>'object','properties'=>['workspace_id'=>$wid,'task_id'=>$id,'revision'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],'block_id'=>$id],'required'=>['workspace_id','task_id','revision','block_id']], 'annotations'=>['readOnlyHint'=>false,'idempotentHint'=>true]],
        ['name'=>'save_project', 'description'=>'Crear o actualizar un proyecto compartido. Para crear, client_id estable evita duplicados; para actualizar, project_id y member_ids explícitos.', 'inputSchema'=>['type'=>'object','properties'=>['workspace_id'=>$wid,'client_id'=>$id,'project_id'=>$id,'name'=>['type'=>'string','minLength'=>1,'maxLength'=>120],'member_ids'=>['type'=>'array','items'=>$wid,'maxItems'=>500]],'required'=>['workspace_id','name','member_ids']], 'annotations'=>['readOnlyHint'=>false]]
    );
    if ($write) $tools[] = ['name'=>'assistant_run_upsert',
        'description'=>'Registrar o actualizar una ejecución con evidencia verificada. Requiere kaizen:write y administración del espacio; clave de idempotencia estable para reintentos.',
        'inputSchema'=>['type'=>'object','properties'=>[
            'workspace_id'=>$wid,
            'source'=>['type'=>'string','pattern'=>'^[a-zA-Z][a-zA-Z0-9_.-]{0,39}$'],
            'external_id'=>['type'=>'string','pattern'=>'^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,119}$'],
            'idempotency_key'=>['type'=>'string','pattern'=>'^[a-zA-Z0-9][a-zA-Z0-9_.:-]{15,99}$'],
            'title'=>['type'=>'string','minLength'=>1,'maxLength'=>180],
            'status'=>['type'=>'string','enum'=>['queued','in_progress','blocked','completed','cancelled']],
            'progress'=>['type'=>'string','maxLength'=>1200],
            'blocker'=>['type'=>'string','maxLength'=>800],
            'next_step'=>['type'=>'string','maxLength'=>800],
            'deliverables'=>['type'=>'array','maxItems'=>12,'items'=>['type'=>'string','minLength'=>1,'maxLength'=>200]],
            'delegated_at'=>['type'=>'string','format'=>'date-time'],
            'completed_at'=>['type'=>'string','format'=>'date-time'],
            'closed_at'=>['type'=>'string','format'=>'date-time']
        ],'required'=>['workspace_id','source','external_id','idempotency_key','title','status'],'additionalProperties'=>false],
        'annotations'=>['readOnlyHint'=>false,'idempotentHint'=>true]];
    foreach ($tools as &$tool) {
        $scopes=($tool['annotations']['readOnlyHint']??true)===false
            ? ['kaizen:read','kaizen:write'] : ['kaizen:read'];
        $tool['securitySchemes']=[['type'=>'oauth2','scopes'=>$scopes]];
    }
    unset($tool);
    return $tools;
}

function mcpInt($value): int {
    if (!is_int($value) || $value < 1) throw new DomainException('Identificador inválido.', 422);
    return $value;
}
function mcpId($value): string {
    if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $value)) throw new DomainException('Identificador inválido.', 422);
    return $value;
}
function mcpText($value, int $max): string {
    if (!is_string($value) || trim($value)==='' || mb_strlen($value)>$max) throw new DomainException('Texto inválido.', 422);
    return trim($value);
}
function mcpAllowedWorkspace(PDO $pdo, int $wid, array $u): ?array {
    $q=$pdo->prepare('SELECT w.*,wm.role AS member_role FROM workspaces w LEFT JOIN workspace_members wm ON wm.workspace_id=w.id AND wm.user_id=? WHERE w.id=? AND (w.created_by=? OR wm.user_id=?)');
    $q->execute([(int)$u['id'],$wid,(int)$u['id'],(int)$u['id']]);
    $access=$q->fetch()?:null;
    if (!$access && ($u['role']??'')!=='super_admin') throw new DomainException('Sin acceso a este espacio.',403);
    return $access;
}
function mcpTaskForWrite(PDO $pdo, int $wid, string $id, array $u, bool $admin): array {
    $q=$pdo->prepare('SELECT id,workspace_id,owner_id,payload_json FROM tasks WHERE id=? AND workspace_id=?');
    $q->execute([$id,$wid]); $row=$q->fetch();
    if (!$row) throw new DomainException('Tarea no encontrada.',404);
    $task=json_decode($row['payload_json'],true)?:[];
    $task['id']=$id; $task['_owner_id']=(int)$row['owner_id'];
    $projects=workspaceProjects($pdo,$wid); $shares=workspaceShares($pdo,$wid);
    if (!projectTaskAccess($task,$projects,(int)$u['id'],sharedIds($shares,$id))) throw new DomainException('No tienes acceso a esta tarea.',403);
    $task['_revision']=hash('sha256',$row['payload_json']);
    return $task;
}
function mcpDate(string $day): array {
    if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$day)) throw new DomainException('Fecha inválida.',422);
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$day,new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d')!==$day) throw new DomainException('Fecha inválida.',422);
    return [$date->format('Y-m-d\\TH:i:s.v\\Z'),$date->modify('+1 day')->format('Y-m-d\\TH:i:s.v\\Z')];
}
function mcpCreateTask(PDO $pdo, int $wid, array $u, bool $admin, string $id, string $title, string $project): array {
    $pdo->beginTransaction();
    try {
        $projects=workspaceProjects($pdo,$wid,true);
        $q=$pdo->prepare('SELECT workspace_id,owner_id,payload_json FROM tasks WHERE id=? FOR UPDATE');$q->execute([$id]);$prior=$q->fetch();
        if ($prior) {
            $saved=json_decode($prior['payload_json'],true)?:[];
            if ((int)$prior['workspace_id']!==$wid || (int)$prior['owner_id']!==(int)$u['id'] || ($saved['title']??null)!==$title || ($saved['project']??'')!==$project)
                throw new DomainException('client_id ya usado con otros datos.',409);
            $pdo->commit();
            return loadWorkspaceTaskById($pdo,$wid,$id,$u,$admin,'projects');
        }
        $task=['id'=>$id,'title'=>$title,'list'=>'inbox','project'=>$project,'due'=>'','quadrant'=>'q2','tags'=>[],'estPomos'=>1,'notes'=>'','subtasks'=>[],'done'=>false,'workBlocks'=>[],'createdAt'=>(int)(microtime(true)*1000)];
        projectTaskWrite($task,null,$projects,$u,$admin,[]);
        $json=json_encode($task,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $q=$pdo->prepare('INSERT INTO tasks(id,workspace_id,owner_id,title,done,payload_json) VALUES(?,?,?,?,?,?)');
        $q->execute([$id,$wid,(int)$u['id'],$title,0,$json]);
        $pdo->commit();
        return loadWorkspaceTaskById($pdo,$wid,$id,$u,$admin,'projects');
    } catch (Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function mcpExecute(PDO $pdo, array $u, array $scopes, string $name, array $a): array {
    $read = in_array('kaizen:read',$scopes,true);
    $write = in_array('kaizen:write',$scopes,true);
    $writeTool=in_array($name,['create_task','update_task','set_task_done','set_work_block','remove_work_block','save_project','assistant_run_upsert'],true);
    if ((!$read && !$write) || ($writeTool && !$write)) throw new DomainException('Permiso MCP insuficiente.',403);
    if ($name==='get_profile') return ['id'=>'kaizen-user-'.(string)$u['id'],'name'=>(string)$u['name'],'email'=>(string)$u['email']];
    if ($name==='list_workspaces') {
        if (($u['role']??'')==='super_admin') return ['workspaces'=>$pdo->query('SELECT id,name,created_by FROM workspaces ORDER BY name')->fetchAll()];
        $q=$pdo->prepare('SELECT w.id,w.name,w.created_by,wm.role AS member_role FROM workspaces w JOIN workspace_members wm ON wm.workspace_id=w.id WHERE wm.user_id=? ORDER BY w.name');$q->execute([(int)$u['id']]);
        return ['workspaces'=>$q->fetchAll()];
    }
    $wid=mcpInt($a['workspace_id']??null);
    $access=mcpAllowedWorkspace($pdo,$wid,$u);
    $admin=workspaceProjectAdmin($wid,$u,$access);
    if ($name==='assistant_runs') {
        assistantWorkspaceAdmin($pdo,$wid,$u);
        ensureAssistantRunSchema($pdo);
        return ['runs'=>assistantRunRecords($pdo,$wid,$u)];
    }
    if ($name==='assistant_run_upsert') {
        assistantWorkspaceAdmin($pdo,$wid,$u);
        $allowed=['workspace_id','source','external_id','idempotency_key','title','status','progress','blocker','next_step','deliverables','delegated_at','completed_at','closed_at'];
        if (array_diff(array_keys($a),$allowed)) throw new DomainException('Campo de ejecución no permitido.',422);
        ensureAssistantRunSchema($pdo);
        return assistantRunUpsert($pdo,$wid,$u,$a);
    }
    if ($name==='list_projects') {
        $all=loadWorkspaceTasks($pdo,$wid,$u,$admin,'projects');
        return ['projects'=>$all['projects']];
    }
    if ($name==='list_tasks') {
        $scope=$a['scope']??'personal';
        if (!is_string($scope)) throw new DomainException('Vista inválida.',422);
        return ['tasks'=>loadWorkspaceTasks($pdo,$wid,$u,$admin,$scope)['tasks']];
    }
    if ($name==='get_task') {
        $scope=$a['scope']??'personal';
        if (!is_string($scope)) throw new DomainException('Vista inválida.',422);
        return ['task'=>loadWorkspaceTaskById($pdo,$wid,mcpId($a['task_id']??null),$u,$admin,$scope)];
    }
    if ($name==='day_agenda') {
        if (!is_string($a['date']??null)) throw new DomainException('Fecha inválida.',422);
        [$start,$end]=mcpDate($a['date']);
        $tasks=loadWorkspaceTasks($pdo,$wid,$u,$admin,'personal')['tasks'];
        $blocks=[]; $dated=[];
        foreach ($tasks as $task) {
            foreach (($task['workBlocks']??[]) as $block) {
                if (is_array($block) && is_string($block['start']??null) && is_string($block['end']??null) && $block['start']<$end && $block['end']>$start)
                    $blocks[]=['task_id'=>$task['id'],'title'=>$task['title']??'','block'=>$block,'revision'=>$task['_revision']];
            }
            if (($task['due']??'')===$a['date'] || (is_string($task['scheduledStart']??null) && is_string($task['scheduledEnd']??null) && $task['scheduledStart']<$end && $task['scheduledEnd']>$start)) $dated[]=$task;
        }
        usort($blocks,fn($x,$y)=>strcmp($x['block']['start'],$y['block']['start']));
        return ['date'=>$a['date'],'time_zone'=>'UTC','blocks'=>$blocks,'dated_tasks'=>$dated];
    }
    if ($name==='create_task') {
        $client=mcpId($a['client_id']??null);$title=mcpText($a['title']??null,255);
        $project=isset($a['project_id'])?mcpId($a['project_id']):'';
        $id='m'.substr(hash('sha256',$wid.':'.$u['id'].':'.$client),0,31);
        return ['task'=>mcpCreateTask($pdo,$wid,$u,$admin,$id,$title,$project)];
    }
    if (in_array($name,['update_task','set_task_done','set_work_block','remove_work_block'],true)) {
        $id=mcpId($a['task_id']??null);$revision=$a['revision']??null;
        if (!is_string($revision)||!preg_match('/^[a-f0-9]{64}$/D',$revision)) throw new DomainException('Revisión inválida.',422);
        $task=mcpTaskForWrite($pdo,$wid,$id,$u,$admin);
        if ($name==='update_task') {
            $changes=$a['changes']??null;
            if (!is_array($changes) || !$changes || array_is_list($changes)) throw new DomainException('Cambios inválidos.',422);
            foreach ($changes as $key=>$value) {
                if ($key==='title') $task['title']=mcpText($value,255);
                elseif ($key==='notes') {
                    if (!is_string($value) || mb_strlen($value)>10000) throw new DomainException('Notas inválidas.',422);
                    $task['notes']=$value;
                } elseif ($key==='due') {
                    if (!is_string($value)) throw new DomainException('Fecha inválida.',422);
                    if ($value!=='') mcpDate($value);
                    $task['due']=$value;
                } elseif ($key==='project_id') {
                    if ($value!=='' && (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{1,32}$/D',$value))) throw new DomainException('Proyecto inválido.',422);
                    $task['project']=$value;
                } else throw new DomainException('Campo de tarea no permitido.',422);
            }
        } elseif ($name==='set_task_done') {
            if (!is_bool($a['done']??null)) throw new DomainException('Estado inválido.',422);
            if ((bool)($task['done']??false)===$a['done']) return ['task'=>loadWorkspaceTaskById($pdo,$wid,$id,$u,$admin,'projects')];
            $task['done']=$a['done'];$task['doneAt']=$a['done']?(int)(microtime(true)*1000):null;
        } elseif ($name==='set_work_block') {
            $blockId=mcpId($a['block_id']??null);
            foreach (['start','end','time_zone'] as $field) if (!is_string($a[$field]??null)) throw new DomainException('Bloque inválido.',422);
            $block=['id'=>$blockId,'start'=>$a['start'],'end'=>$a['end'],'timeZone'=>$a['time_zone']];
            $blocks=is_array($task['workBlocks']??null)?$task['workBlocks']:[];
            foreach ($blocks as $existing) if (($existing['id']??null)===$blockId && $existing===$block) return ['task'=>loadWorkspaceTaskById($pdo,$wid,$id,$u,$admin,'projects')];
            $blocks=array_values(array_filter($blocks,fn($b)=>($b['id']??null)!==$blockId));$blocks[]=$block;
            $task['workBlocks']=$blocks;
        } else {
            $blockId=mcpId($a['block_id']??null);
            $blocks=is_array($task['workBlocks']??null)?$task['workBlocks']:[];
            $remaining=array_values(array_filter($blocks,fn($b)=>($b['id']??null)!==$blockId));
            if (count($remaining)===count($blocks)) return ['task'=>loadWorkspaceTaskById($pdo,$wid,$id,$u,$admin,'projects')];
            $task['workBlocks']=$remaining;
        }
        $task['_revision']=$revision;
        $revisions=syncWorkspaceTasks($pdo,$wid,$u,$admin,[$task]);
        return ['task'=>loadWorkspaceTaskById($pdo,$wid,$id,$u,$admin,'projects'),'revision'=>$revisions[$id]];
    }
    if ($name==='save_project') {
        if (!$access) throw new DomainException('Debes pertenecer al equipo de este espacio.',403);
        $nameValue=mcpText($a['name']??null,120);
        $memberIds=$a['member_ids']??null;
        if (!is_array($memberIds) || array_values($memberIds)!==$memberIds || count($memberIds)>500) throw new DomainException('Miembros inválidos.',422);
        foreach ($memberIds as $member) mcpInt($member);
        $createOnly=!isset($a['project_id']);
        if (!$createOnly) $projectId=mcpId($a['project_id']);
        else {
            $client=mcpId($a['client_id']??null);
            $projectId='m'.substr(hash('sha256',$wid.':'.$u['id'].':'.$client),0,23);
        }
        $project=mcpSaveProject($pdo,$wid,$u,$admin,$projectId,$nameValue,$memberIds,$createOnly);
        return ['project'=>$project];
    }
    throw new DomainException('Herramienta no encontrada.',404);
}

function mcpSaveProject(PDO $pdo, int $wid, array $u, bool $admin, string $id, string $name, array $members, bool $createOnly): array {
    $pdo->beginTransaction();
    try {
        $projects=workspaceProjects($pdo,$wid,true);
        $existing=projectFind($projects,$id);
        if (!$createOnly && !$existing) throw new DomainException('Proyecto no encontrado.',404);
        if ($createOnly && $existing) {
            $requested=array_values(array_unique(array_merge([(int)$u['id']],$members)));
            $saved=array_map('intval',$existing['member_ids']??[]);
            sort($requested);sort($saved);
            if ((int)($existing['owner_id']??0)!==(int)$u['id'] || $existing['name']!==$name || $requested!==$saved)
                throw new DomainException('client_id ya usado con otros datos.',409);
            $pdo->commit();return decorateProject($existing,$u,$admin);
        }
        if ($existing && !projectManage($existing,$u,$admin)) throw new DomainException('Sin permiso para modificar el proyecto.',403);
        $owner=(int)($existing['owner_id']??$u['id']);
        $ids=array_values(array_unique(array_merge([$owner],$members)));
        $q=$pdo->prepare('SELECT user_id FROM workspace_members WHERE workspace_id=?');$q->execute([$wid]);
        $team=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        foreach ($ids as $member) if (!in_array($member,$team,true)) throw new DomainException('Todos los miembros deben pertenecer al equipo.',422);
        $project=array_merge($existing??[],['id'=>$id,'name'=>$name,'owner_id'=>$owner,'member_ids'=>$ids]);
        if ($existing) {
            foreach ($projects as &$p) if (($p['id']??null)===$id) $p=$project;
            unset($p);
        } else $projects[]=$project;
        $pdo->prepare('UPDATE workspace_data SET projects_json=? WHERE workspace_id=?')->execute([json_encode($projects,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$wid]);
        $pdo->commit();return decorateProject($project,$u,$admin);
    } catch (Throwable $e) {if ($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
