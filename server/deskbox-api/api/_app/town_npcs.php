<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__ . '/shared_town.php';
require_once __DIR__ . '/town_navigation.php';
require_once __DIR__ . '/town_npc_life.php';
require_once __DIR__ . '/town_world_events.php';
require_once __DIR__ . '/town_world_clock.php';
require_once __DIR__ . '/town_agent.php';
require_once __DIR__ . '/town_npc_stats.php';

const TOWN_NPC_ACTIONS = ['walk', 'work', 'rest', 'talk', 'trade', 'follow', 'player_talk'];
const TOWN_NPC_OCCUPATIONS = ['farmer', 'merchant', 'craftsman'];

/** Apply a transport-safe dialogue filter before text reaches the client. */
function town_npc_moderate_dialogue(string $dialogue): array
{
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', trim($dialogue));
    if (!is_string($clean)) $clean = trim($dialogue);
    if (preg_match('/<\s*(?:script|iframe|object|embed)\b|javascript\s*:|data\s*:\s*text\/html/i', $clean)) {
        return ['allowed' => false, 'text' => '', 'reason' => 'unsafe_markup'];
    }
    $text = function_exists('mb_substr') ? mb_substr($clean, 0, 500, 'UTF-8') : substr($clean, 0, 500);
    return ['allowed' => true, 'text' => $text, 'reason' => ''];
}

/**
 * Validate the bounded plan envelope produced by an Agent before the server
 * derives its authoritative route.  The model may describe intent and a
 * short sequence, but it cannot add actions, capabilities, coordinates, or
 * an invalid ordering that the action endpoint would silently ignore.
 */
function town_npc_validate_plan_envelope(array $payload, string $action, array $availableCapabilities): void
{
    $declaredCapabilities = $payload['required_capabilities'] ?? [];
    if ($declaredCapabilities !== [] && !is_array($declaredCapabilities)) {
        throw new ApiError('invalid_npc_plan', 'required_capabilities must be an array.', 400);
    }
    $available = array_fill_keys(town_navigation_capabilities($availableCapabilities), true);
    foreach (array_values($declaredCapabilities) as $capability) {
        if (!is_string($capability) || !in_array($capability, TOWN_NAVIGATION_CAPABILITIES, true)) {
            throw new ApiError('invalid_npc_plan', 'The NPC plan requested an unsupported capability.', 400);
        }
        // A declared capability must either already be available to the NPC
        // or be one of the capabilities the authoritative route will require.
        // The latter is checked after route planning by the caller.
    }

    // A plan may omit the optional steps array.  Action-specific payloads
    // still need validation in that case; otherwise a trade could reach the
    // executor without its item, quantity, or amount being checked.
    if (!array_key_exists('steps', $payload)) {
        if ($action === 'trade') {
            $trade = town_npc_array($payload['trade'] ?? $payload);
            $item = trim((string)($trade['item_id'] ?? ''));
            $quantity = filter_var($trade['quantity'] ?? null, FILTER_VALIDATE_INT);
            $amount = filter_var($trade['amount'] ?? null, FILTER_VALIDATE_INT);
            if (!preg_match('/\\A[a-zA-Z0-9._:-]{1,128}\\z/D', $item)
                || $quantity === false || $quantity < 1 || $quantity > 1000000
                || $amount === false || $amount < 0) {
                throw new ApiError('invalid_npc_trade', 'trade requires a valid item_id, quantity and amount.', 400);
            }
        }
        return;
    }
    $steps = $payload['steps'];
    if (!is_array($steps) || count($steps) < 1 || count($steps) > 16) {
        throw new ApiError('invalid_npc_plan', 'steps must contain between 1 and 16 actions.', 400);
    }
    $seenRootAction = false;
    $completedAction = false;
    foreach (array_values($steps) as $index => $step) {
        if (!is_array($step)) throw new ApiError('invalid_npc_plan', 'Each NPC step must be an object.', 400);
        $stepAction = is_string($step['action'] ?? null) ? strtolower(trim((string)$step['action'])) : '';
        if (!in_array($stepAction, TOWN_NPC_ACTIONS, true)) {
            throw new ApiError('invalid_npc_plan', 'The NPC plan contains an unsupported step.', 400);
        }
        if ($completedAction) {
            throw new ApiError('invalid_npc_plan', 'A walk step cannot follow the final action.', 400);
        }
        if ($stepAction === 'walk') {
            $target = $step['target'] ?? null;
            if (!is_string($target) || trim($target) === '' || strlen($target) > 128) {
                throw new ApiError('invalid_npc_plan', 'A walk step requires a bounded target id.', 400);
            }
        }
        if (isset($step['target']) && !is_string($step['target']) && !is_array($step['target'])) {
            throw new ApiError('invalid_npc_plan', 'NPC step target is invalid.', 400);
        }
        if (isset($step['job']) && (!is_string($step['job']) || strlen($step['job']) > 64)) {
            throw new ApiError('invalid_npc_plan', 'NPC step job is invalid.', 400);
        }
        if ($stepAction === $action) $seenRootAction = true;
        if ($index === count($steps) - 1) {
            if ($stepAction !== $action) throw new ApiError('invalid_npc_plan', 'The final NPC step must match the requested action.', 400);
            $completedAction = true;
        } elseif ($stepAction !== 'walk') {
            // Work, talk, trade, and rest are terminal steps in this first version;
            // a later action must be represented by a new server task.
            $completedAction = true;
        }
    }
    if (!$seenRootAction) throw new ApiError('invalid_npc_plan', 'The NPC plan does not contain its requested action.', 400);

    if ($action === 'trade') {
        $trade = town_npc_array($payload['trade'] ?? $payload);
        $item = trim((string)($trade['item_id'] ?? ''));
        $quantity = filter_var($trade['quantity'] ?? null, FILTER_VALIDATE_INT);
        $amount = filter_var($trade['amount'] ?? null, FILTER_VALIDATE_INT);
        if (!preg_match('/\A[a-zA-Z0-9._:-]{1,128}\z/D', $item)
            || $quantity === false || $quantity < 1 || $quantity > 1000000
            || $amount === false || $amount < 0) {
            throw new ApiError('invalid_npc_trade', 'trade requires a valid item_id, quantity and amount.', 400);
        }
    }
}

function town_npc_id(): string { return bin2hex(random_bytes(16)); }

function town_npc_decode($value, $fallback = [])
{
    if (is_string($value)) {
        try { $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { return $fallback; }
    }
    return $value ?? $fallback;
}

function town_npc_array($value): array
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
    $home = is_array($row['home'] ?? null) ? $row['home'] : null;
    $policy = is_array($row['decision_policy'] ?? null) ? $row['decision_policy'] : town_npc_life_policy_defaults();
    $wallet = is_array($row['wallet'] ?? null) ? $row['wallet'] : ['balance' => 0, 'initialized_by' => null, 'updated_at' => ''];
    $lifecycle = is_array($row['lifecycle'] ?? null) ? $row['lifecycle'] : town_npc_lifecycle_default();
    return [
        'npc_id' => (string)$row['npc_id'], 'world_id' => (string)$row['world_id'],
        'name' => (string)$row['name'], 'avatar_asset_id' => (string)($row['avatar_asset_id'] ?? ''),
        'appearance' => town_npc_decode($row['appearance_json'] ?? [], []),
        'occupation' => (string)$row['occupation'], 'enabled' => (bool)$row['enabled'],
        'agent_paused' => (bool)$row['agent_paused'], 'profile' => $profile,
        'stats' => is_array($row['stats'] ?? null) ? $row['stats'] : town_npc_stat_public(town_npc_stat_defaults()),
        'schedule' => town_npc_decode($row['schedule_json'] ?? [], []),
        'home' => $home,
        'lifecycle' => $lifecycle,
        'economy' => ['balance' => (int)($wallet['balance'] ?? 0), 'initialized_by' => $wallet['initialized_by'] ?? null, 'updated_at' => (string)($wallet['updated_at'] ?? ''),
            'inventory' => is_array($row['runtime']['inventory'] ?? null) ? $row['runtime']['inventory'] : []],
        'decision_policy' => $policy,
        'runtime' => [
            'tile_x' => (int)($row['tile_x'] ?? 0), 'tile_y' => (int)($row['tile_y'] ?? 0),
            'height' => (int)($row['runtime_height'] ?? $row['height'] ?? 0),
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

function town_npc_runtime_height_column(PDO $pdo): bool
{
    static $cache = [];
    $key = spl_object_id($pdo);
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $columns = $pdo->query('PRAGMA table_info(town_npc_runtime_states)')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($columns as $column) if ((string)($column['name'] ?? '') === 'height') return $cache[$key] = true;
            return $cache[$key] = false;
        }
        $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=\'town_npc_runtime_states\' AND column_name=\'height\'');
        $query->execute();
        return $cache[$key] = ((int)$query->fetchColumn() > 0);
    } catch (Throwable $error) {
        return $cache[$key] = false;
    }
}

function town_npc_runtime_height_select(PDO $pdo): string
{
    return town_npc_runtime_height_column($pdo) ? 'r.height AS runtime_height' : '0 AS runtime_height';
}

function town_npc_schedule_payload_column(PDO $pdo): bool
{
    static $cache = [];
    $key = spl_object_id($pdo);
    if (!array_key_exists($key, $cache)) {
        $cache[$key] = town_npc_life_column_exists($pdo, 'town_npc_schedules', 'payload_json');
    }
    return $cache[$key];
}

function town_npc_navigation_height(PDO $pdo, int $x, int $y): int
{
    try {
        $cell = town_navigation_cell($pdo, $x, $y);
        return max(0, min(32, (int)($cell['height'] ?? 0)));
    } catch (Throwable $error) {
        return 0;
    }
}

function town_npc_set_runtime_height(PDO $pdo, string $npcId, int $height): void
{
    if (!town_npc_runtime_height_column($pdo)) return;
    $query = $pdo->prepare('UPDATE town_npc_runtime_states SET height=:height WHERE npc_id=:npc');
    $query->execute([':height' => max(0, min(32, $height)), ':npc' => $npcId]);
}

/** Merge intrinsic runtime capabilities with capabilities supplied by NPC items. */
function town_npc_attach_inventory(PDO $pdo, array $rows): array
{
    if ($rows === []) return $rows;
    try {
        $query = $pdo->prepare('SELECT item_id,quantity FROM town_npc_inventory WHERE npc_id=:npc ORDER BY item_id');
        foreach ($rows as $index => $row) {
            $query->execute([':npc' => (string)$row['npc_id']]);
            $items = [];
            $itemCapabilities = [];
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $quantity = max(0, (int)$item['quantity']);
                if ($quantity === 0) continue;
                $itemId = (string)$item['item_id'];
                $items[] = ['item_id' => $itemId, 'quantity' => $quantity];
                if (in_array($itemId, TOWN_NAVIGATION_CAPABILITIES, true)) $itemCapabilities[$itemId] = true;
            }
            $rows[$index]['runtime']['inventory'] = $items;
            $rows[$index]['runtime']['capabilities'] = array_values(array_unique(array_merge(
                is_array($row['runtime']['capabilities'] ?? null) ? $row['runtime']['capabilities'] : [], array_keys($itemCapabilities)
            )));
        }
    } catch (PDOException $error) {
        // Migration 032 is optional during rolling upgrades.
    }
    return $rows;
}

function town_npc_rows(PDO $pdo, bool $includeDisabled = false): array
{
    $where = $includeDisabled ? '' : ' WHERE n.enabled=1';
    $schedulePayload = town_npc_schedule_payload_column($pdo)
        ? ',"payload",COALESCE(JSON_EXTRACT(s.payload_json,\'$\'),JSON_OBJECT())'
        : '';
    $query = $pdo->query('SELECT n.*, p.personality_json,p.family_background,p.short_term_goal,p.long_term_goal,'
        . 'p.speech_style,p.values_json,p.fears_json,r.tile_x,r.tile_y,' . town_npc_runtime_height_select($pdo) . ',r.state AS runtime_state,r.current_task_id,'
        . 'r.dialogue,r.mood_json,r.capabilities_json,r.state_version,r.updated_at AS runtime_updated_at,'
        . '(SELECT JSON_ARRAYAGG(JSON_OBJECT("weekday",s.weekday,"start_minute",s.start_minute,"end_minute",s.end_minute,'
        . '"action",s.action,"target_id",s.target_id,"job",s.job,"priority",s.priority' . $schedulePayload . ')) FROM town_npc_schedules s WHERE s.npc_id=n.npc_id) AS schedule_json '
        . 'FROM town_npcs n LEFT JOIN town_npc_profiles p ON p.npc_id=n.npc_id '
        . 'LEFT JOIN town_npc_runtime_states r ON r.npc_id=n.npc_id' . $where . ' ORDER BY n.created_at,n.npc_id');
    $rows = town_npc_attach_stats($pdo, town_npc_attach_life_data($pdo, $query->fetchAll(PDO::FETCH_ASSOC)));
    return town_npc_attach_inventory($pdo, array_map('town_npc_public', $rows));
}

/** Return administrator-visible relationship rows without exposing model credentials. */
function town_npc_relationship_rows(PDO $pdo, ?string $npcId = null, int $limit = 200, int $offset = 0): array
{
    $limit = max(1, min(1000, $limit));
    $offset = max(0, min(100000, $offset));
    try {
        $where = '';
        $params = [];
        if (is_string($npcId) && preg_match('/\A[a-f0-9]{32}\z/D', $npcId)) {
            $where = ' WHERE r.npc_id=:npc_from OR r.other_npc_id=:npc_to';
            $params[':npc_from'] = $npcId;
            $params[':npc_to'] = $npcId;
        }
        $query = $pdo->prepare('SELECT r.npc_id,r.other_npc_id,r.affinity,r.relationship_json,r.updated_at,n.name AS npc_name,o.name AS other_name '
            . 'FROM town_npc_relationships r LEFT JOIN town_npcs n ON n.npc_id=r.npc_id '
            . 'LEFT JOIN town_npcs o ON o.npc_id=r.other_npc_id' . $where
            . ' ORDER BY r.updated_at DESC,r.npc_id,r.other_npc_id LIMIT ' . $offset . ',' . $limit);
        $query->execute($params);
        return array_map(static function (array $row): array {
            $row['affinity'] = (int)$row['affinity'];
            $row['relationship'] = town_npc_decode($row['relationship_json'] ?? [], []);
            unset($row['relationship_json']);
            return $row;
        }, $query->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $error) {
        return [];
    }
}

/** Return recent durable memories for the administrator's NPC inspection panel. */
function town_npc_memory_rows(PDO $pdo, ?string $npcId = null, int $limit = 200, int $offset = 0): array
{
    $limit = max(1, min(1000, $limit));
    $offset = max(0, min(100000, $offset));
    try {
        $where = '';
        $params = [];
        if (is_string($npcId) && preg_match('/\A[a-f0-9]{32}\z/D', $npcId)) {
            $where = ' WHERE m.npc_id=:npc';
            $params[':npc'] = $npcId;
        }
        $query = $pdo->prepare('SELECT m.memory_id,m.npc_id,n.name AS npc_name,m.memory_type,m.content,m.importance,m.created_at,m.expires_at '
            . 'FROM town_npc_memories m LEFT JOIN town_npcs n ON n.npc_id=m.npc_id' . $where
            . ' ORDER BY m.memory_id DESC LIMIT ' . $offset . ',' . $limit);
        $query->execute($params);
        return array_map(static function (array $row): array {
            $row['memory_id'] = (int)$row['memory_id'];
            $row['importance'] = (int)$row['importance'];
            return $row;
        }, $query->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $error) {
        return [];
    }
}

/** Update a relationship from the administrator panel, keeping both directions consistent. */
function town_npc_save_relationship(PDO $pdo, string $npcId, string $otherNpcId, int $affinity, DateTimeImmutable $now): array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $npcId) || !preg_match('/\A[a-f0-9]{32}\z/D', $otherNpcId) || $npcId === $otherNpcId) {
        throw new ApiError('invalid_relationship', '关系对象无效。', 400);
    }
    $affinity = max(-100, min(100, $affinity));
    $exists = $pdo->prepare('SELECT COUNT(*) FROM town_npcs WHERE npc_id IN (:npc,:other) AND enabled=1');
    $exists->execute([':npc' => $npcId, ':other' => $otherNpcId]);
    if ((int)$exists->fetchColumn() !== 2) throw new ApiError('invalid_relationship', '关系对象不存在或未启用。', 404);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        foreach ([[$npcId, $otherNpcId], [$otherNpcId, $npcId]] as [$from, $to]) {
            $query = $pdo->prepare('SELECT relationship_json FROM town_npc_relationships WHERE npc_id=:npc AND other_npc_id=:other LIMIT 1');
            $query->execute([':npc' => $from, ':other' => $to]);
            $relationship = town_npc_decode($query->fetchColumn(), []);
            if (!is_array($relationship)) $relationship = [];
            $relationship['admin_override'] = true;
            $relationship['updated_at'] = auth_sql_time($now);
            $json = json_encode($relationship, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $update = $pdo->prepare('UPDATE town_npc_relationships SET affinity=:affinity,relationship_json=:relationship,updated_at=:updated WHERE npc_id=:npc AND other_npc_id=:other');
            $update->execute([':affinity' => $affinity, ':relationship' => $json, ':updated' => auth_sql_time($now), ':npc' => $from, ':other' => $to]);
            if ($update->rowCount() === 0) {
                $insert = $pdo->prepare('INSERT INTO town_npc_relationships (npc_id,other_npc_id,affinity,relationship_json,updated_at) VALUES (:npc,:other,:affinity,:relationship,:updated)');
                $insert->execute([':npc' => $from, ':other' => $to, ':affinity' => $affinity, ':relationship' => $json, ':updated' => auth_sql_time($now)]);
            }
        }
        town_npc_emit_event($pdo, $npcId, 'relationship_changed', ['other_npc_id' => $otherNpcId, 'affinity' => $affinity, 'source' => 'admin'], $now);
        town_npc_activity_log($pdo, $npcId, 'memory', '管理员更新了村民关系', ['other_npc_id' => $otherNpcId, 'affinity' => $affinity], $now);
        if ($ownsTransaction) $pdo->commit();
        return ['npc_id' => $npcId, 'other_npc_id' => $otherNpcId, 'affinity' => $affinity];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** SQLite fallback used by service tests and installations before JSON_ARRAYAGG. */
function town_npc_rows_safe(PDO $pdo, bool $includeDisabled = false): array
{
    try { return town_npc_rows($pdo, $includeDisabled); }
    catch (PDOException $error) {
        $where = $includeDisabled ? '' : ' WHERE n.enabled=1';
        $query = $pdo->query('SELECT n.*,p.personality_json,p.family_background,p.short_term_goal,p.long_term_goal,p.speech_style,p.values_json,p.fears_json,'
            . 'r.tile_x,r.tile_y,' . town_npc_runtime_height_select($pdo) . ',r.state AS runtime_state,r.current_task_id,r.dialogue,r.capabilities_json,r.state_version,r.updated_at AS runtime_updated_at '
            . 'FROM town_npcs n LEFT JOIN town_npc_profiles p ON p.npc_id=n.npc_id LEFT JOIN town_npc_runtime_states r ON r.npc_id=n.npc_id' . $where . ' ORDER BY n.created_at,n.npc_id');
        $rows = town_npc_attach_stats($pdo, town_npc_attach_life_data($pdo, $query->fetchAll(PDO::FETCH_ASSOC)));
        return town_npc_attach_inventory($pdo, array_map('town_npc_public', $rows));
    }
}

/** Apply one administrative state change to a bounded set of NPCs. */
function town_npc_bulk_toggle(PDO $pdo, array $npcIds, string $field, bool $value, DateTimeImmutable $now, ?string $actorId = null): array
{
    $field = trim($field);
    if (!in_array($field, ['agent_paused', 'enabled'], true)) {
        throw new ApiError('invalid_npc_bulk_field', '批量村民状态字段无效。', 400);
    }
    $normalized = [];
    foreach ($npcIds as $npcId) {
        if (!is_string($npcId)) throw new ApiError('invalid_npc', '村民 ID 无效。', 400);
        $npcId = strtolower(trim($npcId));
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $npcId)) throw new ApiError('invalid_npc', '村民 ID 无效。', 400);
        $normalized[$npcId] = true;
    }
    $ids = array_keys($normalized);
    if ($ids === [] || count($ids) > 100) throw new ApiError('invalid_npc_selection', '请至少选择一个村民，最多同时操作 100 个村民。', 400);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT npc_id FROM town_npcs WHERE npc_id IN (' . $placeholders . ')');
        $query->execute($ids);
        $found = array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
        sort($found); $expected = $ids; sort($expected);
        if ($found !== $expected) throw new ApiError('npc_not_found', '所选村民中包含不存在的记录，请刷新后重试。', 404);
        $updated = auth_sql_time($now);
        $update = $pdo->prepare('UPDATE town_npcs SET ' . $field . '=:value,updated_at=:updated WHERE npc_id=:id');
        $summary = $field === 'agent_paused' ? ($value ? '管理员批量暂停了 Agent' : '管理员批量恢复了 Agent') : ($value ? '管理员批量启用了村民' : '管理员批量停用了村民');
        foreach ($ids as $npcId) {
            $update->execute([':value' => $value ? 1 : 0, ':updated' => $updated, ':id' => $npcId]);
            town_npc_activity_log($pdo, $npcId, 'action', $summary, ['field' => $field, 'value' => $value, 'source' => 'admin_bulk', 'actor_id' => $actorId], $now);
        }
        $pdo->commit();
        return ['field' => $field, 'value' => $value, 'npc_ids' => $ids, 'updated_count' => count($ids)];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/**
 * Build a bounded context envelope for the Agent Worker. It is deliberately
 * kept out of normal user snapshots so private memories are never sent to a
 * browser client; the worker receives only recent, non-expired memories and
 * directed relationship summaries needed for planning.
 */
