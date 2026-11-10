<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__ . '/shared_town.php';
require_once __DIR__ . '/town_navigation.php';

const TOWN_NPC_ACTIONS = ['walk', 'work', 'rest', 'talk'];
const TOWN_NPC_OCCUPATIONS = ['farmer', 'merchant', 'craftsman'];

function town_npc_id(): string { return bin2hex(random_bytes(16)); }

function town_npc_decode(mixed $value, mixed $fallback = []): mixed
{
    if (is_string($value)) {
        try { $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { return $fallback; }
    }
    return $value ?? $fallback;
}

function town_npc_array(mixed $value): array
{
    if (is_array($value)) return $value;
    if (is_object($value)) {
        $decoded = json_decode(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true);
        return is_array($decoded) ? $decoded : [];
    }
    return [];
}

function town_npc_public(array $row): array
{
    $profile = [
        'personality' => town_npc_decode($row['personality_json'] ?? [], []),
        'family_background' => (string)($row['family_background'] ?? ''),
        'short_term_goal' => (string)($row['short_term_goal'] ?? ''),
        'long_term_goal' => (string)($row['long_term_goal'] ?? ''),
        'speech_style' => (string)($row['speech_style'] ?? ''),
        'values' => town_npc_decode($row['values_json'] ?? [], []),
        'fears' => town_npc_decode($row['fears_json'] ?? [], []),
    ];
    return [
        'npc_id' => (string)$row['npc_id'], 'world_id' => (string)$row['world_id'],
        'name' => (string)$row['name'], 'avatar_asset_id' => (string)($row['avatar_asset_id'] ?? ''),
        'appearance' => town_npc_decode($row['appearance_json'] ?? [], []),
        'occupation' => (string)$row['occupation'], 'enabled' => (bool)$row['enabled'],
        'agent_paused' => (bool)$row['agent_paused'], 'profile' => $profile,
        'schedule' => town_npc_decode($row['schedule_json'] ?? [], []),
        'runtime' => [
            'tile_x' => (int)($row['tile_x'] ?? 0), 'tile_y' => (int)($row['tile_y'] ?? 0),
            'state' => (string)($row['runtime_state'] ?? 'rest'),
            'current_task_id' => $row['current_task_id'] !== null ? (string)$row['current_task_id'] : null,
            'dialogue' => (string)($row['dialogue'] ?? ''),
            'mood' => town_npc_decode($row['mood_json'] ?? [], []),
            'capabilities' => town_navigation_capabilities($row['capabilities_json'] ?? []),
            'state_version' => (int)($row['state_version'] ?? 1),
            'updated_at' => (string)($row['runtime_updated_at'] ?? ''),
        ],
    ];
}

function town_npc_rows(PDO $pdo, bool $includeDisabled = false): array
{
    $where = $includeDisabled ? '' : ' WHERE n.enabled=1';
    $query = $pdo->query('SELECT n.*, p.personality_json,p.family_background,p.short_term_goal,p.long_term_goal,'
        . 'p.speech_style,p.values_json,p.fears_json,r.tile_x,r.tile_y,r.state AS runtime_state,r.current_task_id,'
        . 'r.dialogue,r.mood_json,r.capabilities_json,r.state_version,r.updated_at AS runtime_updated_at,'
        . '(SELECT JSON_ARRAYAGG(JSON_OBJECT("weekday",s.weekday,"start_minute",s.start_minute,"end_minute",s.end_minute,'
        . '"action",s.action,"target_id",s.target_id,"job",s.job,"priority",s.priority)) FROM town_npc_schedules s WHERE s.npc_id=n.npc_id) AS schedule_json '
        . 'FROM town_npcs n LEFT JOIN town_npc_profiles p ON p.npc_id=n.npc_id '
        . 'LEFT JOIN town_npc_runtime_states r ON r.npc_id=n.npc_id' . $where . ' ORDER BY n.created_at,n.npc_id');
    return array_map('town_npc_public', $query->fetchAll(PDO::FETCH_ASSOC));
}

/** SQLite fallback used by service tests and installations before JSON_ARRAYAGG. */
function town_npc_rows_safe(PDO $pdo, bool $includeDisabled = false): array
{
    try { return town_npc_rows($pdo, $includeDisabled); }
    catch (PDOException) {
        $where = $includeDisabled ? '' : ' WHERE n.enabled=1';
        $query = $pdo->query('SELECT n.*,p.personality_json,p.family_background,p.short_term_goal,p.long_term_goal,p.speech_style,p.values_json,p.fears_json,'
            . 'r.tile_x,r.tile_y,r.state AS runtime_state,r.current_task_id,r.dialogue,r.capabilities_json,r.state_version,r.updated_at AS runtime_updated_at '
            . 'FROM town_npcs n LEFT JOIN town_npc_profiles p ON p.npc_id=n.npc_id LEFT JOIN town_npc_runtime_states r ON r.npc_id=n.npc_id' . $where . ' ORDER BY n.created_at,n.npc_id');
        return array_map('town_npc_public', $query->fetchAll(PDO::FETCH_ASSOC));
    }
}

function town_npc_snapshot(PDO $pdo): array
{
    return ['world_id' => SHARED_TOWN_WORLD_ID, 'navigation_version' => town_navigation_version($pdo),
        'npcs' => town_npc_rows_safe($pdo), 'cursor' => (int)($pdo->query('SELECT COALESCE(MAX(event_id),0) FROM town_npc_events')->fetchColumn() ?: 0)];
}

function town_npc_changes(PDO $pdo, int $cursor, int $limit = 200): array
{
    if ($cursor < 0) throw new ApiError('invalid_cursor', 'NPC event cursor is invalid.', 400);
    $query = $pdo->prepare('SELECT event_id,npc_id,event_type,payload_json,created_at FROM town_npc_events WHERE event_id>:cursor ORDER BY event_id LIMIT ' . min(500, max(1, $limit)));
    $query->execute([':cursor' => $cursor]); $events = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $events[] = ['event_id' => (int)$row['event_id'], 'npc_id' => (string)$row['npc_id'], 'type' => (string)$row['event_type'],
            'payload' => town_npc_decode($row['payload_json'], []), 'created_at' => (string)$row['created_at']];
    }
    return ['cursor' => (int)($pdo->query('SELECT COALESCE(MAX(event_id),0) FROM town_npc_events')->fetchColumn() ?: $cursor), 'events' => $events,
        'navigation_version' => town_navigation_version($pdo)];
}

