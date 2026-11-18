<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__ . '/town_navigation.php';
require_once __DIR__ . '/town_world_event_journal.php';

const TOWN_WORLD_EVENT_RECEIPT_STATES = ['unread', 'read', 'ignored', 'deferred'];
const TOWN_WORLD_EVENT_VISIBILITIES = ['public'];
const TOWN_PERCEPTION_CHANNELS = ['visible', 'audible', 'nearby'];

function town_world_event_id(string $value): string
{
    $value = trim($value);
    return preg_match('/\A[a-f0-9]{32}\z/D', $value) ? $value : '';
}

function town_world_event_world_id(array $event): string
{
    $world = trim((string)($event['world_id'] ?? ''));
    if ($world === '') $world = defined('SHARED_TOWN_WORLD_ID') ? SHARED_TOWN_WORLD_ID : 'main';
    if (!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $world)) {
        throw new ApiError('invalid_world', 'The town world is invalid.', 400);
    }
    return $world;
}

function town_world_event_payload(mixed $payload): array
{
    if (!is_array($payload) || array_is_list($payload)) {
        throw new ApiError('invalid_event_payload', 'Public event payload must be an object.', 400);
    }
    try {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 8192) throw new JsonException('payload too large');
        $decoded = json_decode($json, true, 12, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new ApiError('invalid_event_payload', 'Public event payload is invalid or too large.', 400);
    }
    return is_array($decoded) ? $decoded : [];
}

function town_world_event_source_key(array $event): string
{
    $sourceType = trim((string)($event['source_type'] ?? ''));
    $sourceId = trim((string)($event['source_id'] ?? ''));
    $revision = trim((string)($event['source_revision'] ?? '1'));
    if (!preg_match('/\A[a-z][a-z0-9_]{1,31}\z/D', $sourceType)
        || $sourceId === '' || strlen($sourceId) > 128
        || !preg_match('/\A[a-zA-Z0-9_.:-]{1,64}\z/D', $revision)) {
        throw new ApiError('invalid_event_source', 'A public event source is invalid.', 400);
    }
    return substr($sourceType . ':' . $sourceId . ':' . $revision, 0, 191);
}