/** Return memories ranked by bounded lexical relevance for an Agent query. */
function town_npc_search_memories(PDO $pdo, string $npcId, string $queryText, DateTimeImmutable $now, int $limit = 12): array
{
    $limit = max(1, min(50, $limit));
    $queryText = trim((string)$queryText);
    try {
        $memoryColumns = 'memory_id,memory_type,content,importance,created_at,expires_at';
        foreach (['subject_npc_id', 'asset_id', 'asset_name', 'tile_x', 'tile_y', 'navigation_version'] as $column) {
            if (town_npc_life_column_exists($pdo, 'town_npc_memories', $column)) $memoryColumns .= ',' . $column;
        }
        $query = $pdo->prepare('SELECT ' . $memoryColumns . ' FROM town_npc_memories WHERE npc_id=:npc AND (expires_at IS NULL OR expires_at>:now) ORDER BY memory_id DESC LIMIT 200');
        $query->execute([':npc' => $npcId, ':now' => auth_sql_time($now)]);
        $tokens = preg_split('/[\s,，。；;、:：!?！？]+/u', $queryText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_unique(array_filter(array_map(static fn($value): string => trim((string)$value), $tokens), static fn(string $value): bool => $value !== '')));
        $ranked = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $content = (string)($row['content'] ?? '');
            $score = (int)($row['importance'] ?? 0) * 10;
            if ($queryText !== '' && stripos($content, $queryText) !== false) $score += 20;
            foreach ($tokens as $token) if (stripos($content, $token) !== false) $score += 5;
            $row['_score'] = $score;
            $row['memory_id'] = (int)$row['memory_id'];
            $row['importance'] = (int)$row['importance'];
            $ranked[] = $row;
        }
        usort($ranked, static function (array $left, array $right): int {
            $score = ((int)$right['_score']) <=> ((int)$left['_score']);
            return $score !== 0 ? $score : ((int)$right['memory_id']) <=> ((int)$left['memory_id']);
        });
        $ranked = array_slice($ranked, 0, $limit);
        foreach ($ranked as &$row) { unset($row['_score']); $row['content'] = substr((string)$row['content'], 0, 1000); }
        unset($row);
        return $ranked;
    } catch (PDOException $error) { return []; }
}

function town_npc_agent_context(PDO $pdo, string $npcId, ?DateTimeImmutable $now = null, ?string $memoryQuery = null): array
{
    $empty = ['relationships' => [], 'memories' => [], 'observations' => [], 'navigation_version' => town_navigation_version($pdo)];
    $now ??= api_clock();
    try {
        $relationships = $pdo->prepare('SELECT other_npc_id,affinity,relationship_json,updated_at FROM town_npc_relationships WHERE npc_id=:npc ORDER BY affinity DESC,updated_at DESC LIMIT 8');
        $relationships->execute([':npc' => $npcId]);
        $relationshipRows = [];
        foreach ($relationships->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $relationshipRows[] = [
                'other_npc_id' => (string)$row['other_npc_id'],
                'affinity' => (int)$row['affinity'],
                'relationship' => town_npc_decode($row['relationship_json'] ?? [], []),
                'updated_at' => (string)$row['updated_at'],
            ];
        }
        $memoryRows = [];
        $observations = [];
        $navigationVersion = town_navigation_version($pdo);
        try {
            $runtime = $pdo->prepare('SELECT r.tile_x,r.tile_y,' . town_npc_runtime_height_select($pdo) . ' FROM town_npc_runtime_states r WHERE r.npc_id=:npc LIMIT 1');
            $runtime->execute([':npc' => $npcId]);
            $position = $runtime->fetch(PDO::FETCH_ASSOC);
            if (is_array($position)) {
                $observations = town_interest_points_nearby(
                    $pdo,
                    (int)($position['tile_x'] ?? 0),
                    (int)($position['tile_y'] ?? 0),
                    12,
                    ['visible', 'audible', 'nearby'],
                    (int)($position['runtime_height'] ?? 0)
                );
            }
        } catch (PDOException $error) { }
        if (is_string($memoryQuery) && trim($memoryQuery) !== '') {
            $selected = town_npc_search_memories($pdo, $npcId, $memoryQuery, $now, 12);
            foreach ($selected as $row) $memoryRows[] = town_npc_memory_public($row);
            return ['relationships' => $relationshipRows, 'memories' => $memoryRows, 'observations' => $observations, 'navigation_version' => $navigationVersion];
        }
        $memoryColumns = 'memory_type,content,importance,created_at,expires_at';
        foreach (['subject_npc_id', 'asset_id', 'asset_name', 'tile_x', 'tile_y', 'navigation_version'] as $column) {
            if (town_npc_life_column_exists($pdo, 'town_npc_memories', $column)) $memoryColumns .= ',' . $column;
        }
        $memories = $pdo->prepare('SELECT ' . $memoryColumns . ' FROM town_npc_memories WHERE npc_id=:npc AND (expires_at IS NULL OR expires_at>:now) ORDER BY importance DESC,memory_id DESC LIMIT 12');
        $memories->execute([':npc' => $npcId, ':now' => auth_sql_time($now)]);
        foreach ($memories->fetchAll(PDO::FETCH_ASSOC) as $row) $memoryRows[] = town_npc_memory_public($row);
        return ['relationships' => $relationshipRows, 'memories' => $memoryRows, 'observations' => $observations, 'navigation_version' => $navigationVersion];
    } catch (PDOException $error) {
        // Keep rolling upgrades safe until migration 028 is present.
        return $empty;
    }
}

/** Keep spatial memory fields available to the worker while remaining compatible with pre-039 schemas. */
function town_npc_memory_public(array $row): array
{
    $result = [
        'memory_type' => (string)($row['memory_type'] ?? ''),
        'content' => substr((string)($row['content'] ?? ''), 0, 1000),
        'importance' => (int)($row['importance'] ?? 0),
        'created_at' => (string)($row['created_at'] ?? ''),
        'expires_at' => ($row['expires_at'] ?? null) !== null ? (string)$row['expires_at'] : null,
    ];
    foreach (['subject_npc_id', 'asset_id', 'asset_name', 'tile_x', 'tile_y', 'navigation_version'] as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null) $result[$key] = in_array($key, ['tile_x', 'tile_y', 'navigation_version'], true) ? (int)$row[$key] : (string)$row[$key];
    }
    return $result;
}

/** Insert a bounded, auditable location memory for one observed world item. */
function town_npc_insert_spatial_memory(PDO $pdo, string $npcId, string $memoryType, string $content, int $importance, DateTimeImmutable $now, array $spatial = [], ?DateTimeImmutable $expiresAt = null): void
{
    $columns = ['npc_id', 'memory_type', 'content', 'importance', 'created_at'];
    $values = [':npc', ':type', ':content', ':importance', ':created'];
    $parameters = [':npc' => $npcId, ':type' => substr($memoryType, 0, 32), ':content' => substr($content, 0, 4000), ':importance' => max(1, min(5, $importance)), ':created' => auth_sql_time($now)];
    if (town_npc_life_column_exists($pdo, 'town_npc_memories', 'expires_at')) {
        $columns[] = 'expires_at'; $values[] = ':expires'; $parameters[':expires'] = $expiresAt ? auth_sql_time($expiresAt) : null;
    }
    foreach (['subject_npc_id', 'asset_id', 'asset_name', 'tile_x', 'tile_y', 'navigation_version'] as $key) {
        if (!array_key_exists($key, $spatial) || !town_npc_life_column_exists($pdo, 'town_npc_memories', $key)) continue;
        $columns[] = $key; $values[] = ':' . $key; $parameters[':' . $key] = $spatial[$key];
    }
    $pdo->prepare('INSERT INTO town_npc_memories (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ')')->execute($parameters);
}

/**
 * Observe nearby materials, work points, homes and villagers at the configured
 * interval. This runs on the server during an Agent snapshot, so the model
 * receives names and coordinates from authoritative data rather than guessing
 * from an image or stale client state.
 */
function town_npc_observe_environment_if_due(PDO $pdo, array $npc, DateTimeImmutable $now): void
{
    $npcId = (string)($npc['npc_id'] ?? '');
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $npcId) || !(bool)($npc['enabled'] ?? true) || (bool)($npc['agent_paused'] ?? false)) return;
    $policy = town_npc_life_policy($pdo, $npcId);
    $interval = max(5, min(86400, (int)($policy['observation_interval_seconds'] ?? 300)));
    try {
        $last = $pdo->prepare("SELECT created_at FROM town_npc_memories WHERE npc_id=:npc AND memory_type='environment_observation' ORDER BY memory_id DESC LIMIT 1");
        $last->execute([':npc' => $npcId]); $stamp = $last->fetchColumn();
        if (is_string($stamp) && $stamp !== '' && auth_parse_sql_time($stamp)->modify('+' . $interval . ' seconds') > $now) return;
    } catch (PDOException $error) { return; }
    $x = (int)($npc['tile_x'] ?? ($npc['runtime']['tile_x'] ?? 0)); $y = (int)($npc['tile_y'] ?? ($npc['runtime']['tile_y'] ?? 0));
    $radius = 12; $navigationVersion = town_navigation_version($pdo); $observations = [];
    $observerHeight = (int)($npc['runtime_height'] ?? ($npc['runtime']['height'] ?? $npc['height'] ?? 0));
    $canObserve = static function (int $targetX, int $targetY) use ($pdo, $x, $y, $observerHeight): bool {
        return town_navigation_visibility_between($pdo, $x, $y, $targetX, $targetY, $observerHeight)['visible'];
    };
    try {
        $query = $pdo->prepare('SELECT o.asset_id,o.tile_x,o.tile_y,o.footprint_json,COALESCE(i.name,\'\') AS asset_name,COALESCE(i.category,\'\') AS category,COALESCE(i.navigation_json,\'\') AS navigation_json FROM town_objects o LEFT JOIN town_asset_items i ON i.asset_id=o.asset_id WHERE o.world_id=:world AND o.deleted_at IS NULL AND o.tile_x BETWEEN :min_x AND :max_x AND o.tile_y BETWEEN :min_y AND :max_y ORDER BY o.tile_y,o.tile_x LIMIT 64');
        $query->execute([':world' => SHARED_TOWN_WORLD_ID, ':min_x' => max(0, $x - $radius), ':max_x' => min(SHARED_TOWN_MAP_SIZE - 1, $x + $radius), ':min_y' => max(0, $y - $radius), ':max_y' => min(SHARED_TOWN_MAP_SIZE - 1, $y + $radius)]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim((string)($row['asset_name'] ?? '')); $assetId = (string)$row['asset_id']; $ox = (int)$row['tile_x']; $oy = (int)$row['tile_y'];
            if (!$canObserve($ox, $oy)) continue;
            $observations[] = ['type' => 'asset', 'asset_id' => $assetId, 'asset_name' => $name, 'category' => (string)($row['category'] ?? ''), 'tile_x' => $ox, 'tile_y' => $oy, 'description' => '看到素材“' . ($name !== '' ? $name : $assetId) . '”（' . $assetId . '），位于 (' . $ox . ',' . $oy . ')'];
        }
    } catch (PDOException $error) {
        // During a rolling deployment the asset catalog may be unavailable;
        // the NPC still gets memories for work points, homes and villagers.
    }
    try {
        $query = $pdo->prepare('SELECT point_key,name,kind,tile_x,tile_y FROM town_work_points WHERE world_id=:world AND enabled=1 AND tile_x BETWEEN :min_x AND :max_x AND tile_y BETWEEN :min_y AND :max_y ORDER BY tile_y,tile_x LIMIT 32');
        $query->execute([':world' => SHARED_TOWN_WORLD_ID, ':min_x' => max(0, $x - $radius), ':max_x' => min(SHARED_TOWN_MAP_SIZE - 1, $x + $radius), ':min_y' => max(0, $y - $radius), ':max_y' => min(SHARED_TOWN_MAP_SIZE - 1, $y + $radius)]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pointX = (int)$row['tile_x']; $pointY = (int)$row['tile_y'];
            if (!$canObserve($pointX, $pointY)) continue;
            $observations[] = ['type' => 'work_point', 'asset_id' => (string)$row['point_key'], 'asset_name' => (string)$row['name'], 'tile_x' => $pointX, 'tile_y' => $pointY, 'description' => '知道' . (string)$row['name'] . '（' . (string)$row['kind'] . '）在 (' . $pointX . ',' . $pointY . ')'];
        }
    } catch (PDOException $error) { }
    try {
        $query = $pdo->prepare('SELECT h.npc_id,h.home_asset_id,h.home_asset_name,h.tile_x,h.tile_y,n.name FROM town_npc_homes h JOIN town_npcs n ON n.npc_id=h.npc_id WHERE n.enabled=1 AND h.tile_x BETWEEN :min_x AND :max_x AND h.tile_y BETWEEN :min_y AND :max_y LIMIT 32');
        $query->execute([':min_x' => max(0, $x - $radius), ':max_x' => min(SHARED_TOWN_MAP_SIZE - 1, $x + $radius), ':min_y' => max(0, $y - $radius), ':max_y' => min(SHARED_TOWN_MAP_SIZE - 1, $y + $radius)]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $homeX = (int)$row['tile_x']; $homeY = (int)$row['tile_y'];
            if (!$canObserve($homeX, $homeY)) continue;
            $observations[] = ['type' => 'home', 'subject_npc_id' => (string)$row['npc_id'], 'asset_id' => (string)$row['home_asset_id'], 'asset_name' => (string)$row['home_asset_name'], 'tile_x' => $homeX, 'tile_y' => $homeY, 'description' => '知道村民' . (string)$row['name'] . '的家在 (' . $homeX . ',' . $homeY . ')'];
        }
    } catch (PDOException $error) { }
    try {
        $query = $pdo->prepare('SELECT n.npc_id,n.name,r.tile_x,r.tile_y FROM town_npcs n JOIN town_npc_runtime_states r ON r.npc_id=n.npc_id WHERE n.enabled=1 AND n.npc_id<>:npc AND r.tile_x BETWEEN :min_x AND :max_x AND r.tile_y BETWEEN :min_y AND :max_y LIMIT 32');
        $query->execute([':npc' => $npcId, ':min_x' => max(0, $x - $radius), ':max_x' => min(SHARED_TOWN_MAP_SIZE - 1, $x + $radius), ':min_y' => max(0, $y - $radius), ':max_y' => min(SHARED_TOWN_MAP_SIZE - 1, $y + $radius)]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $npcX = (int)$row['tile_x']; $npcY = (int)$row['tile_y'];
            if (!$canObserve($npcX, $npcY)) continue;
            $observations[] = ['type' => 'npc', 'subject_npc_id' => (string)$row['npc_id'], 'tile_x' => $npcX, 'tile_y' => $npcY, 'description' => '看到村民' . (string)$row['name'] . '在 (' . $npcX . ',' . $npcY . ')'];
        }
    } catch (PDOException $error) { }
    if ($observations === []) return;
    if (town_npc_life_column_exists($pdo, 'town_npc_memories', 'navigation_version')) {
        try { $expire = $pdo->prepare("UPDATE town_npc_memories SET expires_at=:now WHERE npc_id=:npc AND memory_type='environment_observation' AND (navigation_version IS NULL OR navigation_version<>:version)"); $expire->execute([':now' => auth_sql_time($now), ':npc' => $npcId, ':version' => $navigationVersion]); } catch (PDOException $error) { }
    }
    $expires = $now->modify('+30 days');
    foreach (array_slice($observations, 0, 64) as $observation) {
        $spatial = array_intersect_key($observation, array_flip(['subject_npc_id', 'asset_id', 'asset_name', 'tile_x', 'tile_y']));
        $spatial['navigation_version'] = $navigationVersion;
        town_npc_insert_spatial_memory($pdo, $npcId, 'environment_observation', (string)$observation['description'], 2, $now, $spatial, $expires);
    }
}

function town_npc_snapshot(PDO $pdo, bool $forAgent = false): array
{
    $rows = town_npc_rows_safe($pdo);
    if ($forAgent) {
        foreach ($rows as $index => $row) {
            town_npc_observe_environment_if_due($pdo, $row, api_clock());
            $profile = is_array($row['profile'] ?? null) ? $row['profile'] : [];
            $runtime = is_array($row['runtime'] ?? null) ? $row['runtime'] : [];
            $memoryQuery = trim(implode(' ', array_filter([
                (string)($profile['short_term_goal'] ?? ''), (string)($profile['long_term_goal'] ?? ''),
                (string)($row['occupation'] ?? ''), (string)($runtime['state'] ?? ''),
            ], static fn(string $value): bool => $value !== '')));
            $rows[$index]['agent_context'] = town_npc_agent_context($pdo, (string)$row['npc_id'], null, $memoryQuery);
        }
    }
    return ['world_id' => SHARED_TOWN_WORLD_ID, 'navigation_version' => town_navigation_version($pdo),
        'npcs' => $rows, 'cursor' => (int)($pdo->query('SELECT COALESCE(MAX(event_id),0) FROM town_npc_events')->fetchColumn() ?: 0)];
}

