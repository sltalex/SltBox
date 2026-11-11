<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__ . '/shared_town.php';
require_once __DIR__ . '/town_navigation.php';

const TOWN_NPC_ACTIONS = ['walk', 'work', 'rest', 'talk'];
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

    if (!array_key_exists('steps', $payload)) return;
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
            // Work, talk, and rest are terminal steps in this first version;
            // a later action must be represented by a new server task.
            $completedAction = true;
        }
    }
    if (!$seenRootAction) throw new ApiError('invalid_npc_plan', 'The NPC plan does not contain its requested action.', 400);
}

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
    } catch (Throwable) {
        return $cache[$key] = false;
    }
}

function town_npc_runtime_height_select(PDO $pdo): string
{
    return town_npc_runtime_height_column($pdo) ? 'r.height AS runtime_height' : '0 AS runtime_height';
}

function town_npc_navigation_height(PDO $pdo, int $x, int $y): int
{
    try {
        $cell = town_navigation_cell($pdo, $x, $y);
        return max(0, min(32, (int)($cell['height'] ?? 0)));
    } catch (Throwable) {
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
    } catch (PDOException) {
        // Migration 032 is optional during rolling upgrades.
    }
    return $rows;
}

function town_npc_rows(PDO $pdo, bool $includeDisabled = false): array
{
    $where = $includeDisabled ? '' : ' WHERE n.enabled=1';
    $query = $pdo->query('SELECT n.*, p.personality_json,p.family_background,p.short_term_goal,p.long_term_goal,'
        . 'p.speech_style,p.values_json,p.fears_json,r.tile_x,r.tile_y,' . town_npc_runtime_height_select($pdo) . ',r.state AS runtime_state,r.current_task_id,'
        . 'r.dialogue,r.mood_json,r.capabilities_json,r.state_version,r.updated_at AS runtime_updated_at,'
        . '(SELECT JSON_ARRAYAGG(JSON_OBJECT("weekday",s.weekday,"start_minute",s.start_minute,"end_minute",s.end_minute,'
        . '"action",s.action,"target_id",s.target_id,"job",s.job,"priority",s.priority)) FROM town_npc_schedules s WHERE s.npc_id=n.npc_id) AS schedule_json '
        . 'FROM town_npcs n LEFT JOIN town_npc_profiles p ON p.npc_id=n.npc_id '
        . 'LEFT JOIN town_npc_runtime_states r ON r.npc_id=n.npc_id' . $where . ' ORDER BY n.created_at,n.npc_id');
    return town_npc_attach_inventory($pdo, array_map('town_npc_public', $query->fetchAll(PDO::FETCH_ASSOC)));
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
    } catch (PDOException) {
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
    } catch (PDOException) {
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
    $pdo->beginTransaction();
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
        $pdo->commit();
        return ['npc_id' => $npcId, 'other_npc_id' => $otherNpcId, 'affinity' => $affinity];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** SQLite fallback used by service tests and installations before JSON_ARRAYAGG. */
function town_npc_rows_safe(PDO $pdo, bool $includeDisabled = false): array
{
    try { return town_npc_rows($pdo, $includeDisabled); }
    catch (PDOException) {
        $where = $includeDisabled ? '' : ' WHERE n.enabled=1';
        $query = $pdo->query('SELECT n.*,p.personality_json,p.family_background,p.short_term_goal,p.long_term_goal,p.speech_style,p.values_json,p.fears_json,'
            . 'r.tile_x,r.tile_y,' . town_npc_runtime_height_select($pdo) . ',r.state AS runtime_state,r.current_task_id,r.dialogue,r.capabilities_json,r.state_version,r.updated_at AS runtime_updated_at '
            . 'FROM town_npcs n LEFT JOIN town_npc_profiles p ON p.npc_id=n.npc_id LEFT JOIN town_npc_runtime_states r ON r.npc_id=n.npc_id' . $where . ' ORDER BY n.created_at,n.npc_id');
        return town_npc_attach_inventory($pdo, array_map('town_npc_public', $query->fetchAll(PDO::FETCH_ASSOC)));
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
        $query = $pdo->prepare('SELECT memory_id,memory_type,content,importance,created_at,expires_at FROM town_npc_memories WHERE npc_id=:npc AND (expires_at IS NULL OR expires_at>:now) ORDER BY memory_id DESC LIMIT 200');
        $query->execute([':npc' => $npcId, ':now' => auth_sql_time($now)]);
        $tokens = preg_split('/[\s,，。；;、:：!?！？]+/u', $queryText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_unique(array_filter(array_map(static fn(mixed $value): string => trim((string)$value), $tokens), static fn(string $value): bool => $value !== '')));
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
    } catch (PDOException) { return []; }
}

function town_npc_agent_context(PDO $pdo, string $npcId, ?DateTimeImmutable $now = null, ?string $memoryQuery = null): array
{
    $empty = ['relationships' => [], 'memories' => []];
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
        if (is_string($memoryQuery) && trim($memoryQuery) !== '') {
            $selected = town_npc_search_memories($pdo, $npcId, $memoryQuery, $now, 12);
            foreach ($selected as $row) $memoryRows[] = [
                'memory_type' => (string)$row['memory_type'], 'content' => substr((string)$row['content'], 0, 1000),
                'importance' => (int)$row['importance'], 'created_at' => (string)$row['created_at'],
                'expires_at' => $row['expires_at'] !== null ? (string)$row['expires_at'] : null,
            ];
            return ['relationships' => $relationshipRows, 'memories' => $memoryRows];
        }
        $memories = $pdo->prepare('SELECT memory_type,content,importance,created_at,expires_at FROM town_npc_memories WHERE npc_id=:npc AND (expires_at IS NULL OR expires_at>:now) ORDER BY importance DESC,memory_id DESC LIMIT 12');
        $memories->execute([':npc' => $npcId, ':now' => auth_sql_time($now)]);
        foreach ($memories->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $memoryRows[] = [
                'memory_type' => (string)$row['memory_type'],
                'content' => substr((string)$row['content'], 0, 1000),
                'importance' => (int)$row['importance'],
                'created_at' => (string)$row['created_at'],
                'expires_at' => $row['expires_at'] !== null ? (string)$row['expires_at'] : null,
            ];
        }
        return ['relationships' => $relationshipRows, 'memories' => $memoryRows];
    } catch (PDOException) {
        // Keep rolling upgrades safe until migration 028 is present.
        return $empty;
    }
}

