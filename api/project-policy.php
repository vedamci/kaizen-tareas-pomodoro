<?php
declare(strict_types=1);

// Legacy projects have no membership list. Keep their existing per-task access
// until an owner/admin explicitly chooses members; never widen access on load.
function projectIsShared(array $project): bool { return isset($project['member_ids']) && is_array($project['member_ids']); }
function projectMember(array $project, int $userId): bool {
    return projectIsShared($project) && in_array($userId, array_map('intval', $project['member_ids']), true);
}
function projectManage(array $project, array $user, bool $workspaceAdmin): bool {
    return $workspaceAdmin || (isset($project['owner_id']) && (int)$project['owner_id'] === (int)$user['id']);
}
function projectFind(array $projects, string $id): ?array {
    foreach ($projects as $project) if (is_array($project) && (string)($project['id'] ?? '') === $id) return $project;
    return null;
}
function projectTaskAccess(array $task, array $projects, int $userId, array $sharedIds): bool {
    $project = projectFind($projects, (string)($task['project'] ?? ''));
    if ($project && projectIsShared($project)) return projectMember($project, $userId);
    return (int)($task['_owner_id'] ?? 0) === $userId || in_array($userId, array_map('intval', $sharedIds), true);
}
function taskPersonalAccess(array $task, int $userId, array $sharedIds): bool {
    // Owner is the creator recorded in SQL; shares are confirmed delegation,
    // never a caller-supplied sharedWith payload or project membership.
    return (int)($task['_owner_id'] ?? 0) === $userId || in_array($userId, array_map('intval', $sharedIds), true);
}
function validateTaskReadScope(string $scope, bool $admin): void {
    if (!in_array($scope, ['personal', 'projects', 'team'], true)) throw new DomainException('Vista de tareas no válida.', 422);
    if ($scope === 'team' && !$admin) throw new DomainException('Solo un administrador del espacio puede consultar las tareas del equipo.', 403);
}
function taskReadAccess(array $task, array $projects, int $userId, array $sharedIds, string $scope, bool $admin): bool {
    validateTaskReadScope($scope, $admin);
    if ($scope === 'team') return true; // Read-only, separately authorized workspace overview.
    if (!projectTaskAccess($task, $projects, $userId, $sharedIds)) return false;
    return $scope === 'projects' || taskPersonalAccess($task, $userId, $sharedIds);
}
function projectTaskWrite(array $next, ?array $previous, array $projects, array $user, bool $admin, array $sharedIds): void {
    $id = (int)$user['id'];
    if ($previous && !projectTaskAccess($previous, $projects, $id, $sharedIds)) throw new DomainException('No tienes acceso a esta tarea.', 403);
    $target = (string)($next['project'] ?? '');
    if ($previous && $target !== (string)($previous['project'] ?? '') && (int)$previous['_owner_id'] !== $id && !$admin) {
        throw new DomainException('Solo quien creó la tarea o un administrador puede moverla de proyecto.', 403);
    }
    if ($target !== '') {
        $project = projectFind($projects, $target);
        if (!$project && (!$previous || $target !== (string)($previous['project'] ?? ''))) throw new DomainException('El proyecto ya no existe. Actualiza el espacio.', 422);
        if ($project && projectIsShared($project) && !projectMember($project, $id)) throw new DomainException('Debes ser miembro del proyecto de destino.', 403);
    }
    validateTaskSchedule($next);
}
function validateTaskSchedule(array $task): void {
    $start = $task['scheduledStart'] ?? '';
    $end = $task['scheduledEnd'] ?? '';
    if ($start === '' && $end === '') return;
    $dates = [];
    foreach ([$start, $end] as $value) {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $value)) throw new DomainException('Indica inicio y fin válidos para la planificación.', 422);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d\TH:i:s.v\Z') !== $value) throw new DomainException('La fecha planificada no es válida.', 422);
        $dates[] = $date;
    }
    if ($dates[1] <= $dates[0]) throw new DomainException('El fin debe ser posterior al inicio.', 422);
    $zone = $task['scheduleTimeZone'] ?? 'UTC';
    if (!is_string($zone) || !in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) throw new DomainException('La zona horaria no es válida.', 422);
}
function validateTaskRevision(string $storedJson, array $incoming): void {
    if (!is_string($incoming['_revision'] ?? null) || !hash_equals(hash('sha256', $storedJson), $incoming['_revision'])) {
        throw new DomainException('Otra persona actualizó esta tarea. Recarga del servidor antes de volver a editarla.', 409);
    }
}
