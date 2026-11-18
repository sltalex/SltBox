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