function town_npc_snapshot(PDO $pdo, bool $forAgent = false): array
{
    $rows = town_npc_rows_safe($pdo);
    if ($forAgent) {
        foreach ($rows as $index => $row) {
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
    $query = $pdo->prepare('SELECT event_id,npc_id,event_type,payload_json,created_at FROM town_npc_events WHERE event_id>:cursor ORDER BY event_id LIMIT ' . min(500, max(1, $limit)));
    $query->execute([':cursor' => $cursor]); $events = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $events[] = ['event_id' => (int)$row['event_id'], 'npc_id' => (string)$row['npc_id'], 'type' => (string)$row['event_type'],
            'payload' => town_npc_decode($row['payload_json'], []), 'created_at' => (string)$row['created_at']];
    }
    return ['cursor' => (int)($pdo->query('SELECT COALESCE(MAX(event_id),0) FROM town_npc_events')->fetchColumn() ?: $cursor), 'events' => $events,
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
        } catch (PDOException) { /* Migration 030 is optional while older installations are upgraded. */ }
    }
    return match (strtolower(trim($targetId))) {
        'farm-zone-3' => ['tile_x' => 128, 'tile_y' => 120],
        'market-zone-1' => ['tile_x' => 96, 'tile_y' => 96],
        'workshop-zone-2' => ['tile_x' => 112, 'tile_y' => 96],
        'town-square' => ['tile_x' => 96, 'tile_y' => 96],
        default => null,
    };
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
    } catch (PDOException) { return []; }
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
    } catch (PDOException) {
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

/** Consume one item for each route capability not already intrinsic to the NPC. */
function town_npc_consume_route_items(PDO $pdo, string $npcId, array $required, mixed $intrinsic, DateTimeImmutable $now): array
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
        } catch (PDOException) { }
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
        try { $expired = auth_parse_sql_time((string)$active['expires_at']) <= $now; } catch (Throwable) { $expired = true; }
        if ($expired) $pdo->prepare("UPDATE town_work_point_reservations SET status='expired',released_at=:released WHERE reservation_id=:id")->execute([':released' => auth_sql_time($now), ':id' => $active['reservation_id']]);
        elseif ((string)$active['task_id'] !== $taskId) throw new ApiError('work_point_busy', '工作点正在被其他村民使用。', 409);
    }
    $expires = $now->modify('+' . max(120, ($durationMinutes * 60) + 120) . ' seconds');
    $insert = $pdo->prepare('INSERT INTO town_work_point_reservations (reservation_id,point_id,npc_id,task_id,status,reserved_at,expires_at,released_at) VALUES (:id,:point,:npc,:task,\'active\',:reserved,:expires,NULL)');
    try { $insert->execute([':id' => town_npc_id(), ':point' => $pointId, ':npc' => $npcId, ':task' => $taskId, ':reserved' => auth_sql_time($now), ':expires' => auth_sql_time($expires)]); }
    catch (PDOException) { throw new ApiError('work_point_busy', '工作点正在被其他村民使用。', 409); }
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
    } catch (PDOException) { }
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
    } catch (PDOException) {
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
    } catch (PDOException) {
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
    } catch (PDOException) { return []; }
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
    } catch (PDOException) { return []; }
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
    } catch (PDOException) { return ['market_stocks' => [], 'workshop_outputs' => []]; }
}

