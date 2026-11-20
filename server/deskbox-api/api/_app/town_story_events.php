<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__ . '/town_npcs.php';

function town_story_event_id(): string { return bin2hex(random_bytes(16)); }

/**
 * api_body() deliberately preserves nested JSON objects as stdClass values.
 * Story drafts come from the Worker as nested objects, so normalize the wire
 * envelope once at the story boundary before applying the server-owned allowlist.
 */
function town_story_event_normalize_value(mixed $value): mixed
{
    if ($value instanceof stdClass) $value = get_object_vars($value);
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) $normalized[$key] = town_story_event_normalize_value($item);
        return $normalized;
    }
    return $value;
}

function town_story_event_policy(PDO $pdo): array
{
    $defaults = ['daily_min_events' => 0, 'daily_max_events' => 2, 'condition_enabled' => true, 'cooldown_minutes' => 240, 'max_participants' => 5, 'enabled' => true];
    try {
        $q = $pdo->prepare('SELECT daily_min_events,daily_max_events,condition_enabled,cooldown_minutes,max_participants,enabled FROM town_story_event_policies WHERE world_id=:world LIMIT 1');
        $q->execute([':world' => SHARED_TOWN_WORLD_ID]); $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return $defaults;
        return ['daily_min_events' => (int)$row['daily_min_events'], 'daily_max_events' => (int)$row['daily_max_events'], 'condition_enabled' => (bool)$row['condition_enabled'], 'cooldown_minutes' => (int)$row['cooldown_minutes'], 'max_participants' => (int)$row['max_participants'], 'enabled' => (bool)$row['enabled']];
    } catch (Throwable $error) { return $defaults; }
}

function town_story_event_policy_save(PDO $pdo, array $input, DateTimeImmutable $now): array
{
    if (!town_npc_life_table_exists($pdo, 'town_story_event_policies')) throw new ApiError('story_migration_required', '故事事件迁移尚未执行。', 503);
    $values = ['daily_min_events' => max(0, min(10, (int)($input['daily_min_events'] ?? 0))), 'daily_max_events' => max(0, min(10, (int)($input['daily_max_events'] ?? 2))), 'condition_enabled' => !empty($input['condition_enabled']), 'cooldown_minutes' => max(5, min(10080, (int)($input['cooldown_minutes'] ?? 240))), 'max_participants' => max(2, min(10, (int)($input['max_participants'] ?? 5))), 'enabled' => !empty($input['enabled'])];
    if ($values['daily_max_events'] < $values['daily_min_events']) throw new ApiError('invalid_story_policy', '每日最大事件数不能小于最小事件数。', 400);
    $stamp = auth_sql_time($now); $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $q = $pdo->prepare('INSERT INTO town_story_event_policies (world_id,daily_min_events,daily_max_events,condition_enabled,cooldown_minutes,max_participants,enabled,updated_at) VALUES (:world,:min,:max,:condition,:cooldown,:participants,:enabled,:updated) ON CONFLICT(world_id) DO UPDATE SET daily_min_events=excluded.daily_min_events,daily_max_events=excluded.daily_max_events,condition_enabled=excluded.condition_enabled,cooldown_minutes=excluded.cooldown_minutes,max_participants=excluded.max_participants,enabled=excluded.enabled,updated_at=excluded.updated_at');
    } else {
        $q = $pdo->prepare('INSERT INTO town_story_event_policies (world_id,daily_min_events,daily_max_events,condition_enabled,cooldown_minutes,max_participants,enabled,updated_at) VALUES (:world,:min,:max,:condition,:cooldown,:participants,:enabled,:updated) ON DUPLICATE KEY UPDATE daily_min_events=VALUES(daily_min_events),daily_max_events=VALUES(daily_max_events),condition_enabled=VALUES(condition_enabled),cooldown_minutes=VALUES(cooldown_minutes),max_participants=VALUES(max_participants),enabled=VALUES(enabled),updated_at=VALUES(updated_at)');
    }
    $q->execute([':world' => SHARED_TOWN_WORLD_ID, ':min' => $values['daily_min_events'], ':max' => $values['daily_max_events'], ':condition' => $values['condition_enabled'] ? 1 : 0, ':cooldown' => $values['cooldown_minutes'], ':participants' => $values['max_participants'], ':enabled' => $values['enabled'] ? 1 : 0, ':updated' => $stamp]);
    return town_story_event_policy($pdo);
}

