<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__ . '/town_world_snapshots.php';

/**
 * Validate a recovery point and the journal entries after it without writing
 * to the live town. This is deliberately a report-only operation; the write
 * recovery executor will be added after this report is proven in production.
 */
function town_world_replay_dry_run(PDO $pdo, ?string $snapshotId = null, int $limit = 500): array
{
    $limit = max(1, min(500, $limit));
    $query = $snapshotId !== null && trim($snapshotId) !== ''
        ? $pdo->prepare('SELECT * FROM town_world_snapshots WHERE world_id=:world AND snapshot_id=:snapshot LIMIT 1')
        : $pdo->prepare('SELECT * FROM town_world_snapshots WHERE world_id=:world ORDER BY created_at DESC,snapshot_id DESC LIMIT 1');
    $params = [':world' => SHARED_TOWN_WORLD_ID];
    if ($snapshotId !== null && trim($snapshotId) !== '') $params[':snapshot'] = trim($snapshotId);
    $query->execute($params);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new ApiError('snapshot_not_found', 'The requested world snapshot was not found.', 404);

    $errors = [];
    $warnings = [];
    $snapshot = null;
    try {
        $snapshot = town_world_snapshot_public($row, null, true);
    } catch (Throwable $error) {
        $errors[] = ['code' => 'snapshot_invalid', 'message' => $error->getMessage()];
    }
    $eventCursor = (int)($row['event_cursor'] ?? 0);
    $events = [];
    $hasMore = false;
    if ($snapshot !== null) {
        $page = town_world_event_journal_since($pdo, SHARED_TOWN_WORLD_ID, $eventCursor, $limit);
        $events = $page['events'];
        $hasMore = (bool)$page['has_more'];
        if ($hasMore) $warnings[] = ['code' => 'journal_truncated', 'message' => 'The report is limited; run again with a later cursor or a larger bounded page.'];
    }

    $lastSequence = $eventCursor;
    $keys = [];
    $eventIds = [];
    $knownTypes = ['public_event'];
    foreach ($events as $event) {
        $sequence = (int)($event['sequence'] ?? 0);
        $key = (string)($event['idempotency_key'] ?? '');
        $type = (string)($event['event_type'] ?? '');
        $payload = $event['payload'] ?? null;
        if ($sequence <= $lastSequence) $errors[] = ['code' => 'journal_order', 'sequence' => $sequence, 'message' => 'Journal sequence is not strictly increasing after the snapshot cursor.'];
        $lastSequence = max($lastSequence, $sequence);
        if ($key === '' || isset($keys[$key])) $errors[] = ['code' => 'journal_idempotency', 'sequence' => $sequence, 'message' => 'Journal idempotency keys must be present and unique in the replay window.'];
        $keys[$key] = true;
        if (!in_array($type, $knownTypes, true)) $warnings[] = ['code' => 'unknown_event_type', 'sequence' => $sequence, 'event_type' => $type, 'message' => 'The event type is preserved but has no replay adapter in this version.'];
        if (!is_array($payload) || array_is_list($payload)) {
            $errors[] = ['code' => 'event_payload', 'sequence' => $sequence, 'message' => 'Journal payload must be a JSON object.'];
            continue;
        }
        if ($type !== 'public_event') continue;
        $eventId = filter_var($payload['event_id'] ?? null, FILTER_VALIDATE_INT);
        if ($eventId === false || (int)$eventId < 1) {
            $errors[] = ['code' => 'public_event_id', 'sequence' => $sequence, 'message' => 'Public event journal entries require a positive event_id.'];
            continue;
        }
        if (isset($eventIds[(int)$eventId])) $errors[] = ['code' => 'public_event_duplicate', 'sequence' => $sequence, 'message' => 'A public event appears more than once in the replay window.'];
        $eventIds[(int)$eventId] = true;
        $sourceKey = trim((string)($payload['source_key'] ?? ''));
        if ($sourceKey === '') $errors[] = ['code' => 'public_event_source', 'sequence' => $sequence, 'message' => 'Public event journal entries require a source_key.'];
        try {
            $check = $pdo->prepare('SELECT event_id,event_type,source_key,public_payload_json FROM town_world_events WHERE world_id=:world AND event_id=:event LIMIT 1');
            $check->execute([':world' => SHARED_TOWN_WORLD_ID, ':event' => (int)$eventId]);
            $stored = $check->fetch(PDO::FETCH_ASSOC);
            if (!is_array($stored)) {
                $errors[] = ['code' => 'public_event_missing', 'sequence' => $sequence, 'event_id' => (int)$eventId, 'message' => 'The journal references a public event that is missing from the authoritative event table.'];
            } else {
                if ((string)$stored['source_key'] !== $sourceKey) $errors[] = ['code' => 'public_event_source_mismatch', 'sequence' => $sequence, 'event_id' => (int)$eventId, 'message' => 'Journal source_key does not match the authoritative public event.'];
                if ((string)$stored['event_type'] !== (string)($payload['event_type'] ?? '')) $errors[] = ['code' => 'public_event_type_mismatch', 'sequence' => $sequence, 'event_id' => (int)$eventId, 'message' => 'Journal event_type does not match the authoritative public event.'];
            }
        } catch (PDOException $error) {
            $warnings[] = ['code' => 'event_table_unavailable', 'sequence' => $sequence, 'message' => 'The public event table could not be checked during this report.'];
        }
    }
    return [
        'ok' => $errors === [],
        'mode' => 'dry_run',
        'writes_applied' => false,
        'snapshot' => $snapshot === null ? ['snapshot_id' => (string)($row['snapshot_id'] ?? ''), 'state_sha256' => (string)($row['state_sha256'] ?? ''), 'event_cursor' => $eventCursor] : $snapshot,
        'journal' => ['from_sequence' => $eventCursor, 'to_sequence' => $lastSequence, 'event_count' => count($events), 'has_more' => $hasMore],
        'errors' => $errors,
        'warnings' => $warnings,
    ];
}
