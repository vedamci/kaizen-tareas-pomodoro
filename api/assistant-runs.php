<?php
declare(strict_types=1);

require_once __DIR__ . '/projects.php';

function ensureAssistantRunSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS assistant_runs (
        workspace_id INT UNSIGNED NOT NULL,
        id CHAR(32) NOT NULL,
        source VARCHAR(40) NOT NULL,
        external_id VARCHAR(120) NOT NULL,
        title VARCHAR(180) NOT NULL,
        status ENUM('queued','in_progress','blocked','completed','cancelled') NOT NULL,
        delegated_at DATETIME(3) NULL,
        completed_at DATETIME(3) NULL,
        closed_at DATETIME(3) NULL,
        progress TEXT NULL,
        blocker TEXT NULL,
        next_step TEXT NULL,
        deliverables_json LONGTEXT NOT NULL,
        verified_at DATETIME(3) NOT NULL,
        updated_by INT UNSIGNED NOT NULL,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (workspace_id,id),
        UNIQUE KEY uq_assistant_run_source (workspace_id,source,external_id),
        INDEX idx_assistant_run_workspace_status (workspace_id,status,verified_at),
        CONSTRAINT fk_assistant_runs_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
        CONSTRAINT fk_assistant_runs_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS assistant_run_updates (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        workspace_id INT UNSIGNED NOT NULL,
        run_id CHAR(32) NOT NULL,
        idempotency_key VARCHAR(100) NOT NULL,
        payload_hash CHAR(64) NOT NULL,
        source VARCHAR(40) NOT NULL,
        status ENUM('queued','in_progress','blocked','completed','cancelled') NOT NULL,
        progress TEXT NULL,
        blocker TEXT NULL,
        next_step TEXT NULL,
        deliverables_json LONGTEXT NOT NULL,
        delegated_at DATETIME(3) NULL,
        completed_at DATETIME(3) NULL,
        closed_at DATETIME(3) NULL,
        verified_at DATETIME(3) NOT NULL,
        recorded_by INT UNSIGNED NOT NULL,
        UNIQUE KEY uq_assistant_run_idempotency (workspace_id,idempotency_key),
        INDEX idx_assistant_updates_run (workspace_id,run_id,id),
        CONSTRAINT fk_assistant_updates_run FOREIGN KEY (workspace_id,run_id) REFERENCES assistant_runs(workspace_id,id) ON DELETE CASCADE,
        CONSTRAINT fk_assistant_updates_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function assistantWorkspaceAdmin(PDO $pdo, int $workspaceId, array $user, bool $lock = false): array {
    if ($workspaceId < 1) throw new DomainException('Espacio de trabajo no válido.', 422);
    $super = ($user['role'] ?? '') === 'super_admin';
    $sql = 'SELECT w.id,w.created_by,wm.role AS member_role FROM workspaces w LEFT JOIN workspace_members wm ON wm.workspace_id=w.id AND wm.user_id=? WHERE w.id=? AND (w.created_by=? OR wm.user_id=? OR ?=1)'.($lock ? ' FOR UPDATE' : '');
    $q = $pdo->prepare($sql);
    $q->execute([(int)($user['id'] ?? 0),$workspaceId,(int)($user['id'] ?? 0),(int)($user['id'] ?? 0),$super ? 1 : 0]);
    $access = $q->fetch();
    if (!$access) throw new DomainException('Sin acceso a este espacio.',403);
    if (!workspaceProjectAdmin($workspaceId,$user,$access)) throw new DomainException('Solo un administrador puede consultar o actualizar estos registros.',403);
    return $access;
}

function assistantRunText($value, string $field, int $max, bool $required = false): ?string {
    if ($value === null) {
        if ($required) throw new DomainException($field.' es obligatorio.',422);
        return null;
    }
    if (!is_string($value)) throw new DomainException($field.' no es válido.',422);
    $text = trim($value);
    if ($required && $text === '') throw new DomainException($field.' es obligatorio.',422);
    if (mb_strlen($text) > $max) throw new DomainException($field.' supera el límite permitido.',422);
    return $text === '' ? null : $text;
}

function assistantRunTimestamp($value, string $field): ?string {
    if ($value === null || $value === '') return null;
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,3})?(?:Z|[+-]\d{2}:\d{2})$/D',$value,$parts)) {
        throw new DomainException($field.' debe ser una fecha ISO 8601 con zona horaria.',422);
    }
    if (!checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1])||(int)$parts[4]>23||(int)$parts[5]>59||(int)$parts[6]>59) throw new DomainException($field.' no es una fecha válida.',422);
    try { $date = new DateTimeImmutable($value); } catch (Throwable $e) { throw new DomainException($field.' no es una fecha válida.',422); }
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
}