/** Return generation and settlement state without spending model tokens. */
function town_story_event_policy_state(PDO $pdo, DateTimeImmutable $now): array
{
    $policy = town_story_event_policy($pdo);
    $dayStart = $now->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    $daily = 0; $lastAt = null;
    try {
        $q = $pdo->prepare('SELECT COUNT(*),MAX(occurred_at) FROM town_story_events WHERE world_id=:world AND occurred_at>=:day_start');
        $q->execute([':world' => SHARED_TOWN_WORLD_ID, ':day_start' => $dayStart]);
        $row = $q->fetch(PDO::FETCH_NUM); $daily = (int)($row[0] ?? 0); $lastAt = is_string($row[1] ?? null) ? $row[1] : null;
    } catch (Throwable $error) { /* migration may still be rolling out */ }
    $nextAllowed = null;
    if ($lastAt !== null && $lastAt !== '') {
        try { $nextAllowed = auth_parse_sql_time($lastAt)->modify('+' . max(0, (int)$policy['cooldown_minutes']) . ' minutes'); }
        catch (Throwable $error) { $nextAllowed = null; }
    }
    $cooldown = $nextAllowed instanceof DateTimeImmutable && $nextAllowed > $now;
    return [
        'policy' => $policy,
        'daily_count' => $daily,
        'minimum_remaining' => max(0, (int)$policy['daily_min_events'] - $daily),
        'maximum_remaining' => max(0, (int)$policy['daily_max_events'] - $daily),
        'cooldown_until' => $nextAllowed?->format('Y-m-d H:i:s'),
        'can_generate' => (bool)$policy['enabled'] && $daily < (int)$policy['daily_max_events'] && !$cooldown,
    ];
}

function town_story_event_public(array $row, array $participants = [], array $effects = []): array
{
    $safeEffects = [];
    foreach ($effects as $effect) {
        if (!is_array($effect)) continue;
        $safeEffects[] = ['npc_id' => (string)($effect['npc_id'] ?? ''), 'stat_delta' => is_array($effect['stat_delta'] ?? null) ? $effect['stat_delta'] : [], 'relationship_delta' => is_array($effect['relationship_delta'] ?? null) ? $effect['relationship_delta'] : [], 'applied' => !empty($effect['applied'])];
    }
    return ['event_id' => (string)($row['event_id'] ?? ''), 'world_id' => (string)($row['world_id'] ?? ''), 'template_key' => (string)($row['template_key'] ?? ''), 'status' => (string)($row['status'] ?? 'scheduled'), 'title' => (string)($row['title'] ?? ''), 'summary' => (string)($row['summary'] ?? ''), 'story' => json_decode((string)($row['story_json'] ?? '{}'), true) ?: [], 'generation_source' => (string)($row['generation_source'] ?? 'rule'), 'model_run_id' => ($row['model_run_id'] ?? null) !== null ? (string)$row['model_run_id'] : null, 'starts_at' => (string)($row['starts_at'] ?? ''), 'expires_at' => ($row['expires_at'] ?? null) !== null ? (string)$row['expires_at'] : null, 'occurred_at' => (string)($row['occurred_at'] ?? ''), 'resolved_at' => ($row['resolved_at'] ?? null) !== null ? (string)$row['resolved_at'] : null, 'participants' => $participants, 'effects' => $safeEffects];
}

function town_story_event_templates(): array
{
    return [
        ['key' => 'market_supply_shortage', 'title' => '集市突然缺少生活用品', 'summary' => '副食品店的货架空了一半，几位村民开始寻找解决办法。', 'roles' => ['merchant', 'helper'], 'deltas' => [['stress' => 3, 'social' => 2], ['labor' => 2, 'mood' => 3]], 'condition' => 'always'],
        ['key' => 'rainy_day_shelter', 'title' => '雨天里的临时避雨处', 'summary' => '一场突然的雨让村民们聚到屋檐下，大家决定一起修整避雨处。', 'roles' => ['builder', 'helper'], 'deltas' => [['stress' => -3, 'social' => 3], ['labor' => 2, 'mood' => 2]], 'condition' => 'rain'],
        ['key' => 'evening_story_circle', 'title' => '广场上的故事会', 'summary' => '夜色降临，广场上有人讲起旧日的故事，围观者开始分享自己的经历。', 'roles' => ['speaker', 'listener'], 'deltas' => [['culture' => 3, 'social' => 2, 'mood' => 3], ['culture' => 2, 'social' => 3, 'mood' => 2]], 'condition' => 'always'],
    ];
}

/**
 * Accept only a small, server-owned event envelope from a model.  The model
 * may write the story text and suggest participants/effects, but it cannot
 * create arbitrary NPCs, change coordinates, or execute an action directly.
 */
