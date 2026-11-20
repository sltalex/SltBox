<?php
declare(strict_types=1);
if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__ . '/town_npcs.php';

/** Public resident inspection uses the same account gate as the town itself. */
function town_resident_require(PDO $pdo, string $userId, string $npcId): array
{
    shared_town_require_access($pdo, $userId);
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $npcId)) throw new ApiError('invalid_npc', '村民 ID 无效。', 400);
    $q = $pdo->prepare('SELECT npc_id,name,avatar_asset_id FROM town_npcs WHERE npc_id=:npc AND world_id=:world AND enabled=1 LIMIT 1');
    $q->execute([':npc' => $npcId, ':world' => SHARED_TOWN_WORLD_ID]);
    $npc = $q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($npc)) throw new ApiError('npc_not_found', '村民不存在或已停用。', 404);
    return $npc;
}

/** Explicit projection: never return Agent context, private memories or raw plans. */
function town_resident_detail(PDO $pdo, string $userId, string $npcId): array
{
    town_resident_require($pdo, $userId, $npcId);
    $q = $pdo->prepare('SELECT n.*,p.personality_json,p.family_background,p.short_term_goal,p.long_term_goal,p.speech_style,p.values_json,p.fears_json,'
        . 'r.tile_x,r.tile_y,' . town_npc_runtime_height_select($pdo) . ',r.state AS runtime_state,r.current_task_id,r.dialogue,r.mood_json,r.capabilities_json,r.state_version,r.updated_at AS runtime_updated_at '
        . 'FROM town_npcs n LEFT JOIN town_npc_profiles p ON p.npc_id=n.npc_id LEFT JOIN town_npc_runtime_states r ON r.npc_id=n.npc_id WHERE n.npc_id=:npc AND n.world_id=:world AND n.enabled=1');
    $q->execute([':npc' => $npcId, ':world' => SHARED_TOWN_WORLD_ID]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new ApiError('npc_not_found', '村民已离开小镇，请刷新。', 404);
    $npc = town_npc_attach_inventory($pdo, array_map('town_npc_public', town_npc_attach_stats($pdo, town_npc_attach_life_data($pdo, [$row]))))[0];
    $resident = array_intersect_key($npc, array_flip(['npc_id', 'world_id', 'name', 'avatar_asset_id', 'appearance', 'occupation', 'agent_paused', 'profile', 'lifecycle']));
    $resident['runtime'] = array_intersect_key($npc['runtime'], array_flip(['tile_x', 'tile_y', 'height', 'state', 'current_task_id', 'dialogue', 'mood', 'capabilities', 'state_version', 'updated_at', 'inventory']));
    $resident['stats'] = $npc['stats'] ?? town_npc_stat_public(town_npc_stat_defaults());
    if (town_npc_life_column_exists($pdo, 'town_npc_runtime_states', 'facing')) {
        $q = $pdo->prepare('SELECT facing FROM town_npc_runtime_states WHERE npc_id=:npc'); $q->execute([':npc' => $npcId]);
        $resident['runtime']['facing'] = (string)($q->fetchColumn() ?: 'down');
    }
    $resident['home'] = is_array($npc['home']) ? array_intersect_key($npc['home'], array_flip(['home_asset_id', 'home_asset_name', 'tile_x', 'tile_y', 'interaction', 'updated_at'])) : null;
    $resident['inventory'] = $npc['runtime']['inventory'] ?? [];
    $resident['balance'] = (int)($npc['economy']['balance'] ?? 0);
    $q = $pdo->prepare('SELECT weekday,start_minute,end_minute,action,target_id,job,priority FROM town_npc_schedules WHERE npc_id=:npc ORDER BY weekday,start_minute,schedule_id LIMIT 168');
    $q->execute([':npc' => $npcId]); $resident['schedule'] = $q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($resident['schedule'] as &$slot) foreach (['weekday', 'start_minute', 'end_minute', 'priority'] as $key) $slot[$key] = (int)$slot[$key];
    unset($slot);
    $resident['task'] = null;
    $taskId = $npc['runtime']['current_task_id'] ?? null;
    if (is_string($taskId) && $taskId !== '') {
        $q = $pdo->prepare('SELECT task_id,action,target_id,status,scheduled_at,started_at,failure_reason,payload_json FROM town_npc_tasks WHERE task_id=:task AND npc_id=:npc LIMIT 1');
        $q->execute([':task' => $taskId, ':npc' => $npcId]); $task = $q->fetch(PDO::FETCH_ASSOC);
        if (is_array($task)) {
            $payload = town_npc_decode($task['payload_json'], []);
            if (!is_array($payload)) $payload = [];
            unset($task['payload_json']);
            // Do not expose the target player's account ID.  The client only
            // needs to know whether the current account owns this invitation
            // so it can show the matching cancel action without allowing one
            // player to end another player's follow/talk request.
            $task['player_invitation'] = in_array((string)($task['action'] ?? ''), ['follow', 'player_talk'], true);
            $task['player_invitation_owned'] = $task['player_invitation']
                && hash_equals((string)($payload['target_player_user_id'] ?? ''), $userId);
            $task['navigation_version'] = (int)($payload['navigation_version'] ?? 0);
            $task['route_cursor'] = max(0, (int)($payload['route_cursor'] ?? 0));
            $task['route_length'] = is_array($payload['route'] ?? null) ? count($payload['route']) : 0;
            $resident['task'] = $task;
        }
    }
    return ['resident' => $resident, 'navigation_version' => town_navigation_version($pdo)];
}

/** Older-first display within a newest-first keyset page of confirmed dialogue. */
function town_resident_conversations(PDO $pdo, string $userId, string $npcId, int $cursor = 0, int $limit = 30): array
{
    town_resident_require($pdo, $userId, $npcId);
    if ($cursor < 0) throw new ApiError('invalid_cursor', '对话游标无效。', 400);
    if ($limit < 1 || $limit > 100) throw new ApiError('invalid_limit', '对话页大小必须为 1～100。', 400);
    $json = "CASE WHEN JSON_VALID(e.payload_json) THEN e.payload_json ELSE '{}' END";
    $other = "JSON_EXTRACT($json,'$.other_npc_id')";
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $other = "JSON_UNQUOTE($other)";
    $where = "((e.event_type='conversation' AND (e.npc_id=:speaker OR $other=:listener)) OR (e.event_type='player_conversation' AND e.npc_id=:photo_npc))";
    $params = [':speaker' => $npcId, ':listener' => $npcId, ':photo_npc' => $npcId];
    if ($cursor > 0) { $where .= ' AND e.event_id<:cursor'; $params[':cursor'] = $cursor; }
    $q = $pdo->prepare('SELECT e.event_id,e.npc_id,e.event_type,e.payload_json,e.created_at,s.name AS speaker_name,s.avatar_asset_id AS speaker_avatar,t.name AS listener_name,t.avatar_asset_id AS listener_avatar FROM town_npc_events e LEFT JOIN town_npcs s ON s.npc_id=e.npc_id LEFT JOIN town_npcs t ON t.npc_id=' . $other . ' WHERE ' . $where . ' ORDER BY e.event_id DESC LIMIT ' . ($limit + 1));
    $q->execute($params); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > $limit;
    $conversations = []; $nextCursor = $cursor;
    foreach (array_slice($rows, 0, $limit) as $row) {
        $nextCursor = (int)$row['event_id'];
        $payload = town_npc_decode($row['payload_json'], []);
        if (!is_array($payload)) continue;
        if ((string)($row['event_type'] ?? '') === 'player_conversation') {
            $reply = town_npc_moderate_dialogue((string)($payload['dialogue'] ?? ''));
            $fact = trim((string)($payload['fact_summary'] ?? ''));
            $playerText = trim((string)($payload['extracted_text'] ?? ''));
            if (!$reply['allowed'] || $reply['text'] === '') continue;
            $conversations[] = [
                'event_id' => (int)$row['event_id'],
                'created_at' => (string)$row['created_at'],
                'speaker' => ['npc_id' => 'player', 'name' => '我', 'avatar_asset_id' => ''],
                'listener' => ['npc_id' => $npcId, 'name' => (string)($row['speaker_name'] ?? '村民'), 'avatar_asset_id' => (string)($row['speaker_avatar'] ?? '')],
                'text' => $reply['text'],
                'player_text' => mb_substr($playerText, 0, 240, 'UTF-8'),
                'source' => is_array($payload['source'] ?? null) ? [
                    'type' => 'photo',
                    'mode' => substr((string)($payload['source']['mode'] ?? ''), 0, 32),
                    'fact_summary' => mb_substr($fact, 0, 240, 'UTF-8'),
                ] : null,
                'approach_tile' => $tile($payload['tile'] ?? null),
                'target_tile' => $tile($payload['tile'] ?? null),
                'navigation_version' => max(0, (int)($payload['navigation_version'] ?? 0)),
            ];
            continue;
        }
        $target = $payload['other_npc_id'] ?? null;
        if (!is_string($target) || !preg_match('/\A[a-f0-9]{32}\z/D', $target) || !is_string($payload['dialogue'] ?? null)) continue;
        $dialogue = town_npc_moderate_dialogue($payload['dialogue']);
        if (!$dialogue['allowed'] || $dialogue['text'] === '') continue;
        $tile = static function (mixed $value): ?array {
            if (!is_array($value)) return null;
            $x = filter_var($value['tile_x'] ?? null, FILTER_VALIDATE_INT); $y = filter_var($value['tile_y'] ?? null, FILTER_VALIDATE_INT);
            return $x !== false && $y !== false && $x >= 0 && $y >= 0 && $x < SHARED_TOWN_MAP_SIZE && $y < SHARED_TOWN_MAP_SIZE ? ['tile_x' => $x, 'tile_y' => $y] : null;
        };
        $conversations[] = ['event_id' => (int)$row['event_id'], 'created_at' => (string)$row['created_at'],
            'speaker' => ['npc_id' => (string)$row['npc_id'], 'name' => (string)($row['speaker_name'] ?? '离开小镇的村民'), 'avatar_asset_id' => (string)($row['speaker_avatar'] ?? '')],
            'listener' => ['npc_id' => $target, 'name' => (string)($row['listener_name'] ?? '离开小镇的村民'), 'avatar_asset_id' => (string)($row['listener_avatar'] ?? '')],
            'text' => $dialogue['text'], 'approach_tile' => $tile($payload['approach_tile'] ?? null), 'target_tile' => $tile($payload['target_tile'] ?? null), 'navigation_version' => max(0, (int)($payload['navigation_version'] ?? 0))];
    }
    return ['npc_id' => $npcId, 'conversations' => array_reverse($conversations), 'cursor' => $nextCursor, 'has_more' => $hasMore];
}