function assistantRunIso(?string $value): ?string {
    if (!$value) return null;
    try { return (new DateTimeImmutable($value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'); }
    catch (Throwable $e) { return null; }
}

function assistantRunNow(): string {
    return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
}

function assistantRunPayload(array $input): array {
    $source = assistantRunText($input['source'] ?? null,'Fuente',40,true);
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,39}$/D',(string)$source)) throw new DomainException('Fuente no válida.',422);
    $external = assistantRunText($input['external_id'] ?? null,'ID de origen',120,true);
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,119}$/D',(string)$external)) throw new DomainException('ID de origen no válido.',422);
    $key = assistantRunText($input['idempotency_key'] ?? null,'Clave de idempotencia',100,true);
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{15,99}$/D',(string)$key)) throw new DomainException('Clave de idempotencia no válida.',422);
    $title = assistantRunText($input['title'] ?? null,'Título',180,true);
    $status = $input['status'] ?? null;
    if (!is_string($status) || !in_array($status,['queued','in_progress','blocked','completed','cancelled'],true)) throw new DomainException('Estado no válido.',422);
    $deliverables = $input['deliverables'] ?? [];
    if (!is_array($deliverables) || array_values($deliverables) !== $deliverables || count($deliverables) > 12) throw new DomainException('Entregables no válidos.',422);
    $cleanDeliverables = [];
    foreach ($deliverables as $value) {
        $item = assistantRunText($value,'Entregable',200,true);
        if (!in_array($item,$cleanDeliverables,true)) $cleanDeliverables[] = $item;
    }
    $delegated = assistantRunTimestamp($input['delegated_at'] ?? null,'Fecha de delegación');
    $completed = assistantRunTimestamp($input['completed_at'] ?? null,'Fecha de finalización');
    $closed = assistantRunTimestamp($input['closed_at'] ?? null,'Fecha de cierre');
    if ($completed && $status !== 'completed') throw new DomainException('La fecha de finalización requiere estado completado.',422);
    if ($closed && $status !== 'cancelled') throw new DomainException('La fecha de cierre requiere estado cancelado.',422);
    return [
        'source'=>$source,'external_id'=>$external,'idempotency_key'=>$key,'title'=>$title,'status'=>$status,
        'progress'=>assistantRunText($input['progress'] ?? null,'Resumen de avance',1200),
        'blocker'=>assistantRunText($input['blocker'] ?? null,'Bloqueo',800),
        'next_step'=>assistantRunText($input['next_step'] ?? null,'Próximo paso',800),
        'deliverables'=>$cleanDeliverables,'deliverables_json'=>json_encode($cleanDeliverables,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
        'delegated_at'=>$delegated,'completed_at'=>$completed,'closed_at'=>$closed
    ];
}

function assistantRunPayloadHash(array $payload): string {
    $canonical = $payload;
    unset($canonical['idempotency_key']);
    return hash('sha256',json_encode($canonical,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}

function assistantRunEvent(string $id, string $type, ?string $at, string $actor, string $summary = ''): array {
    return ['id'=>$id,'type'=>$type,'at'=>assistantRunIso($at),'actor'=>$actor,'summary'=>$summary];
}

function assistantRunRecords(PDO $pdo, int $workspaceId, array $user): array {
    assistantWorkspaceAdmin($pdo,$workspaceId,$user);
    $q = $pdo->prepare('SELECT * FROM assistant_runs WHERE workspace_id=? ORDER BY verified_at DESC,id');
    $q->execute([$workspaceId]); $rows = $q->fetchAll();
    if (!$rows) return [];
    $updatesQ = $pdo->prepare('SELECT aru.*,u.name AS recorded_name FROM assistant_run_updates aru LEFT JOIN users u ON u.id=aru.recorded_by WHERE aru.workspace_id=? ORDER BY aru.id');
    $updatesQ->execute([$workspaceId]); $updates = [];
    foreach ($updatesQ as $update) $updates[$update['run_id']][] = $update;
    $records = [];
    foreach ($rows as $row) {
        $events = [assistantRunEvent('delegated-'.$row['id'],'delegated',$row['delegated_at'],(string)($row['source'] ?? 'Kaizen'),'Delegación registrada; la fecha se muestra solo cuando fue verificada.')];
        $previous = ['status'=>null,'progress'=>null,'blocker'=>null,'next_step'=>null,'deliverables'=>[]];
        foreach ($updates[$row['id']] ?? [] as $update) {
            $at = (string)$update['verified_at'];
            $actor = (string)$update['source'] === 'kaizen_manual' && trim((string)($update['recorded_name'] ?? '')) !== ''
                ? trim((string)$update['recorded_name']) : (string)$update['source'];
            $events[] = assistantRunEvent('verified-'.$update['id'],'status_verified',$at,$actor,'Estado verificado: '.$update['status'].'.');
            if ($previous['status'] !== null && $previous['status'] !== $update['status']) $events[] = assistantRunEvent('status-'.$update['id'],'status_changed',$at,$actor,'Estado actualizado: '.$update['status'].'.');
            if (!empty($update['progress']) && $previous['progress'] !== $update['progress']) $events[] = assistantRunEvent('progress-'.$update['id'],'progress_note',$at,$actor,(string)$update['progress']);
            if ($previous['blocker'] !== $update['blocker']) {
                if (!empty($update['blocker'])) $events[] = assistantRunEvent('blocker-'.$update['id'],'blocker_set',$at,$actor,(string)$update['blocker']);
                elseif (!empty($previous['blocker'])) $events[] = assistantRunEvent('blocker-clear-'.$update['id'],'blocker_cleared',$at,$actor,'El bloqueo quedó resuelto.');
            }
            if ($previous['next_step'] !== $update['next_step']) {
                if (!empty($update['next_step'])) $events[] = assistantRunEvent('next-'.$update['id'],'next_step_set',$at,$actor,(string)$update['next_step']);
                elseif (!empty($previous['next_step'])) $events[] = assistantRunEvent('next-clear-'.$update['id'],'next_step_cleared',$at,$actor,'El próximo paso quedó actualizado.');
            }
            $deliverables = json_decode((string)$update['deliverables_json'],true); $deliverables = is_array($deliverables) ? $deliverables : [];
            foreach (array_diff($deliverables,$previous['deliverables']) as $item) $events[] = assistantRunEvent('deliverable-'.$update['id'].'-'.substr(hash('sha256',(string)$item),0,8),'deliverable_added',$at,$actor,(string)$item);
            foreach (array_diff($previous['deliverables'],$deliverables) as $item) $events[] = assistantRunEvent('deliverable-removed-'.$update['id'].'-'.substr(hash('sha256',(string)$item),0,8),'deliverable_removed',$at,$actor,(string)$item);
            if ($previous['status'] !== 'completed' && $update['status'] === 'completed' && !empty($update['completed_at'])) {
                $summary = (string)$update['source'] === 'kaizen_manual' ? 'Marcada como terminada manualmente en Kaizen.' : 'Finalización verificada por la fuente.';
                $events[] = assistantRunEvent('completed-'.$update['id'],'completed',(string)$update['completed_at'],$actor,$summary);
            }
            if ($previous['status'] !== 'cancelled' && $update['status'] === 'cancelled' && !empty($update['closed_at'])) $events[] = assistantRunEvent('cancelled-'.$update['id'],'cancelled',(string)$update['closed_at'],$actor,'Cancelación verificada por la fuente.');
            $previous = ['status'=>$update['status'],'progress'=>$update['progress'],'blocker'=>$update['blocker'],'next_step'=>$update['next_step'],'deliverables'=>$deliverables];
        }
        $records[] = [
            'kind'=>'assistant_run','id'=>(string)$row['id'],'workspace_id'=>$workspaceId,'source'=>(string)$row['source'],
            'external_id'=>(string)$row['external_id'],'assistant'=>'Luna','title'=>(string)$row['title'],'status'=>(string)$row['status'],
            'delegated_at'=>assistantRunIso($row['delegated_at']),'completed_at'=>assistantRunIso($row['completed_at']),
            'closed_at'=>assistantRunIso($row['closed_at']),'verified_at'=>assistantRunIso($row['verified_at']),
            'events'=>$events
        ];
    }
    return $records;
}

function assistantRunUpsert(PDO $pdo, int $workspaceId, array $user, array $input): array {
    assistantWorkspaceAdmin($pdo,$workspaceId,$user);
    $payload = assistantRunPayload($input);
    $hash = assistantRunPayloadHash($payload);
    $pdo->beginTransaction();
    try {
        assistantWorkspaceAdmin($pdo,$workspaceId,$user,true);
        $verifiedAt = assistantRunNow();
        $existingUpdate = $pdo->prepare('SELECT id,run_id,payload_hash,verified_at FROM assistant_run_updates WHERE workspace_id=? AND idempotency_key=? FOR UPDATE');
        $existingUpdate->execute([$workspaceId,$payload['idempotency_key']]); $priorUpdate = $existingUpdate->fetch();
        if ($priorUpdate) {
            if (!hash_equals((string)$priorUpdate['payload_hash'],$hash)) throw new DomainException('La clave de idempotencia ya se usó con otros datos.',409);
            $pdo->commit();
            $record = null;
            foreach (assistantRunRecords($pdo,$workspaceId,$user) as $candidate) if ($candidate['id']===$priorUpdate['run_id']) { $record=$candidate; break; }
            return ['duplicate'=>true,'verified_at'=>assistantRunIso((string)$priorUpdate['verified_at']),'run'=>$record];
        }
        $find = $pdo->prepare('SELECT * FROM assistant_runs WHERE workspace_id=? AND source=? AND external_id=? FOR UPDATE');
        $find->execute([$workspaceId,$payload['source'],$payload['external_id']]); $run = $find->fetch();
        $runId = $run ? (string)$run['id'] : bin2hex(random_bytes(16));
        if ($run && $run['delegated_at'] !== null && $payload['delegated_at'] !== null && (string)$run['delegated_at'] !== $payload['delegated_at']) throw new DomainException('La fecha original de delegación no se puede reemplazar.',409);
        $delegatedAt = $run && $run['delegated_at'] !== null ? (string)$run['delegated_at'] : $payload['delegated_at'];
        $completedAt = $payload['status']==='completed' ? ($payload['completed_at'] ?? ($run['completed_at'] ?? null)) : null;
        $closedAt = $payload['status']==='cancelled' ? ($payload['closed_at'] ?? ($run['closed_at'] ?? null)) : null;
        if ($run) {
            $save = $pdo->prepare('UPDATE assistant_runs SET title=?,status=?,delegated_at=?,completed_at=?,closed_at=?,progress=?,blocker=?,next_step=?,deliverables_json=?,verified_at=?,updated_at=?,updated_by=? WHERE workspace_id=? AND id=?');
            $save->execute([$payload['title'],$payload['status'],$delegatedAt,$completedAt,$closedAt,$payload['progress'],$payload['blocker'],$payload['next_step'],$payload['deliverables_json'],$verifiedAt,$verifiedAt,(int)$user['id'],$workspaceId,$runId]);
        } else {
            $save = $pdo->prepare('INSERT INTO assistant_runs(workspace_id,id,source,external_id,title,status,delegated_at,completed_at,closed_at,progress,blocker,next_step,deliverables_json,verified_at,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $save->execute([$workspaceId,$runId,$payload['source'],$payload['external_id'],$payload['title'],$payload['status'],$delegatedAt,$completedAt,$closedAt,$payload['progress'],$payload['blocker'],$payload['next_step'],$payload['deliverables_json'],$verifiedAt,(int)$user['id'],$verifiedAt,$verifiedAt]);
        }
        $append = $pdo->prepare('INSERT INTO assistant_run_updates(workspace_id,run_id,idempotency_key,payload_hash,source,status,progress,blocker,next_step,deliverables_json,delegated_at,completed_at,closed_at,verified_at,recorded_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $append->execute([$workspaceId,$runId,$payload['idempotency_key'],$hash,$payload['source'],$payload['status'],$payload['progress'],$payload['blocker'],$payload['next_step'],$payload['deliverables_json'],$delegatedAt,$completedAt,$closedAt,$verifiedAt,(int)$user['id']]);
        $pdo->commit();
        $record = null;
        foreach (assistantRunRecords($pdo,$workspaceId,$user) as $candidate) if ($candidate['id']===$runId) { $record=$candidate; break; }
        return ['duplicate'=>false,'verified_at'=>assistantRunIso($verifiedAt),'run'=>$record];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function assistantRunMarkCompleted(PDO $pdo, int $workspaceId, array $user, $runId): array {
    assistantWorkspaceAdmin($pdo,$workspaceId,$user);
    if (!is_string($runId) || !preg_match('/^[a-f0-9]{32}$/iD',$runId)) throw new DomainException('ID de ejecución no válido.',422);
    $pdo->beginTransaction();
    try {
        assistantWorkspaceAdmin($pdo,$workspaceId,$user,true);
        $find=$pdo->prepare('SELECT * FROM assistant_runs WHERE workspace_id=? AND id=? FOR UPDATE');
        $find->execute([$workspaceId,$runId]); $run=$find->fetch();
        if (!$run) throw new DomainException('No se encontró la ejecución en este espacio.',404);
        if ($run['status']==='cancelled') throw new DomainException('Una ejecución cancelada no se puede marcar como completada.',409);
        if ($run['status']==='completed') {
            $pdo->commit();
            $record=null;
            foreach (assistantRunRecords($pdo,$workspaceId,$user) as $candidate) if ($candidate['id']===$runId) { $record=$candidate; break; }
            return ['duplicate'=>true,'verified_at'=>assistantRunIso((string)$run['verified_at']),'run'=>$record];
        }
        $verifiedAt=assistantRunNow();
        $idempotencyKey='manual-complete:'.$runId.':'.bin2hex(random_bytes(12));
        $payloadHash=hash('sha256',json_encode([
            'run_id'=>$runId,'status'=>'completed','completed_at'=>$verifiedAt,'recorded_by'=>(int)$user['id']
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $deliverablesJson=(string)$run['deliverables_json'];
        $append=$pdo->prepare('INSERT INTO assistant_run_updates(workspace_id,run_id,idempotency_key,payload_hash,source,status,progress,blocker,next_step,deliverables_json,delegated_at,completed_at,closed_at,verified_at,recorded_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $append->execute([$workspaceId,$runId,$idempotencyKey,$payloadHash,'kaizen_manual','completed',$run['progress'],$run['blocker'],$run['next_step'],$deliverablesJson,$run['delegated_at'],$verifiedAt,null,$verifiedAt,(int)$user['id']]);
        $save=$pdo->prepare('UPDATE assistant_runs SET status=?,completed_at=?,closed_at=NULL,verified_at=?,updated_at=?,updated_by=? WHERE workspace_id=? AND id=?');
        $save->execute(['completed',$verifiedAt,$verifiedAt,$verifiedAt,(int)$user['id'],$workspaceId,$runId]);
        $pdo->commit();
        $record=null;
        foreach (assistantRunRecords($pdo,$workspaceId,$user) as $candidate) if ($candidate['id']===$runId) { $record=$candidate; break; }
        return ['duplicate'=>false,'verified_at'=>assistantRunIso($verifiedAt),'run'=>$record];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function supervisionMembers(PDO $pdo, int $workspaceId, array $user): array {
    assistantWorkspaceAdmin($pdo,$workspaceId,$user);
    $q = $pdo->prepare('SELECT u.id,u.name,wm.role AS workspace_role FROM workspace_members wm JOIN users u ON u.id=wm.user_id WHERE wm.workspace_id=? ORDER BY u.name,u.id');
    $q->execute([$workspaceId]);
    return array_map(fn($row)=>['id'=>(int)$row['id'],'name'=>(string)$row['name'],'workspace_role'=>(string)$row['workspace_role']],$q->fetchAll());
}

function supervisionTasks(PDO $pdo, int $workspaceId, int $personId, array $user): array {
    assistantWorkspaceAdmin($pdo,$workspaceId,$user);
    if ($personId < 1) throw new DomainException('Persona no válida.',422);
    $member = $pdo->prepare('SELECT 1 FROM workspace_members WHERE workspace_id=? AND user_id=?');
    $member->execute([$workspaceId,$personId]);
    if (!$member->fetchColumn()) throw new DomainException('La persona no pertenece a este espacio.',404);
    // EXISTS keeps one row per canonical task ID without DISTINCT over the payload.
    $q = $pdo->prepare('SELECT t.id,t.owner_id,t.title,t.done,t.payload_json FROM tasks t WHERE t.workspace_id=? AND (t.owner_id=? OR EXISTS (SELECT 1 FROM task_shares ts WHERE ts.workspace_id=t.workspace_id AND ts.task_id=t.id AND ts.user_id=?)) ORDER BY t.id');
    $q->execute([$workspaceId,$personId,$personId]);
    $projects = workspaceProjects($pdo,$workspaceId); $tasks=[];
    foreach ($q as $row) {
        $payload=json_decode((string)$row['payload_json'],true); $payload=is_array($payload)?$payload:[];
        if (($payload['list']??'')==='trash') continue;
        $projectId=(string)($payload['project']??''); $project=projectFind($projects,$projectId);
        $blocks=[]; $seenBlocks=[];
        $workBlocks=is_array($payload['workBlocks']??null)?$payload['workBlocks']:[];
        foreach ($workBlocks as $block) {
            if (!is_array($block)) continue;
            $blockId=(string)($block['id']??''); $start=(string)($block['start']??''); $end=(string)($block['end']??'');
            $signature=json_encode([$blockId,$start,$end],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if (isset($seenBlocks[$signature])) continue;
            $seenBlocks[$signature]=true;
            $blocks[]=['id'=>$blockId,'start'=>$start,'end'=>$end];
        }
        $tasks[]=[
            'id'=>(string)$row['id'],'title'=>(string)$row['title'],'done'=>(bool)$row['done'],
            'due'=>(string)($payload['due']??''),'project_name'=>(string)($project['name']??'Sin proyecto'),
            'scheduledStart'=>(string)($payload['scheduledStart']??''),'scheduledEnd'=>(string)($payload['scheduledEnd']??''),
            'workBlocks'=>$blocks
        ];
    }
    return $tasks;
}