function town_npc_changes(PDO $pdo, int $cursor, int $limit = 200): array
{
    if ($cursor < 0) throw new ApiError('invalid_cursor', 'NPC event cursor is invalid.', 400);
    $limit = min(500, max(1, $limit));
    $query = $pdo->prepare('SELECT event_id,npc_id,event_type,payload_json,created_at FROM town_npc_events WHERE event_id>:cursor ORDER BY event_id LIMIT ' . ($limit + 1));
    $query->execute([':cursor' => $cursor]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > $limit;
    $events = [];
    foreach (array_slice($rows, 0, $limit) as $row) {
        // Player photo facts are private to the initiating account. They still
        // advance the server cursor, but never enter the shared change feed.
        $cursor = (int)$row['event_id'];
        if ((string)$row['event_type'] === 'player_conversation') continue;
        $events[] = ['event_id' => (int)$row['event_id'], 'npc_id' => (string)$row['npc_id'], 'type' => (string)$row['event_type'],
            'payload' => town_npc_decode($row['payload_json'], []), 'created_at' => (string)$row['created_at']];
    }
    return ['cursor' => $cursor, 'events' => $events, 'has_more' => $hasMore,
        'navigation_version' => town_navigation_version($pdo)];
}

/** Resolve a configured work point first, retaining demo coordinates during migration rollout. */
function town_npc_target_point(string $targetId, ?PDO $pdo = null): ?array
{
    $targetId = trim($targetId);
    if ($pdo !== null && $targetId !== '') {
        try {
            $query = $pdo->prepare('SELECT point_id,point_key,name,kind,tile_x,tile_y,interaction_json,result_json FROM town_work_points WHERE world_id=:world AND point_key=:key AND enabled=1 LIMIT 1');
            $query->execute([':world' => SHARED_TOWN_WORLD_ID, ':key' => $targetId]);
            $point = $query->fetch(PDO::FETCH_ASSOC);
            if (is_array($point)) return [
                'point_id' => (string)$point['point_id'], 'point_key' => (string)$point['point_key'],
                'name' => (string)$point['name'], 'kind' => (string)$point['kind'],
                'tile_x' => (int)$point['tile_x'], 'tile_y' => (int)$point['tile_y'],
                'interaction' => town_npc_decode($point['interaction_json'] ?? [], []),
                'result' => town_npc_decode($point['result_json'] ?? [], []),
            ];
        } catch (PDOException $error) { /* Migration 030 is optional while older installations are upgraded. */ }
    }
    $legacyTargets = [
        'farm-zone-3' => ['tile_x' => 128, 'tile_y' => 120],
        'market-zone-1' => ['tile_x' => 96, 'tile_y' => 96],
        'workshop-zone-2' => ['tile_x' => 112, 'tile_y' => 96],
        'town-square' => ['tile_x' => 96, 'tile_y' => 96],
    ];
    $legacyTarget = strtolower(trim($targetId));
    return $legacyTargets[$legacyTarget] ?? null;
}

function town_npc_work_point(PDO $pdo, string $targetId): ?array
{
    $point = town_npc_target_point($targetId, $pdo);
    return is_array($point) && isset($point['point_id']) ? $point : null;
}

function town_npc_work_points(PDO $pdo, bool $includeDisabled = true): array
{
    try {
        $where = $includeDisabled ? '' : ' WHERE enabled=1';
        $query = $pdo->query('SELECT point_id,world_id,point_key,name,kind,tile_x,tile_y,interaction_json,result_json,enabled,created_at,updated_at FROM town_work_points' . $where . ' ORDER BY point_key');
        return array_map(static function (array $row): array {
            $row['tile_x'] = (int)$row['tile_x']; $row['tile_y'] = (int)$row['tile_y']; $row['enabled'] = (bool)$row['enabled'];
            $row['interaction'] = town_npc_decode($row['interaction_json'] ?? [], []); $row['result'] = town_npc_decode($row['result_json'] ?? [], []);
            unset($row['interaction_json'], $row['result_json']); return $row;
        }, $query->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $error) { return []; }
}

/** Return capabilities provided by positive NPC inventory rows. */
function town_npc_inventory_capabilities(PDO $pdo, string $npcId): array
{
    try {
        $query = $pdo->prepare('SELECT item_id FROM town_npc_inventory WHERE npc_id=:npc AND quantity>0');
        $query->execute([':npc' => $npcId]);
        $capabilities = [];
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $itemId) {
            if (is_string($itemId) && in_array($itemId, TOWN_NAVIGATION_CAPABILITIES, true)) $capabilities[$itemId] = true;
        }
        return array_keys($capabilities);
    } catch (PDOException $error) {
        return [];
    }
}

/** Replace the administrator-managed NPC navigation inventory. */
function town_npc_replace_inventory(PDO $pdo, string $npcId, array $items, DateTimeImmutable $now): int
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $npcId)) throw new ApiError('invalid_npc', 'NPC identifier is invalid.', 400);
    $exists = $pdo->prepare('SELECT 1 FROM town_npcs WHERE npc_id=:npc LIMIT 1'); $exists->execute([':npc' => $npcId]);
    if (!$exists->fetchColumn()) throw new ApiError('npc_not_found', 'NPC was not found.', 404);
    $normalized = [];
    foreach ($items as $itemId => $quantity) {
        $itemId = is_string($itemId) ? trim($itemId) : '';
        if (!in_array($itemId, TOWN_NAVIGATION_CAPABILITIES, true)) continue;
        $amount = max(0, min(9999, (int)$quantity));
        if ($amount > 0) $normalized[$itemId] = $amount;
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM town_npc_inventory WHERE npc_id=:npc')->execute([':npc' => $npcId]);
        $insert = $pdo->prepare('INSERT INTO town_npc_inventory (npc_id,item_id,quantity,updated_at) VALUES (:npc,:item,:quantity,:updated)');
        foreach ($normalized as $itemId => $quantity) $insert->execute([':npc' => $npcId, ':item' => $itemId, ':quantity' => $quantity, ':updated' => auth_sql_time($now)]);
        town_npc_emit_event($pdo, $npcId, 'inventory_changed', ['items' => $normalized], $now);
        town_npc_activity_log($pdo, $npcId, 'action', '管理员更新了道具库存', ['items' => $normalized], $now);
        $pdo->commit();
        return array_sum($normalized);
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

/** Find capabilities required by the cells in a server-produced route. */
function town_npc_route_capabilities(PDO $pdo, array $route): array
{
    $required = [];
    $cache = [];
    foreach ($route as $point) {
        if (!is_array($point)) continue;
        $x = filter_var($point['x'] ?? null, FILTER_VALIDATE_INT); $y = filter_var($point['y'] ?? null, FILTER_VALIDATE_INT);
        if ($x === false || $y === false) continue;
        foreach (town_navigation_cell($pdo, (int)$x, (int)$y, $cache)['required_capabilities'] as $capability) $required[$capability] = true;
    }
    return array_keys($required);
}

/** Resolve the authoritative destination for an existing task during re-planning. */
function town_npc_task_destination(PDO $pdo, string $action, array $payload): ?array
{
    if (in_array($action, ['talk', 'trade'], true)) {
        $approach = town_npc_array($payload['approach_tile'] ?? []);
        $approachX = filter_var($approach['tile_x'] ?? $approach['x'] ?? null, FILTER_VALIDATE_INT);
        $approachY = filter_var($approach['tile_y'] ?? $approach['y'] ?? null, FILTER_VALIDATE_INT);
        if ($approachX !== false && $approachY !== false) return ['tile_x' => (int)$approachX, 'tile_y' => (int)$approachY];
    }
    $target = town_npc_array($payload['target'] ?? []);
    $x = filter_var($target['tile_x'] ?? $target['x'] ?? null, FILTER_VALIDATE_INT);
    $y = filter_var($target['tile_y'] ?? $target['y'] ?? null, FILTER_VALIDATE_INT);
    if ($x !== false && $y !== false) return ['tile_x' => (int)$x, 'tile_y' => (int)$y];
    if ($action === 'work') {
        $point = town_npc_target_point((string)($payload['target_id'] ?? ''), $pdo);
        if (is_array($point) && isset($point['tile_x'], $point['tile_y'])) return ['tile_x' => (int)$point['tile_x'], 'tile_y' => (int)$point['tile_y']];
    }
    if ($action === 'talk') {
        $targetNpc = trim((string)($payload['target_npc_id'] ?? $payload['target_id'] ?? ''));
        if (preg_match('/\A[a-f0-9]{32}\z/D', $targetNpc)) {
            $query = $pdo->prepare('SELECT r.tile_x,r.tile_y FROM town_npcs n LEFT JOIN town_npc_runtime_states r ON r.npc_id=n.npc_id WHERE n.npc_id=:npc AND n.enabled=1 LIMIT 1');
            $query->execute([':npc' => $targetNpc]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && $row['tile_x'] !== null && $row['tile_y'] !== null) return ['tile_x' => (int)$row['tile_x'], 'tile_y' => (int)$row['tile_y']];
        }
    }
    return null;
}

/** Read the current weather stamp without making older rolling schemas fail. */
function town_npc_weather_stamp(PDO $pdo, DateTimeImmutable $now): array
{
    try {
        $clock = town_world_clock_read($pdo, $now);
        return [
            'weather_code' => (string)($clock['weather_code'] ?? 'clear'),
            'weather_revision' => (int)($clock['revision'] ?? 0),
        ];
    } catch (Throwable $error) {
        return ['weather_code' => 'clear', 'weather_revision' => 0];
    }
}

/** Consume one item for each route capability not already intrinsic to the NPC. */
function town_npc_consume_route_items(PDO $pdo, string $npcId, array $required, $intrinsic, DateTimeImmutable $now): array
{
    $intrinsic = array_fill_keys(town_navigation_capabilities($intrinsic), true);
    $consumed = [];
    foreach ($required as $capability) {
        if (isset($intrinsic[$capability])) continue;
        if (!in_array($capability, TOWN_NAVIGATION_CAPABILITIES, true)) throw new ApiError('missing_capability', 'NPC 缺少通行能力。', 409);
        $query = $pdo->prepare('SELECT quantity FROM town_npc_inventory WHERE npc_id=:npc AND item_id=:item' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
        $query->execute([':npc' => $npcId, ':item' => $capability]);
        $quantity = (int)$query->fetchColumn();
        if ($quantity < 1) throw new ApiError('missing_capability', 'NPC 缺少通行道具：' . $capability . '。', 409);
        $update = $pdo->prepare('UPDATE town_npc_inventory SET quantity=quantity-1,updated_at=:updated WHERE npc_id=:npc AND item_id=:item AND quantity>0');
        $update->execute([':updated' => auth_sql_time($now), ':npc' => $npcId, ':item' => $capability]);
        if ($update->rowCount() !== 1) throw new ApiError('missing_capability', 'NPC 通行道具不足：' . $capability . '。', 409);
        $consumed[$capability] = ($consumed[$capability] ?? 0) + 1;
    }
    return $consumed;
}

/** Return consumed route items when a task fails before the interaction completes. */
function town_npc_refund_route_items(PDO $pdo, string $npcId, array $consumed, DateTimeImmutable $now): void
{
    foreach ($consumed as $itemId => $quantity) {
        $quantity = max(0, (int)$quantity); if ($quantity === 0) continue;
        try {
            $exists = $pdo->prepare('SELECT quantity FROM town_npc_inventory WHERE npc_id=:npc AND item_id=:item');
            $exists->execute([':npc' => $npcId, ':item' => (string)$itemId]);
            if ($exists->fetchColumn() === false) $pdo->prepare('INSERT INTO town_npc_inventory (npc_id,item_id,quantity,updated_at) VALUES (:npc,:item,:quantity,:updated)')->execute([':npc' => $npcId, ':item' => (string)$itemId, ':quantity' => $quantity, ':updated' => auth_sql_time($now)]);
            else $pdo->prepare('UPDATE town_npc_inventory SET quantity=quantity+:quantity,updated_at=:updated WHERE npc_id=:npc AND item_id=:item')->execute([':quantity' => $quantity, ':updated' => auth_sql_time($now), ':npc' => $npcId, ':item' => (string)$itemId]);
        } catch (PDOException $error) { }
    }
}

/** Reserve one interaction point until task completion or lease failure timeout. */
function town_npc_reserve_work_point(PDO $pdo, array $point, string $npcId, string $taskId, DateTimeImmutable $now, int $durationMinutes): void
{
    $pointId = trim((string)($point['point_id'] ?? '')); if ($pointId === '') return;
    try {
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $query = $pdo->prepare('SELECT reservation_id,task_id,expires_at FROM town_work_point_reservations WHERE point_id=:point AND status=\'active\'' . $lock);
    $query->execute([':point' => $pointId]);
    $active = $query->fetch(PDO::FETCH_ASSOC);
    if (is_array($active)) {
        try { $expired = auth_parse_sql_time((string)$active['expires_at']) <= $now; } catch (Throwable $error) { $expired = true; }
        if ($expired) $pdo->prepare("UPDATE town_work_point_reservations SET status='expired',released_at=:released WHERE reservation_id=:id")->execute([':released' => auth_sql_time($now), ':id' => $active['reservation_id']]);
        elseif ((string)$active['task_id'] !== $taskId) throw new ApiError('work_point_busy', '工作点正在被其他村民使用。', 409);
    }
    $expires = $now->modify('+' . max(120, ($durationMinutes * 60) + 120) . ' seconds');
    $insert = $pdo->prepare('INSERT INTO town_work_point_reservations (reservation_id,point_id,npc_id,task_id,status,reserved_at,expires_at,released_at) VALUES (:id,:point,:npc,:task,\'active\',:reserved,:expires,NULL)');
    try { $insert->execute([':id' => town_npc_id(), ':point' => $pointId, ':npc' => $npcId, ':task' => $taskId, ':reserved' => auth_sql_time($now), ':expires' => auth_sql_time($expires)]); }
    catch (PDOException $error) { throw new ApiError('work_point_busy', '工作点正在被其他村民使用。', 409); }
    } catch (PDOException $error) {
        $message = strtolower($error->getMessage());
        if (str_contains($message, 'no such table') || str_contains($message, "doesn't exist")) return;
        throw $error;
    }
}

function town_npc_release_work_point(PDO $pdo, string $taskId, string $status, DateTimeImmutable $now): void
{
    try {
        $update = $pdo->prepare("UPDATE town_work_point_reservations SET status=:status,released_at=:released WHERE task_id=:task AND status='active'");
        $update->execute([':status' => in_array($status, ['completed', 'failed', 'expired'], true) ? $status : 'released', ':released' => auth_sql_time($now), ':task' => $taskId]);
    } catch (PDOException $error) { }
}

/** Prevent an abandoned task from continuing after its work-point lease expires. */
function town_npc_work_point_reservation_valid(PDO $pdo, string $taskId, array $payload, DateTimeImmutable $now): bool
{
    $targetId = trim((string)($payload['target_id'] ?? ''));
    if ($targetId === '' || town_npc_work_point($pdo, $targetId) === null) return true;
    try {
        $query = $pdo->prepare("SELECT expires_at FROM town_work_point_reservations WHERE task_id=:task AND status='active' LIMIT 1");
        $query->execute([':task' => $taskId]);
        $expires = $query->fetchColumn();
        return is_string($expires) && auth_parse_sql_time($expires) > $now;
    } catch (PDOException $error) {
        $message = strtolower($error->getMessage());
        return str_contains($message, 'no such table') || str_contains($message, "doesn't exist");
    }
}

/** Apply the first durable world effect: watering one configured farm plot. */
function town_npc_apply_farm_result(PDO $pdo, array $point, array $result, DateTimeImmutable $now): array
{
    if ((string)($point['kind'] ?? '') !== 'farm' || (string)($result['effect'] ?? '') !== 'water_crop') return [];
    try {
        $plotKey = trim((string)($result['plot_key'] ?? ''));
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $sql = 'SELECT plot_id,plot_key,name,crop_id,growth_stage,state,watered_at,watering_count FROM town_farm_plots WHERE world_id=:world AND point_id=:point AND enabled=1';
        if ($plotKey !== '') $sql .= ' AND plot_key=:plot_key';
        $sql .= ' ORDER BY plot_key LIMIT 1' . $lock;
        $query = $pdo->prepare($sql);
        $params = [':world' => SHARED_TOWN_WORLD_ID, ':point' => (string)$point['point_id']];
        if ($plotKey !== '') $params[':plot_key'] = $plotKey;
        $query->execute($params);
        $plot = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($plot)) return ['effect' => 'water_crop', 'applied' => false, 'reason' => 'no_plot'];
        $wateredAt = trim((string)($plot['watered_at'] ?? ''));
        $today = $now->format('Y-m-d');
        if ($wateredAt !== '' && str_starts_with($wateredAt, $today)) {
            return ['effect' => 'water_crop', 'applied' => false, 'reason' => 'already_watered', 'plot_key' => (string)$plot['plot_key'], 'crop_id' => (string)$plot['crop_id'], 'state' => (string)$plot['state'], 'watering_count' => (int)$plot['watering_count']];
        }
        $stamp = auth_sql_time($now);
        $update = $pdo->prepare("UPDATE town_farm_plots SET state='watered',watered_at=:watered,watering_count=watering_count+1,updated_at=:updated WHERE plot_id=:plot");
        $update->execute([':watered' => $stamp, ':updated' => $stamp, ':plot' => (string)$plot['plot_id']]);
        return ['effect' => 'water_crop', 'applied' => true, 'plot_key' => (string)$plot['plot_key'], 'name' => (string)$plot['name'], 'crop_id' => (string)$plot['crop_id'], 'growth_stage' => (int)$plot['growth_stage'], 'state' => 'watered', 'watered_at' => $stamp, 'watering_count' => (int)$plot['watering_count'] + 1];
    } catch (PDOException $error) {
        // Migration 031 is optional during rolling upgrades; the work audit still persists.
        return [];
    }
}

/** Read durable farm state for the shared-town world snapshot. */
function town_farm_snapshot(PDO $pdo): array
{
    try {
        $query = $pdo->query('SELECT plot_id,world_id,point_id,plot_key,name,crop_id,growth_stage,state,watered_at,watering_count,enabled,updated_at FROM town_farm_plots WHERE world_id=' . $pdo->quote(SHARED_TOWN_WORLD_ID) . ' ORDER BY plot_key');
        return array_map(static function (array $row): array {
            foreach (['growth_stage', 'watering_count'] as $key) $row[$key] = (int)$row[$key];
            $row['enabled'] = (bool)$row['enabled'];
            return $row;
        }, $query->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $error) {
        return [];
    }
}

/** Add one market restock unit for a completed merchant task. */
function town_npc_apply_market_result(PDO $pdo, array $point, array $result, DateTimeImmutable $now): array
{
    if ((string)($point['kind'] ?? '') !== 'market' || (string)($result['effect'] ?? '') !== 'restock_market') return [];
    try {
        $stockKey = trim((string)($result['stock_key'] ?? ''));
        $sql = 'SELECT stock_id,stock_key,item_id,quantity FROM town_market_stocks WHERE world_id=:world AND point_id=:point';
        if ($stockKey !== '') $sql .= ' AND stock_key=:stock_key';
        $sql .= ' ORDER BY stock_key LIMIT 1' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '');
        $query = $pdo->prepare($sql); $params = [':world' => SHARED_TOWN_WORLD_ID, ':point' => (string)$point['point_id']];
        if ($stockKey !== '') $params[':stock_key'] = $stockKey;
        $query->execute($params); $stock = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($stock)) return ['effect' => 'restock_market', 'applied' => false, 'reason' => 'no_stock'];
        $quantity = max(1, min(1000, (int)($result['quantity'] ?? 1))); $updated = auth_sql_time($now);
        $update = $pdo->prepare('UPDATE town_market_stocks SET quantity=quantity+:quantity,updated_at=:updated WHERE stock_id=:stock');
        $update->execute([':quantity' => $quantity, ':updated' => $updated, ':stock' => (string)$stock['stock_id']]);
        return ['effect' => 'restock_market', 'applied' => true, 'stock_key' => (string)$stock['stock_key'], 'item_id' => (string)$stock['item_id'], 'quantity_added' => $quantity, 'quantity' => (int)$stock['quantity'] + $quantity, 'updated_at' => $updated];
    } catch (PDOException $error) { return []; }
}

/** Persist one crafted output for a completed craftsman task. */
function town_npc_apply_workshop_result(PDO $pdo, string $npcId, string $taskId, array $point, array $result, DateTimeImmutable $now): array
{
    if ((string)($point['kind'] ?? '') !== 'workshop' || (string)($result['effect'] ?? '') !== 'craft_item') return [];
    try {
        $itemId = trim((string)($result['item_id'] ?? 'crafted_item')); if ($itemId === '') $itemId = 'crafted_item';
        $quantity = max(1, min(1000, (int)($result['quantity'] ?? 1))); $outputId = town_npc_id(); $stamp = auth_sql_time($now);
        $insert = $pdo->prepare('INSERT INTO town_workshop_outputs (output_id,world_id,point_id,npc_id,task_id,item_id,quantity,produced_at) VALUES (:output,:world,:point,:npc,:task,:item,:quantity,:produced)');
        $insert->execute([':output' => $outputId, ':world' => SHARED_TOWN_WORLD_ID, ':point' => (string)$point['point_id'], ':npc' => $npcId, ':task' => $taskId, ':item' => $itemId, ':quantity' => $quantity, ':produced' => $stamp]);
        return ['effect' => 'craft_item', 'applied' => true, 'output_id' => $outputId, 'item_id' => $itemId, 'quantity' => $quantity, 'produced_at' => $stamp];
    } catch (PDOException $error) { return []; }
}

/** Expose shared market stock and recent workshop outputs in the world snapshot. */
function town_economy_snapshot(PDO $pdo): array
{
    try {
        $stocks = $pdo->query("SELECT stock_key,item_id,quantity,updated_at FROM town_market_stocks WHERE world_id='main' ORDER BY stock_key")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stocks as &$stock) $stock['quantity'] = (int)$stock['quantity']; unset($stock);
        $outputs = $pdo->query("SELECT output_id,point_id,npc_id,task_id,item_id,quantity,produced_at FROM town_workshop_outputs WHERE world_id='main' ORDER BY produced_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($outputs as &$output) $output['quantity'] = (int)$output['quantity']; unset($output);
        return ['market_stocks' => $stocks, 'workshop_outputs' => $outputs];
    } catch (PDOException $error) { return ['market_stocks' => [], 'workshop_outputs' => []]; }
}

function town_npc_save_work_point(PDO $pdo, array $body, DateTimeImmutable $now, ?string $existingId = null): array
{
    $key = trim((string)($body['point_key'] ?? '')); $name = trim((string)($body['name'] ?? '')); $kind = trim((string)($body['kind'] ?? 'workshop'));
    if ($key === '' || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $key) || $name === '' || strlen($name) > 80) throw new ApiError('invalid_work_point', '工作点名称或 ID 无效。', 400);
    if (!in_array($kind, ['farm', 'market', 'workshop', 'public'], true)) throw new ApiError('invalid_work_point', '工作点类型无效。', 400);
    $json = static fn($value): string => json_encode(is_array($value) ? $value : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $pointId = $existingId !== null && preg_match('/\A[a-f0-9]{32}\z/D', $existingId) ? $existingId : town_npc_id();
    $enabled = true;
    if ($existingId !== null) { $state = $pdo->prepare('SELECT enabled FROM town_work_points WHERE point_id=:id LIMIT 1'); $state->execute([':id' => $existingId]); $current = $state->fetchColumn(); if ($current === false) throw new ApiError('work_point_not_found', '工作点不存在。', 404); $enabled = (bool)$current; }
    try {
        $query = $pdo->prepare('INSERT INTO town_work_points (point_id,world_id,point_key,name,kind,tile_x,tile_y,interaction_json,result_json,enabled,created_at,updated_at) VALUES (:id,:world,:key,:name,:kind,:x,:y,:interaction,:result,:enabled,:created,:updated) ON DUPLICATE KEY UPDATE point_key=VALUES(point_key),name=VALUES(name),kind=VALUES(kind),tile_x=VALUES(tile_x),tile_y=VALUES(tile_y),interaction_json=VALUES(interaction_json),result_json=VALUES(result_json),updated_at=VALUES(updated_at)');
        $query->execute([':id' => $pointId, ':world' => SHARED_TOWN_WORLD_ID, ':key' => $key, ':name' => $name, ':kind' => $kind, ':x' => max(0, (int)($body['tile_x'] ?? 0)), ':y' => max(0, (int)($body['tile_y'] ?? 0)), ':interaction' => $json($body['interaction'] ?? []), ':result' => $json($body['result'] ?? []), ':enabled' => (int)$enabled, ':created' => auth_sql_time($now), ':updated' => auth_sql_time($now)]);
    } catch (PDOException $error) { throw new ApiError('invalid_work_point', '工作点 ID 已存在或保存失败。', 409); }
    return town_npc_work_point($pdo, $key) ?? ['point_id' => $pointId, 'point_key' => $key];
}