function town_world_event_append(PDO $pdo, array $event, ?DateTimeImmutable $now = null): array
{
    $now ??= api_clock();
    $world = town_world_event_world_id($event);
    $type = strtolower(trim((string)($event['event_type'] ?? '')));
    if (!preg_match('/\A[a-z][a-z0-9_]{1,63}\z/D', $type)) {
        throw new ApiError('invalid_event_type', 'Public event type is invalid.', 400);
    }
    $visibility = strtolower(trim((string)($event['visibility'] ?? 'public')));
    if (!in_array($visibility, TOWN_WORLD_EVENT_VISIBILITIES, true)) {
        throw new ApiError('invalid_event_visibility', 'Public event visibility is invalid.', 400);
    }
    $sourceKey = town_world_event_source_key($event);
    $payload = town_world_event_payload($event['public_payload'] ?? $event['payload'] ?? []);
    $actor = town_world_event_id((string)($event['actor_npc_id'] ?? '')) ?: null;
    $target = town_world_event_id((string)($event['target_npc_id'] ?? '')) ?: null;
    $occurredValue = $event['occurred_at'] ?? null;
    $expiresValue = $event['expires_at'] ?? null;
    $occurredAt = $occurredValue instanceof DateTimeInterface
        ? $occurredValue->format('Y-m-d H:i:s')
        : (is_string($occurredValue) && trim($occurredValue) !== ''
            ? trim($occurredValue) : $now->format('Y-m-d H:i:s'));
    $expiresAt = $expiresValue instanceof DateTimeInterface
        ? $expiresValue->format('Y-m-d H:i:s')
        : (is_string($expiresValue) && trim($expiresValue) !== ''
            ? trim($expiresValue) : null);
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $find = $pdo->prepare('SELECT event_id,world_id,source_key,event_type,actor_npc_id,target_npc_id,public_payload_json,visibility,occurred_at,expires_at,created_at FROM town_world_events WHERE world_id=:world AND source_key=:source LIMIT 1');
    $find->execute([':world' => $world, ':source' => $sourceKey]);
    $existing = $find->fetch(PDO::FETCH_ASSOC);
    if (is_array($existing)) {
        town_world_event_journal_append($pdo, $world, 'public_event', [
            'event_id' => (int)$existing['event_id'],
            'event_type' => (string)$existing['event_type'],
            'source_key' => (string)$existing['source_key'],
            'payload' => json_decode((string)$existing['public_payload_json'], true) ?: [],
        ], 'public_event:' . (string)$existing['event_id'], $now);
        return town_world_event_public($existing, false);
    }
    try {
        $insert = $pdo->prepare('INSERT INTO town_world_events (world_id,source_key,event_type,actor_npc_id,target_npc_id,public_payload_json,visibility,occurred_at,expires_at,created_at) VALUES (:world,:source,:type,:actor,:target,:payload,:visibility,:occurred,:expires,:created)');
        $insert->execute([
            ':world' => $world, ':source' => $sourceKey, ':type' => $type,
            ':actor' => $actor, ':target' => $target, ':payload' => $payloadJson,
            ':visibility' => $visibility, ':occurred' => $occurredAt,
            ':expires' => $expiresAt, ':created' => $now->format('Y-m-d H:i:s'),
        ]);
        $id = (int)$pdo->lastInsertId();
    } catch (PDOException $error) {
        $find->execute([':world' => $world, ':source' => $sourceKey]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        if (!is_array($existing)) throw new ApiError('event_conflict', 'The public event could not be recorded.', 409);
        return town_world_event_public($existing, false);
    }
    $find->execute([':world' => $world, ':source' => $sourceKey]);
    $created = $find->fetch(PDO::FETCH_ASSOC);
    if (!is_array($created)) throw new RuntimeException('Inserted public event could not be read back.');
    town_world_event_journal_append($pdo, $world, 'public_event', [
        'event_id' => $id,
        'event_type' => $type,
        'source_key' => $sourceKey,
        'payload' => $payload,
    ], 'public_event:' . $id, $now);
    return town_world_event_public($created, true);
}

/**
 * Project only bounded, non-private NPC facts into the public event stream.
 * The NPC event remains the private Agent source of truth.
 */
function town_world_event_project_npc(PDO $pdo, int $npcEventId, string $npcId, string $type, array $payload, DateTimeImmutable $now): void
{
    $publicTypes = ['conversation', 'task_completed', 'task_failed', 'task_replay_delta', 'trade', 'home_blocked', 'relationship_changed'];
    if (!in_array($type, $publicTypes, true) || $npcEventId < 1) return;
    $publicPayload = ['npc_event_id' => $npcEventId];
    foreach (['action', 'task_id', 'task_status', 'state', 'summary', 'reason', 'failure_reason', 'other_npc_id', 'item_id', 'quantity', 'amount', 'tile_x', 'tile_y'] as $key) {
        if (array_key_exists($key, $payload) && is_scalar($payload[$key])) $publicPayload[$key] = $payload[$key];
    }
    foreach (['replay', 'trade'] as $key) {
        if (!is_array($payload[$key] ?? null)) continue;
        try {
            $encoded = json_encode($payload[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($encoded) <= 4096) $publicPayload[$key] = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) { /* Do not project malformed or oversized replay details. */ }
    }
    if ($type === 'conversation' && is_string($payload['dialogue'] ?? null)) {
        $dialogue = trim((string)$payload['dialogue']);
        if ($dialogue !== '') $publicPayload['dialogue'] = function_exists('mb_substr') ? mb_substr($dialogue, 0, 160, 'UTF-8') : substr($dialogue, 0, 160);
    }
    town_world_event_append($pdo, [
        'source_type' => 'npc_event',
        'source_id' => (string)$npcEventId,
        'source_revision' => '1',
        'event_type' => 'npc_' . $type,
        'actor_npc_id' => $npcId,
        'target_npc_id' => is_string($payload['target_npc_id'] ?? null) ? (string)$payload['target_npc_id'] : (is_string($payload['other_npc_id'] ?? null) ? (string)$payload['other_npc_id'] : null),
        'public_payload' => $publicPayload,
        'occurred_at' => $now,
    ], $now);
}

function town_world_event_public(array $row, bool $created = false, ?string $receiptState = null): array
{
    $payload = json_decode((string)($row['public_payload_json'] ?? '{}'), true);
    return [
        'event_id' => (int)($row['event_id'] ?? 0),
        'world_id' => (string)($row['world_id'] ?? ''),
        'source_key' => (string)($row['source_key'] ?? ''),
        'event_type' => (string)($row['event_type'] ?? ''),
        'actor_npc_id' => $row['actor_npc_id'] !== null ? (string)$row['actor_npc_id'] : null,
        'target_npc_id' => $row['target_npc_id'] !== null ? (string)$row['target_npc_id'] : null,
        'payload' => is_array($payload) ? $payload : [],
        'visibility' => (string)($row['visibility'] ?? 'public'),
        'occurred_at' => (string)($row['occurred_at'] ?? ''),
        'expires_at' => $row['expires_at'] !== null ? (string)$row['expires_at'] : null,
        'created_at' => (string)($row['created_at'] ?? ''),
        'created' => $created,
        'receipt_state' => $receiptState ?? 'unread',
    ];
}

function town_world_events_query(PDO $pdo, string $viewerId, int $cursor = 0, int $limit = 50, array $types = [], ?string $actorNpcId = null, ?string $targetNpcId = null): array
{
    if ($cursor < 0) throw new ApiError('invalid_cursor', 'The public event cursor is invalid.', 400);
    $limit = max(1, min(200, $limit));
    $where = ['e.world_id=:world', 'e.event_id>:cursor', 'e.visibility=:visibility', '(e.expires_at IS NULL OR e.expires_at>CURRENT_TIMESTAMP)'];
    $params = [':world' => defined('SHARED_TOWN_WORLD_ID') ? SHARED_TOWN_WORLD_ID : 'main', ':cursor' => $cursor, ':visibility' => 'public'];
    if ($types !== []) {
        $normalized = [];
        foreach ($types as $type) if (is_string($type) && preg_match('/\A[a-z][a-z0-9_]{1,63}\z/D', trim($type))) $normalized[] = trim($type);
        $normalized = array_values(array_unique($normalized));
        if ($normalized !== []) {
            $placeholders = [];
            foreach ($normalized as $index => $type) { $key = ':type' . $index; $placeholders[] = $key; $params[$key] = $type; }
            $where[] = 'e.event_type IN (' . implode(',', $placeholders) . ')';
        }
    }
    if ($actorNpcId !== null) { $where[] = 'e.actor_npc_id=:actor'; $params[':actor'] = $actorNpcId; }
    if ($targetNpcId !== null) { $where[] = 'e.target_npc_id=:target'; $params[':target'] = $targetNpcId; }
    $sql = 'SELECT e.event_id,e.world_id,e.source_key,e.event_type,e.actor_npc_id,e.target_npc_id,e.public_payload_json,e.visibility,e.occurred_at,e.expires_at,e.created_at,r.state AS receipt_state FROM town_world_events e LEFT JOIN town_world_event_receipts r ON r.event_id=e.event_id AND r.viewer_id=:viewer WHERE ' . implode(' AND ', $where) . ' ORDER BY e.event_id LIMIT ' . ($limit + 1);
    $params[':viewer'] = $viewerId;
    $query = $pdo->prepare($sql); $query->execute($params);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);
    $events = [];
    foreach ($rows as $row) $events[] = town_world_event_public($row, false, (string)($row['receipt_state'] ?? 'unread'));
    $nextCursor = $events === [] ? $cursor : (int)$events[array_key_last($events)]['event_id'];
    $unread = $pdo->prepare('SELECT COUNT(*) FROM town_world_events e LEFT JOIN town_world_event_receipts r ON r.event_id=e.event_id AND r.viewer_id=:viewer WHERE e.world_id=:world AND e.visibility=:visibility AND (e.expires_at IS NULL OR e.expires_at>CURRENT_TIMESTAMP) AND COALESCE(r.state,\'unread\')=\'unread\'');
    $unread->execute([':viewer' => $viewerId, ':world' => $params[':world'], ':visibility' => 'public']);
    return ['events' => $events, 'cursor' => $nextCursor, 'has_more' => $hasMore, 'unread_count' => (int)$unread->fetchColumn()];
}

function town_world_event_acknowledge(PDO $pdo, string $viewerId, array $eventIds, string $state, ?DateTimeImmutable $now = null): int
{
    if (!in_array($state, TOWN_WORLD_EVENT_RECEIPT_STATES, true) || $state === 'unread') throw new ApiError('invalid_receipt_state', 'The event receipt state is invalid.', 400);
    $now ??= api_clock();
    $ids = [];
    foreach ($eventIds as $value) { $id = filter_var($value, FILTER_VALIDATE_INT); if ($id !== false && $id > 0) $ids[] = (int)$id; }
    $ids = array_values(array_unique($ids));
    if ($ids === [] || count($ids) > 100) throw new ApiError('invalid_event_ids', 'Up to 100 event ids are required.', 400);
    $updated = 0;
    foreach ($ids as $id) {
        $exists = $pdo->prepare('SELECT 1 FROM town_world_events WHERE event_id=:event AND world_id=:world AND visibility=:visibility LIMIT 1');
        $exists->execute([':event' => $id, ':world' => defined('SHARED_TOWN_WORLD_ID') ? SHARED_TOWN_WORLD_ID : 'main', ':visibility' => 'public']);
        if ($exists->fetchColumn() === false) continue;
        $readAt = $state === 'read' ? $now->format('Y-m-d H:i:s') : null;
        $update = $pdo->prepare('UPDATE town_world_event_receipts SET state=:state,read_at=:read,updated_at=:updated WHERE event_id=:event AND viewer_id=:viewer');
        $update->execute([':state' => $state, ':read' => $readAt, ':updated' => $now->format('Y-m-d H:i:s'), ':event' => $id, ':viewer' => $viewerId]);
        if ($update->rowCount() === 0) {
            try {
                $insert = $pdo->prepare('INSERT INTO town_world_event_receipts (event_id,viewer_id,state,read_at,updated_at) VALUES (:event,:viewer,:state,:read,:updated)');
                $insert->execute([':event' => $id, ':viewer' => $viewerId, ':state' => $state, ':read' => $readAt, ':updated' => $now->format('Y-m-d H:i:s')]);
            } catch (PDOException $error) {
                $update->execute([':state' => $state, ':read' => $readAt, ':updated' => $now->format('Y-m-d H:i:s'), ':event' => $id, ':viewer' => $viewerId]);
            }
        }
        $updated++;
    }
    return $updated;
}

function town_interest_points_nearby(PDO $pdo, int $tileX, int $tileY, int $radius = 8, array $channels = [], int $observerHeight = 0): array
{
    $radius = max(1, min(64, $radius));
    $observerHeight = max(0, min(32, $observerHeight));
    $channels = array_values(array_intersect(TOWN_PERCEPTION_CHANNELS, array_values(array_filter($channels, 'is_string'))));
    $world = defined('SHARED_TOWN_WORLD_ID') ? SHARED_TOWN_WORLD_ID : 'main';
    $query = $pdo->prepare('SELECT interest_id,world_id,kind,asset_id,object_id,tile_x,tile_y,radius,perception_channels_json,activity_type,public_name,updated_at FROM town_interest_points WHERE world_id=:world AND enabled=1 AND tile_x BETWEEN :min_x AND :max_x AND tile_y BETWEEN :min_y AND :max_y');
    $query->execute([':world' => $world, ':min_x' => $tileX - $radius, ':max_x' => $tileX + $radius, ':min_y' => $tileY - $radius, ':max_y' => $tileY + $radius]);
    $result = [];
    $seenObjects = [];
    $append = static function (array $row) use (&$result, &$seenObjects, $pdo, $tileX, $tileY, $radius, $channels, $observerHeight): void {
        $objectId = $row['object_id'] !== null ? (string)$row['object_id'] : null;
        if ($objectId !== null) $seenObjects[$objectId] = true;
        $dx = (int)$row['tile_x'] - $tileX; $dy = (int)$row['tile_y'] - $tileY;
        $distance = max(abs($dx), abs($dy));
        if ($distance > $radius || $distance > (int)$row['radius']) return;
        $available = json_decode((string)$row['perception_channels_json'], true);
        $available = is_array($available) ? array_values(array_filter($available, 'is_string')) : [];
        $observedChannels = $channels === [] ? $available : array_values(array_intersect($channels, $available));
        if ($observedChannels === []) return;
        $visibility = null;
        if (in_array('visible', $observedChannels, true)) {
            $visibility = town_navigation_visibility_between($pdo, $tileX, $tileY, (int)$row['tile_x'], (int)$row['tile_y'], $observerHeight);
            if (!$visibility['visible']) $observedChannels = array_values(array_diff($observedChannels, ['visible']));
        }
        if ($observedChannels === []) return;
        $result[] = ['interest_id' => (string)$row['interest_id'], 'world_id' => (string)$row['world_id'], 'kind' => (string)$row['kind'], 'asset_id' => (string)$row['asset_id'], 'object_id' => $objectId, 'tile_x' => (int)$row['tile_x'], 'tile_y' => (int)$row['tile_y'], 'distance' => $distance, 'channels' => $observedChannels, 'visibility' => $visibility ?? ['visible' => true, 'reason' => 'channel_not_visual', 'navigation_version' => town_navigation_version($pdo), 'target_height' => 0], 'activity_type' => (string)$row['activity_type'], 'public_name' => (string)$row['public_name'], 'updated_at' => (string)$row['updated_at'], 'navigation_version' => $visibility['navigation_version'] ?? town_navigation_version($pdo)];
    };
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $append($row);
    }
    // A placed decoration or building is an interest point even when an
    // administrator has not created a separate semantic row yet. Explicit
    // rows win, so this projection never duplicates a configured point.
    try {
        $objects = $pdo->prepare('SELECT o.object_id,o.world_id,o.asset_id,o.tile_x,o.tile_y,COALESCE(i.category,\'decoration\') AS category,COALESCE(i.name,o.asset_id) AS asset_name FROM town_objects o LEFT JOIN town_asset_items i ON i.asset_id=o.asset_id WHERE o.world_id=:world AND o.deleted_at IS NULL AND o.tile_x BETWEEN :min_x AND :max_x AND o.tile_y BETWEEN :min_y AND :max_y');
        $objects->execute([':world' => $world, ':min_x' => $tileX - $radius, ':max_x' => $tileX + $radius, ':min_y' => $tileY - $radius, ':max_y' => $tileY + $radius]);
        foreach ($objects->fetchAll(PDO::FETCH_ASSOC) as $object) {
            $objectId = (string)($object['object_id'] ?? '');
            if ($objectId === '' || isset($seenObjects[$objectId])) continue;
            $append([
                'interest_id' => 'object-' . $objectId, 'world_id' => (string)$object['world_id'],
                'kind' => (string)$object['category'], 'asset_id' => (string)$object['asset_id'], 'object_id' => $objectId,
                'tile_x' => (int)$object['tile_x'], 'tile_y' => (int)$object['tile_y'], 'radius' => $radius,
                'perception_channels_json' => '["visible","nearby"]', 'activity_type' => (string)$object['category'],
                'public_name' => (string)$object['asset_name'], 'updated_at' => '',
            ]);
        }
    } catch (PDOException $error) { }
    usort($result, static fn(array $left, array $right): int => $left['distance'] <=> $right['distance'] ?: strcmp($left['interest_id'], $right['interest_id']));
    return $result;
}
