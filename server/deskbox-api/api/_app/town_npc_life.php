<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }

/** Optional first-version life tables are queried defensively during rolling upgrades. */
function town_npc_life_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $table;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $query = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:table");
            $query->execute([':table' => $table]);
        } else {
            $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');
            $query->execute([':table' => $table]);
        }
        return $cache[$key] = (int)$query->fetchColumn() > 0;
    } catch (Throwable) {
        return $cache[$key] = false;
    }
}

/** Check an optional rolling-migration column without making old installs fail. */
function town_npc_life_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . ':' . $table . ':' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $rows = $pdo->query('PRAGMA table_info(' . preg_replace('/[^a-zA-Z0-9_]/', '', $table) . ')')->fetchAll(PDO::FETCH_ASSOC);
            return $cache[$key] = in_array($column, array_column($rows, 'name'), true);
        }
        $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:table_name AND column_name=:column_name');
        $query->execute([':table_name' => $table, ':column_name' => $column]);
        return $cache[$key] = (int)$query->fetchColumn() > 0;
    } catch (PDOException) {
        return $cache[$key] = false;
    }
}

function town_npc_life_json(mixed $value): string
{
    return json_encode(is_array($value) ? $value : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function town_npc_life_policy_defaults(): array
{
    return ['decision_interval_seconds' => 60, 'observation_interval_seconds' => 300, 'dialogue_cooldown_seconds' => 60, 'max_decisions_per_hour' => 60];
}

/**
 * P2-1 resident lifecycle states.  The first slice intentionally excludes
 * irreversible death and replacement; every supported state can return to
 * active through an explicit server-side transition.
 */
function town_npc_lifecycle_default(): array
{
    return [
        'state' => 'active', 'reason_code' => '', 'source_event_id' => null,
        'started_at' => '', 'expected_return_at' => null, 'replacement_npc_id' => null,
        'revision' => 1, 'updated_at' => '',
    ];
}

function town_npc_lifecycle(PDO $pdo, string $npcId): array
{
    $fallback = town_npc_lifecycle_default();
    if (!town_npc_life_table_exists($pdo, 'town_npc_lifecycle')) return $fallback;
    try {
        $query = $pdo->prepare('SELECT npc_id,state,reason_code,source_event_id,started_at,expected_return_at,replacement_npc_id,revision,updated_at FROM town_npc_lifecycle WHERE npc_id=:npc LIMIT 1');
        $query->execute([':npc' => $npcId]); $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return $fallback;
        return [
            'state' => (string)($row['state'] ?? 'active'),
            'reason_code' => (string)($row['reason_code'] ?? ''),
            'source_event_id' => $row['source_event_id'] !== null ? (int)$row['source_event_id'] : null,
            'started_at' => (string)($row['started_at'] ?? ''),
            'expected_return_at' => $row['expected_return_at'] !== null ? (string)$row['expected_return_at'] : null,
            'replacement_npc_id' => $row['replacement_npc_id'] !== null ? (string)$row['replacement_npc_id'] : null,
            'revision' => max(1, (int)($row['revision'] ?? 1)),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    } catch (PDOException) { return $fallback; }
}

function town_npc_lifecycle_transition_allowed(string $from, string $to): bool
{
    if ($from === $to) return true;
    return match ($from) {
        'active' => in_array($to, ['absent', 'injured'], true),
        'absent' => in_array($to, ['returning', 'active'], true),
        'injured' => in_array($to, ['recovering', 'active'], true),
        'recovering', 'returning' => $to === 'active',
        default => false,
    };
}

/**
 * Cancel all runtime work owned by a resident that is leaving the world.
 * This helper is deliberately usable inside an existing transaction: the
 * lifecycle row, task, work-point reservation, inventory refund and runtime
 * pointer must change atomically.
 */
function town_npc_lifecycle_cancel_runtime(PDO $pdo, string $npcId, string $reason, DateTimeImmutable $now): array
{
    if (!town_npc_life_table_exists($pdo, 'town_npc_runtime_states')) return [];
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $runtimeQuery = $pdo->prepare('SELECT tile_x,tile_y,current_task_id FROM town_npc_runtime_states WHERE npc_id=:npc' . $lock . ' LIMIT 1');
    $runtimeQuery->execute([':npc' => $npcId]);
    $runtime = $runtimeQuery->fetch(PDO::FETCH_ASSOC);
    if (!is_array($runtime)) return [];

    $taskId = trim((string)($runtime['current_task_id'] ?? ''));
    $taskAction = '';
    $taskStatus = '';
    if ($taskId !== '' && town_npc_life_table_exists($pdo, 'town_npc_tasks')) {
        $taskQuery = $pdo->prepare('SELECT action,status,payload_json FROM town_npc_tasks WHERE task_id=:task AND npc_id=:npc' . $lock . ' LIMIT 1');
        $taskQuery->execute([':task' => $taskId, ':npc' => $npcId]);
        $task = $taskQuery->fetch(PDO::FETCH_ASSOC);
        if (is_array($task)) {
            $taskAction = (string)($task['action'] ?? '');
            $taskStatus = (string)($task['status'] ?? '');
            if ($taskStatus === 'running') {
                $payload = json_decode((string)($task['payload_json'] ?? '{}'), true);
                $consumed = is_array($payload) && is_array($payload['consumed_items'] ?? null) ? $payload['consumed_items'] : [];
                if ($consumed !== [] && function_exists('town_npc_refund_route_items')) town_npc_refund_route_items($pdo, $npcId, $consumed, $now);
                if (function_exists('town_npc_release_work_point')) town_npc_release_work_point($pdo, $taskId, 'failed', $now);
                $pdo->prepare("UPDATE town_npc_tasks SET status='cancelled',failure_reason=:reason,completed_at=:completed WHERE task_id=:task AND npc_id=:npc AND status='running'")
                    ->execute([':reason' => substr($reason, 0, 255), ':completed' => auth_sql_time($now), ':task' => $taskId, ':npc' => $npcId]);
                if (function_exists('town_npc_sync_social_task_if_available')) town_npc_sync_social_task_if_available($pdo, $taskId, $npcId, 'cancelled', $reason, $now);
                try {
                    if (function_exists('town_npc_emit_event')) town_npc_emit_event($pdo, $npcId, 'task_cancelled', [
                        'task_id' => $taskId, 'action' => $taskAction, 'reason' => $reason,
                        'tile_x' => (int)$runtime['tile_x'], 'tile_y' => (int)$runtime['tile_y'],
                    ], $now);
                } catch (Throwable) { }
                $taskStatus = 'cancelled';
            }
        }
    }
    $pdo->prepare("UPDATE town_npc_runtime_states SET current_task_id=NULL,state='rest',dialogue='',state_version=state_version+1,updated_at=:updated WHERE npc_id=:npc")
        ->execute([':updated' => auth_sql_time($now), ':npc' => $npcId]);
    return [
        'task_id' => $taskId !== '' ? $taskId : null, 'task_action' => $taskAction,
        'task_status' => $taskStatus, 'tile_x' => (int)$runtime['tile_x'], 'tile_y' => (int)$runtime['tile_y'],
    ];
}

/**
 * Change lifecycle and public event atomically.  Repeating the same state
 * with the same reason and return time is idempotent and creates no duplicate
 * event, which is important when an admin retries a request after a timeout.
 */
function town_npc_transition_lifecycle(PDO $pdo, string $npcId, string $state, string $reasonCode, DateTimeImmutable|string|null $expectedReturnAt, DateTimeImmutable $now, ?string $actorId = null): array
{
    if (!town_npc_life_table_exists($pdo, 'town_npc_lifecycle')) throw new ApiError('npc_lifecycle_migration_required', '请先执行居民生命周期迁移。', 503);
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $npcId)) throw new ApiError('invalid_npc', '村民 ID 无效。', 400);
    $state = strtolower(trim($state));
    if (!in_array($state, ['active', 'absent', 'injured', 'recovering', 'returning'], true)) throw new ApiError('invalid_lifecycle_state', '居民生命周期状态无效。', 400);
    $reasonCode = substr(trim($reasonCode), 0, 64);
    $returnAt = null;
    if ($expectedReturnAt instanceof DateTimeImmutable) $returnAt = $expectedReturnAt->setTimezone(new DateTimeZone('UTC'));
    elseif (is_string($expectedReturnAt) && trim($expectedReturnAt) !== '') {
        try { $returnAt = new DateTimeImmutable($expectedReturnAt, new DateTimeZone('UTC')); }
        catch (Exception) { throw new ApiError('invalid_lifecycle_return_at', '预计恢复时间无效。', 400); }
    }
    if ($state === 'active' && $returnAt !== null) throw new ApiError('invalid_lifecycle_return_at', 'active 状态不能设置预计恢复时间。', 400);
    if ($state !== 'active' && $returnAt !== null && $returnAt <= $now) throw new ApiError('invalid_lifecycle_return_at', '预计恢复时间必须晚于当前时间。', 400);
    $nowSql = auth_sql_time($now); $returnSql = $returnAt ? auth_sql_time($returnAt) : null;
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $exists = $pdo->prepare('SELECT npc_id FROM town_npcs WHERE npc_id=:npc LIMIT 1'); $exists->execute([':npc' => $npcId]);
        if (!$exists->fetchColumn()) throw new ApiError('npc_not_found', '村民不存在。', 404);
        $query = $pdo->prepare('SELECT state,reason_code,expected_return_at,revision,started_at FROM town_npc_lifecycle WHERE npc_id=:npc' . $lock); $query->execute([':npc' => $npcId]);
        $current = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)) {
            $current = ['state' => 'active', 'reason_code' => '', 'expected_return_at' => null, 'revision' => 1, 'started_at' => $nowSql];
            $insert = $pdo->prepare('INSERT INTO town_npc_lifecycle (npc_id,state,reason_code,started_at,revision,updated_at) VALUES (:npc,\'active\',\'\',:started,1,:updated)');
            $insert->execute([':npc' => $npcId, ':started' => $nowSql, ':updated' => $nowSql]);
        }
        $currentReturn = $current['expected_return_at'] !== null ? (string)$current['expected_return_at'] : null;
        if ((string)$current['state'] === $state && (string)$current['reason_code'] === $reasonCode && $currentReturn === $returnSql) {
            $pdo->commit(); return town_npc_lifecycle($pdo, $npcId);
        }
        if (!town_npc_lifecycle_transition_allowed((string)$current['state'], $state)) throw new ApiError('invalid_lifecycle_transition', '居民不能从当前状态转换到目标状态。', 409);
        $revision = max(1, (int)$current['revision']) + 1;
        if (in_array($state, ['absent', 'injured'], true)) town_npc_lifecycle_cancel_runtime($pdo, $npcId, 'lifecycle_' . $state, $now);
        $update = $pdo->prepare('UPDATE town_npc_lifecycle SET state=:state,reason_code=:reason,started_at=:started,expected_return_at=:return_at,revision=:revision,updated_at=:updated WHERE npc_id=:npc');
        $update->execute([':state' => $state, ':reason' => $reasonCode, ':started' => $nowSql, ':return_at' => $returnSql, ':revision' => $revision, ':updated' => $nowSql, ':npc' => $npcId]);
        $eventId = null;
        try {
            $eventId = town_npc_emit_event($pdo, $npcId, 'lifecycle_changed', [
                'state' => $state, 'previous_state' => (string)$current['state'], 'reason_code' => $reasonCode,
                'expected_return_at' => $returnSql, 'lifecycle_revision' => $revision, 'actor_id' => $actorId,
            ], $now);
        } catch (PDOException) { }
        if ($eventId !== null) $pdo->prepare('UPDATE town_npc_lifecycle SET source_event_id=:event WHERE npc_id=:npc')->execute([':event' => $eventId, ':npc' => $npcId]);
        try { town_npc_activity_log($pdo, $npcId, 'lifecycle', '居民状态：' . $state, ['state' => $state, 'previous_state' => (string)$current['state'], 'reason_code' => $reasonCode, 'expected_return_at' => $returnSql, 'actor_id' => $actorId, 'revision' => $revision], $now); } catch (PDOException) { }
        $pdo->commit();
        return town_npc_lifecycle($pdo, $npcId);
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

