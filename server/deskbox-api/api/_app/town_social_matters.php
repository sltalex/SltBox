<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__ . '/town_npcs.php';

const TOWN_SOCIAL_MATTER_STATES = ['open', 'collecting', 'assigned', 'executing', 'closed', 'cancelled', 'failed'];
const TOWN_SOCIAL_COMMITMENT_STATES = ['assigned', 'executing', 'completed', 'failed', 'cancelled'];

function town_social_id(): string
{
    return function_exists('town_npc_id') ? town_npc_id() : bin2hex(random_bytes(16));
}

function town_social_text(mixed $value, int $max): string
{
    $text = trim(is_scalar($value) ? (string)$value : '');
    return function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
}

function town_social_json(mixed $value, $fallback = []): mixed
{
    if (function_exists('town_npc_decode')) return town_npc_decode($value, $fallback);
    if (is_string($value)) {
        try { return json_decode($value, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { return $fallback; }
    }
    return $value ?? $fallback;
}

/**
 * api_body() intentionally keeps nested JSON objects as stdClass values so
 * older endpoints can distinguish an object from a list.  Social matter
 * options are persisted and validated as PHP arrays, however.  Normalize the
 * complete wire payload at this boundary instead of making every option
 * reader know about the transport representation.
 */
function town_social_normalize_value(mixed $value): mixed
{
    if ($value instanceof stdClass) {
        $value = get_object_vars($value);
    }
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = town_social_normalize_value($item);
        }
        return $normalized;
    }
    return $value;
}

function town_social_idempotency(mixed $value, string $field = 'idempotency_key'): string
{
    $key = town_social_text($value, 191);
    if ($key === '' || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,190}\z/D', $key)) {
        throw new ApiError('invalid_social_' . $field, '社会事务幂等键无效。', 400);
    }
    return $key;
}

function town_social_npc_id(mixed $value, string $field = 'npc_id'): string
{
    $id = trim(is_scalar($value) ? (string)$value : '');
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
        throw new ApiError('invalid_social_' . $field, '社会事务居民标识无效。', 400);
    }
    return $id;
}

function town_social_world_id(mixed $value = null): string
{
    $world = trim(is_scalar($value) ? (string)$value : '');
    if ($world === '') $world = defined('SHARED_TOWN_WORLD_ID') ? SHARED_TOWN_WORLD_ID : 'main';
    if (!preg_match('/\A[a-zA-Z0-9_-]{1,32}\z/D', $world)) throw new ApiError('invalid_social_world', '社会事务世界无效。', 400);
    return $world;
}