function town_story_event_sanitize_draft(PDO $pdo, array $draft, array $policy): ?array
{
    $title = trim((string)($draft['title'] ?? ''));
    $summary = trim((string)($draft['summary'] ?? ''));
    if ($title === '' || $summary === '' || mb_strlen($title, 'UTF-8') > 160 || mb_strlen($summary, 'UTF-8') > 1000) return null;
    $story = is_array($draft['story'] ?? null) ? $draft['story'] : [];
    $story['opening'] = mb_substr(trim((string)($story['opening'] ?? $summary)), 0, 1000, 'UTF-8');
    $beats = is_array($story['beats'] ?? null) ? $story['beats'] : [];
    $story['beats'] = array_values(array_map(static fn($beat): string => mb_substr(trim((string)$beat), 0, 300, 'UTF-8'), array_slice($beats, 0, 6)));
    $story['location'] = mb_substr(trim((string)($story['location'] ?? '')), 0, 128, 'UTF-8');
    $query = $pdo->query("SELECT npc_id,name,occupation FROM town_npcs WHERE world_id=" . $pdo->quote(SHARED_TOWN_WORLD_ID) . " AND enabled=1 AND agent_paused=0 ORDER BY npc_id");
    $available = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $available[(string)$row['npc_id']] = $row;
    $participants = [];
    $effects = [];
    $rawParticipants = is_array($draft['participants'] ?? null) ? $draft['participants'] : [];
    foreach ($rawParticipants as $candidate) {
        if (!is_array($candidate)) continue;
        $npcId = trim((string)($candidate['npc_id'] ?? ''));
        if (!isset($available[$npcId]) || isset($participants[$npcId])) continue;
        $role = mb_substr(trim((string)($candidate['role'] ?? 'participant')), 0, 64, 'UTF-8');
        $participants[$npcId] = ['npc_id' => $npcId, 'name' => (string)$available[$npcId]['name'], 'role' => $role === '' ? 'participant' : $role, 'status' => 'joined'];
        if (count($participants) >= max(2, min(10, (int)$policy['max_participants']))) break;
    }
    if (count($participants) < 2) return null;
    $rawEffects = is_array($draft['effects'] ?? null) ? $draft['effects'] : [];
    foreach ($rawEffects as $effect) {
        if (!is_array($effect)) continue;
        $npcId = trim((string)($effect['npc_id'] ?? ''));
        if (!isset($participants[$npcId])) continue;
        $delta = [];
        $rawDelta = is_array($effect['stat_delta'] ?? null) ? $effect['stat_delta'] : [];
        foreach (TOWN_NPC_STAT_KEYS as $key) if (array_key_exists($key, $rawDelta) && is_numeric($rawDelta[$key])) $delta[$key] = max(-15, min(15, (int)$rawDelta[$key]));
        $relationship = [];
        $rawRelationship = is_array($effect['relationship_delta'] ?? null) ? $effect['relationship_delta'] : [];
        $relationshipTarget = trim((string)($rawRelationship['target_npc_id'] ?? ''));
        if (isset($participants[$relationshipTarget]) && $relationshipTarget !== $npcId) {
            $relationship['target_npc_id'] = $relationshipTarget;
            $relationship['affinity'] = max(-10, min(10, (int)($rawRelationship['affinity'] ?? 0)));
        }
        $memory = is_array($effect['memory'] ?? null) ? $effect['memory'] : [];
        $memory = ['content' => mb_substr(trim((string)($memory['content'] ?? ($title . '：' . $summary))), 0, 500, 'UTF-8'), 'importance' => max(1, min(5, (int)($memory['importance'] ?? 3)))];
        $task = is_array($effect['task'] ?? null) ? $effect['task'] : [];
        $taskAction = strtolower(trim((string)($task['action'] ?? '')));
        if (!in_array($taskAction, ['walk', 'work', 'rest', 'talk', 'trade'], true)) $task = [];
        else {
            $taskTargetNpc = trim((string)($task['target_npc_id'] ?? ''));
            $knownTarget = town_story_event_normalize_value($task['known_target'] ?? []);
            if (!is_array($knownTarget)) $knownTarget = [];
            $knownX = filter_var($knownTarget['tile_x'] ?? $knownTarget['x'] ?? null, FILTER_VALIDATE_INT);
            $knownY = filter_var($knownTarget['tile_y'] ?? $knownTarget['y'] ?? null, FILTER_VALIDATE_INT);
            $knownTarget = ($knownX !== false && $knownY !== false) ? ['tile_x' => (int)$knownX, 'tile_y' => (int)$knownY] : [];
            $task = ['action' => $taskAction, 'target_id' => mb_substr(trim((string)($task['target_id'] ?? '')), 0, 128, 'UTF-8'), 'job' => mb_substr(trim((string)($task['job'] ?? '')), 0, 64, 'UTF-8')];
            if (preg_match('/\A[a-f0-9]{32}\z/D', $taskTargetNpc) && isset($participants[$taskTargetNpc])) $task['target_npc_id'] = $taskTargetNpc;
            if ($knownTarget !== []) $task['known_target'] = $knownTarget;
            $duration = filter_var($task['duration_minutes'] ?? null, FILTER_VALIDATE_INT);
            if ($duration !== false) $task['duration_minutes'] = max(0, min(1440, (int)$duration));
            $dialogue = trim((string)($task['dialogue'] ?? ''));
            if ($dialogue !== '') $task['dialogue'] = mb_substr($dialogue, 0, 500, 'UTF-8');
        }
        $effects[$npcId] = ['npc_id' => $npcId, 'stat_delta' => $delta, 'relationship_delta' => $relationship, 'memory' => $memory, 'task' => $task];
    }
    foreach ($participants as $npcId => $_) if (!isset($effects[$npcId])) $effects[$npcId] = ['npc_id' => $npcId, 'stat_delta' => [], 'relationship_delta' => [], 'memory' => ['content' => $title . '：' . $summary, 'importance' => 3], 'task' => []];
    return ['title' => $title, 'summary' => $summary, 'story' => $story, 'participants' => array_values($participants), 'effects' => array_values($effects), 'template_key' => mb_substr(trim((string)($draft['template_key'] ?? 'ai_story')), 0, 64, 'UTF-8') ?: 'ai_story'];
}