/** Resolve the small set of named demo zones until editable interaction points exist. */
function town_npc_target_point(string $targetId): ?array
{
    return match (strtolower(trim($targetId))) {
        'farm-zone-3' => ['tile_x' => 128, 'tile_y' => 120],
        'market-zone-1' => ['tile_x' => 96, 'tile_y' => 96],
        'workshop-zone-2' => ['tile_x' => 112, 'tile_y' => 96],
        'town-square' => ['tile_x' => 96, 'tile_y' => 96],
        default => null,
    };
}

function town_npc_schedule_target(PDO $pdo, string $npcId, string $action): ?string
{
    try {
        $query = $pdo->prepare('SELECT target_id FROM town_npc_schedules WHERE npc_id=:npc AND action=:action AND target_id<>\'\' ORDER BY weekday,start_minute LIMIT 1');
        $query->execute([':npc' => $npcId, ':action' => $action]);
        $target = $query->fetchColumn();
        return is_string($target) && trim($target) !== '' ? trim($target) : null;
    } catch (PDOException) { return null; }
}

function town_npc_emit_event(PDO $pdo, string $npcId, string $type, array $payload, DateTimeImmutable $now): int
{
    $insert = $pdo->prepare('INSERT INTO town_npc_events (npc_id,event_type,payload_json,created_at) VALUES (:npc,:type,:payload,:created)');
    $insert->execute([':npc' => $npcId, ':type' => $type, ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':created' => auth_sql_time($now)]);
    return (int)$pdo->lastInsertId();
}

function town_npc_activity_log(PDO $pdo, string $npcId, string $type, string $summary, array $detail, DateTimeImmutable $now, ?string $runId = null): int
{
    $type = preg_replace('/[^a-z0-9_]/', '_', strtolower($type)) ?: 'system';
    $summary = trim($summary);
    if ($summary === '') $summary = $type;
    $insert = $pdo->prepare('INSERT INTO town_npc_activity_logs (npc_id,run_id,log_type,summary,detail_json,created_at) VALUES (:npc,:run,:type,:summary,:detail,:created)');
    $insert->execute([':npc' => $npcId, ':run' => $runId, ':type' => substr($type, 0, 24), ':summary' => substr($summary, 0, 500), ':detail' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':created' => auth_sql_time($now)]);
    return (int)$pdo->lastInsertId();
}