/** Persist a completed work interaction for later farm/market/workshop effects and reporting. */
function town_npc_record_work_result(PDO $pdo, string $npcId, string $taskId, array $payload, DateTimeImmutable $now): array
{
    $targetId = trim((string)($payload['target_id'] ?? ''));
    if ($targetId === '') return [];
    $point = town_npc_work_point($pdo, $targetId);
    if ($point === null) return [];
    $runId = town_npc_id();
    $job = substr(trim((string)($payload['job'] ?? '')), 0, 64);
    $result = is_array($point['result'] ?? null) ? $point['result'] : [];
    $worldEffect = town_npc_apply_farm_result($pdo, $point, $result, $now);
    if ($worldEffect === []) $worldEffect = town_npc_apply_market_result($pdo, $point, $result, $now);
    if ($worldEffect === []) $worldEffect = town_npc_apply_workshop_result($pdo, $npcId, $taskId, $point, $result, $now);
    if ($worldEffect !== []) $result['world_effect'] = $worldEffect;
    $defaultIncome = 5;
    if (in_array($job, ['water_crop', 'clear_weeds'], true)) $defaultIncome = 10;
    elseif (in_array($job, ['restock_market', 'serve_customer'], true)) $defaultIncome = 12;
    elseif (in_array($job, ['craft_item', 'repair_tool'], true)) $defaultIncome = 15;
    $income = max(0, min(1000000, (int)($result['earn_amount'] ?? $result['income'] ?? $defaultIncome)));
    if ($income > 0) {
        $balance = town_npc_record_income($pdo, $npcId, $income, '完成工作：' . ($job !== '' ? $job : (string)($point['name'] ?? $targetId)), $now);
        $result['income'] = $income; $result['wallet_balance'] = $balance;
    }
    $detail = ['run_id' => $runId, 'point_id' => $point['point_id'], 'point_key' => $point['point_key'], 'point_kind' => $point['kind'], 'job' => $job, 'result' => $result];
    try {
        $insert = $pdo->prepare('INSERT INTO town_work_runs (run_id,point_id,npc_id,task_id,job,status,result_json,started_at,completed_at) VALUES (:run,:point,:npc,:task,:job,\'completed\',:result,:started,:completed)');
        $insert->execute([':run' => $runId, ':point' => $point['point_id'], ':npc' => $npcId, ':task' => $taskId, ':job' => $job, ':result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':started' => auth_sql_time($now), ':completed' => auth_sql_time($now)]);
    } catch (PDOException $error) { return []; }
    // Keep the durable world-effect result in its own journalable event. The
    // task-completed event is emitted by the caller before this work helper
    // runs, so this delta is the authoritative replay boundary.
    town_npc_emit_event($pdo, $npcId, 'task_replay_delta', [
        'task_id' => $taskId, 'action' => 'work', 'task_status' => 'completed',
        'job' => $job, 'work_run_id' => $runId, 'work_point' => ['point_id' => $point['point_id'], 'point_key' => $point['point_key'], 'kind' => $point['kind']],
        'work_result' => $result,
    ], $now);
    town_npc_activity_log($pdo, $npcId, 'action', '工作完成：' . ((string)($point['name'] ?? $targetId)), $detail, $now);
    return ['work_run_id' => $runId, 'work_point' => ['point_id' => $point['point_id'], 'point_key' => $point['point_key'], 'name' => $point['name'], 'kind' => $point['kind']], 'work_result' => $result];
}

/** Persist the first social result: a completed NPC-to-NPC conversation. */
function town_npc_record_talk_result(PDO $pdo, string $npcId, string $taskId, array $payload, DateTimeImmutable $now): array
{
    $otherId = trim((string)($payload['target_npc_id'] ?? $payload['target_id'] ?? ''));
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $otherId) || $otherId === $npcId) return [];
    $dialogue = trim((string)($payload['dialogue'] ?? ''));
    if ($dialogue === '') return [];
    try {
        $exists = $pdo->prepare('SELECT npc_id FROM town_npcs WHERE npc_id=:npc AND enabled=1 LIMIT 1');
        $exists->execute([':npc' => $otherId]);
        if (!$exists->fetchColumn()) return [];
        $relationshipJson = static function ($value): array {
            $decoded = is_string($value) ? json_decode($value, true) : $value;
            return is_array($decoded) ? $decoded : [];
        };
        foreach ([[$npcId, $otherId], [$otherId, $npcId]] as [$from, $to]) {
            $query = $pdo->prepare('SELECT affinity,relationship_json FROM town_npc_relationships WHERE npc_id=:npc AND other_npc_id=:other LIMIT 1');
            $query->execute([':npc' => $from, ':other' => $to]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            $affinity = max(-100, min(100, ((int)($row['affinity'] ?? 0)) + 1));
            $relationship = $relationshipJson($row['relationship_json'] ?? []);
            $relationship['last_interaction'] = 'talk';
            $relationship['last_dialogue'] = $dialogue;
            $relationship['last_task_id'] = $taskId;
            $relationship['updated_at'] = auth_sql_time($now);
            if (is_array($row)) {
                $update = $pdo->prepare('UPDATE town_npc_relationships SET affinity=:affinity,relationship_json=:relationship,updated_at=:updated WHERE npc_id=:npc AND other_npc_id=:other');
                $update->execute([':affinity' => $affinity, ':relationship' => json_encode($relationship, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':updated' => auth_sql_time($now), ':npc' => $from, ':other' => $to]);
            } else {
                $insert = $pdo->prepare('INSERT INTO town_npc_relationships (npc_id,other_npc_id,affinity,relationship_json,updated_at) VALUES (:npc,:other,:affinity,:relationship,:updated)');
                $insert->execute([':npc' => $from, ':other' => $to, ':affinity' => $affinity, ':relationship' => json_encode($relationship, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':updated' => auth_sql_time($now)]);
            }
        }
        $approach = is_array($payload['approach_tile'] ?? null) ? $payload['approach_tile'] : [];
        $targetTile = is_array($payload['target_tile'] ?? null) ? $payload['target_tile'] : [];
        $approachX = filter_var($approach['tile_x'] ?? $approach['x'] ?? null, FILTER_VALIDATE_INT);
        $approachY = filter_var($approach['tile_y'] ?? $approach['y'] ?? null, FILTER_VALIDATE_INT);
        $targetX = filter_var($targetTile['tile_x'] ?? $targetTile['x'] ?? null, FILTER_VALIDATE_INT);
        $targetY = filter_var($targetTile['tile_y'] ?? $targetTile['y'] ?? null, FILTER_VALIDATE_INT);
        $navigationVersion = filter_var($payload['navigation_version'] ?? null, FILTER_VALIDATE_INT);
        if ($navigationVersion === false) $navigationVersion = town_navigation_version($pdo);
        $locationAssetId = trim((string)($payload['location_asset_id'] ?? ''));
        $locationAssetName = trim((string)($payload['location_asset_name'] ?? ''));
        if (($locationAssetId === '' || $locationAssetName === '') && $approachX !== false && $approachY !== false) {
            try {
                $locationQuery = $pdo->prepare("SELECT o.asset_id,COALESCE(i.name,'') AS asset_name FROM town_objects o LEFT JOIN town_asset_items i ON i.asset_id=o.asset_id WHERE o.world_id=:world AND o.deleted_at IS NULL AND o.tile_x=:x AND o.tile_y=:y ORDER BY o.layer DESC LIMIT 1");
                $locationQuery->execute([':world' => SHARED_TOWN_WORLD_ID, ':x' => (int)$approachX, ':y' => (int)$approachY]);
                $locationRow = $locationQuery->fetch(PDO::FETCH_ASSOC);
                if (is_array($locationRow)) { if ($locationAssetId === '') $locationAssetId = trim((string)($locationRow['asset_id'] ?? '')); if ($locationAssetName === '') $locationAssetName = trim((string)($locationRow['asset_name'] ?? '')); }
            } catch (PDOException $error) { }
        }
        foreach ([$npcId, $otherId] as $owner) {
            $counterpart = $owner === $npcId ? $otherId : $npcId;
            $location = ($approachX !== false && $approachY !== false) ? '，地点坐标 (' . (int)$approachX . ',' . (int)$approachY . ')' : '';
            if ($locationAssetName !== '' || $locationAssetId !== '') $location .= '，附近素材“' . ($locationAssetName !== '' ? $locationAssetName : $locationAssetId) . '”';
            $memory = '与村民 ' . $counterpart . ' 面对面交谈' . $location . '：' . $dialogue;
            $spatial = [
                'subject_npc_id' => $counterpart,
                'asset_id' => $locationAssetId !== '' ? $locationAssetId : null,
                'asset_name' => $locationAssetName !== '' ? $locationAssetName : null,
                'tile_x' => $approachX === false ? null : (int)$approachX,
                'tile_y' => $approachY === false ? null : (int)$approachY,
                'navigation_version' => (int)$navigationVersion,
            ];
            town_npc_insert_spatial_memory($pdo, $owner, 'conversation', $memory, 3, $now, $spatial);
            town_npc_compact_memories($pdo, $owner, $now);
        }
        $conversation = ['task_id' => $taskId, 'other_npc_id' => $otherId, 'dialogue' => $dialogue, 'affinity_delta' => 1,
            'approach_tile' => ['tile_x' => $approachX === false ? null : (int)$approachX, 'tile_y' => $approachY === false ? null : (int)$approachY],
            'target_tile' => ['tile_x' => $targetX === false ? null : (int)$targetX, 'tile_y' => $targetY === false ? null : (int)$targetY],
            'navigation_version' => (int)$navigationVersion, 'facing' => (string)($payload['facing'] ?? 'down')];
        town_npc_emit_event($pdo, $npcId, 'conversation', $conversation, $now);
        town_npc_activity_log($pdo, $npcId, 'memory', '交谈已沉淀为关系和空间记忆', $conversation, $now, $payload['run_id'] ?? null);
        return ['other_npc_id' => $otherId, 'dialogue' => $dialogue, 'affinity_delta' => 1, 'approach_tile' => $conversation['approach_tile'], 'target_tile' => $conversation['target_tile'], 'navigation_version' => (int)$navigationVersion];
    } catch (PDOException $error) {
        // Keep older installations usable until the relationship/memory tables are migrated.
        return [];
    }
}

/**
 * Compact older conversation rows into one bounded deterministic summary.
 * This keeps the Agent context useful without requiring another model call.
 * The newest twelve raw conversations remain available for exact wording.
 */
function town_npc_compact_memories(PDO $pdo, string $npcId, DateTimeImmutable $now, int $threshold = 24): void
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $npcId)) return;
    $threshold = max(8, min(100, $threshold));
    try {
        $query = $pdo->prepare('SELECT memory_id,content,created_at FROM town_npc_memories WHERE npc_id=:npc AND memory_type=\'conversation\' ORDER BY memory_id ASC');
        $query->execute([':npc' => $npcId]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) <= $threshold) return;
        $keepCount = 12;
        $older = array_slice($rows, 0, max(1, count($rows) - $keepCount));
        $summaryQuery = $pdo->prepare('SELECT memory_id,content FROM town_npc_memories WHERE npc_id=:npc AND memory_type=\'conversation_summary\' ORDER BY memory_id DESC LIMIT 1');
        $summaryQuery->execute([':npc' => $npcId]);
        $summaryRow = $summaryQuery->fetch(PDO::FETCH_ASSOC);
        $parts = [];
        if (is_array($summaryRow) && trim((string)($summaryRow['content'] ?? '')) !== '') $parts[] = trim((string)$summaryRow['content']);
        foreach ($older as $row) {
            $content = trim((string)($row['content'] ?? ''));
            if ($content !== '') $parts[] = $content;
        }
        $summaryText = '历史交谈摘要（已压缩 ' . count($older) . ' 条）：' . implode('；', $parts);
        $summary = function_exists('mb_substr') ? mb_substr($summaryText, 0, 4000) : substr($summaryText, 0, 4000);
        if (is_array($summaryRow)) {
            $update = $pdo->prepare('UPDATE town_npc_memories SET content=:content,importance=3,created_at=:created WHERE memory_id=:memory');
            $update->execute([':content' => $summary, ':created' => auth_sql_time($now), ':memory' => (int)$summaryRow['memory_id']]);
        } else {
            $insert = $pdo->prepare('INSERT INTO town_npc_memories (npc_id,memory_type,content,importance,created_at) VALUES (:npc,\'conversation_summary\',:content,3,:created)');
            $insert->execute([':npc' => $npcId, ':content' => $summary, ':created' => auth_sql_time($now)]);
        }
        $delete = $pdo->prepare('DELETE FROM town_npc_memories WHERE memory_id=:memory AND npc_id=:npc AND memory_type=\'conversation\'');
        foreach ($older as $row) $delete->execute([':memory' => (int)$row['memory_id'], ':npc' => $npcId]);
    } catch (PDOException $error) {
        // Keep conversation writes usable during rolling upgrades.
    }
}

function town_npc_schedule_target(PDO $pdo, string $npcId, string $action): ?string
{
    try {
        $query = $pdo->prepare('SELECT target_id FROM town_npc_schedules WHERE npc_id=:npc AND action=:action AND target_id<>\'\' ORDER BY weekday,start_minute LIMIT 1');
        $query->execute([':npc' => $npcId, ':action' => $action]);
        $target = $query->fetchColumn();
        return is_string($target) && trim($target) !== '' ? trim($target) : null;
    } catch (PDOException $error) { return null; }
}

function town_npc_talk_target(PDO $pdo, string $targetNpcId): ?array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $targetNpcId)) return null;
    $query = $pdo->prepare('SELECT n.npc_id,n.name,n.enabled,n.agent_paused,r.tile_x,r.tile_y,r.state,r.current_task_id FROM town_npcs n LEFT JOIN town_npc_runtime_states r ON r.npc_id=n.npc_id WHERE n.npc_id=:npc LIMIT 1');
    $query->execute([':npc' => $targetNpcId]); $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || !(bool)$row['enabled'] || (bool)$row['agent_paused'] || $row['tile_x'] === null || $row['tile_y'] === null) return null;
    $row['lifecycle_state'] = town_npc_lifecycle($pdo, $targetNpcId)['state'] ?? 'active';
    if ($row['lifecycle_state'] !== 'active') return null;
    return $row;
}

function town_npc_talk_target_available(array $target): bool
{
    $taskId = trim((string)($target['current_task_id'] ?? ''));
    $state = (string)($target['state'] ?? 'rest');
    return ($target['lifecycle_state'] ?? 'active') === 'active' && $taskId === '' && $state === 'rest';
}

/** Return an online player's authoritative tile for a player-invited NPC task. */
function town_npc_player_target(PDO $pdo, string $userId, DateTimeImmutable $now): ?array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $userId)) return null;
    $query = $pdo->prepare('SELECT p.tile_x,p.tile_y,p.expires_at FROM town_presence p WHERE p.world_id=:world AND p.user_id=:user AND p.expires_at>:now LIMIT 1');
    $query->execute([':world' => SHARED_TOWN_WORLD_ID, ':user' => $userId, ':now' => auth_sql_time($now)]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;
    return ['tile_x' => (int)$row['tile_x'], 'tile_y' => (int)$row['tile_y']];
}

/** Build a route to an adjacent tile without ever occupying the player's cell. */
function town_npc_player_route(PDO $pdo, int $fromX, int $fromY, array $target, array $capabilities): ?array
{
    return town_npc_find_talk_route($pdo, $fromX, $fromY, $target, $capabilities);
}

function town_npc_talk_face_direction(int $fromX, int $fromY, int $toX, int $toY): string
{
    $dx = $toX - $fromX; $dy = $toY - $fromY;
    if (abs($dx) >= abs($dy) && $dx !== 0) return $dx > 0 ? 'right' : 'left';
    if ($dy !== 0) return $dy > 0 ? 'down' : 'up';
    return 'down';
}

function town_npc_set_facing(PDO $pdo, string $npcId, string $facing, DateTimeImmutable $now): void
{
    if (!town_npc_life_column_exists($pdo, 'town_npc_runtime_states', 'facing')) return;
    try { $pdo->prepare('UPDATE town_npc_runtime_states SET facing=:facing,updated_at=:updated WHERE npc_id=:npc')->execute([':facing' => in_array($facing, ['up', 'down', 'left', 'right'], true) ? $facing : 'down', ':updated' => auth_sql_time($now), ':npc' => $npcId]); } catch (PDOException $error) { }
}

/** Persist a failed search so the next Agent decision can choose another action. */
function town_npc_record_conversation_missing(PDO $pdo, string $npcId, string $targetNpcId, array $lastKnown, string $reason, DateTimeImmutable $now, ?string $taskId = null): void
{
    $x = filter_var($lastKnown['tile_x'] ?? null, FILTER_VALIDATE_INT); $y = filter_var($lastKnown['tile_y'] ?? null, FILTER_VALIDATE_INT);
    $coordinate = ($x !== false && $y !== false) ? '(' . (int)$x . ',' . (int)$y . ')' : '未知坐标';
    $content = '寻找村民 ' . $targetNpcId . ' 失败：最后已知坐标 ' . $coordinate . '，原因：' . substr($reason, 0, 180);
    try {
        $spatial = ['subject_npc_id' => $targetNpcId, 'tile_x' => $x === false ? null : (int)$x, 'tile_y' => $y === false ? null : (int)$y, 'navigation_version' => town_navigation_version($pdo)];
        town_npc_insert_spatial_memory($pdo, $npcId, 'conversation_target_missing', $content, 3, $now, $spatial, $now->modify('+7 days'));
    } catch (PDOException $error) { }
    try { town_npc_emit_event($pdo, $npcId, 'conversation_target_missing', ['task_id' => $taskId, 'target_npc_id' => $targetNpcId, 'last_known' => ['tile_x' => $x === false ? null : (int)$x, 'tile_y' => $y === false ? null : (int)$y], 'reason' => substr($reason, 0, 180), 'navigation_version' => town_navigation_version($pdo)], $now); } catch (PDOException $error) { }
    try { town_npc_activity_log($pdo, $npcId, 'path', '找不到交谈对象，等待下一次决策', ['task_id' => $taskId, 'target_npc_id' => $targetNpcId, 'last_known' => ['tile_x' => $x === false ? null : (int)$x, 'tile_y' => $y === false ? null : (int)$y], 'reason' => $reason], $now); } catch (PDOException $error) { }
}

/** Find a route to a legal adjacent interaction tile, never to the target's occupied cell. */
function town_npc_find_talk_route(PDO $pdo, int $fromX, int $fromY, array $target, array $capabilities): ?array
{
    $targetX = (int)$target['tile_x']; $targetY = (int)$target['tile_y']; $candidates = [
        [$targetX, $targetY - 1], [$targetX + 1, $targetY], [$targetX, $targetY + 1], [$targetX - 1, $targetY],
    ];
    $best = null;
    foreach ($candidates as [$x, $y]) {
        if ($x < 0 || $y < 0 || $x >= SHARED_TOWN_MAP_SIZE || $y >= SHARED_TOWN_MAP_SIZE) continue;
        $route = town_navigation_find_route($pdo, $fromX, $fromY, $x, $y, $capabilities);
        if (!$route['found']) continue;
        if ($best === null || count($route['path']) < count($best['path'])) $best = $route + ['approach_tile' => ['tile_x' => $x, 'tile_y' => $y], 'target_tile' => ['tile_x' => $targetX, 'tile_y' => $targetY]];
    }
    return $best;
}

function town_npc_emit_event(PDO $pdo, string $npcId, string $type, array $payload, DateTimeImmutable $now): int
{
    $insert = $pdo->prepare('INSERT INTO town_npc_events (npc_id,event_type,payload_json,created_at) VALUES (:npc,:type,:payload,:created)');
    $insert->execute([':npc' => $npcId, ':type' => $type, ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':created' => auth_sql_time($now)]);
    $eventId = (int)$pdo->lastInsertId();
    try {
        town_world_event_project_npc($pdo, $eventId, $npcId, $type, $payload, $now);
    } catch (PDOException $error) {
        // The public event migration can be rolled out after the NPC migration.
        // Private NPC events must continue to work during that compatibility window.
    }
    return $eventId;
}

/**
 * Keep the optional social-commitment projection in the same transaction as
 * the authoritative NPC task transition. The social module is loaded by the
 * worker advance endpoint; ordinary NPC endpoints remain usable during a
 * rolling deployment where that module is not present yet.
 */