function town_npc_save_work_point(PDO $pdo, array $body, DateTimeImmutable $now, ?string $existingId = null): array
{
    $key = trim((string)($body['point_key'] ?? '')); $name = trim((string)($body['name'] ?? '')); $kind = trim((string)($body['kind'] ?? 'workshop'));
    if ($key === '' || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $key) || $name === '' || strlen($name) > 80) throw new ApiError('invalid_work_point', '工作点名称或 ID 无效。', 400);
    if (!in_array($kind, ['farm', 'market', 'workshop', 'public'], true)) throw new ApiError('invalid_work_point', '工作点类型无效。', 400);
    $json = static fn(mixed $value): string => json_encode(is_array($value) ? $value : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $pointId = $existingId !== null && preg_match('/\A[a-f0-9]{32}\z/D', $existingId) ? $existingId : town_npc_id();
    $enabled = true;
    if ($existingId !== null) { $state = $pdo->prepare('SELECT enabled FROM town_work_points WHERE point_id=:id LIMIT 1'); $state->execute([':id' => $existingId]); $current = $state->fetchColumn(); if ($current === false) throw new ApiError('work_point_not_found', '工作点不存在。', 404); $enabled = (bool)$current; }
    try {
        $query = $pdo->prepare('INSERT INTO town_work_points (point_id,world_id,point_key,name,kind,tile_x,tile_y,interaction_json,result_json,enabled,created_at,updated_at) VALUES (:id,:world,:key,:name,:kind,:x,:y,:interaction,:result,:enabled,:created,:updated) ON DUPLICATE KEY UPDATE point_key=VALUES(point_key),name=VALUES(name),kind=VALUES(kind),tile_x=VALUES(tile_x),tile_y=VALUES(tile_y),interaction_json=VALUES(interaction_json),result_json=VALUES(result_json),updated_at=VALUES(updated_at)');
        $query->execute([':id' => $pointId, ':world' => SHARED_TOWN_WORLD_ID, ':key' => $key, ':name' => $name, ':kind' => $kind, ':x' => max(0, (int)($body['tile_x'] ?? 0)), ':y' => max(0, (int)($body['tile_y'] ?? 0)), ':interaction' => $json($body['interaction'] ?? []), ':result' => $json($body['result'] ?? []), ':enabled' => (int)$enabled, ':created' => auth_sql_time($now), ':updated' => auth_sql_time($now)]);
    } catch (PDOException) { throw new ApiError('invalid_work_point', '工作点 ID 已存在或保存失败。', 409); }
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
    $detail = ['run_id' => $runId, 'point_id' => $point['point_id'], 'point_key' => $point['point_key'], 'point_kind' => $point['kind'], 'job' => $job, 'result' => $result];
    try {
        $insert = $pdo->prepare('INSERT INTO town_work_runs (run_id,point_id,npc_id,task_id,job,status,result_json,started_at,completed_at) VALUES (:run,:point,:npc,:task,:job,\'completed\',:result,:started,:completed)');
        $insert->execute([':run' => $runId, ':point' => $point['point_id'], ':npc' => $npcId, ':task' => $taskId, ':job' => $job, ':result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':started' => auth_sql_time($now), ':completed' => auth_sql_time($now)]);
    } catch (PDOException) { return []; }
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
        $relationshipJson = static function (mixed $value): array {
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
        foreach ([$npcId, $otherId] as $owner) {
            $counterpart = $owner === $npcId ? $otherId : $npcId;
            $memory = '与村民 ' . $counterpart . ' 交谈：' . $dialogue;
            $insert = $pdo->prepare('INSERT INTO town_npc_memories (npc_id,memory_type,content,importance,created_at) VALUES (:npc,\'conversation\',:content,2,:created)');
            $insert->execute([':npc' => $owner, ':content' => $memory, ':created' => auth_sql_time($now)]);
            town_npc_compact_memories($pdo, $owner, $now);
        }
        town_npc_emit_event($pdo, $npcId, 'conversation', ['task_id' => $taskId, 'other_npc_id' => $otherId, 'dialogue' => $dialogue, 'affinity_delta' => 1], $now);
        town_npc_activity_log($pdo, $npcId, 'memory', '交谈已沉淀为关系和记忆', ['task_id' => $taskId, 'other_npc_id' => $otherId, 'dialogue' => $dialogue, 'affinity_delta' => 1], $now, $payload['run_id'] ?? null);
        return ['other_npc_id' => $otherId, 'dialogue' => $dialogue, 'affinity_delta' => 1];
    } catch (PDOException) {
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
    } catch (PDOException) {
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

function town_npc_apply_action(PDO $pdo, array $body, DateTimeImmutable $now, bool $service = false, bool $admin = false): array
{
    if (!$service && !$admin) throw new ApiError('agent_forbidden', 'NPC actions require the worker service identity.', 403);
    $npcId = is_string($body['npc_id'] ?? null) ? trim((string)$body['npc_id']) : '';
    $action = is_string($body['action'] ?? null) ? strtolower(trim((string)$body['action'])) : '';
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $npcId) || !in_array($action, TOWN_NPC_ACTIONS, true)) throw new ApiError('invalid_npc_action', 'NPC action is invalid.', 400);
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME); $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT n.npc_id,n.enabled,n.agent_paused,r.tile_x,r.tile_y,' . town_npc_runtime_height_select($pdo) . ',r.current_task_id,r.capabilities_json FROM town_npcs n LEFT JOIN town_npc_runtime_states r ON r.npc_id=n.npc_id WHERE n.npc_id=:npc' . $lock);
        $query->execute([':npc' => $npcId]); $npc = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($npc) || !(bool)$npc['enabled']) throw new ApiError('npc_not_found', 'NPC was not found.', 404);
        if ((bool)$npc['agent_paused'] && !$admin) throw new ApiError('npc_paused', 'NPC agent is paused.', 409);
        if (is_string($npc['current_task_id'] ?? null) && trim((string)$npc['current_task_id']) !== '') {
            $active = $pdo->prepare('SELECT status FROM town_npc_tasks WHERE task_id=:task AND npc_id=:npc' . $lock);
            $active->execute([':task' => $npc['current_task_id'], ':npc' => $npcId]);
            if ((string)$active->fetchColumn() === 'running') throw new ApiError('npc_busy', 'NPC already has a running task.', 409);
        }
        $payload = town_npc_array($body['payload'] ?? []);
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
            if ($toX === false || $toY === false) throw new ApiError('invalid_target', 'A walk target is required.', 400);
            $route = town_navigation_find_route($pdo, $fromX, $fromY, (int)$toX, (int)$toY, $capabilities);
            if (!$route['found']) throw new ApiError('no_route', 'NPC cannot reach the requested target.', 409);
            $tileX = (int)$toX; $tileY = (int)$toY; $tileHeight = town_npc_navigation_height($pdo, $tileX, $tileY); $payload['route'] = $route['path']; $payload['navigation_version'] = $route['navigation_version'];
        }
        if (in_array($action, ['work', 'talk'], true)) {
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
                        if (is_string($completedAt) && $completedAt !== '' && auth_parse_sql_time($completedAt)->modify('+60 seconds') > $now) {
                            throw new ApiError('talk_cooldown', 'NPC conversation is cooling down.', 409);
                        }
                    } catch (ApiError $error) { throw $error; }
                    catch (PDOException) { /* Older rolling schemas may not have task timestamps. */ }
                }
                if (preg_match('/\A[a-f0-9]{32}\z/D', $talkTarget) && $talkTarget !== $npcId) {
                    $targetExists = $pdo->prepare('SELECT n.npc_id,r.tile_x,r.tile_y FROM town_npcs n LEFT JOIN town_npc_runtime_states r ON r.npc_id=n.npc_id WHERE n.npc_id=:npc AND n.enabled=1 LIMIT 1');
                    $targetExists->execute([':npc' => $talkTarget]);
                    $targetRow = $targetExists->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($targetRow)) throw new ApiError('invalid_talk_target', '交谈对象不存在或未启用。', 409);
                    $payload['target_npc_id'] = $talkTarget;
                    // A conversation target is the other NPC's current authoritative position.
                    // An explicit payload target may still override this for scripted scenes.
                    if (!isset($target['tile_x'], $target['tile_y']) && $targetRow['tile_x'] !== null && $targetRow['tile_y'] !== null) {
                        $target['tile_x'] = (int)$targetRow['tile_x'];
                        $target['tile_y'] = (int)$targetRow['tile_y'];
                    }
                }
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
        if (!is_array($payload['route'] ?? null) || $payload['route'] === []) $payload['route'] = [['x' => $fromX, 'y' => $fromY, 'height' => $fromHeight]];
        $dialogueResult = town_npc_moderate_dialogue((string)($payload['dialogue'] ?? ''));
        if (!$dialogueResult['allowed']) throw new ApiError('dialogue_rejected', 'NPC dialogue contains unsupported markup.', 400);
        $dialogue = (string)$dialogueResult['text'];
        if ($action === 'talk' || $dialogue !== '') $payload['dialogue'] = $dialogue;
        $runId = is_string($payload['run_id'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/D', (string)$payload['run_id']) ? (string)$payload['run_id'] : null;
        $mood = town_npc_array($payload['mood'] ?? []); $payload['route_cursor'] = 0;
        $duration = filter_var($payload['duration_minutes'] ?? null, FILTER_VALIDATE_INT); if ($duration === false) $duration = $action === 'walk' ? 0 : 20; $payload['duration_minutes'] = max(0, min(1440, (int)$duration));
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
        if (count($route) > 1) town_npc_activity_log($pdo, $npcId, 'path', '已规划路线：(' . $fromX . ',' . $fromY . ') → (' . $tileX . ',' . $tileY . ')', ['from' => ['tile_x' => $fromX, 'tile_y' => $fromY], 'to' => ['tile_x' => $tileX, 'tile_y' => $tileY], 'route' => $route, 'route_length' => count($route), 'navigation_version' => $payload['navigation_version'] ?? null], $now, $runId);
        if ($mood !== []) town_npc_activity_log($pdo, $npcId, 'mood', '心情更新', ['mood' => $mood, 'action' => $action], $now, $runId);
        $pdo->commit();
        return ['ok' => true, 'npc_id' => $npcId, 'action' => $action, 'state' => $state, 'task_id' => $taskId, 'task_status' => 'running', 'tile_x' => $fromX, 'tile_y' => $fromY, 'height' => $fromHeight, 'from' => ['tile_x' => $fromX, 'tile_y' => $fromY, 'height' => $fromHeight], 'to' => ['tile_x' => $tileX, 'tile_y' => $tileY, 'height' => $tileHeight], 'route' => $route, 'route_cursor' => 0, 'dialogue' => $dialogue, 'mood' => $mood, 'job' => (string)($payload['job'] ?? ''), 'navigation_version' => town_navigation_version($pdo)];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
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
    $minute = static function (mixed $value, int $fallback): int {
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
        $normalized[] = ['weekday' => $weekday, 'start_minute' => $start, 'end_minute' => $end, 'action' => $action,
            'target_id' => substr(trim((string)($entry['target_id'] ?? '')), 0, 128), 'job' => substr(trim((string)($entry['job'] ?? '')), 0, 64),
            'priority' => max(0, min(255, (int)($entry['priority'] ?? 0)))];
    }
    usort($normalized, static fn(array $left, array $right): int => [$left['weekday'], $left['start_minute'], $left['priority']] <=> [$right['weekday'], $right['start_minute'], $right['priority']]);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM town_npc_schedules WHERE npc_id=:npc')->execute([':npc' => $npcId]);
        $insert = $pdo->prepare('INSERT INTO town_npc_schedules (npc_id,weekday,start_minute,end_minute,action,target_id,job,priority) VALUES (:npc,:weekday,:start,:end,:action,:target,:job,:priority)');
        foreach ($normalized as $entry) $insert->execute([':npc' => $npcId, ':weekday' => $entry['weekday'], ':start' => $entry['start_minute'], ':end' => $entry['end_minute'], ':action' => $entry['action'], ':target' => $entry['target_id'], ':job' => $entry['job'], ':priority' => $entry['priority']]);
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
        $taskId = is_string($runtime['current_task_id'] ?? null) ? trim((string)$runtime['current_task_id']) : '';
        if ($taskId === '') { $height = town_npc_navigation_height($pdo, (int)$runtime['tile_x'], (int)$runtime['tile_y']); $pdo->commit(); return ['ok' => true, 'npc_id' => $npcId, 'task_status' => 'none', 'state' => (string)($runtime['state'] ?? 'rest'), 'tile_x' => (int)$runtime['tile_x'], 'tile_y' => (int)$runtime['tile_y'], 'height' => $height, 'route_remaining' => [], 'navigation_version' => town_navigation_version($pdo)]; }
        $taskQuery = $pdo->prepare('SELECT task_id,action,payload_json,status,started_at FROM town_npc_tasks WHERE task_id=:task AND npc_id=:npc' . $lock); $taskQuery->execute([':task' => $taskId, ':npc' => $npcId]); $task = $taskQuery->fetch(PDO::FETCH_ASSOC);
         if (!is_array($task) || (string)$task['status'] !== 'running') { $height = town_npc_navigation_height($pdo, (int)$runtime['tile_x'], (int)$runtime['tile_y']); $pdo->prepare('UPDATE town_npc_runtime_states SET current_task_id=NULL,state=\'rest\',state_version=state_version+1,updated_at=:updated WHERE npc_id=:npc')->execute([':updated' => auth_sql_time($now), ':npc' => $npcId]); $pdo->commit(); return ['ok' => true, 'npc_id' => $npcId, 'task_id' => $taskId, 'task_status' => (string)($task['status'] ?? 'missing'), 'state' => 'rest', 'tile_x' => (int)$runtime['tile_x'], 'tile_y' => (int)$runtime['tile_y'], 'height' => $height, 'route_remaining' => [], 'navigation_version' => town_navigation_version($pdo)]; }
        $payload = town_npc_array(town_npc_decode($task['payload_json'] ?? [], [])); $consumedItems = is_array($payload['consumed_items'] ?? null) ? $payload['consumed_items'] : []; $currentVersion = town_navigation_version($pdo); $plannedVersion = filter_var($payload['navigation_version'] ?? null, FILTER_VALIDATE_INT);
        $x = (int)$runtime['tile_x']; $y = (int)$runtime['tile_y']; $currentHeight = town_npc_navigation_height($pdo, $x, $y);
        $route = is_array($payload['route'] ?? null) ? array_values($payload['route']) : []; if ($route === []) $route = [['x' => $x, 'y' => $y, 'height' => $currentHeight]];
        $cursor = max(0, min(count($route) - 1, (int)($payload['route_cursor'] ?? 0))); $action = (string)$task['action']; $capabilities = array_values(array_unique(array_merge(town_navigation_capabilities($runtime['capabilities_json'] ?? []), town_npc_inventory_capabilities($pdo, $npcId), array_keys($consumedItems))));
        $fail = static function (string $reason, array $detail) use ($pdo, $npcId, $taskId, $now, $x, $y, $currentHeight, $currentVersion, $consumedItems): array { town_npc_refund_route_items($pdo, $npcId, $consumedItems, $now); town_npc_release_work_point($pdo, $taskId, 'failed', $now); $pdo->prepare('UPDATE town_npc_tasks SET status=\'failed\',failure_reason=:reason,completed_at=:completed WHERE task_id=:task AND npc_id=:npc')->execute([':reason' => substr($reason, 0, 255), ':completed' => auth_sql_time($now), ':task' => $taskId, ':npc' => $npcId]); $heightUpdate = town_npc_runtime_height_column($pdo) ? ',height=:height' : ''; $stateParams = [':updated' => auth_sql_time($now), ':npc' => $npcId]; if ($heightUpdate !== '') $stateParams[':height'] = $currentHeight; $pdo->prepare('UPDATE town_npc_runtime_states SET current_task_id=NULL,state=\'rest\',state_version=state_version+1,updated_at=:updated' . $heightUpdate . ' WHERE npc_id=:npc')->execute($stateParams); town_npc_emit_event($pdo, $npcId, 'task_failed', ['task_id' => $taskId, 'task_status' => 'failed', 'failure_reason' => $reason, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'navigation_version' => $currentVersion] + $detail, $now); town_npc_activity_log($pdo, $npcId, 'path', '任务停止：' . $reason, $detail + ['task_id' => $taskId, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight], $now); return ['ok' => true, 'npc_id' => $npcId, 'task_id' => $taskId, 'task_status' => 'failed', 'failure_reason' => $reason, 'state' => 'rest', 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'route_cursor' => $detail['route_cursor'] ?? 0, 'route_remaining' => [], 'navigation_version' => $currentVersion]; };
        $replan = static function () use ($pdo, $npcId, $taskId, $now, $currentVersion, $action, &$payload, &$consumedItems, &$route, &$cursor, $x, $y, $currentHeight, $runtime, $capabilities): ?array {
            $destination = town_npc_task_destination($pdo, $action, $payload);
            if (!is_array($destination)) return null;
            $oldVersion = (int)($payload['previous_navigation_version'] ?? 0);
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
            unset($payload['previous_navigation_version']);
            $pdo->prepare('UPDATE town_npc_tasks SET payload_json=:payload WHERE task_id=:task AND npc_id=:npc')->execute([':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':task' => $taskId, ':npc' => $npcId]);
            $state = count($route) > 1 ? 'walk' : $action;
            $pdo->prepare('UPDATE town_npc_runtime_states SET state=:state,state_version=state_version+1,updated_at=:updated WHERE npc_id=:npc')->execute([':state' => $state, ':updated' => auth_sql_time($now), ':npc' => $npcId]);
             $destination['height'] = town_npc_navigation_height($pdo, (int)$destination['tile_x'], (int)$destination['tile_y']);
             town_npc_emit_event($pdo, $npcId, 'route_replanned', ['task_id' => $taskId, 'action' => $action, 'from' => ['tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight], 'to' => $destination, 'old_navigation_version' => $oldVersion, 'navigation_version' => $currentVersion, 'old_route_cursor' => $oldCursor, 'route' => $route, 'required_capabilities' => $newRequired], $now);
             town_npc_activity_log($pdo, $npcId, 'path', '地图变化后重新规划路线', ['task_id' => $taskId, 'from' => ['tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight], 'to' => $destination, 'route' => $route, 'route_length' => count($route), 'replan_count' => $payload['replan_count']], $now);
             return ['ok' => true, 'npc_id' => $npcId, 'task_id' => $taskId, 'action' => $action, 'task_status' => 'running', 'state' => $state, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'route_cursor' => 0, 'route_remaining' => $route, 'navigation_version' => $currentVersion, 'route_replanned' => true, 'replan_count' => $payload['replan_count']];
        };
        if ($action === 'work' && !town_npc_work_point_reservation_valid($pdo, $taskId, $payload, $now)) { $result = $fail('work_point_expired', ['route_cursor' => $cursor]); $pdo->commit(); return $result; }
        if ($plannedVersion !== false && $plannedVersion !== $currentVersion) {
            $payload['previous_navigation_version'] = $plannedVersion;
            $result = $replan();
            if (is_array($result)) { $pdo->commit(); return $result; }
            $result = $fail('navigation_changed_no_route', ['planned_navigation_version' => $plannedVersion, 'current_navigation_version' => $currentVersion, 'route_cursor' => $cursor]); $pdo->commit(); return $result;
        }
        if ($cursor >= count($route)) { $result = $fail('invalid_route', ['route_cursor' => $cursor]); $pdo->commit(); return $result; }
        $point = town_npc_array($route[$cursor]);
        $pointHeight = (int)($point['height'] ?? $currentHeight);
        if ((int)($point['x'] ?? -999) !== $x || (int)($point['y'] ?? -999) !== $y || $pointHeight !== $currentHeight) { $result = $fail('stale_route_position', ['route_cursor' => $cursor, 'route_height' => $pointHeight]); $pdo->commit(); return $result; }
        if ($cursor < count($route) - 1) {
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
                $pdo->prepare('UPDATE town_npc_runtime_states SET state=\'rest\',current_task_id=NULL,state_version=state_version+1,updated_at=:updated WHERE npc_id=:npc')->execute([':updated' => auth_sql_time($now), ':npc' => $npcId]);
                town_npc_emit_event($pdo, $npcId, 'task_completed', ['state' => 'rest', 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'task_id' => $taskId, 'task_status' => 'completed', 'action' => $action, 'navigation_version' => $currentVersion], $now);
                town_npc_activity_log($pdo, $npcId, 'action', '任务完成：walk', ['task_id' => $taskId, 'action' => $action, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight], $now);
                $pdo->commit();
                return ['ok' => true, 'npc_id' => $npcId, 'task_id' => $taskId, 'action' => $action, 'task_status' => 'completed', 'state' => 'rest', 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'route_cursor' => $cursor, 'route_remaining' => [], 'navigation_version' => $currentVersion];
            }
            town_npc_emit_event($pdo, $npcId, 'state_changed', ['state' => $state, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'task_id' => $taskId, 'task_status' => 'running', 'route_cursor' => $cursor, 'route_remaining' => array_slice($route, $cursor), 'navigation_version' => $currentVersion], $now); town_npc_activity_log($pdo, $npcId, 'path', 'NPC 前进一步', ['task_id' => $taskId, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'route_cursor' => $cursor, 'route_remaining' => array_slice($route, $cursor)], $now);
            $pdo->commit(); return ['ok' => true, 'npc_id' => $npcId, 'task_id' => $taskId, 'action' => $action, 'task_status' => 'running', 'state' => $state, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'route_cursor' => $cursor, 'route_remaining' => array_slice($route, $cursor), 'navigation_version' => $currentVersion];
        }
        $payload['route_cursor'] = $cursor; $state = $action === 'walk' ? 'rest' : $action; $arrivedAt = is_string($payload['arrived_at'] ?? null) ? (string)$payload['arrived_at'] : '';
         if ($action !== 'walk' && $arrivedAt === '') { $payload['arrived_at'] = auth_sql_time($now); $pdo->prepare('UPDATE town_npc_tasks SET payload_json=:payload WHERE task_id=:task AND npc_id=:npc')->execute([':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':task' => $taskId, ':npc' => $npcId]); $pdo->prepare('UPDATE town_npc_runtime_states SET state=:state,state_version=state_version+1,updated_at=:updated WHERE npc_id=:npc')->execute([':state' => $state, ':updated' => auth_sql_time($now), ':npc' => $npcId]); town_npc_emit_event($pdo, $npcId, 'state_changed', ['state' => $state, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'task_id' => $taskId, 'task_status' => 'running', 'route_cursor' => $cursor, 'route_remaining' => [$route[$cursor]], 'navigation_version' => $currentVersion], $now); $pdo->commit(); return ['ok' => true, 'npc_id' => $npcId, 'task_id' => $taskId, 'action' => $action, 'task_status' => 'running', 'state' => $state, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'route_cursor' => $cursor, 'route_remaining' => [$route[$cursor]], 'navigation_version' => $currentVersion]; }
         if ($action !== 'walk') { try { $arrivedTime = new DateTimeImmutable($arrivedAt); } catch (Throwable) { $arrivedTime = $now; } $duration = max(0, min(1440, (int)($payload['duration_minutes'] ?? 20))); if ($now->getTimestamp() < $arrivedTime->getTimestamp() + ($duration * 60)) { $pdo->commit(); return ['ok' => true, 'npc_id' => $npcId, 'task_id' => $taskId, 'action' => $action, 'task_status' => 'running', 'state' => $state, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'route_cursor' => $cursor, 'route_remaining' => [$route[$cursor]], 'navigation_version' => $currentVersion, 'remaining_seconds' => max(0, $arrivedTime->getTimestamp() + ($duration * 60) - $now->getTimestamp())]; } }
         $pdo->prepare('UPDATE town_npc_tasks SET status=\'completed\',completed_at=:completed,payload_json=:payload WHERE task_id=:task AND npc_id=:npc')->execute([':completed' => auth_sql_time($now), ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':task' => $taskId, ':npc' => $npcId]); $stateSql = 'UPDATE town_npc_runtime_states SET current_task_id=NULL,state=\'rest\'' . (town_npc_runtime_height_column($pdo) ? ',height=:height' : '') . ',state_version=state_version+1,updated_at=:updated WHERE npc_id=:npc'; $stateParams = [':updated' => auth_sql_time($now), ':npc' => $npcId]; if (town_npc_runtime_height_column($pdo)) $stateParams[':height'] = $currentHeight; $pdo->prepare($stateSql)->execute($stateParams); town_npc_emit_event($pdo, $npcId, 'task_completed', ['state' => 'rest', 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'task_id' => $taskId, 'task_status' => 'completed', 'action' => $action, 'navigation_version' => $currentVersion], $now); town_npc_activity_log($pdo, $npcId, 'action', '任务完成：' . $action, ['task_id' => $taskId, 'action' => $action, 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight], $now); if ($action === 'work') { town_npc_record_work_result($pdo, $npcId, $taskId, $payload, $now); town_npc_release_work_point($pdo, $taskId, 'completed', $now); } elseif ($action === 'talk') { town_npc_record_talk_result($pdo, $npcId, $taskId, $payload, $now); } $pdo->commit(); return ['ok' => true, 'npc_id' => $npcId, 'task_id' => $taskId, 'action' => $action, 'task_status' => 'completed', 'state' => 'rest', 'tile_x' => $x, 'tile_y' => $y, 'height' => $currentHeight, 'route_cursor' => $cursor, 'route_remaining' => [], 'navigation_version' => $currentVersion];
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
        $profileSql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INSERT INTO town_npc_profiles (npc_id,personality_json,family_background,short_term_goal,long_term_goal,speech_style,values_json,fears_json) VALUES (:id,:personality,:family,:short,:long,:speech,:values,:fears) ON CONFLICT(npc_id) DO UPDATE SET personality_json=excluded.personality_json,family_background=excluded.family_background,short_term_goal=excluded.short_term_goal,long_term_goal=excluded.long_term_goal,speech_style=excluded.speech_style,values_json=excluded.values_json,fears_json=excluded.fears_json'
            : 'INSERT INTO town_npc_profiles (npc_id,personality_json,family_background,short_term_goal,long_term_goal,speech_style,values_json,fears_json) VALUES (:id,:personality,:family,:short,:long,:speech,:values,:fears) ON DUPLICATE KEY UPDATE personality_json=VALUES(personality_json),family_background=VALUES(family_background),short_term_goal=VALUES(short_term_goal),long_term_goal=VALUES(long_term_goal),speech_style=VALUES(speech_style),values_json=VALUES(values_json),fears_json=VALUES(fears_json)';
        $pdo->prepare($profileSql)->execute([':id' => $id, ':personality' => $json($profile['personality'] ?? []), ':family' => (string)($profile['family_background'] ?? ''), ':short' => (string)($profile['short_term_goal'] ?? ''), ':long' => (string)($profile['long_term_goal'] ?? ''), ':speech' => (string)($profile['speech_style'] ?? ''), ':values' => $json($profile['values'] ?? []), ':fears' => $json($profile['fears'] ?? [])]);
        if (is_array($body['schedule'] ?? null)) {
            $pdo->prepare('DELETE FROM town_npc_schedules WHERE npc_id=:id')->execute([':id' => $id]);
            $insert = $pdo->prepare('INSERT INTO town_npc_schedules (npc_id,weekday,start_minute,end_minute,action,target_id,job,priority) VALUES (:id,:weekday,:start,:end,:action,:target,:job,:priority)');
            foreach ($body['schedule'] as $entry) if (is_array($entry) && in_array((string)($entry['action'] ?? ''), TOWN_NPC_ACTIONS, true)) $insert->execute([':id' => $id, ':weekday' => max(0, min(6, (int)($entry['weekday'] ?? 0))), ':start' => max(0, min(1439, (int)($entry['start_minute'] ?? 0))), ':end' => max(0, min(1440, (int)($entry['end_minute'] ?? 1440))), ':action' => (string)$entry['action'], ':target' => (string)($entry['target_id'] ?? ''), ':job' => (string)($entry['job'] ?? ''), ':priority' => max(0, min(255, (int)($entry['priority'] ?? 0)))]);
        }
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
