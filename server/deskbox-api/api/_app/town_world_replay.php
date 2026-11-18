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

/**
 * Restore an exact snapshot only when no journal entries need applying.
 * This deliberately refuses to guess how a future event type mutates state.
 * The operation runs in one transaction and creates a new recovery snapshot
 * before commit, so a failed post-check rolls back every production change.
 */
function town_world_snapshot_restore(PDO $pdo, string $snapshotId, string $confirmSha256, int $expectedWorldVersion, string $actor): array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $snapshotId)) throw new ApiError('invalid_snapshot', 'The snapshot identifier is invalid.', 400);
    if (!preg_match('/\A[a-f0-9]{64}\z/D', strtolower($confirmSha256))) throw new ApiError('invalid_snapshot_confirmation', 'The snapshot checksum confirmation is invalid.', 400);
    if ($expectedWorldVersion < 0) throw new ApiError('invalid_world_revision', 'The expected world revision is invalid.', 400);
    $report = town_world_replay_dry_run($pdo, $snapshotId, 500);
    $snapshot = $report['snapshot'] ?? [];
    if (!$report['ok']) throw new ApiError('recovery_blocked', 'Snapshot validation failed; no production state was changed.', 409);
    if (!hash_equals(strtolower($confirmSha256), strtolower((string)($snapshot['state_sha256'] ?? '')))) throw new ApiError('recovery_confirmation_mismatch', 'The snapshot checksum confirmation does not match.', 409);
    if (($report['journal']['event_count'] ?? 0) > 0 || ($report['journal']['has_more'] ?? false)) {
        throw new ApiError('recovery_requires_replay', 'Journal entries exist after this snapshot; apply adapters before restoring production state.', 409);
    }
    $state = is_array($snapshot['state'] ?? null) ? $snapshot['state'] : [];
    $worldState = is_array($state['world'] ?? null) ? $state['world'] : [];
    $objects = is_array($state['objects'] ?? null) ? $state['objects'] : [];
    $npcs = is_array($state['npcs']['npcs'] ?? null) ? $state['npcs']['npcs'] : [];
    $now = api_clock();
    $pdo->beginTransaction();
    try {
        $worldSql = 'SELECT world_id,world_version,change_cursor,navigation_version FROM town_worlds WHERE world_id=:world LIMIT 1';
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') $worldSql .= ' FOR UPDATE';
        $worldQuery = $pdo->prepare($worldSql); $worldQuery->execute([':world' => SHARED_TOWN_WORLD_ID]);
        $currentWorld = $worldQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($currentWorld)) throw new ApiError('town_unavailable', 'The shared town is not initialized.', 503);
        if ((int)$currentWorld['world_version'] !== $expectedWorldVersion) throw new ApiError('world_revision_conflict', 'The live world changed; read a fresh snapshot before restoring.', 409);
        $activeIds = [];
        foreach ($objects as $object) {
            if (!is_array($object)) throw new ApiError('invalid_snapshot_state', 'The snapshot contains an invalid object.', 409);
            $id = (string)($object['object_id'] ?? ''); $owner = (string)($object['owner_user_id'] ?? '');
            if (!preg_match('/\A[a-f0-9]{32}\z/D', $id) || !preg_match('/\A[a-f0-9]{32}\z/D', $owner)) throw new ApiError('invalid_snapshot_state', 'The snapshot contains an invalid object identity.', 409);
            $footprint = is_array($object['footprint'] ?? null) ? $object['footprint'] : ['width' => 1, 'height' => 1];
            $footprintJson = json_encode($footprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $exists = $pdo->prepare('SELECT object_id FROM town_objects WHERE object_id=:id LIMIT 1'); $exists->execute([':id' => $id]);
            $params = [':world' => SHARED_TOWN_WORLD_ID, ':asset' => substr((string)($object['asset_id'] ?? ''), 0, 128), ':owner' => $owner,
                ':chunk_x' => (int)($object['chunk_x'] ?? intdiv((int)($object['tile_x'] ?? 0), SHARED_TOWN_CHUNK_SIZE)), ':chunk_y' => (int)($object['chunk_y'] ?? intdiv((int)($object['tile_y'] ?? 0), SHARED_TOWN_CHUNK_SIZE)),
                ':tile_x' => (int)($object['tile_x'] ?? 0), ':tile_y' => (int)($object['tile_y'] ?? 0), ':footprint' => $footprintJson, ':layer' => max(1, min(6, (int)($object['layer'] ?? 1))), ':updated' => auth_sql_time($now), ':id' => $id];
            if ($exists->fetchColumn() !== false) {
                $update = $pdo->prepare('UPDATE town_objects SET world_id=:world,asset_id=:asset,owner_user_id=:owner,chunk_x=:chunk_x,chunk_y=:chunk_y,tile_x=:tile_x,tile_y=:tile_y,footprint_json=:footprint,layer=:layer,object_version=object_version+1,updated_at=:updated,deleted_at=NULL WHERE object_id=:id');
                $update->execute($params);
            } else {
                $insert = $pdo->prepare('INSERT INTO town_objects (object_id,world_id,asset_id,owner_user_id,chunk_x,chunk_y,tile_x,tile_y,footprint_json,layer,object_version,created_at,updated_at,deleted_at) VALUES (:id,:world,:asset,:owner,:chunk_x,:chunk_y,:tile_x,:tile_y,:footprint,:layer,1,:updated,:updated,NULL)');
                $insert->execute($params);
            }
            $activeIds[$id] = true;
        }
        if ($activeIds === []) {
            $pdo->prepare('UPDATE town_objects SET deleted_at=:deleted,updated_at=:updated WHERE world_id=:world AND deleted_at IS NULL')->execute([':deleted' => auth_sql_time($now), ':updated' => auth_sql_time($now), ':world' => SHARED_TOWN_WORLD_ID]);
        } else {
            $placeholders = implode(',', array_fill(0, count($activeIds), '?'));
            $params = array_merge([auth_sql_time($now), auth_sql_time($now), SHARED_TOWN_WORLD_ID], array_keys($activeIds));
            $pdo->prepare('UPDATE town_objects SET deleted_at=?,updated_at=? WHERE world_id=? AND deleted_at IS NULL AND object_id NOT IN (' . $placeholders . ')')->execute($params);
        }
        foreach ($npcs as $npc) {
            if (!is_array($npc)) continue;
            $npcId = (string)($npc['npc_id'] ?? ''); $runtime = is_array($npc['runtime'] ?? null) ? $npc['runtime'] : [];
            if (!preg_match('/\A[a-f0-9]{32}\z/D', $npcId)) throw new ApiError('invalid_snapshot_state', 'The snapshot contains an invalid NPC identity.', 409);
            $exists = $pdo->prepare('SELECT 1 FROM town_npcs WHERE npc_id=:npc LIMIT 1'); $exists->execute([':npc' => $npcId]);
            if (!$exists->fetchColumn()) throw new ApiError('snapshot_npc_missing', 'The snapshot references an NPC that no longer exists.', 409);
            $set = 'tile_x=:x,tile_y=:y,state=:state,current_task_id=NULL,dialogue=:dialogue,capabilities_json=:capabilities,state_version=state_version+1,updated_at=:updated';
            $params = [':x' => (int)($runtime['tile_x'] ?? 0), ':y' => (int)($runtime['tile_y'] ?? 0), ':state' => in_array((string)($runtime['state'] ?? 'rest'), ['walk','work','rest','talk','follow','player_talk'], true) ? (string)$runtime['state'] : 'rest', ':dialogue' => substr((string)($runtime['dialogue'] ?? ''), 0, 500), ':capabilities' => json_encode(is_array($runtime['capabilities'] ?? null) ? $runtime['capabilities'] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':updated' => auth_sql_time($now), ':npc' => $npcId];
            if (town_npc_runtime_height_column($pdo)) { $set .= ',height=:height'; $params[':height'] = max(0, min(32, (int)($runtime['height'] ?? 0))); }
            if (town_npc_life_column_exists($pdo, 'town_npc_runtime_states', 'mood_json')) { $set .= ',mood_json=:mood'; $params[':mood'] = json_encode(is_array($runtime['mood'] ?? null) ? $runtime['mood'] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
            $pdo->prepare('UPDATE town_npc_runtime_states SET ' . $set . ' WHERE npc_id=:npc')->execute($params);
        }
        $nextWorldVersion = (int)$currentWorld['world_version'] + 1;
        $nextChangeCursor = (int)$currentWorld['change_cursor'] + 1;
        $nextNavigationVersion = max((int)$currentWorld['navigation_version'], (int)($worldState['navigation_version'] ?? 1)) + 1;
        $pdo->prepare('UPDATE town_worlds SET world_version=:version,change_cursor=:change,navigation_version=:navigation,updated_at=:updated WHERE world_id=:world')->execute([':version' => $nextWorldVersion, ':change' => $nextChangeCursor, ':navigation' => $nextNavigationVersion, ':updated' => auth_sql_time($now), ':world' => SHARED_TOWN_WORLD_ID]);
        $newSnapshot = town_world_snapshot_create($pdo, substr($actor, 0, 128), $now);
        $pdo->commit();
        return ['ok' => true, 'mode' => 'restore', 'writes_applied' => true, 'source_snapshot_id' => $snapshotId, 'source_state_sha256' => strtolower($confirmSha256), 'world_version' => $nextWorldVersion, 'snapshot' => $newSnapshot];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