function town_npc_sync_social_task_if_available(PDO $pdo, string $taskId, string $npcId, string $status, ?string $reason, DateTimeImmutable $now): void
{
    if ($taskId === '') return;
    if (!function_exists('town_social_sync_task')) {
        $socialModule = __DIR__ . '/town_social_matters.php';
        if (is_file($socialModule)) require_once $socialModule;
    }
    if (!function_exists('town_social_sync_task')) return;
    town_social_sync_task($pdo, $taskId, $npcId, $status, $reason, $now);
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

/** Continue an already established face-to-face player conversation. */
function town_npc_continue_player_talk(PDO $pdo, string $npcId, string $userId, string $playerDialogue, DateTimeImmutable $now, ?array $source = null): array
{
    $moderated = town_npc_moderate_dialogue($playerDialogue);
    if (!$moderated['allowed'] || $moderated['text'] === '') throw new ApiError('dialogue_rejected', '交谈内容无效。', 400);
    $playerDialogue = mb_substr((string)$moderated['text'], 0, 240, 'UTF-8');
    // Model calls happen outside the write transaction. A slow provider must
    // never hold the NPC row lock or block other players from observing it.
    $history = [];
    try {
        $historyQuery = $pdo->prepare('SELECT payload_json FROM town_npc_tasks t JOIN town_npc_runtime_states r ON r.current_task_id=t.task_id WHERE t.npc_id=:npc AND t.action=\'player_talk\' AND t.status=\'running\' LIMIT 1');
        $historyQuery->execute([':npc' => $npcId]);
        $historyRow = $historyQuery->fetch(PDO::FETCH_ASSOC);
        if (is_array($historyRow)) { $historyPayload = town_npc_array(town_npc_decode($historyRow['payload_json'] ?? [], [])); $history = is_array($historyPayload['player_turns'] ?? null) ? $historyPayload['player_turns'] : []; }
    } catch (PDOException $error) { }
    $modelReply = town_agent_player_talk($npcId, $playerDialogue, $history);
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT n.agent_paused,r.tile_x,r.tile_y,r.state,r.current_task_id,t.action,t.status,t.payload_json FROM town_npcs n LEFT JOIN town_npc_runtime_states r ON r.npc_id=n.npc_id LEFT JOIN town_npc_tasks t ON t.task_id=r.current_task_id AND t.npc_id=r.npc_id WHERE n.npc_id=:npc' . $lock);
        $q->execute([':npc' => $npcId]); $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (bool)$row['agent_paused']) throw new ApiError('npc_paused', '该村民的 Agent 已暂停。', 409);
        if ((string)($row['state'] ?? '') !== 'player_talk' || (string)($row['action'] ?? '') !== 'player_talk' || (string)($row['status'] ?? '') !== 'running') {
            throw new ApiError('npc_invitation_not_found', '请先让村民走到你身边，再继续交谈。', 409);
        }
        $payload = town_npc_array(town_npc_decode($row['payload_json'] ?? [], []));
        if (!hash_equals((string)($payload['target_player_user_id'] ?? ''), $userId)) throw new ApiError('npc_invitation_forbidden', '这不是你发起的交谈。', 403);
        $requestedConversation = is_array($source) ? trim((string)($source['conversation_id'] ?? '')) : '';
        if ($requestedConversation !== '' && !hash_equals($requestedConversation, (string)$row['current_task_id'])) throw new ApiError('conversation_mismatch', '交谈会话已发生变化，请刷新后重试。', 409);
        $target = town_npc_player_target($pdo, $userId, $now);
        $x = (int)($row['tile_x'] ?? 0); $y = (int)($row['tile_y'] ?? 0);
        if (!is_array($target) || abs($target['tile_x'] - $x) + abs($target['tile_y'] - $y) > 1) throw new ApiError('player_target_missing', '你已经离开面对面交谈的位置。', 409);
        $reply = (string)$modelReply['reply'];
        $turns = is_array($payload['player_turns'] ?? null) ? $payload['player_turns'] : [];
        $photoSource = is_array($source) && (string)($source['type'] ?? '') === 'photo' ? [
            'type' => 'photo', 'mode' => substr((string)($source['mode'] ?? ''), 0, 32),
            'fact_summary' => mb_substr((string)($source['fact_summary'] ?? ''), 0, 240, 'UTF-8'),
        ] : null;
        $turn = ['speaker' => 'player', 'text' => $playerDialogue, 'created_at' => auth_sql_time($now)];
        if ($photoSource !== null) $turn['source'] = $photoSource;
        $turns[] = $turn;
        $turns[] = ['speaker' => 'npc', 'text' => $reply, 'created_at' => auth_sql_time($now)];
        $payload['player_turns'] = array_slice($turns, -12); $payload['dialogue'] = $reply;
        $payload['reply_source'] = (string)($modelReply['source'] ?? 'fallback');
        $payload['mood'] = is_array($modelReply['mood'] ?? null) ? $modelReply['mood'] : [];
        $payload['next_action'] = is_array($modelReply['next_action'] ?? null) ? $modelReply['next_action'] : [];
        if (is_string($modelReply['run_id'] ?? null) && $modelReply['run_id'] !== '') $payload['model_run_id'] = $modelReply['run_id'];
        // Each new line keeps the face-to-face session alive for another minute.
        $payload['arrived_at'] = auth_sql_time($now); $payload['duration_minutes'] = 1;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $pdo->prepare('UPDATE town_npc_tasks SET payload_json=:payload WHERE task_id=:task AND npc_id=:npc')->execute([':payload' => $json, ':task' => (string)$row['current_task_id'], ':npc' => $npcId]);
        $pdo->prepare('UPDATE town_npc_runtime_states SET dialogue=:dialogue,state_version=state_version+1,updated_at=:updated WHERE npc_id=:npc')->execute([':dialogue' => $reply, ':updated' => auth_sql_time($now), ':npc' => $npcId]);
        town_npc_emit_event($pdo, $npcId, 'state_changed', ['state' => 'player_talk', 'tile_x' => $x, 'tile_y' => $y, 'task_id' => (string)$row['current_task_id'], 'task_status' => 'running', 'dialogue' => $reply, 'target_player_user_id' => $userId], $now);
        $playerDetail = ['dialogue' => $playerDialogue, 'task_id' => (string)$row['current_task_id'], 'target_player_user_id' => $userId, 'tile_x' => $x, 'tile_y' => $y];
        if ($photoSource !== null) $playerDetail['photo'] = $photoSource + ['extracted_text' => mb_substr((string)($source['extracted_text'] ?? ''), 0, 240, 'UTF-8')];
        town_npc_activity_log($pdo, $npcId, 'player_speech', $playerDialogue, $playerDetail, $now);
        town_npc_activity_log($pdo, $npcId, 'speech', $reply, ['dialogue' => $reply, 'action' => 'player_talk', 'task_id' => (string)$row['current_task_id'], 'target_player_user_id' => $userId, 'source' => $modelReply['source'] ?? 'fallback', 'mood' => $modelReply['mood'] ?? [], 'next_action' => $modelReply['next_action'] ?? []], $now, is_string($modelReply['run_id'] ?? null) ? $modelReply['run_id'] : null);
        town_npc_insert_spatial_memory($pdo, $npcId, 'player_conversation', '与玩家 ' . $userId . ' 面对面交谈：玩家说“' . $playerDialogue . '”，NPC 回复“' . $reply . '”', 3, $now, ['tile_x' => $x, 'tile_y' => $y, 'navigation_version' => town_navigation_version($pdo)], $now->modify('+30 days'));
        town_npc_compact_memories($pdo, $npcId, $now);
        town_npc_emit_event($pdo, $npcId, 'player_conversation', ['user_id' => $userId, 'task_id' => (string)$row['current_task_id'], 'player_dialogue' => $playerDialogue, 'dialogue' => $reply, 'tile_x' => $x, 'tile_y' => $y, 'source' => $modelReply['source'] ?? 'fallback', 'next_action' => $modelReply['next_action'] ?? []], $now);
        $messageId = null;
        if ($photoSource !== null) {
            $messageId = town_npc_emit_event($pdo, $npcId, 'player_conversation', [
                'user_id' => $userId, 'task_id' => (string)$row['current_task_id'], 'dialogue' => $reply,
                'source' => $photoSource, 'extracted_text' => mb_substr((string)($source['extracted_text'] ?? ''), 0, 240, 'UTF-8'),
                'fact_summary' => mb_substr((string)($source['fact_summary'] ?? ''), 0, 240, 'UTF-8'),
                'tile_x' => $x, 'tile_y' => $y,
            ], $now);
        }
        $pdo->commit();
        return ['ok' => true, 'npc_id' => $npcId, 'action' => 'player_talk', 'task_id' => (string)$row['current_task_id'], 'task_status' => 'running', 'state' => 'player_talk', 'tile_x' => $x, 'tile_y' => $y, 'dialogue' => $reply, 'continued' => true] + ($messageId !== null ? ['message_id' => $messageId, 'photo' => $photoSource] : []);
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function town_npc_apply_action(PDO $pdo, array $body, DateTimeImmutable $now, bool $service = false, bool $admin = false, bool $allowIdleRestInterrupt = false): array
{
    if (!$service && !$admin) throw new ApiError('agent_forbidden', 'NPC actions require the worker service identity.', 403);
    $npcId = is_string($body['npc_id'] ?? null) ? trim((string)$body['npc_id']) : '';
    $action = is_string($body['action'] ?? null) ? strtolower(trim((string)$body['action'])) : '';
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $npcId) || !in_array($action, TOWN_NPC_ACTIONS, true)) throw new ApiError('invalid_npc_action', 'NPC action is invalid.', 400);
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME); $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
    // Social commitment assignment calls this authoritative action executor
    // while its matter row is already locked. Reusing the surrounding
    // transaction keeps commitment creation and task creation atomic; direct
    // worker/admin calls retain the original transaction boundary.
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT n.npc_id,n.enabled,n.agent_paused,r.tile_x,r.tile_y,' . town_npc_runtime_height_select($pdo) . ',r.state,r.current_task_id,r.capabilities_json FROM town_npcs n LEFT JOIN town_npc_runtime_states r ON r.npc_id=n.npc_id WHERE n.npc_id=:npc' . $lock);
        $query->execute([':npc' => $npcId]); $npc = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($npc) || !(bool)$npc['enabled']) throw new ApiError('npc_not_found', 'NPC was not found.', 404);
        $lifecycle = town_npc_lifecycle($pdo, $npcId);
        if (($lifecycle['state'] ?? 'active') !== 'active') {
            throw new ApiError('npc_lifecycle_inactive', 'NPC 当前不在岗，暂时不能执行行动。', 409);
        }
        if ((bool)$npc['agent_paused'] && !$admin) throw new ApiError('npc_paused', 'NPC agent is paused.', 409);
        if ($action === 'player_talk' && (string)($npc['state'] ?? '') !== 'rest') {
            throw new ApiError('npc_busy', '我现在正在忙，请先等一等。', 409);
        }
        if (is_string($npc['current_task_id'] ?? null) && trim((string)$npc['current_task_id']) !== '') {
            $active = $pdo->prepare('SELECT action,status,payload_json FROM town_npc_tasks WHERE task_id=:task AND npc_id=:npc' . $lock);
            $active->execute([':task' => $npc['current_task_id'], ':npc' => $npcId]);
            $activeTask = $active->fetch(PDO::FETCH_ASSOC);
            // Player invitations may interrupt only for their two invitation
            // actions.  A server-authorized social commitment can also take
            // over an idle rest task, but callers still cannot bypass this
            // guard for a running work, talk, trade, or walk task.
            $canInterruptRest = $allowIdleRestInterrupt
                && (($service && in_array($action, TOWN_NPC_ACTIONS, true))
                    || in_array($action, ['follow', 'player_talk'], true))
                && (string)($npc['state'] ?? '') === 'rest'
                && is_array($activeTask)
                && (string)($activeTask['action'] ?? '') === 'rest'
                && (string)($activeTask['status'] ?? '') === 'running';
            if ((string)($activeTask['status'] ?? '') === 'running' && !$canInterruptRest) {
                throw new ApiError('npc_busy', '我现在正在忙，请先等一等。', 409);
            }
            if ($canInterruptRest) {
                $pdo->prepare("UPDATE town_npc_tasks SET status='cancelled',failure_reason='player_invitation',completed_at=:completed WHERE task_id=:task AND npc_id=:npc AND status='running'")
                    ->execute([':completed' => auth_sql_time($now), ':task' => $npc['current_task_id'], ':npc' => $npcId]);
                town_npc_sync_social_task_if_available($pdo, (string)$npc['current_task_id'], $npcId, 'cancelled', 'player_invitation', $now);
                town_npc_emit_event($pdo, $npcId, 'task_cancelled', [
                    'task_id' => (string)$npc['current_task_id'], 'action' => 'rest', 'reason' => 'player_invitation',
                    'tile_x' => (int)$npc['tile_x'], 'tile_y' => (int)$npc['tile_y'],
                ], $now);
            }
        }
        $payload = town_npc_array($body['payload'] ?? []);
        $weatherStamp = town_npc_weather_stamp($pdo, $now);
        $payload['weather_code'] = $weatherStamp['weather_code'];
        $payload['weather_revision'] = $weatherStamp['weather_revision'];
        $workPoint = null; $requiredCapabilities = []; $consumedItems = [];
        $fromX = (int)($npc['tile_x'] ?? 0); $fromY = (int)($npc['tile_y'] ?? 0); $fromHeight = town_npc_navigation_height($pdo, $fromX, $fromY); $tileX = $fromX; $tileY = $fromY; $tileHeight = $fromHeight;
        $capabilities = array_values(array_unique(array_merge(
            town_navigation_capabilities($npc['capabilities_json'] ?? []), town_npc_inventory_capabilities($pdo, $npcId)
        )));
        $declaredCapabilities = is_array($payload['required_capabilities'] ?? null)
            ? array_values($payload['required_capabilities']) : [];
        town_npc_validate_plan_envelope($payload, $action, $capabilities);
        if ($action === 'walk') {
            $target = town_npc_array($payload['target'] ?? $payload);
            $toX = filter_var($target['tile_x'] ?? $target['x'] ?? null, FILTER_VALIDATE_INT);
            $toY = filter_var($target['tile_y'] ?? $target['y'] ?? null, FILTER_VALIDATE_INT);
            if ($toX === false || $toY === false) {
                $targetId = trim((string)($payload['target_id'] ?? $target['target_id'] ?? ''));
                if ($targetId !== '') {
                    $named = town_npc_target_point($targetId, $pdo);
                    $toX = $named['tile_x'] ?? false;
                    $toY = $named['tile_y'] ?? false;
                }
            }
            if ($toX === false || $toY === false) throw new ApiError('invalid_target', 'A walk target is required.', 400);
            $route = town_navigation_find_route($pdo, $fromX, $fromY, (int)$toX, (int)$toY, $capabilities);
            if (!$route['found']) throw new ApiError('no_route', 'NPC cannot reach the requested target.', 409);
            $tileX = (int)$toX; $tileY = (int)$toY; $tileHeight = town_npc_navigation_height($pdo, $tileX, $tileY); $payload['route'] = $route['path']; $payload['navigation_version'] = $route['navigation_version'];
        }
        if (in_array($action, ['work', 'talk', 'trade'], true)) {
            $target = town_npc_array($payload['target'] ?? []); $targetId = (string)($payload['target_id'] ?? '');
            if ($targetId === '') $targetId = town_npc_schedule_target($pdo, $npcId, $action) ?? '';
            if ($targetId !== '') $payload['target_id'] = $targetId;
            if ($action === 'talk') {
                $talkTarget = trim((string)($payload['target_npc_id'] ?? $targetId));
                if (!$admin && preg_match('/\A[a-f0-9]{32}\z/D', $talkTarget) && $talkTarget !== $npcId) {
                    // Keep the first version from repeatedly spawning the same
                    // conversation when a schedule/model returns talk every
                    // second. The cooldown is server-side and therefore also
                    // applies to a second worker or a forged request.
                    try {
                        $recent = $pdo->prepare('SELECT completed_at FROM town_npc_tasks WHERE npc_id=:npc AND action=\'talk\' AND target_id=:target AND status=\'completed\' AND completed_at IS NOT NULL ORDER BY completed_at DESC LIMIT 1');
                        $recent->execute([':npc' => $npcId, ':target' => $talkTarget]);
                        $completedAt = $recent->fetchColumn();
                        $cooldownSeconds = (int)(town_npc_life_policy($pdo, $npcId)['dialogue_cooldown_seconds'] ?? 60);
                        if (is_string($completedAt) && $completedAt !== '' && auth_parse_sql_time($completedAt)->modify('+' . max(0, $cooldownSeconds) . ' seconds') > $now) {
                            throw new ApiError('talk_cooldown', 'NPC conversation is cooling down.', 409);
                        }
                    } catch (ApiError $error) { throw $error; }
                    catch (PDOException $error) { /* Older rolling schemas may not have task timestamps. */ }
                }
                if (preg_match('/\A[a-f0-9]{32}\z/D', $talkTarget) && $talkTarget !== $npcId) {
                    $targetRow = town_npc_talk_target($pdo, $talkTarget);
                    if (!is_array($targetRow)) {
                        town_npc_record_conversation_missing($pdo, $npcId, $talkTarget, $target, '交谈对象不存在、已停用或没有当前位置', $now);
                        if ($ownsTransaction) $pdo->commit();
                        throw new ApiError('conversation_target_missing', '交谈对象当前不可用，已记录最后已知位置。', 409);
                    }
                    if (!town_npc_talk_target_available($targetRow)) {
                        town_npc_record_conversation_missing($pdo, $npcId, $talkTarget, $targetRow, '交谈对象正在工作、行走或已有其他任务', $now);
                        if ($ownsTransaction) $pdo->commit();
                        throw new ApiError('conversation_target_busy', '交谈对象当前正在忙碌，已记录寻找失败。', 409);
                    }
                    $payload['target_npc_id'] = $talkTarget;
                    // The worker may send a remembered coordinate. When it has no
                    // memory, use the current authoritative position as the first
                    // observation. The completion check below detects movement
                    // after planning and records a missing-target memory.
                    $knownTargetPayload = town_npc_array($payload['known_target'] ?? []);
                    $knownX = filter_var($target['tile_x'] ?? $target['x'] ?? $knownTargetPayload['tile_x'] ?? $knownTargetPayload['x'] ?? null, FILTER_VALIDATE_INT);
                    $knownY = filter_var($target['tile_y'] ?? $target['y'] ?? $knownTargetPayload['tile_y'] ?? $knownTargetPayload['y'] ?? null, FILTER_VALIDATE_INT);
                    if ($knownX === false || $knownY === false) { $knownX = (int)$targetRow['tile_x']; $knownY = (int)$targetRow['tile_y']; }
                    $knownTarget = ['tile_x' => (int)$knownX, 'tile_y' => (int)$knownY];
                    $talkRoute = town_npc_find_talk_route($pdo, $fromX, $fromY, $knownTarget + ['npc_id' => $talkTarget], $capabilities);
                    if (!is_array($talkRoute)) {
                        town_npc_record_conversation_missing($pdo, $npcId, $talkTarget, $knownTarget, '最后已知位置没有可达的相邻交互格', $now);
                        if ($ownsTransaction) $pdo->commit();
                        throw new ApiError('conversation_no_route', '无法到达交谈对象旁边的交互位置，已记录寻找失败。', 409);
                    }
                    $target = $talkRoute['approach_tile'];
                    $payload['target_id'] = $talkTarget;
                    $payload['known_target'] = $knownTarget;
                    $payload['target_tile'] = ['tile_x' => (int)$knownTarget['tile_x'], 'tile_y' => (int)$knownTarget['tile_y']];
                    $payload['approach_tile'] = ['tile_x' => (int)$talkRoute['approach_tile']['tile_x'], 'tile_y' => (int)$talkRoute['approach_tile']['tile_y']];
                    $payload['facing'] = town_npc_talk_face_direction($fromX, $fromY, (int)$knownTarget['tile_x'], (int)$knownTarget['tile_y']);
                    $payload['navigation_version'] = (int)$talkRoute['navigation_version'];
                    $payload['route'] = $talkRoute['path'];
                }
            }
            if ($action === 'trade') {
                $tradeTarget = trim((string)($payload['target_npc_id'] ?? $payload['counterparty_npc_id'] ?? $targetId));
                if (!preg_match('/\A[a-f0-9]{32}\z/D', $tradeTarget) || $tradeTarget === $npcId) {
                    throw new ApiError('invalid_npc_trade', '交易必须指定另一个启用的村民。', 400);
                }
                $targetRow = town_npc_talk_target($pdo, $tradeTarget);
                if (!is_array($targetRow)) throw new ApiError('trade_target_missing', '交易对象当前不可用。', 409);
                if (!town_npc_talk_target_available($targetRow)) throw new ApiError('trade_target_busy', '交易对象当前正在执行其他任务。', 409);
                $knownTargetPayload = town_npc_array($payload['known_target'] ?? []);
                $knownX = filter_var($target['tile_x'] ?? $target['x'] ?? $knownTargetPayload['tile_x'] ?? $knownTargetPayload['x'] ?? null, FILTER_VALIDATE_INT);
                $knownY = filter_var($target['tile_y'] ?? $target['y'] ?? $knownTargetPayload['tile_y'] ?? $knownTargetPayload['y'] ?? null, FILTER_VALIDATE_INT);
                if ($knownX === false || $knownY === false) { $knownX = (int)$targetRow['tile_x']; $knownY = (int)$targetRow['tile_y']; }
                $knownTarget = ['tile_x' => (int)$knownX, 'tile_y' => (int)$knownY];
                $tradeRoute = town_npc_find_talk_route($pdo, $fromX, $fromY, $knownTarget + ['npc_id' => $tradeTarget], $capabilities);
                if (!is_array($tradeRoute)) throw new ApiError('trade_no_route', '无法到达交易对象旁边的交互位置。', 409);
                $target = $tradeRoute['approach_tile']; $targetId = $tradeTarget;
                $payload['target_npc_id'] = $tradeTarget; $payload['target_id'] = $tradeTarget; $payload['known_target'] = $knownTarget;
                $payload['target_tile'] = ['tile_x' => (int)$knownTarget['tile_x'], 'tile_y' => (int)$knownTarget['tile_y']];
                $payload['approach_tile'] = ['tile_x' => (int)$tradeRoute['approach_tile']['tile_x'], 'tile_y' => (int)$tradeRoute['approach_tile']['tile_y']];
                $payload['facing'] = town_npc_talk_face_direction($fromX, $fromY, (int)$knownTarget['tile_x'], (int)$knownTarget['tile_y']);
                $payload['navigation_version'] = (int)$tradeRoute['navigation_version']; $payload['route'] = $tradeRoute['path'];
                $trade = town_npc_array($payload['trade'] ?? $payload);
                $itemId = trim((string)($trade['item_id'] ?? '')); $quantity = filter_var($trade['quantity'] ?? null, FILTER_VALIDATE_INT); $amount = filter_var($trade['amount'] ?? null, FILTER_VALIDATE_INT);
                if (!preg_match('/\A[a-zA-Z0-9._:-]{1,128}\z/D', $itemId) || $quantity === false || $quantity < 1 || $quantity > 1000000 || $amount === false || $amount < 0) throw new ApiError('invalid_npc_trade', '交易商品、数量或金额无效。', 400);
                $seller = trim((string)($trade['seller_npc_id'] ?? '')); $buyer = trim((string)($trade['buyer_npc_id'] ?? ''));
                if ($seller === '' && $buyer === '') { $seller = $npcId; $buyer = $tradeTarget; }
                elseif ($seller === '') $seller = $seller === $buyer ? $npcId : ($buyer === $npcId ? $tradeTarget : $npcId);
                elseif ($buyer === '') $buyer = $seller === $npcId ? $tradeTarget : $npcId;
                if (!in_array($npcId, [$seller, $buyer], true) || !in_array($tradeTarget, [$seller, $buyer], true) || $seller === $buyer) throw new ApiError('invalid_npc_trade', '交易双方必须是发起者和目标村民。', 400);
                $payload['trade'] = ['seller_npc_id' => $seller, 'buyer_npc_id' => $buyer, 'item_id' => $itemId, 'quantity' => (int)$quantity, 'amount' => (int)$amount, 'reason' => substr(trim((string)($trade['reason'] ?? 'NPC 之间交换物资')), 0, 255)];
            }
            if ($action === 'work' && $targetId !== '') $workPoint = town_npc_work_point($pdo, $targetId);
            $toX = filter_var($target['tile_x'] ?? $target['x'] ?? null, FILTER_VALIDATE_INT); $toY = filter_var($target['tile_y'] ?? $target['y'] ?? null, FILTER_VALIDATE_INT);
            if ($toX === false || $toY === false) { $named = town_npc_target_point($targetId, $pdo); $toX = $named['tile_x'] ?? false; $toY = $named['tile_y'] ?? false; }
            if ($toX !== false && $toY !== false) {
                $route = town_navigation_find_route($pdo, $fromX, $fromY, (int)$toX, (int)$toY, $capabilities);
                if (!$route['found']) throw new ApiError('no_route', 'NPC cannot reach the requested work or talk target.', 409);
                $tileX = (int)$toX; $tileY = (int)$toY; $tileHeight = town_npc_navigation_height($pdo, $tileX, $tileY); $payload['route'] = $route['path']; $payload['navigation_version'] = $route['navigation_version'];
            }
        }
        if (in_array($action, ['follow', 'player_talk'], true)) {
            $playerId = trim((string)($payload['target_player_user_id'] ?? ''));
            if (!preg_match('/\A[a-f0-9]{32}\z/D', $playerId)) throw new ApiError('invalid_player_target', 'Player invitation target is invalid.', 400);
            $playerTarget = town_npc_player_target($pdo, $playerId, $now);
            if (!is_array($playerTarget)) throw new ApiError('player_target_missing', 'The player is no longer online in the town.', 409);
            $playerRoute = town_npc_player_route($pdo, $fromX, $fromY, $playerTarget, $capabilities);
            if (!is_array($playerRoute)) throw new ApiError('player_target_no_route', 'NPC cannot reach the player.', 409);
            $payload['target_player_user_id'] = $playerId;
            $payload['target_tile'] = $playerTarget;
            $payload['approach_tile'] = $playerRoute['approach_tile'];
            $payload['navigation_version'] = (int)$playerRoute['navigation_version'];
            $payload['route'] = $playerRoute['path'];
            $payload['duration_minutes'] = $action === 'follow' ? 1440 : 1;
            $tileX = (int)$playerRoute['approach_tile']['tile_x']; $tileY = (int)$playerRoute['approach_tile']['tile_y'];
        }
        if (!is_array($payload['route'] ?? null) || $payload['route'] === []) $payload['route'] = [['x' => $fromX, 'y' => $fromY, 'height' => $fromHeight]];
        $dialogueResult = town_npc_moderate_dialogue((string)($payload['dialogue'] ?? ''));
        if (!$dialogueResult['allowed']) throw new ApiError('dialogue_rejected', 'NPC dialogue contains unsupported markup.', 400);
        $dialogue = (string)$dialogueResult['text'];
        if ($action === 'talk' || $dialogue !== '') $payload['dialogue'] = $dialogue;
        $runId = is_string($payload['run_id'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/D', (string)$payload['run_id']) ? (string)$payload['run_id'] : null;
        $mood = town_npc_array($payload['mood'] ?? []); $payload['route_cursor'] = 0;
        $duration = filter_var($payload['duration_minutes'] ?? null, FILTER_VALIDATE_INT); if ($duration === false) $duration = in_array($action, ['walk', 'trade'], true) ? 0 : 20; $payload['duration_minutes'] = max(0, min(1440, (int)$duration));
        $route = $payload['route'];
        $requiredCapabilities = town_npc_route_capabilities($pdo, $route);
        $declaredAvailable = array_fill_keys(array_values(array_unique(array_merge($capabilities, $requiredCapabilities))), true);
        foreach ($declaredCapabilities as $capability) {
            if (!isset($declaredAvailable[$capability])) {
                throw new ApiError('invalid_npc_plan', 'The NPC plan requested a capability it cannot use for this route.', 409);
            }
        }
        $consumedItems = town_npc_consume_route_items($pdo, $npcId, $requiredCapabilities, $npc['capabilities_json'] ?? [], $now);
        $payload['required_capabilities'] = $requiredCapabilities;
        $payload['consumed_items'] = $consumedItems;
        $taskId = town_npc_id();
        $task = $pdo->prepare('INSERT INTO town_npc_tasks (task_id,npc_id,action,target_id,payload_json,status,attempts,scheduled_at,started_at) VALUES (:task,:npc,:action,:target,:payload,\'running\',1,:scheduled,:started)');
        $task->execute([':task' => $taskId, ':npc' => $npcId, ':action' => $action, ':target' => (string)($payload['target_id'] ?? ''), ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':scheduled' => auth_sql_time($now), ':started' => auth_sql_time($now)]);
        if ($action === 'work' && is_array($workPoint)) town_npc_reserve_work_point($pdo, $workPoint, $npcId, $taskId, $now, (int)$payload['duration_minutes']);
        $state = count($route) > 1 ? 'walk' : $action; $moodJson = json_encode($mood, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $update = $pdo->prepare('UPDATE town_npc_runtime_states SET tile_x=:x,tile_y=:y' . (town_npc_runtime_height_column($pdo) ? ',height=:height' : '') . ',state=:state,current_task_id=:task,dialogue=:dialogue,mood_json=:mood,state_version=state_version+1,updated_at=:updated WHERE npc_id=:npc');
        $updateParams = [':x' => $fromX, ':y' => $fromY, ':state' => $state, ':task' => $taskId, ':dialogue' => $dialogue, ':mood' => $moodJson, ':updated' => auth_sql_time($now), ':npc' => $npcId];
        if (town_npc_runtime_height_column($pdo)) $updateParams[':height'] = $fromHeight;
        $update->execute($updateParams);
        if ($update->rowCount() === 0) {
            $heightColumns = town_npc_runtime_height_column($pdo) ? ',height' : '';
            $heightValues = town_npc_runtime_height_column($pdo) ? ',:height' : '';
            $insert = $pdo->prepare('INSERT INTO town_npc_runtime_states (npc_id,tile_x,tile_y' . $heightColumns . ',state,current_task_id,dialogue,mood_json,capabilities_json,state_version,updated_at) VALUES (:npc,:x,:y' . $heightValues . ',:state,:task,:dialogue,:mood,\'[]\',1,:updated)');
            $insertParams = [':npc' => $npcId, ':x' => $fromX, ':y' => $fromY, ':state' => $state, ':task' => $taskId, ':dialogue' => $dialogue, ':mood' => $moodJson, ':updated' => auth_sql_time($now)];
            if (town_npc_runtime_height_column($pdo)) $insertParams[':height'] = $fromHeight;
            $insert->execute($insertParams);
        }
        $detail = ['action' => $action, 'state' => $state, 'task_id' => $taskId, 'target_id' => (string)($payload['target_id'] ?? ''), 'from' => ['tile_x' => $fromX, 'tile_y' => $fromY, 'height' => $fromHeight], 'to' => ['tile_x' => $tileX, 'tile_y' => $tileY, 'height' => $tileHeight], 'route' => $route, 'route_length' => count($route), 'route_cursor' => 0, 'dialogue' => $dialogue, 'mood' => $mood, 'job' => (string)($payload['job'] ?? ''), 'navigation_version' => $payload['navigation_version'] ?? null];
        town_npc_emit_event($pdo, $npcId, 'state_changed', ['state' => $state, 'tile_x' => $fromX, 'tile_y' => $fromY, 'height' => $fromHeight, 'task_id' => $taskId, 'task_status' => 'running', 'dialogue' => $dialogue, 'mood' => $mood, 'route' => $route, 'route_cursor' => 0, 'navigation_version' => $payload['navigation_version'] ?? null], $now);
        town_npc_activity_log($pdo, $npcId, 'action', $action . ' · ' . ($payload['target_id'] ?? '无目标'), $detail, $now, $runId);
        if ($dialogue !== '') town_npc_activity_log($pdo, $npcId, 'speech', $dialogue, ['dialogue' => $dialogue, 'action' => $action, 'task_id' => $taskId], $now, $runId);
        if ($action === 'player_talk' && trim((string)($payload['player_dialogue'] ?? '')) !== '') {
            $playerLine = trim((string)$payload['player_dialogue']);
            town_npc_activity_log($pdo, $npcId, 'player_speech', $playerLine, ['dialogue' => $playerLine, 'action' => $action, 'task_id' => $taskId, 'target_player_user_id' => $payload['target_player_user_id'] ?? null], $now, $runId);
            town_npc_insert_spatial_memory($pdo, $npcId, 'player_conversation', '与玩家 ' . (string)($payload['target_player_user_id'] ?? '') . ' 面对面交谈：玩家说“' . $playerLine . '”，NPC 回复“' . $dialogue . '”', 3, $now, ['tile_x' => $fromX, 'tile_y' => $fromY, 'navigation_version' => town_navigation_version($pdo)], $now->modify('+30 days'));
            town_npc_emit_event($pdo, $npcId, 'player_conversation', ['user_id' => $payload['target_player_user_id'] ?? null, 'task_id' => $taskId, 'player_dialogue' => $playerLine, 'dialogue' => $dialogue, 'tile_x' => $fromX, 'tile_y' => $fromY, 'source' => $payload['reply_source'] ?? 'fallback', 'next_action' => $payload['next_action'] ?? []], $now);
        }
        if (count($route) > 1) town_npc_activity_log($pdo, $npcId, 'path', '已规划路线：(' . $fromX . ',' . $fromY . ') → (' . $tileX . ',' . $tileY . ')', ['from' => ['tile_x' => $fromX, 'tile_y' => $fromY], 'to' => ['tile_x' => $tileX, 'tile_y' => $tileY], 'route' => $route, 'route_length' => count($route), 'navigation_version' => $payload['navigation_version'] ?? null], $now, $runId);
        if ($mood !== []) town_npc_activity_log($pdo, $npcId, 'mood', '心情更新', ['mood' => $mood, 'action' => $action], $now, $runId);
        if ($ownsTransaction) $pdo->commit();
        return ['ok' => true, 'npc_id' => $npcId, 'action' => $action, 'state' => $state, 'task_id' => $taskId, 'task_status' => 'running', 'tile_x' => $fromX, 'tile_y' => $fromY, 'height' => $fromHeight, 'from' => ['tile_x' => $fromX, 'tile_y' => $fromY, 'height' => $fromHeight], 'to' => ['tile_x' => $tileX, 'tile_y' => $tileY, 'height' => $tileHeight], 'route' => $route, 'route_cursor' => 0, 'dialogue' => $dialogue, 'mood' => $mood, 'job' => (string)($payload['job'] ?? ''), 'target_npc_id' => $payload['target_npc_id'] ?? null, 'target_tile' => $payload['target_tile'] ?? null, 'approach_tile' => $payload['approach_tile'] ?? null, 'facing' => $payload['facing'] ?? null, 'navigation_version' => town_navigation_version($pdo)];
    } catch (Throwable $error) { if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

/** Queue the authoritative return-home rest task after a work interaction. */
function town_npc_queue_home_rest(PDO $pdo, string $npcId, DateTimeImmutable $now): ?array
{
    $home = town_npc_life_home($pdo, $npcId);
    if (!is_array($home)) return null;
    $query = $pdo->prepare('SELECT r.tile_x,r.tile_y,r.capabilities_json,r.current_task_id FROM town_npc_runtime_states r WHERE r.npc_id=:npc LIMIT 1');
    $query->execute([':npc' => $npcId]); $runtime = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($runtime)) return null;
    $fromX = (int)$runtime['tile_x']; $fromY = (int)$runtime['tile_y']; $toX = (int)$home['tile_x']; $toY = (int)$home['tile_y'];
    if ($fromX === $toX && $fromY === $toY) return null;
    try {
        $capabilities = array_values(array_unique(array_merge(town_navigation_capabilities($runtime['capabilities_json'] ?? []), town_npc_inventory_capabilities($pdo, $npcId))));
        $route = town_navigation_find_route($pdo, $fromX, $fromY, $toX, $toY, $capabilities);
        if (!$route['found']) {
            town_npc_emit_event($pdo, $npcId, 'home_blocked', ['tile_x' => $fromX, 'tile_y' => $fromY, 'home_tile_x' => $toX, 'home_tile_y' => $toY, 'reason' => 'no_route', 'navigation_version' => $route['navigation_version'] ?? town_navigation_version($pdo)], $now);
            town_npc_activity_log($pdo, $npcId, 'path', '工作完成后无法回家', ['from' => ['tile_x' => $fromX, 'tile_y' => $fromY], 'home' => ['tile_x' => $toX, 'tile_y' => $toY], 'reason' => 'no_route'], $now);
            return null;
        }
        $required = town_npc_route_capabilities($pdo, $route['path']);
        $consumed = town_npc_consume_route_items($pdo, $npcId, $required, $runtime['capabilities_json'] ?? [], $now);
        $taskId = town_npc_id(); $payload = ['target_id' => 'npc-home', 'home_asset_id' => (string)$home['home_asset_id'], 'home_asset_name' => (string)$home['home_asset_name'], 'target' => ['tile_x' => $toX, 'tile_y' => $toY], 'route' => $route['path'], 'route_cursor' => 0, 'navigation_version' => $route['navigation_version'], 'duration_minutes' => 20, 'required_capabilities' => $required, 'consumed_items' => $consumed, 'home_return' => true];
        $pdo->prepare('INSERT INTO town_npc_tasks (task_id,npc_id,action,target_id,payload_json,status,attempts,scheduled_at,started_at) VALUES (:task,:npc,\'rest\',\'npc-home\',:payload,\'running\',1,:scheduled,:started)')->execute([':task' => $taskId, ':npc' => $npcId, ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':scheduled' => auth_sql_time($now), ':started' => auth_sql_time($now)]);
        $state = count($route['path']) > 1 ? 'walk' : 'rest'; $update = $pdo->prepare('UPDATE town_npc_runtime_states SET state=:state,current_task_id=:task,state_version=state_version+1,updated_at=:updated WHERE npc_id=:npc');
        $update->execute([':state' => $state, ':task' => $taskId, ':updated' => auth_sql_time($now), ':npc' => $npcId]);
        town_npc_emit_event($pdo, $npcId, 'home_return_started', ['task_id' => $taskId, 'state' => $state, 'tile_x' => $fromX, 'tile_y' => $fromY, 'home_tile_x' => $toX, 'home_tile_y' => $toY, 'route' => $route['path'], 'navigation_version' => $route['navigation_version']], $now);
        town_npc_activity_log($pdo, $npcId, 'path', '工作完成后返回自己的家', ['task_id' => $taskId, 'home_asset_id' => $home['home_asset_id'], 'home_asset_name' => $home['home_asset_name'], 'route' => $route['path'], 'route_length' => count($route['path'])], $now);
        return ['task_id' => $taskId, 'state' => $state, 'route' => $route['path'], 'tile_x' => $fromX, 'tile_y' => $fromY, 'home' => $home];
    } catch (ApiError $error) {
        town_npc_emit_event($pdo, $npcId, 'home_blocked', ['tile_x' => $fromX, 'tile_y' => $fromY, 'home_tile_x' => $toX, 'home_tile_y' => $toY, 'reason' => $error->apiCode], $now);
        town_npc_activity_log($pdo, $npcId, 'path', '工作完成后回家受阻', ['from' => ['tile_x' => $fromX, 'tile_y' => $fromY], 'home' => ['tile_x' => $toX, 'tile_y' => $toY], 'reason' => $error->apiCode], $now);
        return null;
    }
}

/** Execute a model-proposed follow-up only after the face-to-face task is closed. */
function town_npc_queue_player_talk_followup(PDO $pdo, string $npcId, array $nextAction, DateTimeImmutable $now): ?array
{
    $action = strtolower(trim((string)($nextAction['action'] ?? '')));
    if (!in_array($action, ['walk', 'work', 'rest', 'talk', 'trade'], true)) return null;
    $payload = is_array($nextAction['payload'] ?? null) ? $nextAction['payload'] : [];
    foreach (['target_id', 'target_npc_id', 'job', 'dialogue', 'trade'] as $key) if (array_key_exists($key, $nextAction) && !array_key_exists($key, $payload)) $payload[$key] = $nextAction[$key];
    try { return town_npc_apply_action($pdo, ['npc_id' => $npcId, 'action' => $action, 'payload' => $payload], $now, true, false, false); }
    catch (Throwable $error) { town_npc_activity_log($pdo, $npcId, 'action', '对话后行动未执行', ['action' => $action, 'reason' => substr($error->getMessage(), 0, 240)], $now); return null; }
}

/** Execute one administrator-assigned task through the same route and collision checks as the worker. */
function town_npc_admin_force_action(PDO $pdo, array $body, DateTimeImmutable $now): array
{
    return town_npc_apply_action($pdo, $body, $now, false, true);
}

/** Remove durable NPC memories while retaining the audit trail of the administrative action. */
function town_npc_clear_memories(PDO $pdo, string $npcId, DateTimeImmutable $now): int
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $npcId)) throw new ApiError('invalid_npc', 'NPC identifier is invalid.', 400);
    $exists = $pdo->prepare('SELECT 1 FROM town_npcs WHERE npc_id=:npc LIMIT 1');
    $exists->execute([':npc' => $npcId]);
    if (!$exists->fetchColumn()) throw new ApiError('npc_not_found', 'NPC was not found.', 404);
    $delete = $pdo->prepare('DELETE FROM town_npc_memories WHERE npc_id=:npc');
    $delete->execute([':npc' => $npcId]);
    $count = $delete->rowCount();
    town_npc_emit_event($pdo, $npcId, 'memory_cleared', ['deleted_count' => $count], $now);
    town_npc_activity_log($pdo, $npcId, 'memory', '管理员清除了长期记忆', ['deleted_count' => $count], $now);
    return $count;
}