/** Advance only time-based absence/injury transitions; no model is involved. */
function town_npc_recover_due(PDO $pdo, DateTimeImmutable $now): array
{
    if (!town_npc_life_table_exists($pdo, 'town_npc_lifecycle')) return [];
    $query = $pdo->prepare("SELECT npc_id,state FROM town_npc_lifecycle WHERE (state IN ('absent','injured') AND expected_return_at IS NOT NULL AND expected_return_at<=:now) OR state IN ('returning','recovering') ORDER BY npc_id");
    $query->execute([':now' => auth_sql_time($now)]); $changed = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $npcId = (string)$row['npc_id'];
        $currentState = (string)$row['state'];
        try {
            if (in_array($currentState, ['absent', 'injured'], true)) {
                $next = $currentState === 'absent' ? 'returning' : 'recovering';
                $lifecycle = town_npc_transition_lifecycle($pdo, $npcId, $next, 'return_window_reached', null, $now, 'scheduler');
                $changed[] = town_npc_lifecycle_realtime_payload($pdo, $npcId, $lifecycle);
            }
            $lifecycle = town_npc_transition_lifecycle($pdo, $npcId, 'active', 'recovered', null, $now, 'scheduler');
            $changed[] = town_npc_lifecycle_realtime_payload($pdo, $npcId, $lifecycle);
        }
        catch (ApiError $error) { if ($error->apiCode !== 'invalid_lifecycle_transition') throw $error; }
    }
    return $changed;
}