function town_npc_apply_action(PDO $pdo, array $body, DateTimeImmutable $now, bool $service = false): array
{
    if (!$service) throw new ApiError('agent_forbidden', 'NPC actions require the worker service identity.', 403);
    $npcId = is_string($body['npc_id'] ?? null) ? trim((string)$body['npc_id']) : '';
    $action = is_string($body['action'] ?? null) ? strtolower(trim((string)$body['action'])) : '';
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $npcId) || !in_array($action, TOWN_NPC_ACTIONS, true)) throw new ApiError('invalid_npc_action', 'NPC action is invalid.', 400);
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME); $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT n.npc_id,n.enabled,n.agent_paused,r.tile_x,r.tile_y,r.capabilities_json FROM town_npcs n LEFT JOIN town_npc_runtime_states r ON r.npc_id=n.npc_id WHERE n.npc_id=:npc' . $lock);
        $query->execute([':npc' => $npcId]); $npc = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($npc) || !(bool)$npc['enabled']) throw new ApiError('npc_not_found', 'NPC was not found.', 404);
        if ((bool)$npc['agent_paused']) throw new ApiError('npc_paused', 'NPC agent is paused.', 409);
        $payload = town_npc_array($body['payload'] ?? []);
        $tileX = (int)($npc['tile_x'] ?? 0); $tileY = (int)($npc['tile_y'] ?? 0);
        if ($action === 'walk') {
            $target = town_npc_array($payload['target'] ?? $payload);
            $toX = filter_var($target['tile_x'] ?? $target['x'] ?? null, FILTER_VALIDATE_INT);
            $toY = filter_var($target['tile_y'] ?? $target['y'] ?? null, FILTER_VALIDATE_INT);
            if ($toX === false || $toY === false) throw new ApiError('invalid_target', 'A walk target is required.', 400);
            $route = town_navigation_find_route($pdo, $tileX, $tileY, (int)$toX, (int)$toY, town_navigation_capabilities($npc['capabilities_json'] ?? []));
            if (!$route['found']) throw new ApiError('no_route', 'NPC cannot reach the requested target.', 409);
            $tileX = (int)$toX; $tileY = (int)$toY; $payload['route'] = $route['path']; $payload['navigation_version'] = $route['navigation_version'];
        }
        if (in_array($action, ['work', 'talk'], true)) {
            $target = town_npc_array($payload['target'] ?? []);
            $targetId = (string)($payload['target_id'] ?? '');
            if ($targetId === '') $targetId = town_npc_schedule_target($pdo, $npcId, $action) ?? '';
            if ($targetId !== '') $payload['target_id'] = $targetId;
            $toX = filter_var($target['tile_x'] ?? $target['x'] ?? null, FILTER_VALIDATE_INT);
            $toY = filter_var($target['tile_y'] ?? $target['y'] ?? null, FILTER_VALIDATE_INT);
            if ($toX === false || $toY === false) {
                $named = town_npc_target_point($targetId);
                $toX = $named['tile_x'] ?? false; $toY = $named['tile_y'] ?? false;
            }
            if ($toX !== false && $toY !== false && ((int)$toX !== $tileX || (int)$toY !== $tileY)) {
                $route = town_navigation_find_route($pdo, $tileX, $tileY, (int)$toX, (int)$toY, town_navigation_capabilities($npc['capabilities_json'] ?? []));
                if (!$route['found']) throw new ApiError('no_route', 'NPC cannot reach the requested work or talk target.', 409);
                $tileX = (int)$toX; $tileY = (int)$toY; $payload['route'] = $route['path']; $payload['navigation_version'] = $route['navigation_version'];
            }
        }
        $dialogue = (string)($payload['dialogue'] ?? '');
        $dialogue = function_exists('mb_substr') ? mb_substr($dialogue, 0, 500, 'UTF-8') : substr($dialogue, 0, 500);
        if ($action === 'talk' || $dialogue !== '') $payload['dialogue'] = $dialogue;
        $runId = is_string($payload['run_id'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/D', (string)$payload['run_id']) ? (string)$payload['run_id'] : null;
        $mood = town_npc_array($payload['mood'] ?? []);
        $from = ['tile_x' => (int)($npc['tile_x'] ?? 0), 'tile_y' => (int)($npc['tile_y'] ?? 0)];
        $to = ['tile_x' => $tileX, 'tile_y' => $tileY];
        $route = is_array($payload['route'] ?? null) ? $payload['route'] : [];
        $taskId = town_npc_id();
        $task = $pdo->prepare('INSERT INTO town_npc_tasks (task_id,npc_id,action,target_id,payload_json,status,attempts,scheduled_at) VALUES (:task,:npc,:action,:target,:payload,\'completed\',1,:scheduled)');
        $task->execute([':task' => $taskId, ':npc' => $npcId, ':action' => $action, ':target' => (string)($payload['target_id'] ?? ''), ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':scheduled' => auth_sql_time($now)]);
        $state = $action === 'walk' ? 'walk' : $action;
        $moodJson = json_encode($mood, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $update = $pdo->prepare('UPDATE town_npc_runtime_states SET tile_x=:x,tile_y=:y,state=:state,current_task_id=:task,dialogue=:dialogue,mood_json=:mood,state_version=state_version+1,updated_at=:updated WHERE npc_id=:npc');
        $update->execute([':x' => $tileX, ':y' => $tileY, ':state' => $state, ':task' => $taskId, ':dialogue' => $dialogue, ':mood' => $moodJson, ':updated' => auth_sql_time($now), ':npc' => $npcId]);
        if ($update->rowCount() === 0) {
            $insert = $pdo->prepare('INSERT INTO town_npc_runtime_states (npc_id,tile_x,tile_y,state,current_task_id,dialogue,mood_json,capabilities_json,state_version,updated_at) VALUES (:npc,:x,:y,:state,:task,:dialogue,:mood,\'[]\',1,:updated)');
            $insert->execute([':npc' => $npcId, ':x' => $tileX, ':y' => $tileY, ':state' => $state, ':task' => $taskId, ':dialogue' => $dialogue, ':mood' => $moodJson, ':updated' => auth_sql_time($now)]);
        }
        $detail = ['action' => $action, 'state' => $state, 'task_id' => $taskId, 'target_id' => (string)($payload['target_id'] ?? ''), 'from' => $from, 'to' => $to, 'route' => $route, 'route_length' => count($route), 'dialogue' => $dialogue, 'mood' => $mood, 'job' => (string)($payload['job'] ?? ''), 'navigation_version' => $payload['navigation_version'] ?? null];
        // Keep the validated route in the incremental runtime event so
        // clients can animate each grid step instead of jumping to the
        // final tile. The server remains authoritative for the route.
        town_npc_emit_event($pdo, $npcId, 'state_changed', ['state' => $state, 'tile_x' => $tileX, 'tile_y' => $tileY, 'task_id' => $taskId, 'dialogue' => $dialogue, 'mood' => $mood, 'route' => $route, 'navigation_version' => $payload['navigation_version'] ?? null], $now);
        town_npc_activity_log($pdo, $npcId, 'action', $action . ' · ' . ($payload['target_id'] ?? '无目标'), $detail, $now, $runId);
        if ($dialogue !== '') town_npc_activity_log($pdo, $npcId, 'speech', $dialogue, ['dialogue' => $dialogue, 'action' => $action, 'task_id' => $taskId], $now, $runId);
        if ($route !== []) town_npc_activity_log($pdo, $npcId, 'path', '从 (' . $from['tile_x'] . ',' . $from['tile_y'] . ') 走到 (' . $to['tile_x'] . ',' . $to['tile_y'] . ')', ['from' => $from, 'to' => $to, 'route' => $route, 'route_length' => count($route), 'navigation_version' => $payload['navigation_version'] ?? null], $now, $runId);
        if ($mood !== []) town_npc_activity_log($pdo, $npcId, 'mood', '心情更新', ['mood' => $mood, 'action' => $action], $now, $runId);
        $pdo->commit();
        return [
            'ok' => true,
            'npc_id' => $npcId,
            'action' => $action,
            'state' => $state,
            'task_id' => $taskId,
            'tile_x' => $tileX,
            'tile_y' => $tileY,
            'from' => $from,
            'to' => $to,
            'route' => $route,
            'dialogue' => $dialogue,
            'mood' => $mood,
            'job' => (string)($payload['job'] ?? ''),
            'navigation_version' => town_navigation_version($pdo),
        ];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function town_npc_upsert(PDO $pdo, array $body, DateTimeImmutable $now, ?string $existingId = null): array
{
    $name = trim((string)($body['name'] ?? '')); $occupation = trim((string)($body['occupation'] ?? 'farmer'));
    if ($name === '' || strlen($name) > 80 || !in_array($occupation, TOWN_NPC_OCCUPATIONS, true)) throw new ApiError('invalid_npc', 'NPC name or occupation is invalid.', 400);
    $id = $existingId ?? town_npc_id(); $json = static fn(mixed $value): string => json_encode(is_array($value) ? $value : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $pdo->beginTransaction();
    try {
        if ($existingId === null) {
            $pdo->prepare('INSERT INTO town_npcs (npc_id,world_id,name,avatar_asset_id,appearance_json,occupation,enabled,agent_paused,created_at,updated_at) VALUES (:id,\'main\',:name,:avatar,:appearance,:occupation,1,0,:created,:updated)')->execute([':id' => $id, ':name' => $name, ':avatar' => (string)($body['avatar_asset_id'] ?? ''), ':appearance' => $json($body['appearance'] ?? []), ':occupation' => $occupation, ':created' => auth_sql_time($now), ':updated' => auth_sql_time($now)]);
        } else {
            $pdo->prepare('UPDATE town_npcs SET name=:name,avatar_asset_id=:avatar,appearance_json=:appearance,occupation=:occupation,enabled=:enabled,agent_paused=:paused,updated_at=:updated WHERE npc_id=:id')->execute([':id' => $id, ':name' => $name, ':avatar' => (string)($body['avatar_asset_id'] ?? ''), ':appearance' => $json($body['appearance'] ?? []), ':occupation' => $occupation, ':enabled' => (int)(bool)($body['enabled'] ?? true), ':paused' => (int)(bool)($body['agent_paused'] ?? false), ':updated' => auth_sql_time($now)]);
        }
        $profile = $body['profile'] ?? [];
        $pdo->prepare('INSERT INTO town_npc_profiles (npc_id,personality_json,family_background,short_term_goal,long_term_goal,speech_style,values_json,fears_json) VALUES (:id,:personality,:family,:short,:long,:speech,:values,:fears) ON DUPLICATE KEY UPDATE personality_json=VALUES(personality_json),family_background=VALUES(family_background),short_term_goal=VALUES(short_term_goal),long_term_goal=VALUES(long_term_goal),speech_style=VALUES(speech_style),values_json=VALUES(values_json),fears_json=VALUES(fears_json)')->execute([':id' => $id, ':personality' => $json($profile['personality'] ?? []), ':family' => (string)($profile['family_background'] ?? ''), ':short' => (string)($profile['short_term_goal'] ?? ''), ':long' => (string)($profile['long_term_goal'] ?? ''), ':speech' => (string)($profile['speech_style'] ?? ''), ':values' => $json($profile['values'] ?? []), ':fears' => $json($profile['fears'] ?? [])]);
        if (is_array($body['schedule'] ?? null)) {
            $pdo->prepare('DELETE FROM town_npc_schedules WHERE npc_id=:id')->execute([':id' => $id]);
            $insert = $pdo->prepare('INSERT INTO town_npc_schedules (npc_id,weekday,start_minute,end_minute,action,target_id,job,priority) VALUES (:id,:weekday,:start,:end,:action,:target,:job,:priority)');
            foreach ($body['schedule'] as $entry) if (is_array($entry) && in_array((string)($entry['action'] ?? ''), TOWN_NPC_ACTIONS, true)) $insert->execute([':id' => $id, ':weekday' => max(0, min(6, (int)($entry['weekday'] ?? 0))), ':start' => max(0, min(1439, (int)($entry['start_minute'] ?? 0))), ':end' => max(0, min(1440, (int)($entry['end_minute'] ?? 1440))), ':action' => (string)$entry['action'], ':target' => (string)($entry['target_id'] ?? ''), ':job' => (string)($entry['job'] ?? ''), ':priority' => max(0, min(255, (int)($entry['priority'] ?? 0)))]);
        }
        $runtime = $pdo->prepare('INSERT IGNORE INTO town_npc_runtime_states (npc_id,tile_x,tile_y,state,capabilities_json,state_version,updated_at) VALUES (:id,:x,:y,\'rest\',\'[]\',1,:updated)');
        $runtime->execute([':id' => $id, ':x' => max(0, (int)($body['tile_x'] ?? 0)), ':y' => max(0, (int)($body['tile_y'] ?? 0)), ':updated' => auth_sql_time($now)]);
        $pdo->commit();
        $row = $pdo->prepare('SELECT n.*,p.personality_json,p.family_background,p.short_term_goal,p.long_term_goal,p.speech_style,p.values_json,p.fears_json,r.tile_x,r.tile_y,r.state AS runtime_state,r.current_task_id,r.dialogue,r.mood_json,r.capabilities_json,r.state_version,r.updated_at AS runtime_updated_at FROM town_npcs n LEFT JOIN town_npc_profiles p ON p.npc_id=n.npc_id LEFT JOIN town_npc_runtime_states r ON r.npc_id=n.npc_id WHERE n.npc_id=:id'); $row->execute([':id' => $id]);
        return town_npc_public($row->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
