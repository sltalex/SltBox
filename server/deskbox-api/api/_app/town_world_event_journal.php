<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }

/**
 * Append an already validated world fact to the replay journal.
 * A missing table is tolerated during rolling deployment; once migration 053
 * is installed, every public event is journaled and retries are idempotent.
 */
function town_world_event_journal_append(PDO $pdo, string $worldId, string $eventType, array $payload, string $idempotencyKey, ?DateTimeImmutable $now = null): ?int
{
    $now ??= api_clock();
    $worldId = trim($worldId);
    $eventType = strtolower(trim($eventType));
    $idempotencyKey = trim($idempotencyKey);
    if ($worldId === '' || $eventType === '' || $idempotencyKey === '') return null;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    try {
        $find = $pdo->prepare('SELECT sequence FROM town_world_event_journal WHERE world_id=:world AND idempotency_key=:key LIMIT 1');
        $find->execute([':world' => $worldId, ':key' => substr($idempotencyKey, 0, 191)]);
        $existing = $find->fetchColumn();
        if ($existing !== false) return (int)$existing;
        $insert = $pdo->prepare('INSERT INTO town_world_event_journal (world_id,event_type,payload_json,schema_version,idempotency_key,created_at) VALUES (:world,:type,:payload,1,:key,:created)');
        $insert->execute([
            ':world' => $worldId,
            ':type' => substr($eventType, 0, 64),
            ':payload' => $json,
            ':key' => substr($idempotencyKey, 0, 191),
            ':created' => auth_sql_time($now),
        ]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $error) {
        // Allow old nodes to keep serving while migration 053 rolls out.
        $message = strtolower($error->getMessage());
        if (strpos($message, 'no such table') !== false || strpos($message, 'doesn\'t exist') !== false) return null;
        $find = $pdo->prepare('SELECT sequence FROM town_world_event_journal WHERE world_id=:world AND idempotency_key=:key LIMIT 1');
        $find->execute([':world' => $worldId, ':key' => substr($idempotencyKey, 0, 191)]);
        $existing = $find->fetchColumn();
        if ($existing !== false) return (int)$existing;
        throw $error;
    }
}

function town_world_event_journal_latest_sequence(PDO $pdo, string $worldId): int
{
    try {
        $query = $pdo->prepare('SELECT COALESCE(MAX(sequence),0) FROM town_world_event_journal WHERE world_id=:world');
        $query->execute([':world' => $worldId]);
        return (int)$query->fetchColumn();
    } catch (PDOException $error) {
        $message = strtolower($error->getMessage());
        if (strpos($message, 'no such table') !== false || strpos($message, 'doesn\'t exist') !== false) return 0;
        throw $error;
    }
}

/** Return bounded journal entries strictly after a snapshot cursor. */
function town_world_event_journal_since(PDO $pdo, string $worldId, int $afterSequence = 0, int $limit = 200): array
{
    if ($afterSequence < 0) throw new ApiError('invalid_journal_cursor', 'The journal cursor is invalid.', 400);
    $limit = max(1, min(500, $limit));
    $query = $pdo->prepare('SELECT sequence,world_id,event_type,payload_json,schema_version,idempotency_key,created_at FROM town_world_event_journal WHERE world_id=:world AND sequence>:after ORDER BY sequence LIMIT ' . ($limit + 1));
    $query->execute([':world' => $worldId, ':after' => $afterSequence]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);
    $events = [];
    foreach ($rows as $row) {
        $payload = json_decode((string)$row['payload_json'], true);
        $events[] = [
            'sequence' => (int)$row['sequence'],
            'world_id' => (string)$row['world_id'],
            'event_type' => (string)$row['event_type'],
            'payload' => is_array($payload) ? $payload : [],
            'schema_version' => (int)$row['schema_version'],
            'idempotency_key' => (string)$row['idempotency_key'],
            'created_at' => (string)$row['created_at'],
        ];
    }
    $next = $events === [] ? $afterSequence : (int)$events[array_key_last($events)]['sequence'];
    return ['events' => $events, 'cursor' => $next, 'has_more' => $hasMore];
}