/** Add the authoritative coordinates needed by a realtime lifecycle delta. */
function town_npc_lifecycle_realtime_payload(PDO $pdo, string $npcId, array $lifecycle): array
{
    $payload = $lifecycle + ['npc_id' => $npcId];
    if (town_npc_life_table_exists($pdo, 'town_npc_runtime_states')) {
        try {
            $query = $pdo->prepare('SELECT tile_x,tile_y,state,current_task_id,dialogue FROM town_npc_runtime_states WHERE npc_id=:npc LIMIT 1');
            $query->execute([':npc' => $npcId]);
            $runtime = $query->fetch(PDO::FETCH_ASSOC);
            if (is_array($runtime)) {
                $payload['tile_x'] = (int)$runtime['tile_x'];
                $payload['tile_y'] = (int)$runtime['tile_y'];
                $payload['runtime_state'] = (string)($runtime['state'] ?? 'rest');
                $payload['current_task_id'] = $runtime['current_task_id'] !== null ? (string)$runtime['current_task_id'] : null;
                $payload['dialogue'] = (string)($runtime['dialogue'] ?? '');
            }
        } catch (PDOException) { }
    }
    return $payload;
}

/** Return the world-wide frequency limits. Missing rolling migrations use safe defaults. */
function town_npc_life_global_policy(PDO $pdo): array
{
    $defaults = town_npc_life_policy_defaults();
    if (!town_npc_life_table_exists($pdo, 'town_agent_global_policies')) return $defaults + ['enabled' => false];
    $world = defined('SHARED_TOWN_WORLD_ID') ? SHARED_TOWN_WORLD_ID : 'main';
    $query = $pdo->prepare('SELECT enabled,decision_interval_seconds,observation_interval_seconds,dialogue_cooldown_seconds,max_decisions_per_hour FROM town_agent_global_policies WHERE world_id=:world LIMIT 1');
    $query->execute([':world' => $world]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return $defaults + ['enabled' => false];
    return [
        'enabled' => (bool)$row['enabled'],
        'decision_interval_seconds' => max(5, min(86400, (int)$row['decision_interval_seconds'])),
        'observation_interval_seconds' => max(5, min(86400, (int)$row['observation_interval_seconds'])),
        'dialogue_cooldown_seconds' => max(0, min(86400, (int)$row['dialogue_cooldown_seconds'])),
        'max_decisions_per_hour' => max(1, min(10000, (int)$row['max_decisions_per_hour'])),
    ];
}

function town_npc_player_character(PDO $pdo, string $userId): ?array
{
    if (!town_npc_life_table_exists($pdo, 'town_player_characters')) return null;
    $query = $pdo->prepare('SELECT world_id,user_id,display_name,appearance_asset_id,appearance_json,created_at,updated_at FROM town_player_characters WHERE world_id=:world AND user_id=:user LIMIT 1');
    $query->execute([':world' => defined('SHARED_TOWN_WORLD_ID') ? SHARED_TOWN_WORLD_ID : 'main', ':user' => $userId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;
    return [
        'world_id' => (string)$row['world_id'], 'user_id' => (string)$row['user_id'],
        'display_name' => (string)$row['display_name'], 'appearance_asset_id' => (string)$row['appearance_asset_id'],
        'appearance' => town_npc_decode($row['appearance_json'] ?? [], []),
        'created_at' => (string)$row['created_at'], 'updated_at' => (string)$row['updated_at'],
    ];
}

/** Create the account-scoped player character on first town entry. */
function town_npc_ensure_player_character(PDO $pdo, string $userId, string $displayName, DateTimeImmutable $now): array
{
    $existing = town_npc_player_character($pdo, $userId);
    if ($existing !== null) return $existing;
    if (!town_npc_life_table_exists($pdo, 'town_player_characters')) {
        return ['world_id' => defined('SHARED_TOWN_WORLD_ID') ? SHARED_TOWN_WORLD_ID : 'main', 'user_id' => $userId,
            'display_name' => $displayName !== '' ? $displayName : '玩家', 'appearance_asset_id' => '', 'appearance' => [],
            'created_at' => auth_sql_time($now), 'updated_at' => auth_sql_time($now)];
    }
    $displayName = trim($displayName) !== '' ? trim($displayName) : '玩家';
    $displayName = function_exists('mb_substr') ? mb_substr($displayName, 0, 64, 'UTF-8') : substr($displayName, 0, 64);
    $world = defined('SHARED_TOWN_WORLD_ID') ? SHARED_TOWN_WORLD_ID : 'main';
    $sql = 'INSERT INTO town_player_characters (world_id,user_id,display_name,appearance_asset_id,appearance_json,created_at,updated_at) VALUES (:world,:user,:name,\'\',:appearance,:created,:updated)';
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') $sql .= ' ON CONFLICT(world_id,user_id) DO NOTHING';
    else $sql .= ' ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)';
    $pdo->prepare($sql)->execute([':world' => $world, ':user' => $userId, ':name' => $displayName, ':appearance' => '{}', ':created' => auth_sql_time($now), ':updated' => auth_sql_time($now)]);
    return town_npc_player_character($pdo, $userId) ?? ['world_id' => $world, 'user_id' => $userId, 'display_name' => $displayName, 'appearance_asset_id' => '', 'appearance' => [], 'created_at' => auth_sql_time($now), 'updated_at' => auth_sql_time($now)];
}

function town_npc_life_home(PDO $pdo, string $npcId): ?array
{
    if (!town_npc_life_table_exists($pdo, 'town_npc_homes')) return null;
    $query = $pdo->prepare('SELECT npc_id,home_asset_id,home_asset_name,tile_x,tile_y,interaction_json,updated_at FROM town_npc_homes WHERE npc_id=:npc LIMIT 1');
    $query->execute([':npc' => $npcId]); $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;
    return ['npc_id' => (string)$row['npc_id'], 'home_asset_id' => (string)$row['home_asset_id'], 'home_asset_name' => (string)$row['home_asset_name'],
        'tile_x' => (int)$row['tile_x'], 'tile_y' => (int)$row['tile_y'], 'interaction' => town_npc_decode($row['interaction_json'] ?? [], []), 'updated_at' => (string)$row['updated_at']];
}

function town_npc_life_policy_override(PDO $pdo, string $npcId): array
{
    $defaults = town_npc_life_policy_defaults();
    if (!town_npc_life_table_exists($pdo, 'town_npc_decision_policies')) return $defaults;
    $query = $pdo->prepare('SELECT decision_interval_seconds,observation_interval_seconds,dialogue_cooldown_seconds,max_decisions_per_hour FROM town_npc_decision_policies WHERE npc_id=:npc LIMIT 1');
    $query->execute([':npc' => $npcId]); $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return $defaults;
    return ['decision_interval_seconds' => (int)$row['decision_interval_seconds'], 'observation_interval_seconds' => (int)$row['observation_interval_seconds'], 'dialogue_cooldown_seconds' => (int)$row['dialogue_cooldown_seconds'], 'max_decisions_per_hour' => (int)$row['max_decisions_per_hour']];
}

/**
 * Effective per-NPC policy. Per-NPC values may be stricter, but never bypass
 * the world-wide minimum intervals or hourly decision ceiling.
 */
function town_npc_life_policy(PDO $pdo, string $npcId): array
{
    $npc = town_npc_life_policy_override($pdo, $npcId);
    $global = town_npc_life_global_policy($pdo);
    if (!($global['enabled'] ?? false)) return $npc;
    return [
        'decision_interval_seconds' => max($npc['decision_interval_seconds'], $global['decision_interval_seconds']),
        'observation_interval_seconds' => max($npc['observation_interval_seconds'], $global['observation_interval_seconds']),
        'dialogue_cooldown_seconds' => max($npc['dialogue_cooldown_seconds'], $global['dialogue_cooldown_seconds']),
        'max_decisions_per_hour' => min($npc['max_decisions_per_hour'], $global['max_decisions_per_hour']),
    ];
}

function town_npc_save_global_policy(PDO $pdo, array $policy, DateTimeImmutable $now): array
{
    if (!town_npc_life_table_exists($pdo, 'town_agent_global_policies')) throw new ApiError('npc_life_migration_required', '请先执行共享小镇全局 Agent 策略迁移。', 503);
    $values = [
        'decision_interval_seconds' => max(5, min(86400, (int)($policy['decision_interval_seconds'] ?? 60))),
        'observation_interval_seconds' => max(5, min(86400, (int)($policy['observation_interval_seconds'] ?? 300))),
        'dialogue_cooldown_seconds' => max(0, min(86400, (int)($policy['dialogue_cooldown_seconds'] ?? 60))),
        'max_decisions_per_hour' => max(1, min(10000, (int)($policy['max_decisions_per_hour'] ?? 60))),
    ];
    $world = defined('SHARED_TOWN_WORLD_ID') ? SHARED_TOWN_WORLD_ID : 'main';
    $sql = 'INSERT INTO town_agent_global_policies (world_id,enabled,decision_interval_seconds,observation_interval_seconds,dialogue_cooldown_seconds,max_decisions_per_hour,updated_at) VALUES (:world,:enabled,:decision,:observation,:dialogue,:max,:updated)';
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $sql .= ' ON CONFLICT(world_id) DO UPDATE SET enabled=excluded.enabled,decision_interval_seconds=excluded.decision_interval_seconds,observation_interval_seconds=excluded.observation_interval_seconds,dialogue_cooldown_seconds=excluded.dialogue_cooldown_seconds,max_decisions_per_hour=excluded.max_decisions_per_hour,updated_at=excluded.updated_at';
    } else {
        $sql .= ' ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),decision_interval_seconds=VALUES(decision_interval_seconds),observation_interval_seconds=VALUES(observation_interval_seconds),dialogue_cooldown_seconds=VALUES(dialogue_cooldown_seconds),max_decisions_per_hour=VALUES(max_decisions_per_hour),updated_at=VALUES(updated_at)';
    }
    $pdo->prepare($sql)->execute([':world' => $world, ':enabled' => !empty($policy['enabled']) ? 1 : 0, ':decision' => $values['decision_interval_seconds'], ':observation' => $values['observation_interval_seconds'], ':dialogue' => $values['dialogue_cooldown_seconds'], ':max' => $values['max_decisions_per_hour'], ':updated' => auth_sql_time($now)]);
    return town_npc_life_global_policy($pdo);
}

function town_npc_life_wallet(PDO $pdo, string $npcId): array
{
    if (!town_npc_life_table_exists($pdo, 'town_npc_wallets')) return ['npc_id' => $npcId, 'balance' => 0, 'initialized_by' => null, 'updated_at' => ''];
    $query = $pdo->prepare('SELECT npc_id,balance,initialized_by,updated_at FROM town_npc_wallets WHERE npc_id=:npc LIMIT 1');
    $query->execute([':npc' => $npcId]); $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? ['npc_id' => (string)$row['npc_id'], 'balance' => (int)$row['balance'], 'initialized_by' => $row['initialized_by'] !== null ? (string)$row['initialized_by'] : null, 'updated_at' => (string)$row['updated_at']] : ['npc_id' => $npcId, 'balance' => 0, 'initialized_by' => null, 'updated_at' => ''];
}

/** Attach optional life data without making old rolling schemas unreadable. */
function town_npc_attach_life_data(PDO $pdo, array $rows): array
{
    foreach ($rows as $index => $row) {
        $id = (string)($row['npc_id'] ?? '');
        $home = town_npc_life_home($pdo, $id);
        $row['home'] = $home;
        $row['decision_policy'] = town_npc_life_policy($pdo, $id);
        $row['decision_policy_override'] = town_npc_life_policy_override($pdo, $id);
        $row['wallet'] = town_npc_life_wallet($pdo, $id);
        $row['lifecycle'] = town_npc_lifecycle($pdo, $id);
        $rows[$index] = $row;
    }
    return $rows;
}

function town_npc_save_home(PDO $pdo, string $npcId, array $home, DateTimeImmutable $now): array
{
    if (!town_npc_life_table_exists($pdo, 'town_npc_homes')) throw new ApiError('npc_life_migration_required', '请先执行共享小镇生活模型迁移。', 503);
    $exists = $pdo->prepare('SELECT 1 FROM town_npcs WHERE npc_id=:npc LIMIT 1'); $exists->execute([':npc' => $npcId]);
    if (!$exists->fetchColumn()) throw new ApiError('npc_not_found', '村民不存在。', 404);
    $assetId = trim((string)($home['home_asset_id'] ?? '')); $assetName = trim((string)($home['home_asset_name'] ?? ''));
    $x = (int)($home['tile_x'] ?? 0); $y = (int)($home['tile_y'] ?? 0);
    if ($x < 0 || $x >= SHARED_TOWN_MAP_SIZE || $y < 0 || $y >= SHARED_TOWN_MAP_SIZE || strlen($assetId) > 128 || strlen($assetName) > 160) throw new ApiError('invalid_npc_home', 'NPC 家的素材或坐标无效。', 400);
    if ($assetId !== '' && $assetName === '' && town_npc_life_table_exists($pdo, 'town_asset_items')) {
        $lookup = $pdo->prepare('SELECT name FROM town_asset_items WHERE asset_id=:asset LIMIT 1'); $lookup->execute([':asset' => $assetId]); $assetName = (string)($lookup->fetchColumn() ?: '');
    }
    $interaction = is_array($home['interaction'] ?? null) ? $home['interaction'] : [];
    $sql = 'INSERT INTO town_npc_homes (npc_id,home_asset_id,home_asset_name,tile_x,tile_y,interaction_json,updated_at) VALUES (:npc,:asset,:name,:x,:y,:interaction,:updated)';
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') $sql .= ' ON CONFLICT(npc_id) DO UPDATE SET home_asset_id=excluded.home_asset_id,home_asset_name=excluded.home_asset_name,tile_x=excluded.tile_x,tile_y=excluded.tile_y,interaction_json=excluded.interaction_json,updated_at=excluded.updated_at';
    else $sql .= ' ON DUPLICATE KEY UPDATE home_asset_id=VALUES(home_asset_id),home_asset_name=VALUES(home_asset_name),tile_x=VALUES(tile_x),tile_y=VALUES(tile_y),interaction_json=VALUES(interaction_json),updated_at=VALUES(updated_at)';
    $pdo->prepare($sql)->execute([':npc' => $npcId, ':asset' => $assetId, ':name' => $assetName, ':x' => $x, ':y' => $y, ':interaction' => town_npc_life_json($interaction), ':updated' => auth_sql_time($now)]);
    return town_npc_life_home($pdo, $npcId) ?? [];
}

function town_npc_save_decision_policy(PDO $pdo, string $npcId, array $policy, DateTimeImmutable $now): array
{
    if (!town_npc_life_table_exists($pdo, 'town_npc_decision_policies')) throw new ApiError('npc_life_migration_required', '请先执行共享小镇生活模型迁移。', 503);
    $exists = $pdo->prepare('SELECT 1 FROM town_npcs WHERE npc_id=:npc LIMIT 1'); $exists->execute([':npc' => $npcId]);
    if (!$exists->fetchColumn()) throw new ApiError('npc_not_found', '村民不存在。', 404);
    $values = ['decision_interval_seconds' => max(5, min(86400, (int)($policy['decision_interval_seconds'] ?? 60))), 'observation_interval_seconds' => max(5, min(86400, (int)($policy['observation_interval_seconds'] ?? 300))), 'dialogue_cooldown_seconds' => max(0, min(86400, (int)($policy['dialogue_cooldown_seconds'] ?? 60))), 'max_decisions_per_hour' => max(1, min(10000, (int)($policy['max_decisions_per_hour'] ?? 60)))];
    $sql = 'INSERT INTO town_npc_decision_policies (npc_id,decision_interval_seconds,observation_interval_seconds,dialogue_cooldown_seconds,max_decisions_per_hour,updated_at) VALUES (:npc,:decision,:observation,:dialogue,:max,:updated)';
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') $sql .= ' ON CONFLICT(npc_id) DO UPDATE SET decision_interval_seconds=excluded.decision_interval_seconds,observation_interval_seconds=excluded.observation_interval_seconds,dialogue_cooldown_seconds=excluded.dialogue_cooldown_seconds,max_decisions_per_hour=excluded.max_decisions_per_hour,updated_at=excluded.updated_at';
    else $sql .= ' ON DUPLICATE KEY UPDATE decision_interval_seconds=VALUES(decision_interval_seconds),observation_interval_seconds=VALUES(observation_interval_seconds),dialogue_cooldown_seconds=VALUES(dialogue_cooldown_seconds),max_decisions_per_hour=VALUES(max_decisions_per_hour),updated_at=VALUES(updated_at)';
    $pdo->prepare($sql)->execute([':npc' => $npcId, ':decision' => $values['decision_interval_seconds'], ':observation' => $values['observation_interval_seconds'], ':dialogue' => $values['dialogue_cooldown_seconds'], ':max' => $values['max_decisions_per_hour'], ':updated' => auth_sql_time($now)]);
    return town_npc_life_policy($pdo, $npcId);
}

function town_npc_set_wallet(PDO $pdo, string $npcId, int $balance, ?string $actorId, DateTimeImmutable $now): array
{
    $exists = $pdo->prepare('SELECT 1 FROM town_npcs WHERE npc_id=:npc LIMIT 1'); $exists->execute([':npc' => $npcId]);
    if (!$exists->fetchColumn()) throw new ApiError('npc_not_found', '村民不存在。', 404);
    if (!town_npc_life_table_exists($pdo, 'town_npc_wallets')) throw new ApiError('npc_life_migration_required', '请先执行共享小镇生活模型迁移。', 503);
    if ($balance < 0 || $balance > 9223372036854775807) throw new ApiError('invalid_npc_wallet', 'NPC 初始余额无效。', 400);
    $previous = town_npc_life_wallet($pdo, $npcId);
    $sql = 'INSERT INTO town_npc_wallets (npc_id,balance,initialized_by,updated_at) VALUES (:npc,:balance,:actor,:updated)';
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') $sql .= ' ON CONFLICT(npc_id) DO UPDATE SET balance=excluded.balance,initialized_by=excluded.initialized_by,updated_at=excluded.updated_at';
    else $sql .= ' ON DUPLICATE KEY UPDATE balance=VALUES(balance),initialized_by=VALUES(initialized_by),updated_at=VALUES(updated_at)';
    $pdo->prepare($sql)->execute([':npc' => $npcId, ':balance' => $balance, ':actor' => $actorId, ':updated' => auth_sql_time($now)]);
    if (town_npc_life_table_exists($pdo, 'town_npc_wallet_entries') && $balance !== (int)$previous['balance']) {
        $pdo->prepare('INSERT INTO town_npc_wallet_entries (npc_id,entry_type,delta,balance_after,transaction_id,reason,created_at) VALUES (:npc,\'initialize\',:delta,:balance,NULL,:reason,:created)')->execute([':npc' => $npcId, ':delta' => $balance - (int)$previous['balance'], ':balance' => $balance, ':reason' => '管理员初始化 NPC 余额', ':created' => auth_sql_time($now)]);
    }
    return town_npc_life_wallet($pdo, $npcId);
}

function town_npc_set_economy_inventory(PDO $pdo, string $npcId, array $items, DateTimeImmutable $now): array
{
    if (!town_npc_life_table_exists($pdo, 'town_npc_inventory')) throw new ApiError('npc_economy_migration_required', '请先执行 NPC 经济迁移。', 503);
    $exists = $pdo->prepare('SELECT 1 FROM town_npcs WHERE npc_id=:npc LIMIT 1'); $exists->execute([':npc' => $npcId]);
    if (!$exists->fetchColumn()) throw new ApiError('npc_not_found', '村民不存在。', 404);
    $normalized = [];
    foreach ($items as $itemId => $quantity) {
        if (!is_string($itemId) || !preg_match('/\A[a-zA-Z0-9._:-]{1,128}\z/D', trim($itemId))) continue;
        $amount = max(0, min(1000000, (int)$quantity));
        if ($amount > 0) $normalized[trim($itemId)] = $amount;
    }
    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM town_npc_inventory WHERE npc_id=:npc')->execute([':npc' => $npcId]);
        $insert = $pdo->prepare('INSERT INTO town_npc_inventory (npc_id,item_id,quantity,updated_at) VALUES (:npc,:item,:quantity,:updated)');
        foreach ($normalized as $itemId => $quantity) $insert->execute([':npc' => $npcId, ':item' => $itemId, ':quantity' => $quantity, ':updated' => auth_sql_time($now)]);
        if ($startedTransaction) $pdo->commit(); return $normalized;
    } catch (Throwable $error) { if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function town_npc_transaction_rows(PDO $pdo, ?string $npcId = null, int $limit = 100): array
{
    if (!town_npc_life_table_exists($pdo, 'town_npc_transactions')) return [];
    $limit = max(1, min(500, $limit)); $params = []; $where = '';
    if (is_string($npcId) && preg_match('/\A[a-f0-9]{32}\z/D', $npcId)) { $where = ' WHERE t.seller_npc_id=:npc OR t.buyer_npc_id=:npc2'; $params[':npc'] = $npcId; $params[':npc2'] = $npcId; }
    $query = $pdo->prepare('SELECT t.*,s.name AS seller_name,b.name AS buyer_name FROM town_npc_transactions t LEFT JOIN town_npcs s ON s.npc_id=t.seller_npc_id LEFT JOIN town_npcs b ON b.npc_id=t.buyer_npc_id' . $where . ' ORDER BY t.created_at DESC,t.transaction_id DESC LIMIT ' . $limit);
    $query->execute($params); return array_map(static function (array $row): array { $row['quantity'] = (int)$row['quantity']; $row['amount'] = (int)$row['amount']; return $row; }, $query->fetchAll(PDO::FETCH_ASSOC));
}

/** Transfer an NPC item and balance atomically; only the worker/admin may call this. */
function town_npc_trade(PDO $pdo, string $sellerNpcId, string $buyerNpcId, string $itemId, int $quantity, int $amount, string $reason, DateTimeImmutable $now): array
{
    if (!town_npc_life_table_exists($pdo, 'town_npc_transactions') || !town_npc_life_table_exists($pdo, 'town_npc_inventory')) throw new ApiError('npc_economy_migration_required', '请先执行 NPC 经济迁移。', 503);
    $itemId = trim($itemId); $reason = trim($reason);
    if ($sellerNpcId === $buyerNpcId || !preg_match('/\A[a-zA-Z0-9._:-]{1,128}\z/D', $itemId) || $quantity < 1 || $quantity > 1000000 || $amount < 0 || $amount > 9223372036854775807) throw new ApiError('invalid_npc_trade', 'NPC 交易参数无效。', 400);
    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) $pdo->beginTransaction();
    try {
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $exists = $pdo->prepare('SELECT npc_id FROM town_npcs WHERE npc_id IN (:seller,:buyer) AND enabled=1'); $exists->execute([':seller' => $sellerNpcId, ':buyer' => $buyerNpcId]);
        if (count($exists->fetchAll(PDO::FETCH_COLUMN)) !== 2) throw new ApiError('invalid_npc_trade', '交易对象不存在或未启用。', 404);
        $stock = $pdo->prepare('SELECT quantity FROM town_npc_inventory WHERE npc_id=:npc AND item_id=:item' . $lock); $stock->execute([':npc' => $sellerNpcId, ':item' => $itemId]);
        if ((int)$stock->fetchColumn() < $quantity) throw new ApiError('npc_item_insufficient', '卖方库存不足。', 409);
        $buyerWallet = $pdo->prepare('SELECT balance FROM town_npc_wallets WHERE npc_id=:npc' . $lock); $buyerWallet->execute([':npc' => $buyerNpcId]);
        if ((int)$buyerWallet->fetchColumn() < $amount) throw new ApiError('npc_balance_insufficient', '买方余额不足。', 409);
        $nowSql = auth_sql_time($now);
        // MySQL native prepared statements reject reusing the same named
        // placeholder twice. Keep the decrement and guard parameters
        // distinct so the production driver matches the SQLite tests.
        $pdo->prepare('UPDATE town_npc_inventory SET quantity=quantity-:decrement,updated_at=:updated WHERE npc_id=:npc AND item_id=:item AND quantity>=:minimum')->execute([':decrement' => $quantity, ':minimum' => $quantity, ':updated' => $nowSql, ':npc' => $sellerNpcId, ':item' => $itemId]);
        $insertInventory = 'INSERT INTO town_npc_inventory (npc_id,item_id,quantity,updated_at) VALUES (:npc,:item,:quantity,:updated)';
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') $insertInventory .= ' ON CONFLICT(npc_id,item_id) DO UPDATE SET quantity=quantity+excluded.quantity,updated_at=excluded.updated_at';
        else $insertInventory .= ' ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity),updated_at=VALUES(updated_at)';
        $pdo->prepare($insertInventory)->execute([':npc' => $buyerNpcId, ':item' => $itemId, ':quantity' => $quantity, ':updated' => $nowSql]);
        $pdo->prepare('UPDATE town_npc_wallets SET balance=balance-:debit,updated_at=:updated WHERE npc_id=:npc AND balance>=:minimum')->execute([':debit' => $amount, ':minimum' => $amount, ':updated' => $nowSql, ':npc' => $buyerNpcId]);
        $sellerWallet = 'INSERT INTO town_npc_wallets (npc_id,balance,initialized_by,updated_at) VALUES (:npc,:amount,NULL,:updated)';
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') $sellerWallet .= ' ON CONFLICT(npc_id) DO UPDATE SET balance=balance+excluded.balance,updated_at=excluded.updated_at';
        else $sellerWallet .= ' ON DUPLICATE KEY UPDATE balance=balance+VALUES(balance),updated_at=VALUES(updated_at)';
        $pdo->prepare($sellerWallet)->execute([':npc' => $sellerNpcId, ':amount' => $amount, ':updated' => $nowSql]);
        $transactionId = bin2hex(random_bytes(16));
        $pdo->prepare('INSERT INTO town_npc_transactions (transaction_id,seller_npc_id,buyer_npc_id,item_id,quantity,amount,reason,created_at) VALUES (:id,:seller,:buyer,:item,:quantity,:amount,:reason,:created)')->execute([':id' => $transactionId, ':seller' => $sellerNpcId, ':buyer' => $buyerNpcId, ':item' => $itemId, ':quantity' => $quantity, ':amount' => $amount, ':reason' => substr($reason, 0, 255), ':created' => $nowSql]);
        if (town_npc_life_table_exists($pdo, 'town_npc_wallet_entries')) {
            $sellerBalance = (int)town_npc_life_wallet($pdo, $sellerNpcId)['balance']; $buyerBalance = (int)town_npc_life_wallet($pdo, $buyerNpcId)['balance'];
            $entry = $pdo->prepare('INSERT INTO town_npc_wallet_entries (npc_id,entry_type,delta,balance_after,transaction_id,reason,created_at) VALUES (:npc,:type,:delta,:balance,:transaction,:reason,:created)');
            $entry->execute([':npc' => $sellerNpcId, ':type' => 'trade_income', ':delta' => $amount, ':balance' => $sellerBalance, ':transaction' => $transactionId, ':reason' => '出售 ' . $itemId, ':created' => $nowSql]);
            $entry->execute([':npc' => $buyerNpcId, ':type' => 'trade_expense', ':delta' => -$amount, ':balance' => $buyerBalance, ':transaction' => $transactionId, ':reason' => '购买 ' . $itemId, ':created' => $nowSql]);
        }
        if ($startedTransaction) $pdo->commit();
        return ['transaction_id' => $transactionId, 'seller_npc_id' => $sellerNpcId, 'buyer_npc_id' => $buyerNpcId, 'item_id' => $itemId, 'quantity' => $quantity, 'amount' => $amount, 'reason' => substr($reason, 0, 255)];
    } catch (Throwable $error) { if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

/** Add income earned through a completed work point without allowing negatives. */
function town_npc_record_income(PDO $pdo, string $npcId, int $amount, string $reason, DateTimeImmutable $now): int
{
    if ($amount <= 0 || !town_npc_life_table_exists($pdo, 'town_npc_wallets')) return (int)(town_npc_life_wallet($pdo, $npcId)['balance'] ?? 0);
    $nowSql = auth_sql_time($now); $sql = 'INSERT INTO town_npc_wallets (npc_id,balance,initialized_by,updated_at) VALUES (:npc,:amount,NULL,:updated)';
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') $sql .= ' ON CONFLICT(npc_id) DO UPDATE SET balance=balance+excluded.balance,updated_at=excluded.updated_at';
    else $sql .= ' ON DUPLICATE KEY UPDATE balance=balance+VALUES(balance),updated_at=VALUES(updated_at)';
    $pdo->prepare($sql)->execute([':npc' => $npcId, ':amount' => min(1000000000, $amount), ':updated' => $nowSql]);
    $balance = (int)town_npc_life_wallet($pdo, $npcId)['balance'];
    if (town_npc_life_table_exists($pdo, 'town_npc_wallet_entries')) {
        $pdo->prepare('INSERT INTO town_npc_wallet_entries (npc_id,entry_type,delta,balance_after,transaction_id,reason,created_at) VALUES (:npc,\'income\',:delta,:balance,NULL,:reason,:created)')->execute([':npc' => $npcId, ':delta' => min(1000000000, $amount), ':balance' => $balance, ':reason' => substr($reason, 0, 255), ':created' => $nowSql]);
    }
    return $balance;
}