function town_social_parse_time(mixed $value, ?DateTimeImmutable $now = null): ?string
{
    if ($value === null || $value === '') return null;
    if ($value instanceof DateTimeInterface) return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    if (!is_string($value) || trim($value) === '') throw new ApiError('invalid_social_deadline', '社会事务截止时间无效。', 400);
    try {
        $time = new DateTimeImmutable(trim($value), new DateTimeZone('UTC'));
    } catch (Throwable $error) {
        throw new ApiError('invalid_social_deadline', '社会事务截止时间无效。', 400);
    }
    $now ??= api_clock();
    if ($time <= $now->modify('-1 minute')) throw new ApiError('invalid_social_deadline', '社会事务截止时间必须在未来。', 400);
    return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Normalize the finite choice set that a model is allowed to select. */
function town_social_options(mixed $raw): array
{
    $raw = town_social_normalize_value($raw);
    $isList = is_array($raw) && (function (array $value): bool { $expected = 0; foreach ($value as $key => $_item) { if ($key !== $expected++) return false; } return true; })($raw);
    if (!is_array($raw) || !$isList || count($raw) < 1 || count($raw) > 8) {
        throw new ApiError('invalid_social_options', '社会事务必须包含 1 到 8 个候选选项。', 400);
    }
    $options = [];
    foreach (array_values($raw) as $index => $option) {
        if (!is_array($option)) throw new ApiError('invalid_social_options', '社会事务候选选项必须是对象。', 400);
        $id = town_social_text($option['option_id'] ?? '', 64);
        if (!preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/D', $id)) throw new ApiError('invalid_social_options', '候选选项标识无效。', 400);
        if (isset($options[$id])) throw new ApiError('invalid_social_options', '候选选项标识不能重复。', 400);
        $label = town_social_text($option['label'] ?? '', 120);
        if ($label === '') throw new ApiError('invalid_social_options', '候选选项必须有名称。', 400);
        $npcIds = [];
        foreach (is_array($option['candidate_npc_ids'] ?? null) ? $option['candidate_npc_ids'] : [] as $npc) {
            $npcIds[] = town_social_npc_id($npc, 'candidate_npc_id');
        }
        $occupations = [];
        foreach (is_array($option['allowed_occupations'] ?? null) ? $option['allowed_occupations'] : [] as $occupation) {
            $occupation = strtolower(town_social_text($occupation, 32));
            if ($occupation !== '' && in_array($occupation, TOWN_NPC_OCCUPATIONS, true)) $occupations[] = $occupation;
        }
        $occupations = array_values(array_unique($occupations));
        $npcIds = array_values(array_unique($npcIds));
        if ($npcIds === [] && $occupations === []) throw new ApiError('invalid_social_options', '候选选项必须声明居民或职业资格。', 400);
        $actionId = town_social_text($option['action_id'] ?? $id, 128);
        if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}\z/D', $actionId)) throw new ApiError('invalid_social_options', '候选选项动作无效。', 400);
        $conditions = is_array($option['conditions'] ?? null) ? $option['conditions'] : [];
        $npcAction = strtolower(town_social_text($option['npc_action'] ?? ($conditions['npc_action'] ?? ''), 16));
        if ($npcAction !== '' && !in_array($npcAction, ['walk', 'work', 'rest', 'talk', 'trade'], true)) {
            throw new ApiError('invalid_social_options', '候选选项绑定的 NPC 行动无效。', 400);
        }
        $taskPayload = $option['task_payload'] ?? ($conditions['task_payload'] ?? ($conditions['payload'] ?? []));
        if (!is_array($taskPayload)) throw new ApiError('invalid_social_options', '候选选项行动参数必须是对象。', 400);
        $options[$id] = [
            'option_id' => $id,
            'label' => $label,
            'candidate_npc_ids' => $npcIds,
            'allowed_occupations' => $occupations,
            'max_commitments' => max(1, min(16, (int)($option['max_commitments'] ?? 1))),
            'action_id' => $actionId,
            'npc_action' => $npcAction,
            'task_payload' => $taskPayload,
            'conditions' => $conditions,
        ];
    }
    return array_values($options);
}

function town_social_find_option(array $matter, string $optionId): ?array
{
    foreach (is_array($matter['options'] ?? null) ? $matter['options'] : [] as $option) {
        if (is_array($option) && (string)($option['option_id'] ?? '') === $optionId) return $option;
    }
    return null;
}

function town_social_option_public(array $option, bool $includeCandidates): array
{
    $result = [
        'option_id' => (string)($option['option_id'] ?? ''),
        'label' => (string)($option['label'] ?? ''),
        'max_commitments' => (int)($option['max_commitments'] ?? 1),
        'action_id' => (string)($option['action_id'] ?? ''),
        'npc_action' => (string)($option['npc_action'] ?? ''),
        'conditions' => is_array($option['conditions'] ?? null) ? $option['conditions'] : [],
    ];
    if ($includeCandidates) {
        $result['candidate_npc_ids'] = array_values(array_filter($option['candidate_npc_ids'] ?? [], 'is_string'));
        $result['allowed_occupations'] = array_values(array_filter($option['allowed_occupations'] ?? [], 'is_string'));
        $result['task_payload'] = is_array($option['task_payload'] ?? null) ? $option['task_payload'] : [];
    } else {
        // Conditions may contain item requirements, remembered coordinates or
        // other executor-only data. Keep the public matter projection limited
        // to bounded action metadata.
        $result['conditions'] = [];
    }
    return $result;
}

/**
 * Resolve the only action a social matter is allowed to execute.  A model may
 * choose an option, but it cannot invent an NPC action or task payload.  The
 * option author must bind the social action to the existing NPC task executor.
 */
function town_social_execution_spec(array $option): array
{
    $conditions = is_array($option['conditions'] ?? null) ? $option['conditions'] : [];
    $action = strtolower(trim((string)($option['npc_action'] ?? ($conditions['npc_action'] ?? ''))));
    if (!in_array($action, ['walk', 'work', 'rest', 'talk', 'trade'], true)) {
        throw new ApiError('social_action_unbound', '社会事务候选选项未绑定有效的 NPC 行动。', 409);
    }
    $payload = $option['task_payload'] ?? ($conditions['task_payload'] ?? ($conditions['payload'] ?? []));
    if (!is_array($payload)) throw new ApiError('social_action_payload_invalid', '社会事务行动参数无效。', 400);
    return ['action' => $action, 'payload' => $payload];
}

function town_social_matter_row(PDO $pdo, string $matterId, bool $includePrivate = false): ?array
{
    $query = $pdo->prepare('SELECT matter_id,world_id,kind,source_event_id,creator_npc_id,state,public_summary,options_json,deadline_at,idempotency_key,revision,failure_reason,created_at,updated_at,closed_at FROM town_social_matters WHERE matter_id=:matter LIMIT 1');
    $query->execute([':matter' => $matterId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;
    $matter = [
        'matter_id' => (string)$row['matter_id'], 'world_id' => (string)$row['world_id'],
        'kind' => (string)$row['kind'], 'source_event_id' => $row['source_event_id'] !== null ? (int)$row['source_event_id'] : null,
        'creator_npc_id' => $row['creator_npc_id'] !== null ? (string)$row['creator_npc_id'] : null,
        'state' => (string)$row['state'], 'public_summary' => (string)$row['public_summary'],
        'options' => town_social_json($row['options_json'], []), 'deadline_at' => $row['deadline_at'] !== null ? (string)$row['deadline_at'] : null,
        'idempotency_key' => (string)$row['idempotency_key'], 'revision' => (int)$row['revision'],
        'failure_reason' => (string)$row['failure_reason'], 'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'], 'closed_at' => $row['closed_at'] !== null ? (string)$row['closed_at'] : null,
    ];
    if (!is_array($matter['options'])) $matter['options'] = [];
    $responses = $pdo->prepare('SELECT response_id,matter_id,resident_id,option_id,response_kind,public_text,idempotency_key,created_at FROM town_social_responses WHERE matter_id=:matter ORDER BY created_at,response_id');
    $responses->execute([':matter' => $matterId]);
    $matter['responses'] = array_map(static function (array $response): array {
        return ['response_id' => (string)$response['response_id'], 'matter_id' => (string)$response['matter_id'], 'resident_id' => (string)$response['resident_id'], 'option_id' => (string)$response['option_id'], 'response_kind' => (string)$response['response_kind'], 'public_text' => (string)$response['public_text'], 'idempotency_key' => (string)$response['idempotency_key'], 'created_at' => (string)$response['created_at']];
    }, $responses->fetchAll(PDO::FETCH_ASSOC));
    $commitments = $pdo->prepare('SELECT commitment_id,matter_id,owner_npc_id,option_id,action_id,task_id,conditions_json,state,due_at,failure_reason,idempotency_key,created_at,updated_at,completed_at FROM town_social_commitments WHERE matter_id=:matter ORDER BY created_at,commitment_id');
    $commitments->execute([':matter' => $matterId]);
    $matter['commitments'] = array_map(static function (array $commitment): array {
        return ['commitment_id' => (string)$commitment['commitment_id'], 'matter_id' => (string)$commitment['matter_id'], 'owner_npc_id' => (string)$commitment['owner_npc_id'], 'option_id' => (string)$commitment['option_id'], 'action_id' => (string)$commitment['action_id'], 'task_id' => isset($commitment['task_id']) && $commitment['task_id'] !== null ? (string)$commitment['task_id'] : null, 'conditions' => town_social_json($commitment['conditions_json'], []), 'state' => (string)$commitment['state'], 'due_at' => $commitment['due_at'] !== null ? (string)$commitment['due_at'] : null, 'failure_reason' => (string)$commitment['failure_reason'], 'idempotency_key' => (string)$commitment['idempotency_key'], 'created_at' => (string)$commitment['created_at'], 'updated_at' => (string)$commitment['updated_at'], 'completed_at' => $commitment['completed_at'] !== null ? (string)$commitment['completed_at'] : null];
    }, $commitments->fetchAll(PDO::FETCH_ASSOC));
    if (!$includePrivate) {
        $matter['options'] = array_map(static fn(array $option): array => town_social_option_public($option, false), $matter['options']);
        $matter['idempotency_key'] = '';
        foreach ($matter['responses'] as &$response) $response['idempotency_key'] = '';
        foreach ($matter['commitments'] as &$commitment) { $commitment['idempotency_key'] = ''; $commitment['conditions'] = []; }
        unset($response, $commitment);
    } else {
        $matter['options'] = array_map(static fn(array $option): array => town_social_option_public($option, true), $matter['options']);
    }
    return $matter;
}

function town_social_require_matter(PDO $pdo, string $matterId, bool $includePrivate = true): array
{
    $matter = town_social_matter_row($pdo, $matterId, $includePrivate);
    if (!is_array($matter)) throw new ApiError('social_matter_not_found', '社会事务不存在。', 404);
    return $matter;
}

/** Lock the matter row before allocating a response, quota or commitment. */
function town_social_lock_matter(PDO $pdo, string $matterId, bool $includePrivate = true): array
{
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $query = $pdo->prepare('SELECT matter_id FROM town_social_matters WHERE matter_id=:matter LIMIT 1' . $lock);
    $query->execute([':matter' => $matterId]);
    if ($query->fetchColumn() === false) throw new ApiError('social_matter_not_found', '社会事务不存在。', 404);
    return town_social_require_matter($pdo, $matterId, $includePrivate);
}

function town_social_require_active_npc(PDO $pdo, string $npcId): array
{
    $query = $pdo->prepare('SELECT npc_id,name,occupation,enabled FROM town_npcs WHERE npc_id=:npc LIMIT 1');
    $query->execute([':npc' => $npcId]); $npc = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($npc) || !(bool)$npc['enabled']) throw new ApiError('social_candidate_invalid', '候选居民不存在或已停用。', 409);
    if (function_exists('town_npc_lifecycle') && (town_npc_lifecycle($pdo, $npcId)['state'] ?? 'active') !== 'active') throw new ApiError('social_candidate_inactive', '候选居民当前不在岗。', 409);
    return $npc;
}

function town_social_option_allows(PDO $pdo, array $option, string $npcId): array
{
    $npc = town_social_require_active_npc($pdo, $npcId);
    $ids = array_values(array_filter($option['candidate_npc_ids'] ?? [], 'is_string'));
    $occupations = array_values(array_filter($option['allowed_occupations'] ?? [], 'is_string'));
    if (($ids !== [] && in_array($npcId, $ids, true)) || ($occupations !== [] && in_array((string)$npc['occupation'], $occupations, true))) return $npc;
    throw new ApiError('social_candidate_ineligible', '该居民不符合社会事务候选资格。', 409);
}

function town_social_public_event(PDO $pdo, string $matterId, string $eventType, array $payload, DateTimeImmutable $now): ?array
{
    if (!function_exists('town_world_event_append')) return null;
    return town_world_event_append($pdo, [
        'source_type' => 'social_matter', 'source_id' => $matterId,
        'source_revision' => (string)($payload['revision'] ?? '1'),
        'event_type' => $eventType, 'public_payload' => $payload,
        'occurred_at' => $now,
    ], $now);
}

function town_social_create_matter(PDO $pdo, array $input, DateTimeImmutable $now, bool $includePrivate = true): array
{
    /** @var array<string,mixed> $input */
    $input = town_social_normalize_value($input);
    $world = town_social_world_id($input['world_id'] ?? null);
    $kind = strtolower(town_social_text($input['kind'] ?? '', 32));
    if (!preg_match('/\A[a-z][a-z0-9_-]{1,31}\z/D', $kind)) throw new ApiError('invalid_social_kind', '社会事务类型无效。', 400);
    $summary = town_social_text($input['public_summary'] ?? '', 255);
    if ($summary === '') throw new ApiError('invalid_social_summary', '社会事务必须有公共摘要。', 400);
    $options = town_social_options($input['options'] ?? []);
    $idempotency = town_social_idempotency($input['idempotency_key'] ?? '');
    $creator = trim((string)($input['creator_npc_id'] ?? ''));
    if ($creator !== '') town_social_require_active_npc($pdo, town_social_npc_id($creator, 'creator_npc_id'));
    else $creator = null;
    $deadline = town_social_parse_time($input['deadline_at'] ?? null, $now);
    $matterId = town_social_id();
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME); $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $existing = $pdo->prepare('SELECT matter_id FROM town_social_matters WHERE world_id=:world AND idempotency_key=:key LIMIT 1' . $lock);
        $existing->execute([':world' => $world, ':key' => $idempotency]);
        $existingId = $existing->fetchColumn();
        if (is_string($existingId) && $existingId !== '') {
            $pdo->commit(); return ['created' => false, 'duplicate' => true, 'matter' => town_social_require_matter($pdo, $existingId, $includePrivate)];
        }
        $json = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stamp = auth_sql_time($now);
        $insert = $pdo->prepare('INSERT INTO town_social_matters (matter_id,world_id,kind,creator_npc_id,state,public_summary,options_json,deadline_at,idempotency_key,revision,failure_reason,created_at,updated_at) VALUES (:matter,:world,:kind,:creator,\'open\',:summary,:options,:deadline,:idempotency,1,\'\',:created,:updated)');
        $insert->execute([':matter' => $matterId, ':world' => $world, ':kind' => $kind, ':creator' => $creator, ':summary' => $summary, ':options' => $json, ':deadline' => $deadline, ':idempotency' => $idempotency, ':created' => $stamp, ':updated' => $stamp]);
        try {
            $event = town_social_public_event($pdo, $matterId, 'social_matter_opened', ['matter_id' => $matterId, 'kind' => $kind, 'summary' => $summary, 'revision' => 1], $now);
            if (is_array($event) && isset($event['event_id'])) $pdo->prepare('UPDATE town_social_matters SET source_event_id=:event,revision=2,updated_at=:updated WHERE matter_id=:matter')->execute([':event' => (int)$event['event_id'], ':updated' => $stamp, ':matter' => $matterId]);
        } catch (PDOException $error) {
            throw new ApiError('social_event_unavailable', '公共事件系统尚未准备好，社会事务未创建。', 503);
        }
        $pdo->commit();
        return ['created' => true, 'duplicate' => false, 'matter' => town_social_require_matter($pdo, $matterId, $includePrivate)];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function town_social_submit_response(PDO $pdo, array $input, DateTimeImmutable $now, bool $includePrivate = true): array
{
    $matterId = town_social_idempotency($input['matter_id'] ?? '', 'matter_id');
    $residentId = town_social_npc_id($input['resident_id'] ?? 'resident_id', 'resident_id');
    $optionId = town_social_text($input['option_id'] ?? '', 64);
    $responseKind = strtolower(town_social_text($input['response_kind'] ?? 'support', 16));
    if (!in_array($responseKind, ['support', 'decline', 'abstain'], true)) throw new ApiError('invalid_social_response', '社会事务响应类型无效。', 400);
    $idempotency = town_social_idempotency($input['idempotency_key'] ?? '', 'response_idempotency_key');
    $publicText = town_social_text($input['public_text'] ?? '', 255);
    $matter = town_social_require_matter($pdo, $matterId, true);
    if (!in_array($matter['state'], ['open', 'collecting'], true)) throw new ApiError('social_matter_closed', '社会事务已停止接收响应。', 409);
    if ($matter['deadline_at'] !== null && auth_parse_sql_time($matter['deadline_at']) <= $now) throw new ApiError('social_matter_expired', '社会事务已超过截止时间。', 409);
    $option = town_social_find_option($matter, $optionId);
    if (!is_array($option)) throw new ApiError('social_option_invalid', '社会事务候选选项不存在。', 400);
    town_social_option_allows($pdo, $option, $residentId);
    $pdo->beginTransaction();
    try {
        $matter = town_social_lock_matter($pdo, $matterId, true);
        if (!in_array($matter['state'], ['open', 'collecting'], true)) throw new ApiError('social_matter_closed', '社会事务已停止接收响应。', 409);
        if ($matter['deadline_at'] !== null && auth_parse_sql_time($matter['deadline_at']) <= $now) throw new ApiError('social_matter_expired', '社会事务已超过截止时间。', 409);
        $existing = $pdo->prepare('SELECT response_id FROM town_social_responses WHERE matter_id=:matter AND (idempotency_key=:key OR resident_id=:resident) ORDER BY response_id LIMIT 1');
        $existing->execute([':matter' => $matterId, ':key' => $idempotency, ':resident' => $residentId]);
        $existingId = $existing->fetchColumn();
        if (is_string($existingId) && $existingId !== '') { $pdo->commit(); return ['created' => false, 'duplicate' => true, 'response' => town_social_response_row($pdo, $existingId, $includePrivate), 'matter' => town_social_require_matter($pdo, $matterId, $includePrivate)]; }
        $stamp = auth_sql_time($now); $responseId = town_social_id();
        $insert = $pdo->prepare('INSERT INTO town_social_responses (response_id,matter_id,resident_id,option_id,response_kind,public_text,idempotency_key,created_at) VALUES (:response,:matter,:resident,:option,:kind,:text,:key,:created)');
        $insert->execute([':response' => $responseId, ':matter' => $matterId, ':resident' => $residentId, ':option' => $optionId, ':kind' => $responseKind, ':text' => $publicText, ':key' => $idempotency, ':created' => $stamp]);
        $pdo->prepare('UPDATE town_social_matters SET state=CASE WHEN state=\'open\' THEN \'collecting\' ELSE state END,revision=revision+1,updated_at=:updated WHERE matter_id=:matter')->execute([':updated' => $stamp, ':matter' => $matterId]);
        town_social_public_event($pdo, $matterId, 'social_matter_response', ['matter_id' => $matterId, 'resident_id' => $residentId, 'option_id' => $optionId, 'response_kind' => $responseKind, 'revision' => $matter['revision'] + 1], $now);
        $pdo->commit();
        return ['created' => true, 'duplicate' => false, 'response' => town_social_response_row($pdo, $responseId, $includePrivate), 'matter' => town_social_require_matter($pdo, $matterId, $includePrivate)];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function town_social_response_row(PDO $pdo, string $responseId, bool $includePrivate = true): ?array
{
    $query = $pdo->prepare('SELECT response_id,matter_id,resident_id,option_id,response_kind,public_text,idempotency_key,created_at FROM town_social_responses WHERE response_id=:response LIMIT 1'); $query->execute([':response' => $responseId]); $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;
    $result = ['response_id' => (string)$row['response_id'], 'matter_id' => (string)$row['matter_id'], 'resident_id' => (string)$row['resident_id'], 'option_id' => (string)$row['option_id'], 'response_kind' => (string)$row['response_kind'], 'public_text' => (string)$row['public_text'], 'idempotency_key' => (string)$row['idempotency_key'], 'created_at' => (string)$row['created_at']];
    if (!$includePrivate) $result['idempotency_key'] = '';
    return $result;
}

function town_social_assign_commitment(PDO $pdo, array $input, DateTimeImmutable $now, bool $includePrivate = true): array
{
    $matterId = town_social_idempotency($input['matter_id'] ?? '', 'matter_id'); $ownerId = town_social_npc_id($input['owner_npc_id'] ?? '', 'owner_npc_id'); $optionId = town_social_text($input['option_id'] ?? '', 64); $requestedActionId = town_social_text($input['action_id'] ?? '', 128); $idempotency = town_social_idempotency($input['idempotency_key'] ?? '', 'commitment_idempotency_key');
    $stamp = auth_sql_time($now); $id = town_social_id();
    $pdo->beginTransaction();
    try {
        $matter = town_social_lock_matter($pdo, $matterId, true);
        if (!in_array($matter['state'], ['open', 'collecting', 'assigned', 'executing'], true)) throw new ApiError('social_matter_closed', '社会事务已不能分配承诺。', 409);
        if ($matter['deadline_at'] !== null && auth_parse_sql_time($matter['deadline_at']) <= $now) throw new ApiError('social_matter_expired', '社会事务已超过截止时间。', 409);
        $option = town_social_find_option($matter, $optionId); if (!is_array($option)) throw new ApiError('social_option_invalid', '社会事务候选选项不存在。', 400);
        $actionId = (string)($option['action_id'] ?? '');
        if ($actionId === '' || ($requestedActionId !== '' && !hash_equals($actionId, $requestedActionId))) throw new ApiError('social_action_mismatch', '社会事务动作必须与候选选项一致。', 409);
        $execution = town_social_execution_spec($option);
        town_social_option_allows($pdo, $option, $ownerId);
        // Resolve idempotency before capacity checks.  A retry of an already
        // accepted commitment must return the original record even when the
        // option's quota is now full.
        $existing = $pdo->prepare('SELECT commitment_id FROM town_social_commitments WHERE matter_id=:matter AND idempotency_key=:key LIMIT 1'); $existing->execute([':matter' => $matterId, ':key' => $idempotency]); $existingId = $existing->fetchColumn();
        if (is_string($existingId) && $existingId !== '') { $pdo->commit(); return ['created' => false, 'duplicate' => true, 'commitment' => town_social_commitment_row($pdo, $existingId, $includePrivate), 'matter' => town_social_require_matter($pdo, $matterId, $includePrivate)]; }
        $max = (int)($option['max_commitments'] ?? 1);
        $count = $pdo->prepare("SELECT COUNT(*) FROM town_social_commitments WHERE matter_id=:matter AND option_id=:option AND state IN ('assigned','executing','completed')"); $count->execute([':matter' => $matterId, ':option' => $optionId]);
        if ((int)$count->fetchColumn() >= $max) throw new ApiError('social_commitment_limit', '该候选选项的承诺名额已满。', 409);
        $dueAt = town_social_parse_time($input['due_at'] ?? $matter['deadline_at'], $now); $conditions = is_array($option['conditions'] ?? null) ? $option['conditions'] : [];
        $insert = $pdo->prepare('INSERT INTO town_social_commitments (commitment_id,matter_id,owner_npc_id,option_id,action_id,task_id,conditions_json,state,due_at,failure_reason,idempotency_key,created_at,updated_at) VALUES (:commitment,:matter,:owner,:option,:action,NULL,:conditions,\'assigned\',:due,\'\',:key,:created,:updated)');
        $insert->execute([':commitment' => $id, ':matter' => $matterId, ':owner' => $ownerId, ':option' => $optionId, ':action' => $actionId, ':conditions' => json_encode($conditions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':due' => $dueAt, ':key' => $idempotency, ':created' => $stamp, ':updated' => $stamp]);
        $taskPayload = $execution['payload'];
        $taskPayload['social_commitment_id'] = $id;
        $taskPayload['social_matter_id'] = $matterId;
        $taskPayload['social_action_id'] = $actionId;
        if ($dueAt !== null) $taskPayload['social_due_at'] = $dueAt;
        // A scheduled rest is represented by a running authoritative task.
        // Social work may take over that idle rest task, but the executor must
        // continue rejecting work, talk, trade, or an already active walk.
        $taskResult = town_npc_apply_action(
            $pdo,
            ['npc_id' => $ownerId, 'action' => $execution['action'], 'payload' => $taskPayload],
            $now,
            true,
            false,
            true,
        );
        $taskId = (string)($taskResult['task_id'] ?? '');
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $taskId)) throw new ApiError('social_task_not_created', '社会事务未能创建权威 NPC 任务。', 503);
        $pdo->prepare('UPDATE town_social_commitments SET task_id=:task,state=\'executing\',updated_at=:updated WHERE commitment_id=:commitment')->execute([':task' => $taskId, ':updated' => $stamp, ':commitment' => $id]);
        $revision = $matter['revision'] + 1;
        $pdo->prepare('UPDATE town_social_matters SET state=\'executing\',revision=:revision,updated_at=:updated WHERE matter_id=:matter')->execute([':revision' => $revision, ':updated' => $stamp, ':matter' => $matterId]);
        town_social_public_event($pdo, $matterId, 'social_matter_commitment_assigned', ['matter_id' => $matterId, 'owner_npc_id' => $ownerId, 'option_id' => $optionId, 'action_id' => $actionId, 'npc_action' => $execution['action'], 'task_id' => $taskId, 'revision' => $revision], $now);
        $pdo->commit();
        return ['created' => true, 'duplicate' => false, 'commitment' => town_social_commitment_row($pdo, $id, $includePrivate), 'matter' => town_social_require_matter($pdo, $matterId, $includePrivate)];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function town_social_commitment_row(PDO $pdo, string $commitmentId, bool $includePrivate = true): ?array
{
    $query = $pdo->prepare('SELECT commitment_id,matter_id,owner_npc_id,option_id,action_id,task_id,conditions_json,state,due_at,failure_reason,idempotency_key,created_at,updated_at,completed_at FROM town_social_commitments WHERE commitment_id=:commitment LIMIT 1'); $query->execute([':commitment' => $commitmentId]); $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;
    $result = ['commitment_id' => (string)$row['commitment_id'], 'matter_id' => (string)$row['matter_id'], 'owner_npc_id' => (string)$row['owner_npc_id'], 'option_id' => (string)$row['option_id'], 'action_id' => (string)$row['action_id'], 'task_id' => isset($row['task_id']) && $row['task_id'] !== null ? (string)$row['task_id'] : null, 'conditions' => town_social_json($row['conditions_json'], []), 'state' => (string)$row['state'], 'due_at' => $row['due_at'] !== null ? (string)$row['due_at'] : null, 'failure_reason' => (string)$row['failure_reason'], 'idempotency_key' => (string)$row['idempotency_key'], 'created_at' => (string)$row['created_at'], 'updated_at' => (string)$row['updated_at'], 'completed_at' => $row['completed_at'] !== null ? (string)$row['completed_at'] : null];
    if (!$includePrivate) { $result['idempotency_key'] = ''; $result['conditions'] = []; }
    return $result;
}

/**
 * Project an authoritative NPC task transition back to its social matter.
 * This is intentionally transaction-safe: task advancement calls it while
 * holding the same transaction that marks the task completed or failed.
 */
function town_social_sync_task(PDO $pdo, string $taskId, string $npcId, string $taskStatus, ?string $reason, DateTimeImmutable $now): void
{
    if ($taskId === '' || !in_array($taskStatus, ['running', 'completed', 'failed', 'cancelled'], true)) return;
    try {
        $query = $pdo->prepare('SELECT commitment_id,matter_id,owner_npc_id,state FROM town_social_commitments WHERE task_id=:task AND owner_npc_id=:npc LIMIT 1');
        $query->execute([':task' => $taskId, ':npc' => $npcId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $error) {
        // The social migration is optional during a rolling deployment.
        return;
    }
    if (!is_array($row)) return;
    $next = $taskStatus === 'running' ? 'executing' : $taskStatus;
    $current = (string)($row['state'] ?? '');
    if (in_array($current, ['completed', 'failed', 'cancelled'], true)) return;
    $stamp = auth_sql_time($now);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $message = town_social_text($reason ?? '', 255);
        $completed = in_array($next, ['completed', 'failed', 'cancelled'], true) ? $stamp : null;
        $update = $pdo->prepare('UPDATE town_social_commitments SET state=:state,failure_reason=:reason,updated_at=:updated,completed_at=:completed WHERE commitment_id=:commitment AND state IN (\'assigned\',\'executing\')');
        $update->execute([':state' => $next, ':reason' => $message, ':updated' => $stamp, ':completed' => $completed, ':commitment' => (string)$row['commitment_id']]);
        if ($update->rowCount() === 0) {
            if ($ownsTransaction) $pdo->commit();
            return;
        }
        $matter = town_social_matter_row($pdo, (string)$row['matter_id'], true);
        if (!is_array($matter)) throw new ApiError('social_matter_not_found', '社会事务不存在。', 404);
        $revision = (int)$matter['revision'] + 1;
        $matterState = (string)$matter['state'];
        $closedAt = null;
        if (in_array($next, ['completed', 'failed', 'cancelled'], true)) {
            $pending = $pdo->prepare("SELECT COUNT(*) FROM town_social_commitments WHERE matter_id=:matter AND state IN ('assigned','executing')");
            $pending->execute([':matter' => (string)$row['matter_id']]);
            if ((int)$pending->fetchColumn() === 0) {
                $failed = $pdo->prepare("SELECT COUNT(*) FROM town_social_commitments WHERE matter_id=:matter AND state='failed'");
                $failed->execute([':matter' => (string)$row['matter_id']]);
                $cancelled = $pdo->prepare("SELECT COUNT(*) FROM town_social_commitments WHERE matter_id=:matter AND state='cancelled'");
                $cancelled->execute([':matter' => (string)$row['matter_id']]);
                $matterState = (int)$failed->fetchColumn() > 0 ? 'failed' : ((int)$cancelled->fetchColumn() > 0 ? 'cancelled' : 'closed');
                $closedAt = $stamp;
            }
        }
        $matterUpdate = $pdo->prepare('UPDATE town_social_matters SET state=:state,failure_reason=:reason,revision=:revision,updated_at=:updated,closed_at=:closed WHERE matter_id=:matter');
        $matterUpdate->execute([':state' => $matterState, ':reason' => $message, ':revision' => $revision, ':updated' => $stamp, ':closed' => $closedAt, ':matter' => (string)$row['matter_id']]);
        town_social_public_event($pdo, (string)$row['matter_id'], 'social_matter_commitment_' . $next, [
            'matter_id' => (string)$row['matter_id'], 'commitment_id' => (string)$row['commitment_id'],
            'owner_npc_id' => (string)$row['owner_npc_id'], 'task_id' => $taskId,
            'task_status' => $taskStatus, 'failure_reason' => $message, 'revision' => $revision,
        ], $now);
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Cancel a bound task when a matter is force-closed or expires. */
function town_social_cancel_bound_task(PDO $pdo, string $taskId, string $npcId, string $reason, DateTimeImmutable $now): void
{
    if ($taskId === '') return;
    $query = $pdo->prepare('SELECT payload_json,status FROM town_npc_tasks WHERE task_id=:task AND npc_id=:npc LIMIT 1');
    try { $query->execute([':task' => $taskId, ':npc' => $npcId]); $task = $query->fetch(PDO::FETCH_ASSOC); }
    catch (PDOException $error) { return; }
    if (!is_array($task) || (string)$task['status'] !== 'running') return;
    $runtime = $pdo->prepare('SELECT current_task_id FROM town_npc_runtime_states WHERE npc_id=:npc LIMIT 1');
    $runtime->execute([':npc' => $npcId]);
    if ((string)$runtime->fetchColumn() === $taskId && function_exists('town_npc_lifecycle_cancel_runtime')) {
        town_npc_lifecycle_cancel_runtime($pdo, $npcId, $reason, $now);
        return;
    }
    $payload = town_social_json($task['payload_json'] ?? [], []);
    if (is_array($payload['consumed_items'] ?? null) && function_exists('town_npc_refund_route_items')) town_npc_refund_route_items($pdo, $npcId, $payload['consumed_items'], $now);
    if (function_exists('town_npc_release_work_point')) town_npc_release_work_point($pdo, $taskId, 'failed', $now);
    $pdo->prepare("UPDATE town_npc_tasks SET status='cancelled',failure_reason=:reason,completed_at=:completed WHERE task_id=:task AND npc_id=:npc AND status='running'")->execute([
        ':reason' => town_social_text($reason, 255), ':completed' => auth_sql_time($now), ':task' => $taskId, ':npc' => $npcId,
    ]);
    if (function_exists('town_npc_sync_social_task_if_available')) town_npc_sync_social_task_if_available($pdo, $taskId, $npcId, 'cancelled', $reason, $now);
}

function town_social_update_commitment(PDO $pdo, array $input, DateTimeImmutable $now, bool $includePrivate = true): array
{
    $commitmentId = town_social_idempotency($input['commitment_id'] ?? '', 'commitment_id'); $state = strtolower(town_social_text($input['state'] ?? '', 16));
    if (!in_array($state, ['executing', 'completed', 'failed', 'cancelled'], true)) throw new ApiError('invalid_social_commitment_state', '承诺状态无效。', 400);
    $query = $pdo->prepare('SELECT commitment_id,matter_id,state,owner_npc_id,task_id FROM town_social_commitments WHERE commitment_id=:commitment LIMIT 1'); $query->execute([':commitment' => $commitmentId]); $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new ApiError('social_commitment_not_found', '社会事务承诺不存在。', 404);
    if (trim((string)($row['task_id'] ?? '')) !== '') throw new ApiError('social_commitment_task_authority', '承诺状态必须由权威 NPC 任务结果推进。', 409);
    $current = (string)$row['state'];
    if (in_array($current, ['completed', 'failed', 'cancelled'], true)) {
        if ($current === $state) return ['updated' => false, 'duplicate' => true, 'commitment' => town_social_commitment_row($pdo, $commitmentId, $includePrivate), 'matter' => town_social_require_matter($pdo, (string)$row['matter_id'], $includePrivate)];
        throw new ApiError('social_commitment_terminal', '已结案的承诺不能再次修改。', 409);
    }
    $reason = town_social_text($input['failure_reason'] ?? '', 255); $stamp = auth_sql_time($now); $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE town_social_commitments SET state=:state,failure_reason=:reason,updated_at=:updated,completed_at=:completed WHERE commitment_id=:commitment AND state IN (\'assigned\',\'executing\')');
        $update->execute([':state' => $state, ':reason' => $reason, ':updated' => $stamp, ':completed' => in_array($state, ['completed', 'failed', 'cancelled'], true) ? $stamp : null, ':commitment' => $commitmentId]);
        if ($update->rowCount() === 0) throw new ApiError('social_commitment_conflict', '社会事务承诺已被其他执行者更新。', 409);
        $matterId = (string)$row['matter_id']; $matter = town_social_require_matter($pdo, $matterId, true); $revision = $matter['revision'] + 1;
        $pending = $pdo->prepare("SELECT COUNT(*) FROM town_social_commitments WHERE matter_id=:matter AND state IN ('assigned','executing')"); $pending->execute([':matter' => $matterId]);
        if ((int)$pending->fetchColumn() === 0 && $state === 'failed') $pdo->prepare('UPDATE town_social_matters SET state=\'failed\',failure_reason=:reason,revision=:revision,updated_at=:updated,closed_at=:closed WHERE matter_id=:matter')->execute([':reason' => $reason !== '' ? $reason : 'commitment_failed', ':revision' => $revision, ':updated' => $stamp, ':closed' => $stamp, ':matter' => $matterId]);
        else $pdo->prepare('UPDATE town_social_matters SET revision=:revision,updated_at=:updated WHERE matter_id=:matter')->execute([':revision' => $revision, ':updated' => $stamp, ':matter' => $matterId]);
        town_social_public_event($pdo, $matterId, 'social_matter_commitment_' . $state, ['matter_id' => $matterId, 'commitment_id' => $commitmentId, 'owner_npc_id' => (string)$row['owner_npc_id'], 'failure_reason' => $reason, 'revision' => $revision], $now);
        $pdo->commit();
        return ['updated' => true, 'duplicate' => false, 'commitment' => town_social_commitment_row($pdo, $commitmentId, $includePrivate), 'matter' => town_social_require_matter($pdo, $matterId, $includePrivate)];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function town_social_close_matter(PDO $pdo, array $input, DateTimeImmutable $now, bool $includePrivate = true): array
{
    $matterId = town_social_idempotency($input['matter_id'] ?? '', 'matter_id'); $state = strtolower(town_social_text($input['state'] ?? 'closed', 16)); $reason = town_social_text($input['failure_reason'] ?? '', 255);
    if (!in_array($state, ['closed', 'cancelled', 'failed'], true)) throw new ApiError('invalid_social_close_state', '社会事务结案状态无效。', 400);
    $matter = town_social_require_matter($pdo, $matterId, true);
    if (in_array($matter['state'], ['closed', 'cancelled', 'failed'], true)) {
        if ($matter['state'] === $state) return ['updated' => false, 'duplicate' => true, 'matter' => town_social_require_matter($pdo, $matterId, $includePrivate)];
        throw new ApiError('social_matter_terminal', '社会事务已经结案。', 409);
    }
    $pending = $pdo->prepare("SELECT COUNT(*) FROM town_social_commitments WHERE matter_id=:matter AND state IN ('assigned','executing')"); $pending->execute([':matter' => $matterId]);
    if ((int)$pending->fetchColumn() > 0 && !($input['force'] ?? false)) throw new ApiError('social_commitments_pending', '仍有未完成的社会事务承诺。', 409);
    $stamp = auth_sql_time($now); $pdo->beginTransaction();
    try {
        if (($input['force'] ?? false) === true) {
            $bound = $pdo->prepare("SELECT task_id,owner_npc_id FROM town_social_commitments WHERE matter_id=:matter AND state IN ('assigned','executing') AND task_id IS NOT NULL");
            $bound->execute([':matter' => $matterId]);
            foreach ($bound->fetchAll(PDO::FETCH_ASSOC) as $task) town_social_cancel_bound_task($pdo, (string)$task['task_id'], (string)$task['owner_npc_id'], $reason !== '' ? $reason : 'matter_closed', $now);
            $pdo->prepare("UPDATE town_social_commitments SET state='cancelled',failure_reason=:reason,updated_at=:updated,completed_at=:completed WHERE matter_id=:matter AND state IN ('assigned','executing')")->execute([':reason' => $reason !== '' ? $reason : 'matter_closed', ':updated' => $stamp, ':completed' => $stamp, ':matter' => $matterId]);
        }
        $revision = $matter['revision'] + 1; $pdo->prepare('UPDATE town_social_matters SET state=:state,failure_reason=:reason,revision=:revision,updated_at=:updated,closed_at=:closed WHERE matter_id=:matter AND state NOT IN (\'closed\',\'cancelled\',\'failed\')')->execute([':state' => $state, ':reason' => $reason, ':revision' => $revision, ':updated' => $stamp, ':closed' => $stamp, ':matter' => $matterId]);
        town_social_public_event($pdo, $matterId, 'social_matter_' . $state, ['matter_id' => $matterId, 'reason' => $reason, 'revision' => $revision], $now);
        $pdo->commit(); return ['updated' => true, 'duplicate' => false, 'matter' => town_social_require_matter($pdo, $matterId, $includePrivate)];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function town_social_expire(PDO $pdo, DateTimeImmutable $now, int $limit = 50): array
{
    $limit = max(1, min(200, $limit)); $query = $pdo->prepare("SELECT matter_id FROM town_social_matters WHERE deadline_at IS NOT NULL AND deadline_at<=:now AND state IN ('open','collecting','assigned','executing') ORDER BY deadline_at,matter_id LIMIT {$limit}"); $query->execute([':now' => auth_sql_time($now)]); $ids = $query->fetchAll(PDO::FETCH_COLUMN); $expired = [];
    foreach ($ids as $id) { try { $result = town_social_close_matter($pdo, ['matter_id' => (string)$id, 'state' => 'failed', 'failure_reason' => 'deadline_expired', 'force' => true], $now, true); $expired[] = $result['matter']; } catch (ApiError $error) { } }
    return ['expired_count' => count($expired), 'matters' => $expired];
}

function town_social_list(PDO $pdo, string $worldId = 'main', ?string $state = null, int $limit = 50, bool $includePrivate = false): array
{
    $world = town_social_world_id($worldId); $limit = max(1, min(200, $limit)); $where = ' WHERE world_id=:world'; $params = [':world' => $world];
    if ($state !== null && $state !== '') { if (!in_array($state, TOWN_SOCIAL_MATTER_STATES, true)) throw new ApiError('invalid_social_state', '社会事务状态无效。', 400); $where .= ' AND state=:state'; $params[':state'] = $state; }
    $query = $pdo->prepare('SELECT matter_id FROM town_social_matters' . $where . ' ORDER BY created_at DESC,matter_id DESC LIMIT ' . $limit); $query->execute($params); $matters = [];
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) { $matter = town_social_matter_row($pdo, (string)$id, $includePrivate); if (is_array($matter)) $matters[] = $matter; }
    return ['matters' => $matters, 'count' => count($matters)];
}