function town_story_event_generate(PDO $pdo, DateTimeImmutable $now, ?int $seed = null, ?array $draft = null, ?string $modelRunId = null): array
{
    if (!town_npc_life_table_exists($pdo, 'town_story_events') || !town_npc_life_table_exists($pdo, 'town_story_event_participants')) throw new ApiError('story_migration_required', '故事事件迁移尚未执行。', 503);
    $policy = town_story_event_policy($pdo); if (!$policy['enabled']) throw new ApiError('story_events_disabled', '故事事件当前已停用。', 409);
    $dayStart = $now->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    $daily = $pdo->prepare('SELECT COUNT(*) FROM town_story_events WHERE world_id=:world AND occurred_at>=:day_start'); $daily->execute([':world' => SHARED_TOWN_WORLD_ID, ':day_start' => $dayStart]);
    if ((int)$daily->fetchColumn() >= $policy['daily_max_events']) throw new ApiError('story_daily_limit', '今日故事事件已达到上限。', 409);
    $last = $pdo->prepare('SELECT occurred_at FROM town_story_events WHERE world_id=:world ORDER BY occurred_at DESC LIMIT 1'); $last->execute([':world' => SHARED_TOWN_WORLD_ID]); $lastAt = $last->fetchColumn();
    if (is_string($lastAt) && $lastAt !== '' && strtotime($lastAt) !== false && (time() - strtotime($lastAt)) < ($policy['cooldown_minutes'] * 60)) throw new ApiError('story_cooldown', '故事事件仍在冷却时间内。', 409);
    $seed ??= random_int(1, PHP_INT_MAX); $templates = town_story_event_templates();
    if ($policy['condition_enabled']) {
        try {
            $clock = function_exists('town_world_clock_read') ? town_world_clock_read($pdo, $now) : [];
            $weather = (string)($clock['weather_code'] ?? 'clear');
            $templates = array_values(array_filter($templates, static fn(array $item): bool => ($item['condition'] ?? 'always') !== 'rain' || $weather === 'rain')) ?: $templates;
        } catch (Throwable $error) { /* use all templates while clock tables roll out */ }
    }
    $template = $templates[$seed % count($templates)];
    $q = $pdo->query("SELECT n.npc_id,n.name,n.occupation FROM town_npcs n WHERE n.world_id=" . $pdo->quote(SHARED_TOWN_WORLD_ID) . " AND n.enabled=1 AND n.agent_paused=0 ORDER BY n.npc_id");
    $npcs = $q->fetchAll(PDO::FETCH_ASSOC); if (count($npcs) < 2) throw new ApiError('story_not_enough_npcs', '至少需要两个启用的村民才能生成故事事件。', 409);
    usort($npcs, static function (array $a, array $b) use ($seed): int { return strcmp(hash('sha256', $seed . ':' . $a['npc_id']), hash('sha256', $seed . ':' . $b['npc_id'])); });
    $selected = array_slice($npcs, 0, min($policy['max_participants'], count($npcs)));
    $source = 'rule'; $modelStory = null;
    if (is_array($draft)) {
        $modelStory = town_story_event_sanitize_draft($pdo, $draft, $policy);
        if (is_array($modelStory)) $source = 'model';
    }
    if (is_array($modelStory)) {
        $byId = []; foreach ($npcs as $npc) $byId[(string)$npc['npc_id']] = $npc;
        $modelSelected = []; foreach ($modelStory['participants'] as $participant) if (isset($byId[$participant['npc_id']])) $modelSelected[] = $byId[$participant['npc_id']];
        if (count($modelSelected) >= 2) $selected = array_slice($modelSelected, 0, $policy['max_participants']);
    }
    $eventId = town_story_event_id(); $stamp = auth_sql_time($now);
    if (is_array($modelStory)) {
        $story = $modelStory['story'] + ['seed' => $seed, 'stage' => 'opening']; $title = $modelStory['title']; $summary = $modelStory['summary']; $templateKey = $modelStory['template_key'];
    } else {
        $story = ['seed' => $seed, 'template_key' => $template['key'], 'stage' => 'opening', 'roles' => $template['roles']]; $title = $template['title']; $summary = $template['summary']; $templateKey = $template['key'];
    }
    $insert = $pdo->prepare('INSERT INTO town_story_events (event_id,world_id,template_key,status,title,summary,story_json,random_seed,generation_source,model_run_id,starts_at,expires_at,occurred_at,created_at) VALUES (:id,:world,:template,\'active\',:title,:summary,:story,:seed,:source,:run,:starts,:expires,:occurred,:created)');
    $insert->execute([':id' => $eventId, ':world' => SHARED_TOWN_WORLD_ID, ':template' => $templateKey, ':title' => $title, ':summary' => $summary, ':story' => json_encode($story, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':seed' => $seed, ':source' => $source, ':run' => $modelRunId, ':starts' => $stamp, ':expires' => auth_sql_time($now->modify('+1 day')), ':occurred' => $stamp, ':created' => $stamp]);
    $participants = []; $effects = [];
    foreach ($selected as $index => $npc) {
        $npcId = (string)$npc['npc_id']; $role = (string)($template['roles'][$index] ?? 'participant');
        if (is_array($modelStory)) {
            $draftParticipant = array_values(array_filter($modelStory['participants'], static fn(array $row): bool => $row['npc_id'] === $npcId))[0] ?? null;
            if (is_array($draftParticipant)) $role = (string)$draftParticipant['role'];
        }
        $participantInsert = $pdo->prepare('INSERT INTO town_story_event_participants (event_id,npc_id,role,status,eligibility_json,outcome_json) VALUES (:event,:npc,:role,\'joined\',:eligibility,:outcome)');
        $participantInsert->execute([':event' => $eventId, ':npc' => $npcId, ':role' => $role, ':eligibility' => json_encode(['occupation' => $npc['occupation'], 'reason' => 'enabled_npc'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':outcome' => '{}']);
        $draftEffect = is_array($modelStory) ? (array_values(array_filter($modelStory['effects'], static fn(array $row): bool => $row['npc_id'] === $npcId))[0] ?? null) : null;
        $delta = is_array($draftEffect) ? ($draftEffect['stat_delta'] ?? []) : ($template['deltas'][$index] ?? []);
        $relationship = is_array($draftEffect) ? ($draftEffect['relationship_delta'] ?? []) : [];
        $memory = is_array($draftEffect) ? ($draftEffect['memory'] ?? []) : ['content' => $title . '：' . $summary, 'importance' => 3];
        $task = is_array($draftEffect) ? ($draftEffect['task'] ?? []) : [];
        // Rule events always produce at least one durable participant task.
        // The second participant is left as an observation participant; this
        // avoids two NPCs racing to reserve the same face-to-face interaction.
        if (!is_array($modelStory) && $index === 0 && count($selected) > 1) {
            $task = ['action' => 'talk', 'target_npc_id' => (string)$selected[1]['npc_id'], 'known_target' => [], 'duration_minutes' => 5, 'dialogue' => $summary];
        }
        $task = town_story_event_normalize_value($task); if (!is_array($task)) $task = [];
        $effectInsert = $pdo->prepare('INSERT INTO town_story_event_effects (event_id,npc_id,stat_delta_json,relationship_delta_json,memory_json,task_payload_json) VALUES (:event,:npc,:delta,:relationship,:memory,:task)');
        $effectInsert->execute([':event' => $eventId, ':npc' => $npcId, ':delta' => json_encode($delta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':relationship' => json_encode($relationship, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':memory' => json_encode($memory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':task' => json_encode($task, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        if ($task !== [] && function_exists('town_npc_apply_action')) {
            try {
                $taskResult = town_npc_apply_action($pdo, ['npc_id' => $npcId, 'action' => $task['action'], 'payload' => $task], $now, true, false, true);
                $assignedTaskId = (string)($taskResult['task_id'] ?? '');
                $pdo->prepare("UPDATE town_story_event_participants SET status='executing',task_id=:task,joined_at=:joined WHERE event_id=:event AND npc_id=:npc")->execute([':task' => $assignedTaskId !== '' ? $assignedTaskId : null, ':joined' => auth_sql_time($now), ':event' => $eventId, ':npc' => $npcId]);
            } catch (Throwable $taskError) {
                $pdo->prepare("UPDATE town_story_event_participants SET status='blocked',outcome_json=:outcome WHERE event_id=:event AND npc_id=:npc")->execute([':outcome' => json_encode(['reason' => mb_substr($taskError->getMessage(), 0, 240, 'UTF-8')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':event' => $eventId, ':npc' => $npcId]);
            }
        }
        $participants[] = ['npc_id' => $npcId, 'name' => (string)$npc['name'], 'role' => $role, 'status' => 'joined']; $effects[] = ['npc_id' => $npcId, 'stat_delta' => $delta, 'relationship_delta' => $relationship, 'task' => $task];
    }
    $public = town_world_event_append($pdo, ['source_type' => 'story_event', 'source_id' => $eventId, 'source_revision' => '1', 'event_type' => 'story_event_started', 'actor_npc_id' => (string)$selected[0]['npc_id'], 'target_npc_id' => (string)$selected[1]['npc_id'], 'public_payload' => ['story_event_id' => $eventId, 'title' => $title, 'summary' => $summary, 'participants' => $participants]], $now);
    return ['event' => town_story_event_public(['event_id' => $eventId, 'world_id' => SHARED_TOWN_WORLD_ID, 'template_key' => $templateKey, 'status' => 'active', 'title' => $title, 'summary' => $summary, 'story_json' => json_encode($story), 'generation_source' => $source, 'starts_at' => $stamp, 'expires_at' => auth_sql_time($now->modify('+1 day')), 'occurred_at' => $stamp, 'resolved_at' => null], $participants, $effects), 'public_event' => $public];
}

/**
 * Read active stories so a replacement Worker can recover its in-memory
 * scheduler.  A story is due when all participant tasks are terminal, when a
 * taskless event has had a short opening window, or when its expiry is reached.
 */
function town_story_events_active(PDO $pdo, DateTimeImmutable $now, int $limit = 50): array
{
    if (!town_npc_life_table_exists($pdo, 'town_story_events')) return [];
    $limit = max(1, min(100, $limit)); $q = $pdo->query('SELECT event_id,starts_at,expires_at FROM town_story_events WHERE world_id=' . $pdo->quote(SHARED_TOWN_WORLD_ID) . " AND status='active' ORDER BY occurred_at LIMIT {$limit}");
    $active = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $eventId = (string)$row['event_id']; $p = $pdo->prepare('SELECT p.status,p.task_id,t.status AS task_status FROM town_story_event_participants p LEFT JOIN town_npc_tasks t ON t.task_id=p.task_id WHERE p.event_id=:event'); $p->execute([':event' => $eventId]);
        $participants = $p->fetchAll(PDO::FETCH_ASSOC); $hasTask = false; $allTerminal = true; $failed = false;
        foreach ($participants as $participant) {
            $taskStatus = (string)($participant['task_status'] ?? ''); $status = (string)($participant['status'] ?? '');
            if ($participant['task_id'] !== null && $participant['task_id'] !== '') $hasTask = true;
            if (!in_array($taskStatus, ['completed', 'failed', 'cancelled'], true) && !in_array($status, ['completed', 'blocked'], true)) $allTerminal = false;
            if (in_array($taskStatus, ['failed', 'cancelled'], true) || $status === 'blocked') $failed = true;
        }
        $expires = null; try { $expires = $row['expires_at'] ? auth_parse_sql_time((string)$row['expires_at']) : null; } catch (Throwable $error) { }
        $starts = null; try { $starts = auth_parse_sql_time((string)$row['starts_at']); } catch (Throwable $error) { }
        $expired = $expires instanceof DateTimeImmutable && $expires <= $now;
        $tasklessReady = !$hasTask && $starts instanceof DateTimeImmutable && $starts->modify('+120 seconds') <= $now;
        $due = $expired || $allTerminal || $tasklessReady;
        $active[] = ['event_id' => $eventId, 'due' => $due, 'due_at' => $expired ? $expires?->format('Y-m-d H:i:s') : ($tasklessReady ? $starts?->modify('+120 seconds')->format('Y-m-d H:i:s') : null), 'outcome' => $failed || $expired ? ($allTerminal && !$failed ? 'success' : 'partial') : 'success'];
    }
    return $active;
}

function town_story_event_settlement(PDO $pdo, string $eventId, DateTimeImmutable $now): array
{
    $q = $pdo->prepare('SELECT starts_at,expires_at FROM town_story_events WHERE event_id=:event LIMIT 1'); $q->execute([':event' => $eventId]); $event = $q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($event)) throw new ApiError('story_event_not_found', '故事事件不存在。', 404);
    $p = $pdo->prepare('SELECT p.npc_id,p.status,p.task_id,t.status AS task_status FROM town_story_event_participants p LEFT JOIN town_npc_tasks t ON t.task_id=p.task_id WHERE p.event_id=:event'); $p->execute([':event' => $eventId]);
    $participants = $p->fetchAll(PDO::FETCH_ASSOC); $hasTask = false; $allTerminal = true; $failed = false; $statusByNpc = [];
    foreach ($participants as $participant) {
        $npcId = (string)$participant['npc_id']; $taskStatus = (string)($participant['task_status'] ?? ''); $status = (string)($participant['status'] ?? '');
        $statusByNpc[$npcId] = ['status' => $status, 'task_status' => $taskStatus];
        if ($participant['task_id'] !== null && $participant['task_id'] !== '') $hasTask = true;
        if (!in_array($taskStatus, ['completed', 'failed', 'cancelled'], true) && !in_array($status, ['completed', 'blocked'], true)) $allTerminal = false;
        if (in_array($taskStatus, ['failed', 'cancelled'], true) || $status === 'blocked') $failed = true;
    }
    $starts = null; $expires = null;
    try { $starts = auth_parse_sql_time((string)$event['starts_at']); } catch (Throwable $error) { }
    try { $expires = $event['expires_at'] ? auth_parse_sql_time((string)$event['expires_at']) : null; } catch (Throwable $error) { }
    $expired = $expires instanceof DateTimeImmutable && $expires <= $now;
    $tasklessReady = !$hasTask && $starts instanceof DateTimeImmutable && $starts->modify('+120 seconds') <= $now;
    $due = $expired || $allTerminal || $tasklessReady;
    $outcomeValue = $failed || $expired ? ($allTerminal && !$failed ? 'success' : 'partial') : 'success';
    return ['due' => $due, 'outcome' => $outcomeValue, 'status_by_npc' => $statusByNpc];
}

function town_story_event_resolve(PDO $pdo, string $eventId, DateTimeImmutable $now, string $outcome = 'success'): array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $eventId)) throw new ApiError('invalid_story_event', '故事事件 ID 无效。', 400);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME); $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
    $ownsTransaction = !$pdo->inTransaction(); if ($ownsTransaction) $pdo->beginTransaction();
    try {
    $q = $pdo->prepare('SELECT * FROM town_story_events WHERE event_id=:event LIMIT 1' . $lock); $q->execute([':event' => $eventId]); $event = $q->fetch(PDO::FETCH_ASSOC); if (!is_array($event)) throw new ApiError('story_event_not_found', '故事事件不存在。', 404);
    if ((string)$event['status'] === 'resolved') { if ($ownsTransaction) $pdo->commit(); return ['ok' => true, 'event_id' => $eventId, 'status' => 'resolved', 'idempotent' => true]; }
    $settlement = town_story_event_settlement($pdo, $eventId, $now);
    if (!$settlement['due']) throw new ApiError('story_event_not_ready', '故事事件仍有参与任务未完成。', 409);
    $outcome = in_array($outcome, ['success', 'partial', 'expired', 'failed'], true) ? $outcome : (string)$settlement['outcome'];
    if ($outcome === 'success' && (string)$settlement['outcome'] !== 'success') $outcome = (string)$settlement['outcome'];
    $effects = $pdo->prepare('SELECT effect_id,npc_id,stat_delta_json,relationship_delta_json,memory_json,task_payload_json,applied_at FROM town_story_event_effects WHERE event_id=:event ORDER BY effect_id' . $lock); $effects->execute([':event' => $eventId]);
    $applied = [];
    foreach ($effects->fetchAll(PDO::FETCH_ASSOC) as $effect) {
        if ($effect['applied_at'] !== null) continue;
        $participantState = $settlement['status_by_npc'][(string)$effect['npc_id']] ?? [];
        $participantFailed = in_array((string)($participantState['task_status'] ?? ''), ['failed', 'cancelled'], true)
            || (string)($participantState['status'] ?? '') === 'blocked'
            || ($outcome === 'expired' && (string)($participantState['task_status'] ?? '') !== 'completed');
        $delta = json_decode((string)$effect['stat_delta_json'], true); $delta = is_array($delta) ? $delta : [];
        if ($participantFailed) $delta = [];
        $before = town_npc_stats_for($pdo, (string)$effect['npc_id']); $values = $before['values']; foreach ($delta as $key => $amount) if (in_array($key, TOWN_NPC_STAT_KEYS, true)) $values[$key] = max(1, min(100, (int)$values[$key] + max(-15, min(15, (int)$amount))));
        town_npc_stats_save($pdo, (string)$effect['npc_id'], $values, 'story_event', $eventId, (string)$event['title'], $now);
        $memory = json_decode((string)$effect['memory_json'], true);
        if (!$participantFailed && is_array($memory) && function_exists('town_npc_insert_spatial_memory')) town_npc_insert_spatial_memory($pdo, (string)$effect['npc_id'], 'story_event', (string)($memory['content'] ?? $event['title']), max(1, min(5, (int)($memory['importance'] ?? 3))), $now);
        $relationship = json_decode((string)($effect['relationship_delta_json'] ?? '{}'), true);
        if (!$participantFailed && is_array($relationship) && preg_match('/\A[a-f0-9]{32}\z/D', (string)($relationship['target_npc_id'] ?? '')) && function_exists('town_npc_save_relationship') && town_npc_life_table_exists($pdo, 'town_npc_relationships')) {
            $targetNpc = (string)$relationship['target_npc_id'];
            try {
                $current = $pdo->prepare('SELECT affinity FROM town_npc_relationships WHERE npc_id=:npc AND other_npc_id=:other LIMIT 1'); $current->execute([':npc' => $effect['npc_id'], ':other' => $targetNpc]);
                $affinity = (int)($current->fetchColumn() ?: 0) + max(-10, min(10, (int)($relationship['affinity'] ?? 0)));
                town_npc_save_relationship($pdo, (string)$effect['npc_id'], $targetNpc, $affinity, $now);
            } catch (Throwable $relationshipError) { /* relationship tables may be in a rolling migration */ }
        }
        $pdo->prepare('UPDATE town_story_event_effects SET applied_at=:applied WHERE effect_id=:id AND applied_at IS NULL')->execute([':applied' => auth_sql_time($now), ':id' => (int)$effect['effect_id']]);
        $participantStatus = $participantFailed ? 'failed' : 'completed';
        $pdo->prepare('UPDATE town_story_event_participants SET status=:status,completed_at=:completed,outcome_json=:outcome WHERE event_id=:event AND npc_id=:npc')->execute([':status' => $participantStatus, ':completed' => auth_sql_time($now), ':outcome' => json_encode(['outcome' => $outcome, 'stat_delta' => $delta, 'task_status' => $participantState['task_status'] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':event' => $eventId, ':npc' => $effect['npc_id']]);
        town_npc_emit_event($pdo, (string)$effect['npc_id'], 'story_event_effect', ['story_event_id' => $eventId, 'stat_delta' => $delta, 'stats' => town_npc_stats_for($pdo, (string)$effect['npc_id']), 'outcome' => $outcome], $now);
        $applied[] = ['npc_id' => (string)$effect['npc_id'], 'stat_delta' => $delta];
    }
    $pdo->prepare("UPDATE town_story_events SET status='resolved',resolved_at=:resolved WHERE event_id=:event AND status<>'resolved'")->execute([':resolved' => auth_sql_time($now), ':event' => $eventId]);
    if ($ownsTransaction) $pdo->commit();
    return ['ok' => true, 'event_id' => $eventId, 'status' => 'resolved', 'applied' => $applied, 'idempotent' => false];
    } catch (Throwable $error) { if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function town_story_events_query(PDO $pdo, int $limit = 50): array
{
    if (!town_npc_life_table_exists($pdo, 'town_story_events')) return ['events' => []];
    $limit = max(1, min(100, $limit)); $q = $pdo->query('SELECT * FROM town_story_events WHERE world_id=' . $pdo->quote(SHARED_TOWN_WORLD_ID) . ' ORDER BY occurred_at DESC LIMIT ' . $limit); $events = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $p = $pdo->prepare('SELECT p.npc_id,n.name,p.role,p.status FROM town_story_event_participants p JOIN town_npcs n ON n.npc_id=p.npc_id WHERE p.event_id=:event ORDER BY p.npc_id'); $p->execute([':event' => $row['event_id']]);
        $e = $pdo->prepare('SELECT npc_id,stat_delta_json,relationship_delta_json,memory_json,task_payload_json,applied_at FROM town_story_event_effects WHERE event_id=:event ORDER BY effect_id'); $e->execute([':event' => $row['event_id']]); $effects = [];
        foreach ($e->fetchAll(PDO::FETCH_ASSOC) as $effect) $effects[] = ['npc_id' => (string)$effect['npc_id'], 'stat_delta' => json_decode((string)$effect['stat_delta_json'], true) ?: [], 'relationship_delta' => json_decode((string)$effect['relationship_delta_json'], true) ?: [], 'applied' => $effect['applied_at'] !== null];
        $events[] = town_story_event_public($row, $p->fetchAll(PDO::FETCH_ASSOC), $effects);
    }
    return ['events' => $events];
}