/** Replace an NPC schedule from the administrator's tabular editor. */
function town_npc_replace_schedule(PDO $pdo, string $npcId, array $entries, DateTimeImmutable $now): int
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $npcId)) throw new ApiError('invalid_npc', 'NPC identifier is invalid.', 400);
    $exists = $pdo->prepare('SELECT 1 FROM town_npcs WHERE npc_id=:npc LIMIT 1');
    $exists->execute([':npc' => $npcId]);
    if (!$exists->fetchColumn()) throw new ApiError('npc_not_found', 'NPC was not found.', 404);
    $minute = static function ($value, int $fallback): int {
        if (is_string($value) && preg_match('/\A(\d{1,2}):(\d{2})\z/D', trim($value), $match)) return ((int)$match[1] * 60) + (int)$match[2];
        return (int)$value === 0 && $value !== '0' && $value !== 0 ? $fallback : (int)$value;
    };
    $normalized = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) continue;
        $action = strtolower(trim((string)($entry['action'] ?? '')));
        if ($action === '') continue;
        if (!in_array($action, TOWN_NPC_ACTIONS, true)) throw new ApiError('invalid_schedule', '日程中的动作无效。', 400);
        $weekday = max(0, min(6, (int)($entry['weekday'] ?? 0)));
        $start = max(0, min(1439, $minute($entry['start_minute'] ?? 0, 0)));
        $end = max(1, min(1440, $minute($entry['end_minute'] ?? 1440, 1440)));
        if ($end <= $start) throw new ApiError('invalid_schedule', '日程结束时间必须晚于开始时间。', 400);
        $payload = is_array($entry['payload'] ?? null) ? $entry['payload'] : [];
        $normalized[] = ['weekday' => $weekday, 'start_minute' => $start, 'end_minute' => $end, 'action' => $action,
            'target_id' => substr(trim((string)($entry['target_id'] ?? '')), 0, 128), 'job' => substr(trim((string)($entry['job'] ?? '')), 0, 64),
            'payload' => $payload, 'priority' => max(0, min(255, (int)($entry['priority'] ?? 0)))];
    }
    usort($normalized, static fn(array $left, array $right): int => [$left['weekday'], $left['start_minute'], $left['priority']] <=> [$right['weekday'], $right['start_minute'], $right['priority']]);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM town_npc_schedules WHERE npc_id=:npc')->execute([':npc' => $npcId]);
        $hasPayload = town_npc_schedule_payload_column($pdo);
        $insert = $hasPayload
            ? $pdo->prepare('INSERT INTO town_npc_schedules (npc_id,weekday,start_minute,end_minute,action,target_id,job,priority,payload_json) VALUES (:npc,:weekday,:start,:end,:action,:target,:job,:priority,:payload)')
            : $pdo->prepare('INSERT INTO town_npc_schedules (npc_id,weekday,start_minute,end_minute,action,target_id,job,priority) VALUES (:npc,:weekday,:start,:end,:action,:target,:job,:priority)');
        foreach ($normalized as $entry) {
            $params = [':npc' => $npcId, ':weekday' => $entry['weekday'], ':start' => $entry['start_minute'], ':end' => $entry['end_minute'], ':action' => $entry['action'], ':target' => $entry['target_id'], ':job' => $entry['job'], ':priority' => $entry['priority']];
            if ($hasPayload) $params[':payload'] = json_encode($entry['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $insert->execute($params);
        }
        town_npc_emit_event($pdo, $npcId, 'schedule_changed', ['entry_count' => count($normalized)], $now);
        town_npc_activity_log($pdo, $npcId, 'action', '管理员更新了日程', ['entry_count' => count($normalized)], $now);
        $pdo->commit();
        return count($normalized);
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

/** Advance one authoritative NPC task step. The worker must call this repeatedly. */
function town_npc_advance_task(PDO $pdo, string $npcId, DateTimeImmutable $now, bool $service = false): array
{
    if (!$service) throw new ApiError('agent_forbidden', 'NPC task advancement requires the worker service identity.', 403);
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $npcId)) throw new ApiError('invalid_npc', 'NPC identifier is invalid.', 400);
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME); $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT n.enabled,n.agent_paused,r.tile_x,r.tile_y,' . town_npc_runtime_height_select($pdo) . ',r.state,r.current_task_id,r.dialogue,r.mood_json,r.capabilities_json FROM town_npcs n LEFT JOIN town_npc_runtime_states r ON r.npc_id=n.npc_id WHERE n.npc_id=:npc' . $lock); $query->execute([':npc' => $npcId]); $runtime = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($runtime) || !(bool)$runtime['enabled']) throw new ApiError('npc_not_found', 'NPC was not found.', 404);
        if ((bool)$runtime['agent_paused']) throw new ApiError('npc_paused', 'NPC agent is paused.', 409);
        $lifecycle = town_npc_lifecycle($pdo, $npcId);
        if (($lifecycle['state'] ?? 'active') !== 'active') {
            $taskId = is_string($runtime['current_task_id'] ?? null) ? trim((string)$runtime['current_task_id']) : '';
            $cancelled = $taskId !== '' ? town_npc_lifecycle_cancel_runtime($pdo, $npcId, 'lifecycle_' . ($lifecycle['state'] ?? 'inactive'), $now) : [];
            $height = town_npc_navigation_height($pdo, (int)$runtime['tile_x'], (int)$runtime['tile_y']);
            $pdo->commit();
            return [
                'ok' => true, 'npc_id' => $npcId, 'task_id' => $cancelled['task_id'] ?? ($taskId !== '' ? $taskId : null),
                'task_status' => $taskId !== '' ? 'failed' : 'none', 'failure_reason' => 'npc_lifecycle_inactive',
                'lifecycle' => $lifecycle, 'state' => 'rest', 'tile_x' => (int)$runtime['tile_x'], 'tile_y' => (int)$runtime['tile_y'],
                'height' => $height, 'route_remaining' => [], 'navigation_version' => town_navigation_version($pdo),
            ];
        }
        $taskId = is_string($runtime['current_task_id'] ?? null) ? trim((string)$runtime['current_task_id']) : '';
        if ($taskId === '') { $height = town_npc_navigation_height($pdo, (int)$runtime['tile_x'], (int)$runtime['tile_y']); $pdo->commit(); return ['ok' => true, 'npc_id' => $npcId, 'task_status' => 'none', 'state' => (string)($runtime['state'] ?? 'rest'), 'tile_x' => (int)$runtime['tile_x'], 'tile_y' => (int)$runtime['tile_y'], 'height' => $height, 'route_remaining' => [], 'navigation_version' => town_navigation_version($pdo)]; }
        $taskQuery = $pdo->prepare('SELECT task_id,action,payload_json,status,started_at FROM town_npc_tasks WHERE task_id=:task AND npc_id=:npc' . $lock); $taskQuery->execute([':task' => $taskId, ':npc' => $npcId]); $task = $taskQuery->fetch(PDO::FETCH_ASSOC);
         if (!is_array($task) || (string)$task['status'] !== 'running') { $height = town_npc_navigation_height($pdo, (int)$runtime['tile_x'], (int)$runtime['tile_y']); $pdo->prepare('UPDATE town_npc_runtime_states SET current_task_id=NULL,state=\'rest\',dialogue=\'\',state_version=state_version+1,updated_at=:updated WHERE npc_id=:npc')->execute([':updated' => auth_sql_time($now), ':npc' => $npcId]); $pdo->commit(); return ['ok' => true, 'npc_id' => $npcId, 'task_id' => $taskId, 'task_status' => (string)($task['status'] ?? 'missing'), 'state' => 'rest', 'tile_x' => (int)$runtime['tile_x'], 'tile_y' => (int)$runtime['tile_y'], 'height' => $height, 'route_remaining' => [], 'navigation_version' => town_navigation_version($pdo)]; }
        $payload = town_npc_array(town_npc_decode($task['payload_json'] ?? [], [])); $consumedItems = is_array($payload['consumed_items'] ?? null) ? $payload['consumed_items'] : []; $currentVersion = town_navigation_version($pdo); $plannedVersion = filter_var($payload['navigation_version'] ?? null, FILTER_VALIDATE_INT); $weatherStamp = town_npc_weather_stamp($pdo, $now); $currentWeather = $weatherStamp['weather_code']; $plannedWeather = isset($payload['weather_code']) ? trim((string)$payload['weather_code']) : '';
        $x = (int)$runtime['tile_x']; $y = (int)$runtime['tile_y']; $currentHeight = town_npc_navigation_height($pdo, $x, $y);
        $route = is_array($payload['route'] ?? null) ? array_values($payload['route']) : []; if ($route === []) $route = [['x' => $x, 'y' => $y, 'height' => $currentHeight]];
         $cursor = max(0, min(count($route) - 1, (int)($payload['route_cursor'] ?? 0))); $action = (string)$task['action']; $tradeResult = null; $capabilities = array_values(array_unique(array_merge(town_navigation_capabilities($runtime['capabilities_json'] ?? []), town_npc_inventory_capabilities($pdo, $npcId), array_keys($consumedItems))));
        $fail = static function (string $reason, array $detail) use ($pdo, $npcId, $taskId, $now, $x, $y, $currentHeight, $currentVersion, $consumedItems): array { town_npc_refund_route_items($pdo, $npcId, $consumedItems, $now); town_npc_release_work_point($pdo, $taskId, 'failed', $now); $pdo->prepare('UPDATE town_npc_tasks SET status=\'failed\',failure_reason=:reason,completed_at=:completed WHERE task_id=:task AND npc_id=:npc')->execute([':reason' => substr($reason, 0, 255), ':completed' => auth_sql_time($now), ':task' => $taskId, ':npc' => $npcId]); town_npc_sync_social_task_if_available($pdo, $taskId, $npcId, 'failed', $reason, $now); $heightUpdate = town_npc_runtime_height_column($pdo) ? ',height=:height' : ''; $stateParams = [':updated' => auth_sql_time($now), ':npc' => $npcId]; if ($heightUpdate !== '') $stateParams[':height'] = $currentHeight; $pdo->prepare('UPDATE town_npc_runtime_states SET current_task_id=NULL,state=\'rest\',dialogue=\'\',state_version=state_version+1,updated_at=:updated' . $heightUpdate . ' WHERE npc_id=:npc')->execute($stateParams); town_npc_emit_event($pdo, $npcId, 'task_failed', ['task_id' => $taskId, 'task_status' => 'failed', 'failure_reason' => $reason, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'navigation_version' => $currentVersion] + $detail, $now); town_npc_activity_log($pdo, $npcId, 'path', '任务停止：' . $reason, $detail + ['task_id' => $taskId, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight], $now); return ['ok' => true, 'npc_id' => $npcId, 'task_id' => $taskId, 'task_status' => 'failed', 'failure_reason' => $reason, 'state' => 'rest', 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'route_cursor' => $detail['route_cursor'] ?? 0, 'route_remaining' => [], 'navigation_version' => $currentVersion]; };
        $replan = static function (string $reason = 'navigation_changed') use ($pdo, $npcId, $taskId, $now, $currentVersion, $currentWeather, $plannedWeather, $action, &$payload, &$consumedItems, &$route, &$cursor, $x, $y, $currentHeight, $runtime, $capabilities): ?array {
            $destination = town_npc_task_destination($pdo, $action, $payload);
            if (!is_array($destination)) return null;
            $oldVersion = (int)($payload['previous_navigation_version'] ?? $payload['navigation_version'] ?? 0);
            $planned = town_navigation_find_route($pdo, $x, $y, (int)$destination['tile_x'], (int)$destination['tile_y'], $capabilities);
            if (!$planned['found']) return null;
            $newRoute = array_values($planned['path']);
            $newRequired = town_npc_route_capabilities($pdo, $newRoute);
            $intrinsic = town_navigation_capabilities($runtime['capabilities_json'] ?? []);
            foreach ($consumedItems as $itemId => $quantity) {
                if (!in_array((string)$itemId, $newRequired, true)) {
                    town_npc_refund_route_items($pdo, $npcId, [(string)$itemId => (int)$quantity], $now);
                    unset($consumedItems[$itemId]);
                } else {
                    $intrinsic[] = (string)$itemId;
                }
            }
            $extraItems = town_npc_consume_route_items($pdo, $npcId, $newRequired, $intrinsic, $now);
            foreach ($extraItems as $itemId => $quantity) $consumedItems[$itemId] = (int)($consumedItems[$itemId] ?? 0) + (int)$quantity;
            $oldCursor = $cursor;
            $route = $newRoute; $cursor = 0;
            $payload['route'] = $route; $payload['route_cursor'] = 0; $payload['navigation_version'] = $currentVersion;
            $payload['required_capabilities'] = $newRequired; $payload['consumed_items'] = $consumedItems;
            $payload['replan_count'] = ((int)($payload['replan_count'] ?? 0)) + 1;
            $payload['weather_code'] = $currentWeather;
            $payload['weather_revision'] = town_npc_weather_stamp($pdo, $now)['weather_revision'];
            unset($payload['previous_navigation_version'], $payload['previous_weather_code']);
            $pdo->prepare('UPDATE town_npc_tasks SET payload_json=:payload WHERE task_id=:task AND npc_id=:npc')->execute([':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':task' => $taskId, ':npc' => $npcId]);
            $state = count($route) > 1 ? 'walk' : $action;
            $pdo->prepare('UPDATE town_npc_runtime_states SET state=:state,state_version=state_version+1,updated_at=:updated WHERE npc_id=:npc')->execute([':state' => $state, ':updated' => auth_sql_time($now), ':npc' => $npcId]);
             $destination['height'] = town_npc_navigation_height($pdo, (int)$destination['tile_x'], (int)$destination['tile_y']);
             $detail = ['task_id' => $taskId, 'action' => $action, 'reason' => $reason, 'from' => ['tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight], 'to' => $destination, 'old_navigation_version' => $oldVersion, 'navigation_version' => $currentVersion, 'old_route_cursor' => $oldCursor, 'route' => $route, 'required_capabilities' => $newRequired];
             if ($reason === 'weather_changed') { $detail['old_weather_code'] = $plannedWeather; $detail['weather_code'] = $currentWeather; }
             town_npc_emit_event($pdo, $npcId, 'route_replanned', $detail, $now);
             town_npc_activity_log($pdo, $npcId, 'path', $reason === 'weather_changed' ? '天气变化后重新规划路线' : '地图变化后重新规划路线', $detail + ['route_length' => count($route), 'replan_count' => $payload['replan_count']], $now);
             return ['ok' => true, 'npc_id' => $npcId, 'task_id' => $taskId, 'action' => $action, 'task_status' => 'running', 'state' => $state, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'route_cursor' => 0, 'route_remaining' => $route, 'navigation_version' => $currentVersion, 'weather_code' => $currentWeather, 'route_replanned' => true, 'replan_reason' => $reason, 'replan_count' => $payload['replan_count']];
        };
        if ($action === 'work' && !town_npc_work_point_reservation_valid($pdo, $taskId, $payload, $now)) { $result = $fail('work_point_expired', ['route_cursor' => $cursor]); $pdo->commit(); return $result; }
        $weatherChanged = $plannedWeather !== '' && $plannedWeather !== $currentWeather;
        if (($plannedVersion !== false && $plannedVersion !== $currentVersion) || ($weatherChanged && in_array($action, ['walk', 'work', 'rest', 'talk', 'trade'], true))) {
            $payload['previous_navigation_version'] = $plannedVersion;
            if ($weatherChanged) $payload['previous_weather_code'] = $plannedWeather;
            $replanReason = $weatherChanged ? 'weather_changed' : 'navigation_changed';
            $result = $replan($replanReason);
            if (is_array($result)) { $pdo->commit(); return $result; }
            $failureReason = $weatherChanged ? 'weather_changed_no_route' : 'navigation_changed_no_route';
            $result = $fail($failureReason, ['planned_navigation_version' => $plannedVersion, 'current_navigation_version' => $currentVersion, 'planned_weather_code' => $plannedWeather, 'weather_code' => $currentWeather, 'route_cursor' => $cursor]); $pdo->commit(); return $result;
        }
        if ($cursor >= count($route)) { $result = $fail('invalid_route', ['route_cursor' => $cursor]); $pdo->commit(); return $result; }
        $point = town_npc_array($route[$cursor]);
        $pointHeight = (int)($point['height'] ?? $currentHeight);
        if ((int)($point['x'] ?? -999) !== $x || (int)($point['y'] ?? -999) !== $y || $pointHeight !== $currentHeight) { $result = $fail('stale_route_position', ['route_cursor' => $cursor, 'route_height' => $pointHeight]); $pdo->commit(); return $result; }
        if ($cursor < count($route) - 1) {
            if ($action === 'player_talk') {
                $playerId = trim((string)($payload['target_player_user_id'] ?? ''));
                $livePlayer = town_npc_player_target($pdo, $playerId, $now);
                $plannedPlayer = is_array($payload['target_tile'] ?? null) ? $payload['target_tile'] : [];
                $playerMoved = !is_array($livePlayer)
                    || (int)($plannedPlayer['tile_x'] ?? -1) !== $livePlayer['tile_x']
                    || (int)($plannedPlayer['tile_y'] ?? -1) !== $livePlayer['tile_y'];
                if ($playerMoved) {
                    $result = $fail('player_target_missing', ['target_player_user_id' => $playerId, 'route_cursor' => $cursor]);
                    $pdo->commit();
                    return $result;
                }
            }
            $next = town_npc_array($route[$cursor + 1]); $nextX = (int)($next['x'] ?? -999); $nextY = (int)($next['y'] ?? -999);
            if (abs($nextX - $x) + abs($nextY - $y) !== 1) { $result = $fail('invalid_route_step', ['route_cursor' => $cursor]); $pdo->commit(); return $result; }
            $nextCell = town_navigation_cell($pdo, $nextX, $nextY);
            $nextHeight = (int)($nextCell['height'] ?? 0);
            $routeHeight = (int)($next['height'] ?? $nextHeight);
            if ($routeHeight !== $nextHeight) { $result = $fail('route_height_mismatch', ['route_cursor' => $cursor, 'route_height' => $routeHeight, 'actual_height' => $nextHeight]); $pdo->commit(); return $result; }
            $currentCell = town_navigation_cell($pdo, $x, $y);
            if (!town_navigation_transition_allow($currentCell, $nextCell, $nextX - $x, $nextY - $y)) { $result = $fail('height_transition_blocked', ['route_cursor' => $cursor, 'from_height' => $currentHeight, 'to_height' => $nextHeight]); $pdo->commit(); return $result; }
            $allowed = town_navigation_can_traverse($pdo, $nextX, $nextY, $capabilities); if (!$allowed['allowed']) { $result = $fail('route_blocked', ['route_cursor' => $cursor, 'missing_capabilities' => $allowed['missing_capabilities'], 'reason' => $allowed['reason'], 'height' => $nextHeight]); $pdo->commit(); return $result; }
            $cursor++; $x = $nextX; $y = $nextY; $currentHeight = $nextHeight; $payload['route_cursor'] = $cursor; if ($cursor === count($route) - 1) $payload['arrived_at'] = auth_sql_time($now);
            $state = $cursor < count($route) - 1 ? 'walk' : ($action === 'walk' ? 'walk' : $action); $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $pdo->prepare('UPDATE town_npc_tasks SET payload_json=:payload WHERE task_id=:task AND npc_id=:npc')->execute([':payload' => $payloadJson, ':task' => $taskId, ':npc' => $npcId]);
            $moveSql = 'UPDATE town_npc_runtime_states SET tile_x=:x,tile_y=:y' . (town_npc_runtime_height_column($pdo) ? ',height=:height' : '') . ',state=:state,state_version=state_version+1,updated_at=:updated WHERE npc_id=:npc';
            $moveParams = [':x' => $x, ':y' => $y, ':state' => $state, ':updated' => auth_sql_time($now), ':npc' => $npcId]; if (town_npc_runtime_height_column($pdo)) $moveParams[':height'] = $currentHeight;
            $pdo->prepare($moveSql)->execute($moveParams);
            if ($cursor === count($route) - 1 && $action === 'walk') {
                $pdo->prepare('UPDATE town_npc_tasks SET status=\'completed\',completed_at=:completed WHERE task_id=:task AND npc_id=:npc')->execute([':completed' => auth_sql_time($now), ':task' => $taskId, ':npc' => $npcId]);
                // A walking task can finish in this branch before the generic
                // action completion path below. Keep social commitments bound
                // to the same authoritative task transition, otherwise a
                // social matter remains executing forever after arrival.
                town_npc_sync_social_task_if_available($pdo, $taskId, $npcId, 'completed', null, $now);
                $pdo->prepare('UPDATE town_npc_runtime_states SET state=\'rest\',current_task_id=NULL,dialogue=\'\',state_version=state_version+1,updated_at=:updated WHERE npc_id=:npc')->execute([':updated' => auth_sql_time($now), ':npc' => $npcId]);
                town_npc_emit_event($pdo, $npcId, 'task_completed', ['state' => 'rest', 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'task_id' => $taskId, 'task_status' => 'completed', 'action' => $action, 'navigation_version' => $currentVersion], $now);
                town_npc_activity_log($pdo, $npcId, 'action', '任务完成：walk', ['task_id' => $taskId, 'action' => $action, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight], $now);
                $pdo->commit();
                return ['ok' => true, 'npc_id' => $npcId, 'task_id' => $taskId, 'action' => $action, 'task_status' => 'completed', 'state' => 'rest', 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'route_cursor' => $cursor, 'route_remaining' => [], 'navigation_version' => $currentVersion];
            }
            town_npc_emit_event($pdo, $npcId, 'state_changed', ['state' => $state, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'task_id' => $taskId, 'task_status' => 'running', 'route_cursor' => $cursor, 'route_remaining' => array_slice($route, $cursor), 'navigation_version' => $currentVersion, 'dialogue' => $state === 'player_talk' ? (string)($payload['dialogue'] ?? '') : ''], $now); town_npc_activity_log($pdo, $npcId, 'path', 'NPC 前进一步', ['task_id' => $taskId, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'route_cursor' => $cursor, 'route_remaining' => array_slice($route, $cursor)], $now);
            $pdo->commit(); return ['ok' => true, 'npc_id' => $npcId, 'task_id' => $taskId, 'action' => $action, 'task_status' => 'running', 'state' => $state, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'route_cursor' => $cursor, 'route_remaining' => array_slice($route, $cursor), 'navigation_version' => $currentVersion];
        }
        if (in_array($action, ['follow', 'player_talk'], true)) {
            $playerId = trim((string)($payload['target_player_user_id'] ?? ''));
            $playerTarget = town_npc_player_target($pdo, $playerId, $now);
            if (!is_array($playerTarget)) {
                $result = $fail('player_target_missing', ['target_player_user_id' => $playerId, 'route_cursor' => $cursor]);
                $pdo->commit();
                return $result;
            }
            $sameTarget = is_array($payload['target_tile'] ?? null)
                && (int)($payload['target_tile']['tile_x'] ?? -1) === $playerTarget['tile_x']
                && (int)($payload['target_tile']['tile_y'] ?? -1) === $playerTarget['tile_y'];
            $adjacent = abs($playerTarget['tile_x'] - $x) + abs($playerTarget['tile_y'] - $y) <= 1;
            if (!$sameTarget || !$adjacent) {
                if ($action === 'player_talk' && !$sameTarget) {
                    $result = $fail('player_target_missing', ['target_player_user_id' => $playerId, 'route_cursor' => $cursor]);
                    $pdo->commit();
                    return $result;
                }
                $newRoute = town_npc_player_route($pdo, $x, $y, $playerTarget, $capabilities);
                if (!is_array($newRoute)) {
                    $result = $fail('player_target_no_route', ['target_player_user_id' => $playerId, 'route_cursor' => $cursor]);
                    $pdo->commit();
                    return $result;
                }
                $payload['target_tile'] = $playerTarget;
                $payload['approach_tile'] = $newRoute['approach_tile'];
                $payload['navigation_version'] = (int)$newRoute['navigation_version'];
                $payload['route'] = $newRoute['path'];
                $payload['route_cursor'] = 0;
                $payload['arrived_at'] = '';
                $pdo->prepare('UPDATE town_npc_tasks SET payload_json=:payload WHERE task_id=:task AND npc_id=:npc')->execute([':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':task' => $taskId, ':npc' => $npcId]);
                $state = count($newRoute['path']) > 1 ? 'walk' : $action;
                $pdo->prepare('UPDATE town_npc_runtime_states SET state=:state,state_version=state_version+1,updated_at=:updated WHERE npc_id=:npc')->execute([':state' => $state, ':updated' => auth_sql_time($now), ':npc' => $npcId]);
                town_npc_emit_event($pdo, $npcId, 'state_changed', ['state' => $state, 'tile_x' => $x, 'tile_y' => $y, 'task_id' => $taskId, 'task_status' => 'running', 'route_cursor' => 0, 'route_remaining' => $newRoute['path'], 'navigation_version' => $newRoute['navigation_version'], 'target_player_user_id' => $playerId], $now);
                $pdo->commit();
                return ['ok' => true, 'npc_id' => $npcId, 'task_id' => $taskId, 'action' => $action, 'task_status' => 'running', 'state' => $state, 'tile_x' => $x, 'tile_y' => $y, 'route_cursor' => 0, 'route_remaining' => $newRoute['path'], 'navigation_version' => $newRoute['navigation_version'], 'route_replanned' => true];
            }
            if ($action === 'follow') {
                $payload['route_cursor'] = $cursor;
                $pdo->prepare('UPDATE town_npc_tasks SET payload_json=:payload WHERE task_id=:task AND npc_id=:npc')->execute([':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':task' => $taskId, ':npc' => $npcId]);
                $pdo->prepare("UPDATE town_npc_runtime_states SET state='follow',state_version=state_version+1,updated_at=:updated WHERE npc_id=:npc")->execute([':updated' => auth_sql_time($now), ':npc' => $npcId]);
                town_npc_emit_event($pdo, $npcId, 'state_changed', ['state' => 'follow', 'tile_x' => $x, 'tile_y' => $y, 'task_id' => $taskId, 'task_status' => 'running', 'route_cursor' => $cursor, 'route_remaining' => [$route[$cursor]], 'navigation_version' => $currentVersion, 'target_player_user_id' => $playerId], $now);
                $pdo->commit();
                return ['ok' => true, 'npc_id' => $npcId, 'task_id' => $taskId, 'action' => 'follow', 'task_status' => 'running', 'state' => 'follow', 'tile_x' => $x, 'tile_y' => $y, 'route_cursor' => $cursor, 'route_remaining' => [$route[$cursor]], 'navigation_version' => $currentVersion];
            }
            // Player talk is a short, face-to-face interaction.  Re-check the
            // live presence here after the route has been walked so a player
            // who moved away cannot leave the NPC talking to an empty tile.
            $playerTarget = town_npc_player_target($pdo, $playerId, $now);
            $samePlayerTile = is_array($playerTarget)
                && abs($playerTarget['tile_x'] - $x) + abs($playerTarget['tile_y'] - $y) <= 1;
            if (!$samePlayerTile) {
                $result = $fail('player_target_missing', ['target_player_user_id' => $playerId, 'route_cursor' => $cursor]);
                $pdo->commit();
                return $result;
            }
            $payload['facing'] = town_npc_talk_face_direction($x, $y, (int)$playerTarget['tile_x'], (int)$playerTarget['tile_y']);
            town_npc_set_facing($pdo, $npcId, (string)$payload['facing'], $now);
            $payload['duration_minutes'] = 1;
            $payload['target_tile'] = $playerTarget;
            $payload['approach_tile'] = ['tile_x' => $x, 'tile_y' => $y];
            $payload['route_cursor'] = $cursor;
            $pdo->prepare('UPDATE town_npc_tasks SET payload_json=:payload WHERE task_id=:task AND npc_id=:npc')->execute([
                ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ':task' => $taskId, ':npc' => $npcId,
            ]);
        }

        // A talk task may only complete when the target is still at the
        // remembered position and remains available. This closes the race
        // between route planning and the other NPC going home or starting
        // another task while the initiator is walking over.
        if (in_array($action, ['talk', 'trade'], true)) {
            $targetId = trim((string)($payload['target_npc_id'] ?? $payload['target_id'] ?? ''));
            $targetRow = town_npc_talk_target($pdo, $targetId);
            $expected = is_array($payload['target_tile'] ?? null) ? $payload['target_tile'] : [];
            $expectedX = filter_var($expected['tile_x'] ?? $expected['x'] ?? null, FILTER_VALIDATE_INT);
            $expectedY = filter_var($expected['tile_y'] ?? $expected['y'] ?? null, FILTER_VALIDATE_INT);
            $targetAvailable = is_array($targetRow) && town_npc_talk_target_available($targetRow);
            $samePosition = $targetAvailable && $expectedX !== false && $expectedY !== false
                && (int)$targetRow['tile_x'] === (int)$expectedX && (int)$targetRow['tile_y'] === (int)$expectedY
                && abs((int)$targetRow['tile_x'] - $x) + abs((int)$targetRow['tile_y'] - $y) <= 1;
            if (!$samePosition) {
                $lastKnown = $expectedX !== false && $expectedY !== false ? ['tile_x' => (int)$expectedX, 'tile_y' => (int)$expectedY] : ($targetRow ?: []);
                if ($action === 'talk') town_npc_record_conversation_missing($pdo, $npcId, $targetId, $lastKnown, !$targetAvailable ? '交谈对象已回家、外出或正在执行其他任务' : '交谈对象已离开记忆中的位置或不在相邻交互范围', $now, $taskId);
                $reason = $action === 'talk' ? 'conversation_target_missing' : 'trade_target_missing';
                $result = $fail($reason, ['target_npc_id' => $targetId, 'target_tile' => $lastKnown, 'route_cursor' => $cursor]);
                $pdo->commit();
                return $result;
            }
            $payload['facing'] = town_npc_talk_face_direction($x, $y, (int)$targetRow['tile_x'], (int)$targetRow['tile_y']);
            town_npc_set_facing($pdo, $npcId, (string)$payload['facing'], $now);
            town_npc_set_facing($pdo, $targetId, town_npc_talk_face_direction((int)$targetRow['tile_x'], (int)$targetRow['tile_y'], $x, $y), $now);
            $payload['target_tile'] = ['tile_x' => (int)$targetRow['tile_x'], 'tile_y' => (int)$targetRow['tile_y']];
        }
        $payload['route_cursor'] = $cursor; $state = $action === 'walk' ? 'rest' : $action; $arrivedAt = is_string($payload['arrived_at'] ?? null) ? (string)$payload['arrived_at'] : '';
         if ($action !== 'walk' && $arrivedAt === '') { $payload['arrived_at'] = auth_sql_time($now); $pdo->prepare('UPDATE town_npc_tasks SET payload_json=:payload WHERE task_id=:task AND npc_id=:npc')->execute([':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':task' => $taskId, ':npc' => $npcId]); $pdo->prepare('UPDATE town_npc_runtime_states SET state=:state,state_version=state_version+1,updated_at=:updated WHERE npc_id=:npc')->execute([':state' => $state, ':updated' => auth_sql_time($now), ':npc' => $npcId]); town_npc_emit_event($pdo, $npcId, 'state_changed', ['state' => $state, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'task_id' => $taskId, 'task_status' => 'running', 'route_cursor' => $cursor, 'route_remaining' => [$route[$cursor]], 'navigation_version' => $currentVersion, 'dialogue' => $state === 'player_talk' ? (string)($payload['dialogue'] ?? '') : ''], $now); $pdo->commit(); return ['ok' => true, 'npc_id' => $npcId, 'task_id' => $taskId, 'action' => $action, 'task_status' => 'running', 'state' => $state, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'route_cursor' => $cursor, 'route_remaining' => [$route[$cursor]], 'navigation_version' => $currentVersion]; }
        if ($action !== 'walk') {
            // auth_sql_time stores UTC without an offset; PHP's local timezone
            // must not shorten or extend the interaction duration on read-back.
            try { $arrivedTime = new DateTimeImmutable($arrivedAt, new DateTimeZone('UTC')); }
            catch (Throwable $error) { $arrivedTime = $now; }
            $duration = max(0, min(1440, (int)($payload['duration_minutes'] ?? 20)));
            $remainingSeconds = $arrivedTime->getTimestamp() + ($duration * 60) - $now->getTimestamp();
            if ($remainingSeconds > 0) {
                $pdo->commit();
                return ['ok' => true, 'npc_id' => $npcId, 'task_id' => $taskId, 'action' => $action, 'task_status' => 'running', 'state' => $state, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'route_cursor' => $cursor, 'route_remaining' => [$route[$cursor]], 'navigation_version' => $currentVersion, 'remaining_seconds' => $remainingSeconds];
            }
        }
         if ($action === 'trade' && !is_array($payload['trade_result'] ?? null)) {
             $trade = town_npc_array($payload['trade'] ?? []);
             // The task transaction also contains route/task state updates.
             // Keep the economy transfer behind a savepoint so a failed
             // ledger insert or an unexpected database error cannot commit a
             // partially applied trade when the task is marked failed below.
             $tradeSavepoint = 'npc_trade_' . substr(hash('sha256', $taskId), 0, 12);
             $pdo->exec('SAVEPOINT ' . $tradeSavepoint);
             try {
                 $tradeResult = town_npc_trade($pdo, (string)$trade['seller_npc_id'], (string)$trade['buyer_npc_id'], (string)$trade['item_id'], (int)$trade['quantity'], (int)$trade['amount'], (string)($trade['reason'] ?? 'NPC 之间交换物资'), $now);
                 $payload['trade_result'] = $tradeResult;
             } catch (Throwable $error) {
                 try { $pdo->exec('ROLLBACK TO SAVEPOINT ' . $tradeSavepoint); } finally { $pdo->exec('RELEASE SAVEPOINT ' . $tradeSavepoint); }
                 $reason = $error instanceof ApiError ? 'trade_' . $error->apiCode : 'trade_failed';
                 $result = $fail($reason, ['target_npc_id' => (string)($payload['target_npc_id'] ?? $payload['target_id'] ?? ''), 'route_cursor' => $cursor, 'error' => substr($error->getMessage(), 0, 180)]);
                 $pdo->commit();
                 return $result;
             }
             $pdo->exec('RELEASE SAVEPOINT ' . $tradeSavepoint);
         }
         $pdo->prepare('UPDATE town_npc_tasks SET status=\'completed\',completed_at=:completed,payload_json=:payload WHERE task_id=:task AND npc_id=:npc')->execute([':completed' => auth_sql_time($now), ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':task' => $taskId, ':npc' => $npcId]); town_npc_sync_social_task_if_available($pdo, $taskId, $npcId, 'completed', null, $now); $stateSql = 'UPDATE town_npc_runtime_states SET current_task_id=NULL,state=\'rest\',dialogue=\'\'' . (town_npc_runtime_height_column($pdo) ? ',height=:height' : '') . ',state_version=state_version+1,updated_at=:updated'; $stateParams = [':updated' => auth_sql_time($now), ':npc' => $npcId]; if (town_npc_runtime_height_column($pdo)) $stateParams[':height'] = $currentHeight; $stateSql .= ' WHERE npc_id=:npc'; $pdo->prepare($stateSql)->execute($stateParams); town_npc_emit_event($pdo, $npcId, 'task_completed', ['state' => 'rest', 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'task_id' => $taskId, 'task_status' => 'completed', 'action' => $action, 'navigation_version' => $currentVersion, 'trade' => $tradeResult], $now); town_npc_activity_log($pdo, $npcId, 'action', '任务完成：' . $action, ['task_id' => $taskId, 'action' => $action, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'trade' => $tradeResult], $now); if ($action === 'trade' && is_array($tradeResult)) { $counterparty = (string)($tradeResult['seller_npc_id'] ?? '') === $npcId ? (string)($tradeResult['buyer_npc_id'] ?? '') : (string)($tradeResult['seller_npc_id'] ?? ''); if (preg_match('/\A[a-f0-9]{32}\z/D', $counterparty)) { town_npc_emit_event($pdo, $counterparty, 'trade', $tradeResult + ['initiator_npc_id' => $npcId], $now); town_npc_activity_log($pdo, $counterparty, 'action', '收到 NPC 交易', $tradeResult + ['initiator_npc_id' => $npcId], $now); } town_npc_emit_event($pdo, $npcId, 'trade', $tradeResult + ['initiator_npc_id' => $npcId], $now); } $homeReturn = null; if ($action === 'work') { town_npc_record_work_result($pdo, $npcId, $taskId, $payload, $now); town_npc_release_work_point($pdo, $taskId, 'completed', $now); $homeReturn = town_npc_queue_home_rest($pdo, $npcId, $now); } elseif ($action === 'talk') { town_npc_record_talk_result($pdo, $npcId, $taskId, $payload, $now); } $pdo->commit(); $followup = $action === 'player_talk' ? town_npc_queue_player_talk_followup($pdo, $npcId, town_npc_array($payload['next_action'] ?? []), $now) : null; if (is_array($homeReturn)) return ['ok' => true, 'npc_id' => $npcId, 'task_id' => $homeReturn['task_id'], 'completed_task_id' => $taskId, 'action' => 'rest', 'task_status' => 'running', 'state' => $homeReturn['state'], 'tile_x' => $homeReturn['tile_x'], 'tile_y' => $homeReturn['tile_y'], 'height' => $currentHeight, 'route_cursor' => 0, 'route' => $homeReturn['route'], 'route_remaining' => $homeReturn['route'], 'navigation_version' => town_navigation_version($pdo), 'home_return' => true]; return ['ok' => true, 'npc_id' => $npcId, 'task_id' => $taskId, 'action' => $action, 'task_status' => 'completed', 'state' => 'rest', 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'route_cursor' => $cursor, 'route_remaining' => [], 'navigation_version' => $currentVersion, 'trade' => $tradeResult];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

/** Cancel only a player-owned invitation; work and NPC-NPC interactions stay authoritative. */
function town_npc_cancel_player_invitation(PDO $pdo, string $npcId, string $userId, DateTimeImmutable $now): array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $npcId) || !preg_match('/\A[a-f0-9]{32}\z/D', $userId)) {
        throw new ApiError('invalid_npc_invitation', 'NPC invitation is invalid.', 400);
    }
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME); $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT r.current_task_id,r.tile_x,r.tile_y,r.state FROM town_npc_runtime_states r WHERE r.npc_id=:npc' . $lock);
        $query->execute([':npc' => $npcId]); $runtime = $query->fetch(PDO::FETCH_ASSOC);
        $taskId = trim((string)($runtime['current_task_id'] ?? ''));
        if ($taskId === '') throw new ApiError('npc_invitation_not_found', 'NPC 当前没有玩家邀请任务。', 409);
        $taskQuery = $pdo->prepare('SELECT action,payload_json,status FROM town_npc_tasks WHERE task_id=:task AND npc_id=:npc' . $lock);
        $taskQuery->execute([':task' => $taskId, ':npc' => $npcId]); $task = $taskQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($task) || (string)$task['status'] !== 'running') throw new ApiError('npc_invitation_not_found', 'NPC 当前没有可取消的玩家邀请。', 409);
        if (!in_array((string)$task['action'], ['follow', 'player_talk'], true)) throw new ApiError('npc_busy', 'NPC 正在工作或与其他村民互动，不能打断。', 409);
        $payload = town_npc_array(town_npc_decode($task['payload_json'] ?? [], []));
        if ((string)($payload['target_player_user_id'] ?? '') !== $userId) throw new ApiError('npc_invitation_forbidden', '只能结束自己发起的邀请。', 403);
        $pdo->prepare('UPDATE town_npc_tasks SET status=\'failed\',failure_reason=\'player_cancelled\',completed_at=:completed WHERE task_id=:task AND npc_id=:npc')->execute([
            ':completed' => auth_sql_time($now), ':task' => $taskId, ':npc' => $npcId,
        ]);
        town_npc_refund_route_items($pdo, $npcId, is_array($payload['consumed_items'] ?? null) ? $payload['consumed_items'] : [], $now);
        $pdo->prepare('UPDATE town_npc_runtime_states SET current_task_id=NULL,state=\'rest\',dialogue=\'\',state_version=state_version+1,updated_at=:updated WHERE npc_id=:npc')->execute([
            ':updated' => auth_sql_time($now), ':npc' => $npcId,
        ]);
        town_npc_emit_event($pdo, $npcId, 'task_cancelled', ['task_id' => $taskId, 'action' => (string)$task['action'], 'reason' => 'player_cancelled', 'tile_x' => (int)$runtime['tile_x'], 'tile_y' => (int)$runtime['tile_y']], $now);
        town_npc_activity_log($pdo, $npcId, 'action', '玩家结束了邀请', ['task_id' => $taskId, 'action' => (string)$task['action'], 'user_id' => $userId], $now);
        $pdo->commit();
        return ['ok' => true, 'npc_id' => $npcId, 'task_id' => $taskId, 'task_status' => 'cancelled', 'state' => 'rest', 'tile_x' => (int)$runtime['tile_x'], 'tile_y' => (int)$runtime['tile_y']];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function town_npc_upsert(PDO $pdo, array $body, DateTimeImmutable $now, ?string $existingId = null): array
{
    $name = trim((string)($body['name'] ?? '')); $occupation = trim((string)($body['occupation'] ?? 'farmer'));
    if ($name === '' || strlen($name) > 80 || !in_array($occupation, TOWN_NPC_OCCUPATIONS, true)) throw new ApiError('invalid_npc', 'NPC name or occupation is invalid.', 400);
    $id = $existingId ?? town_npc_id(); $json = static fn($value): string => json_encode(is_array($value) ? $value : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $pdo->beginTransaction();
    try {
        if ($existingId === null) {
            $pdo->prepare('INSERT INTO town_npcs (npc_id,world_id,name,avatar_asset_id,appearance_json,occupation,enabled,agent_paused,created_at,updated_at) VALUES (:id,\'main\',:name,:avatar,:appearance,:occupation,1,0,:created,:updated)')->execute([':id' => $id, ':name' => $name, ':avatar' => (string)($body['avatar_asset_id'] ?? ''), ':appearance' => $json($body['appearance'] ?? []), ':occupation' => $occupation, ':created' => auth_sql_time($now), ':updated' => auth_sql_time($now)]);
        } else {
            $pdo->prepare('UPDATE town_npcs SET name=:name,avatar_asset_id=:avatar,appearance_json=:appearance,occupation=:occupation,enabled=:enabled,agent_paused=:paused,updated_at=:updated WHERE npc_id=:id')->execute([':id' => $id, ':name' => $name, ':avatar' => (string)($body['avatar_asset_id'] ?? ''), ':appearance' => $json($body['appearance'] ?? []), ':occupation' => $occupation, ':enabled' => (int)(bool)($body['enabled'] ?? true), ':paused' => (int)(bool)($body['agent_paused'] ?? false), ':updated' => auth_sql_time($now)]);
        }
        $profile = $body['profile'] ?? [];
        $profileSql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INSERT INTO town_npc_profiles (npc_id,personality_json,family_background,short_term_goal,long_term_goal,speech_style,values_json,fears_json) VALUES (:id,:personality,:family,:short,:long,:speech,:values,:fears) ON CONFLICT(npc_id) DO UPDATE SET personality_json=excluded.personality_json,family_background=excluded.family_background,short_term_goal=excluded.short_term_goal,long_term_goal=excluded.long_term_goal,speech_style=excluded.speech_style,values_json=excluded.values_json,fears_json=excluded.fears_json'
            : 'INSERT INTO town_npc_profiles (npc_id,personality_json,family_background,short_term_goal,long_term_goal,speech_style,values_json,fears_json) VALUES (:id,:personality,:family,:short,:long,:speech,:values,:fears) ON DUPLICATE KEY UPDATE personality_json=VALUES(personality_json),family_background=VALUES(family_background),short_term_goal=VALUES(short_term_goal),long_term_goal=VALUES(long_term_goal),speech_style=VALUES(speech_style),values_json=VALUES(values_json),fears_json=VALUES(fears_json)';
        $pdo->prepare($profileSql)->execute([':id' => $id, ':personality' => $json($profile['personality'] ?? []), ':family' => (string)($profile['family_background'] ?? ''), ':short' => (string)($profile['short_term_goal'] ?? ''), ':long' => (string)($profile['long_term_goal'] ?? ''), ':speech' => (string)($profile['speech_style'] ?? ''), ':values' => $json($profile['values'] ?? []), ':fears' => $json($profile['fears'] ?? [])]);
        if (is_array($body['schedule'] ?? null)) {
            $pdo->prepare('DELETE FROM town_npc_schedules WHERE npc_id=:id')->execute([':id' => $id]);
            $insert = $pdo->prepare('INSERT INTO town_npc_schedules (npc_id,weekday,start_minute,end_minute,action,target_id,job,priority) VALUES (:id,:weekday,:start,:end,:action,:target,:job,:priority)');
            foreach ($body['schedule'] as $entry) if (is_array($entry) && in_array((string)($entry['action'] ?? ''), TOWN_NPC_ACTIONS, true)) $insert->execute([':id' => $id, ':weekday' => max(0, min(6, (int)($entry['weekday'] ?? 0))), ':start' => max(0, min(1439, (int)($entry['start_minute'] ?? 0))), ':end' => max(0, min(1440, (int)($entry['end_minute'] ?? 1440))), ':action' => (string)$entry['action'], ':target' => (string)($entry['target_id'] ?? ''), ':job' => (string)($entry['job'] ?? ''), ':priority' => max(0, min(255, (int)($entry['priority'] ?? 0)))]);
        }
        if (is_array($body['home'] ?? null)) town_npc_save_home($pdo, $id, $body['home'], $now);
        if (is_array($body['decision_policy'] ?? null)) town_npc_save_decision_policy($pdo, $id, $body['decision_policy'], $now);
        if (array_key_exists('wallet_balance', $body)) town_npc_set_wallet($pdo, $id, max(0, (int)$body['wallet_balance']), is_string($body['initialized_by'] ?? null) ? (string)$body['initialized_by'] : null, $now);
        if (is_array($body['economy_inventory'] ?? null)) town_npc_set_economy_inventory($pdo, $id, $body['economy_inventory'], $now);
        $initialX = max(0, (int)($body['tile_x'] ?? 0)); $initialY = max(0, (int)($body['tile_y'] ?? 0));
        if ($existingId === null) {
            $initialPosition = town_navigation_can_traverse($pdo, $initialX, $initialY, []);
            if (!$initialPosition['allowed']) {
                throw new ApiError('invalid_npc_position', '村民初始位置必须位于可通行格子（' . (string)$initialPosition['reason'] . '）。', 409);
            }
        }
        $runtimeHeight = town_npc_runtime_height_column($pdo) ? ',height' : '';
        $runtimeHeightValue = town_npc_runtime_height_column($pdo) ? ',:height' : '';
        $runtimePrefix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $runtime = $pdo->prepare($runtimePrefix . ' INTO town_npc_runtime_states (npc_id,tile_x,tile_y' . $runtimeHeight . ',state,capabilities_json,state_version,updated_at) VALUES (:id,:x,:y' . $runtimeHeightValue . ',\'rest\',\'[]\',1,:updated)');
        $runtimeParams = [':id' => $id, ':x' => $initialX, ':y' => $initialY, ':updated' => auth_sql_time($now)];
        if (town_npc_runtime_height_column($pdo)) $runtimeParams[':height'] = town_npc_navigation_height($pdo, $initialX, $initialY);
        $runtime->execute($runtimeParams);
        if ($existingId !== null && !empty($body['set_position'])) {
            $current = $pdo->prepare('SELECT current_task_id,capabilities_json FROM town_npc_runtime_states WHERE npc_id=:id LIMIT 1');
            $current->execute([':id' => $id]);
            $currentRow = $current->fetch(PDO::FETCH_ASSOC) ?: [];
            $currentTask = $currentRow['current_task_id'] ?? null;
            if (is_string($currentTask) && trim($currentTask) !== '') {
                $taskStatus = $pdo->prepare('SELECT status FROM town_npc_tasks WHERE task_id=:task AND npc_id=:npc LIMIT 1');
                $taskStatus->execute([':task' => trim($currentTask), ':npc' => $id]);
                if ((string)$taskStatus->fetchColumn() === 'running') throw new ApiError('npc_busy', '请先等待当前任务完成，再修改村民位置。', 409);
            }
            $x = max(0, (int)($body['tile_x'] ?? 0)); $y = max(0, (int)($body['tile_y'] ?? 0));
            $positionCapabilities = array_values(array_unique(array_merge(town_navigation_capabilities($currentRow['capabilities_json'] ?? []), town_npc_inventory_capabilities($pdo, $id))));
            $positionAllowed = town_navigation_can_traverse($pdo, $x, $y, $positionCapabilities);
            if (!$positionAllowed['allowed']) throw new ApiError('invalid_npc_position', '村民位置必须位于可通行格子（' . (string)$positionAllowed['reason'] . '）。', 409);
            $height = (int)($positionAllowed['height'] ?? town_npc_navigation_height($pdo, $x, $y));
            $move = $pdo->prepare('UPDATE town_npc_runtime_states SET tile_x=:x,tile_y=:y' . (town_npc_runtime_height_column($pdo) ? ',height=:height' : '') . ',state=\'rest\',current_task_id=NULL,dialogue=\'\',state_version=state_version+1,updated_at=:updated WHERE npc_id=:id');
            $moveParams = [':x' => $x, ':y' => $y, ':updated' => auth_sql_time($now), ':id' => $id];
            if (town_npc_runtime_height_column($pdo)) $moveParams[':height'] = $height;
            $move->execute($moveParams);
            town_npc_emit_event($pdo, $id, 'state_changed', ['state' => 'rest', 'tile_x' => $x, 'tile_y' => $y, 'height' => $height, 'reason' => 'admin_position_update'], $now);
            town_npc_activity_log($pdo, $id, 'action', '管理员更新了村民位置', ['tile_x' => $x, 'tile_y' => $y, 'height' => $height], $now);
        }
        $pdo->commit();
        $row = $pdo->prepare('SELECT n.*,p.personality_json,p.family_background,p.short_term_goal,p.long_term_goal,p.speech_style,p.values_json,p.fears_json,r.tile_x,r.tile_y,' . town_npc_runtime_height_select($pdo) . ',r.state AS runtime_state,r.current_task_id,r.dialogue,r.mood_json,r.capabilities_json,r.state_version,r.updated_at AS runtime_updated_at FROM town_npcs n LEFT JOIN town_npc_profiles p ON p.npc_id=n.npc_id LEFT JOIN town_npc_runtime_states r ON r.npc_id=n.npc_id WHERE n.npc_id=:id'); $row->execute([':id' => $id]);
        return town_npc_public($row->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
