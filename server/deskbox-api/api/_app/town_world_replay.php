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
    $replayable = true;
    $replayBlockers = [];
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
        if (!in_array($type, $knownTypes, true)) {
            $warnings[] = ['code' => 'unknown_event_type', 'sequence' => $sequence, 'event_type' => $type, 'message' => 'The event type is preserved but has no replay adapter in this version.'];
            $replayable = false; $replayBlockers[] = ['sequence' => $sequence, 'event_type' => $type];
        }
        if (!is_array($payload) || array_is_list($payload)) {
            $errors[] = ['code' => 'event_payload', 'sequence' => $sequence, 'message' => 'Journal payload must be a JSON object.'];
            $replayable = false; $replayBlockers[] = ['sequence' => $sequence, 'event_type' => $type];
            continue;
        }
        if ($type !== 'public_event') continue;
        $innerType = strtolower(trim((string)($payload['event_type'] ?? '')));
        $tradeReplay = $innerType === 'npc_task_completed'
            && is_array($payload['payload']['trade'] ?? null)
            && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['payload']['trade']['seller_npc_id'] ?? ''))
            && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['payload']['trade']['buyer_npc_id'] ?? ''))
            && preg_match('/\A[a-zA-Z0-9._:-]{1,128}\z/D', (string)($payload['payload']['trade']['item_id'] ?? ''))
            && filter_var($payload['payload']['trade']['quantity'] ?? null, FILTER_VALIDATE_INT) !== false
            && filter_var($payload['payload']['trade']['amount'] ?? null, FILTER_VALIDATE_INT) !== false;
        $workReplay = $innerType === 'npc_task_replay_delta'
            && is_array($payload['payload']['work_result'] ?? null)
            && is_array($payload['payload']['work_point'] ?? null)
            && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['payload']['task_id'] ?? ''));
        $npcRestReplay = $innerType === 'npc_task_completed'
            && strtolower(trim((string)($payload['payload']['action'] ?? ''))) === 'rest'
            && strtolower(trim((string)($payload['payload']['task_status'] ?? ''))) === 'completed'
            && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['payload']['task_id'] ?? ''))
            && filter_var($payload['payload']['tile_x'] ?? null, FILTER_VALIDATE_INT) !== false
            && filter_var($payload['payload']['tile_y'] ?? null, FILTER_VALIDATE_INT) !== false;
        $npcWalkReplay = $innerType === 'npc_task_completed'
            && strtolower(trim((string)($payload['payload']['action'] ?? ''))) === 'walk'
            && strtolower(trim((string)($payload['payload']['task_status'] ?? ''))) === 'completed'
            && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['payload']['task_id'] ?? ''))
            && filter_var($payload['payload']['tile_x'] ?? null, FILTER_VALIDATE_INT) !== false
            && filter_var($payload['payload']['tile_y'] ?? null, FILTER_VALIDATE_INT) !== false;
        // Work completion carries the NPC's final position. Its durable
        // world effect is replayed separately by npc_task_replay_delta.
        $npcWorkReplay = $innerType === 'npc_task_completed'
            && strtolower(trim((string)($payload['payload']['action'] ?? ''))) === 'work'
            && strtolower(trim((string)($payload['payload']['task_status'] ?? ''))) === 'completed'
            && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['payload']['task_id'] ?? ''))
            && filter_var($payload['payload']['tile_x'] ?? null, FILTER_VALIDATE_INT) !== false
            && filter_var($payload['payload']['tile_y'] ?? null, FILTER_VALIDATE_INT) !== false;
        $npcFailureReplay = $innerType === 'npc_task_failed'
            && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['payload']['task_id'] ?? ''));
        $socialCommitmentState = strpos($innerType, 'social_matter_commitment_') === 0 ? substr($innerType, strlen('social_matter_commitment_')) : '';
        $socialCommitmentReplay = in_array($socialCommitmentState, ['assigned', 'executing', 'completed', 'failed', 'cancelled'], true)
            && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['payload']['matter_id'] ?? ''))
            && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['payload']['commitment_id'] ?? ''))
            && filter_var($payload['payload']['revision'] ?? null, FILTER_VALIDATE_INT) !== false;
        $socialMatterState = strpos($innerType, 'social_matter_') === 0 ? substr($innerType, strlen('social_matter_')) : '';
        $socialMatterReplay = in_array($socialMatterState, ['closed', 'failed', 'cancelled'], true)
            && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['payload']['matter_id'] ?? ''))
            && filter_var($payload['payload']['revision'] ?? null, FILTER_VALIDATE_INT) !== false;
        $socialMatterOpenedReplay = $innerType === 'social_matter_opened'
            && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['payload']['matter_id'] ?? ''))
            && preg_match('/\A[a-zA-Z0-9._:-]{1,64}\z/D', (string)($payload['payload']['kind'] ?? ''))
            && is_scalar($payload['payload']['summary'] ?? null)
            && filter_var($payload['payload']['revision'] ?? null, FILTER_VALIDATE_INT) !== false;
        $socialMatterResponseReplay = $innerType === 'social_matter_response'
            && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['payload']['matter_id'] ?? ''))
            && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['payload']['resident_id'] ?? ''))
            && preg_match('/\A[a-zA-Z0-9._:-]{1,64}\z/D', (string)($payload['payload']['option_id'] ?? ''))
            && preg_match('/\A[a-zA-Z0-9._:-]{1,32}\z/D', (string)($payload['payload']['response_kind'] ?? 'support'))
            && filter_var($payload['payload']['revision'] ?? null, FILTER_VALIDATE_INT) !== false;
        if (!in_array($innerType, ['time_advanced', 'weather_changed'], true) && !$npcRestReplay && !$npcWalkReplay && !$npcWorkReplay && !$npcFailureReplay && !$tradeReplay && !$workReplay && !$socialCommitmentReplay && !$socialMatterReplay && !$socialMatterOpenedReplay && !$socialMatterResponseReplay) {
            $replayable = false; $replayBlockers[] = ['sequence' => $sequence, 'event_type' => $innerType ?: 'missing'];
        }
        $eventId = filter_var($payload['event_id'] ?? null, FILTER_VALIDATE_INT);
        if ($eventId === false || (int)$eventId < 1) {
            $errors[] = ['code' => 'public_event_id', 'sequence' => $sequence, 'message' => 'Public event journal entries require a positive event_id.'];
            $replayable = false;
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
        'replayable' => $replayable,
        'replay_blockers' => $replayBlockers,
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
    if (!($report['replayable'] ?? false)) throw new ApiError('recovery_requires_adapter', 'The snapshot journal contains event types without deterministic recovery adapters.', 409);
    if (!hash_equals(strtolower($confirmSha256), strtolower((string)($snapshot['state_sha256'] ?? '')))) throw new ApiError('recovery_confirmation_mismatch', 'The snapshot checksum confirmation does not match.', 409);
    $eventCursor = (int)($snapshot['event_cursor'] ?? 0);
    $journal = town_world_event_journal_since($pdo, SHARED_TOWN_WORLD_ID, $eventCursor, 500);
    if ($journal['has_more']) throw new ApiError('recovery_requires_replay', 'The recovery window is larger than the bounded replay batch.', 409);
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
        town_world_replay_restore_clock($pdo, is_array($state['clock'] ?? null) ? $state['clock'] : [], $now);
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
        foreach ($journal['events'] as $event) town_world_replay_apply_event($pdo, $event, $now);
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

/** Restore the snapshot's clock before applying ordered clock events. */
function town_world_replay_restore_clock(PDO $pdo, array $clock, DateTimeImmutable $now): void
{
    if ($clock === []) return;
    $query = $pdo->prepare('SELECT world_id FROM town_world_state WHERE world_id=:world LIMIT 1');
    try { $query->execute([':world' => SHARED_TOWN_WORLD_ID]); }
    catch (PDOException $error) { throw new ApiError('recovery_clock_unavailable', 'The world clock table is unavailable; recovery was rolled back.', 409); }
    if ($query->fetchColumn() === false) throw new ApiError('recovery_clock_unavailable', 'The world clock is not initialized; recovery was rolled back.', 409);
    $weather = strtolower(trim((string)($clock['weather_code'] ?? 'clear')));
    if (!in_array($weather, ['clear', 'cloudy', 'rain', 'storm', 'snow', 'fog'], true)) throw new ApiError('invalid_snapshot_state', 'The snapshot contains an invalid weather code.', 409);
    $started = (string)($clock['weather_started_at'] ?? $now->format('Y-m-d H:i:s'));
    $next = $clock['next_weather_change_at'] !== null ? (string)$clock['next_weather_change_at'] : null;
    $update = $pdo->prepare('UPDATE town_world_state SET absolute_minute=:absolute,calendar_day=:day,weather_code=:weather,weather_started_at=:started,next_weather_change_at=:next,revision=:revision,updated_at=:updated WHERE world_id=:world');
    $update->execute([':absolute' => max(0, (int)($clock['absolute_minute'] ?? 0)), ':day' => max(1, (int)($clock['calendar_day'] ?? 1)), ':weather' => $weather, ':started' => $started, ':next' => $next, ':revision' => max(0, (int)($clock['revision'] ?? 0)), ':updated' => auth_sql_time($now), ':world' => SHARED_TOWN_WORLD_ID]);
}

/** Apply one public journal record only when this version has a deterministic adapter. */
function town_world_replay_apply_event(PDO $pdo, array $event, DateTimeImmutable $now): void
{
    if ((string)($event['event_type'] ?? '') !== 'public_event') throw new ApiError('recovery_requires_adapter', 'The journal event has no public-event adapter.', 409);
    $outer = is_array($event['payload'] ?? null) ? $event['payload'] : [];
    $eventId = filter_var($outer['event_id'] ?? null, FILTER_VALIDATE_INT);
    $type = strtolower(trim((string)($outer['event_type'] ?? '')));
    $sourceKey = trim((string)($outer['source_key'] ?? ''));
    $payload = is_array($outer['payload'] ?? null) ? $outer['payload'] : [];
    $trade = is_array($payload['trade'] ?? null) ? $payload['trade'] : [];
    $npcTradeReplay = $type === 'npc_task_completed'
        && preg_match('/\A[a-f0-9]{32}\z/D', (string)($trade['seller_npc_id'] ?? ''))
        && preg_match('/\A[a-f0-9]{32}\z/D', (string)($trade['buyer_npc_id'] ?? ''))
        && preg_match('/\A[a-zA-Z0-9._:-]{1,128}\z/D', (string)($trade['item_id'] ?? ''))
        && filter_var($trade['quantity'] ?? null, FILTER_VALIDATE_INT) !== false
        && filter_var($trade['amount'] ?? null, FILTER_VALIDATE_INT) !== false;
    $workReplay = $type === 'npc_task_replay_delta'
        && is_array($payload['work_result'] ?? null)
        && is_array($payload['work_point'] ?? null)
        && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['task_id'] ?? ''));
    $npcTaskRest = $type === 'npc_task_completed'
        && strtolower(trim((string)($payload['action'] ?? ''))) === 'rest'
        && strtolower(trim((string)($payload['task_status'] ?? ''))) === 'completed'
        && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['task_id'] ?? ''))
        && filter_var($payload['tile_x'] ?? null, FILTER_VALIDATE_INT) !== false
        && filter_var($payload['tile_y'] ?? null, FILTER_VALIDATE_INT) !== false;
    $npcTaskWalk = $type === 'npc_task_completed'
        && strtolower(trim((string)($payload['action'] ?? ''))) === 'walk'
        && strtolower(trim((string)($payload['task_status'] ?? ''))) === 'completed'
        && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['task_id'] ?? ''))
        && filter_var($payload['tile_x'] ?? null, FILTER_VALIDATE_INT) !== false
        && filter_var($payload['tile_y'] ?? null, FILTER_VALIDATE_INT) !== false;
    // Work completion only restores the NPC runtime position. The associated
    // farm/market/workshop mutation is carried by a following replay delta.
    $npcTaskWork = $type === 'npc_task_completed'
        && strtolower(trim((string)($payload['action'] ?? ''))) === 'work'
        && strtolower(trim((string)($payload['task_status'] ?? ''))) === 'completed'
        && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['task_id'] ?? ''))
        && filter_var($payload['tile_x'] ?? null, FILTER_VALIDATE_INT) !== false
        && filter_var($payload['tile_y'] ?? null, FILTER_VALIDATE_INT) !== false;
    $npcTaskFailure = $type === 'npc_task_failed'
        && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['task_id'] ?? ''));
    $socialCommitmentStates = ['assigned', 'executing', 'completed', 'failed', 'cancelled'];
    $socialCommitmentState = str_starts_with($type, 'social_matter_commitment_') ? substr($type, strlen('social_matter_commitment_')) : '';
    $socialCommitmentReplay = in_array($socialCommitmentState, $socialCommitmentStates, true)
        && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['matter_id'] ?? ''))
        && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['commitment_id'] ?? ''))
        && filter_var($payload['revision'] ?? null, FILTER_VALIDATE_INT) !== false;
    $socialMatterState = str_starts_with($type, 'social_matter_') ? substr($type, strlen('social_matter_')) : '';
    $socialMatterReplay = in_array($socialMatterState, ['closed', 'failed', 'cancelled'], true)
        && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['matter_id'] ?? ''))
        && filter_var($payload['revision'] ?? null, FILTER_VALIDATE_INT) !== false;
    $socialMatterOpenedReplay = $type === 'social_matter_opened'
        && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['matter_id'] ?? ''))
        && preg_match('/\A[a-zA-Z0-9._:-]{1,64}\z/D', (string)($payload['kind'] ?? ''))
        && is_scalar($payload['summary'] ?? null)
        && filter_var($payload['revision'] ?? null, FILTER_VALIDATE_INT) !== false;
    $socialMatterResponseReplay = $type === 'social_matter_response'
        && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['matter_id'] ?? ''))
        && preg_match('/\A[a-f0-9]{32}\z/D', (string)($payload['resident_id'] ?? ''))
        && preg_match('/\A[a-zA-Z0-9._:-]{1,64}\z/D', (string)($payload['option_id'] ?? ''))
        && preg_match('/\A[a-zA-Z0-9._:-]{1,32}\z/D', (string)($payload['response_kind'] ?? 'support'))
        && filter_var($payload['revision'] ?? null, FILTER_VALIDATE_INT) !== false;
    if ($eventId === false || (int)$eventId < 1 || $sourceKey === '' || (!in_array($type, ['time_advanced', 'weather_changed'], true) && !$npcTaskRest && !$npcTaskWalk && !$npcTaskWork && !$npcTaskFailure && !$npcTradeReplay && !$workReplay && !$socialCommitmentReplay && !$socialMatterReplay && !$socialMatterOpenedReplay && !$socialMatterResponseReplay)) throw new ApiError('recovery_requires_adapter', 'The journal contains an event type without a deterministic recovery adapter.', 409);
    $find = $pdo->prepare('SELECT event_id FROM town_world_events WHERE world_id=:world AND event_id=:event LIMIT 1');
    $find->execute([':world' => SHARED_TOWN_WORLD_ID, ':event' => (int)$eventId]);
    if ($find->fetchColumn() === false) {
        $created = (string)($event['created_at'] ?? $now->format('Y-m-d H:i:s'));
        $insert = $pdo->prepare('INSERT INTO town_world_events (event_id,world_id,source_key,event_type,actor_npc_id,target_npc_id,public_payload_json,visibility,occurred_at,expires_at,created_at) VALUES (:id,:world,:source,:type,NULL,NULL,:payload,\'public\',:occurred,NULL,:created)');
        $insert->execute([':id' => (int)$eventId, ':world' => SHARED_TOWN_WORLD_ID, ':source' => $sourceKey, ':type' => $type, ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':occurred' => $created, ':created' => $created]);
    }
    if ($npcTaskRest || $npcTaskWalk || $npcTaskWork || $npcTaskFailure) {
        try {
            $actorQuery = $pdo->prepare('SELECT actor_npc_id FROM town_world_events WHERE world_id=:world AND event_id=:event LIMIT 1');
            $actorQuery->execute([':world' => SHARED_TOWN_WORLD_ID, ':event' => (int)$eventId]);
            $actor = (string)($actorQuery->fetchColumn() ?: '');
        } catch (PDOException $error) { $actor = ''; }
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $actor)) throw new ApiError('recovery_event_invalid', 'The NPC task event has no valid actor.', 409);
        if (!$npcTaskFailure) {
            $runtime = $pdo->prepare('UPDATE town_npc_runtime_states SET tile_x=:x,tile_y=:y,state=\'rest\',current_task_id=NULL,dialogue=\'\',state_version=state_version+1,updated_at=:updated WHERE npc_id=:npc');
            $runtime->execute([':x' => (int)$payload['tile_x'], ':y' => (int)$payload['tile_y'], ':updated' => auth_sql_time($now), ':npc' => $actor]);
            if ($runtime->rowCount() < 1) throw new ApiError('recovery_event_invalid', 'The NPC runtime state is missing for a task event.', 409);
        } else {
            $runtime = $pdo->prepare('UPDATE town_npc_runtime_states SET state=\'rest\',current_task_id=NULL,dialogue=\'\',state_version=state_version+1,updated_at=:updated WHERE npc_id=:npc');
            $runtime->execute([':updated' => auth_sql_time($now), ':npc' => $actor]);
        }
        try {
            $status = $npcTaskFailure ? 'failed' : 'completed';
            $task = $pdo->prepare('UPDATE town_npc_tasks SET status=:status,completed_at=:completed,failure_reason=:reason WHERE task_id=:task AND npc_id=:npc');
            $task->execute([':status' => $status, ':completed' => (string)($event['created_at'] ?? auth_sql_time($now)), ':reason' => $npcTaskFailure ? substr((string)($payload['failure_reason'] ?? $payload['reason'] ?? 'replayed_failure'), 0, 255) : '', ':task' => (string)$payload['task_id'], ':npc' => $actor]);
        } catch (PDOException $error) { /* Task history is optional on older rolling nodes. */ }
        return;
    }
    if ($npcTradeReplay) {
        $transactionId = trim((string)($trade['transaction_id'] ?? ''));
        if ($transactionId !== '') {
            $existingTrade = $pdo->prepare('SELECT transaction_id FROM town_npc_transactions WHERE transaction_id=:id LIMIT 1');
            $existingTrade->execute([':id' => $transactionId]);
            if ($existingTrade->fetchColumn() !== false) return;
        }
        $result = town_npc_trade($pdo, (string)$trade['seller_npc_id'], (string)$trade['buyer_npc_id'], (string)$trade['item_id'], (int)$trade['quantity'], (int)$trade['amount'], (string)($trade['reason'] ?? '重放 NPC 交易'), $now);
        if ($transactionId !== '' && (string)($result['transaction_id'] ?? '') !== $transactionId) throw new ApiError('recovery_event_invalid', 'The replayed trade transaction id does not match the journal.', 409);
        return;
    }
    if ($workReplay) {
        $actorQuery = $pdo->prepare('SELECT actor_npc_id FROM town_world_events WHERE world_id=:world AND event_id=:event LIMIT 1');
        $actorQuery->execute([':world' => SHARED_TOWN_WORLD_ID, ':event' => (int)$eventId]);
        $actor = (string)($actorQuery->fetchColumn() ?: '');
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $actor)) throw new ApiError('recovery_event_invalid', 'The work replay event has no valid actor.', 409);
        $workRunId = trim((string)($payload['work_run_id'] ?? ''));
        if ($workRunId !== '') {
            try { $exists = $pdo->prepare('SELECT 1 FROM town_work_runs WHERE run_id=:run LIMIT 1'); $exists->execute([':run' => $workRunId]); if ($exists->fetchColumn() !== false) return; } catch (PDOException $error) { }
        }
        $pointId = trim((string)($payload['work_point']['point_id'] ?? ''));
        $point = null;
        try {
            $pointQuery = $pdo->prepare('SELECT point_id,point_key,name,kind,tile_x,tile_y,interaction_json,result_json FROM town_work_points WHERE point_id=:point LIMIT 1');
            $pointQuery->execute([':point' => $pointId]); $row = $pointQuery->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) $point = ['point_id' => (string)$row['point_id'], 'point_key' => (string)$row['point_key'], 'name' => (string)$row['name'], 'kind' => (string)$row['kind'], 'tile_x' => (int)$row['tile_x'], 'tile_y' => (int)$row['tile_y'], 'interaction' => town_npc_decode($row['interaction_json'] ?? [], []), 'result' => town_npc_decode($row['result_json'] ?? [], [])];
        } catch (PDOException $error) { }
        if (!is_array($point)) throw new ApiError('recovery_event_invalid', 'The work point is missing for a replay event.', 409);
        $workResult = is_array($payload['work_result'] ?? null) ? $payload['work_result'] : [];
        $effect = is_array($workResult['world_effect'] ?? null) ? $workResult['world_effect'] : [];
        if ((string)($effect['effect'] ?? '') === 'water_crop') town_npc_apply_farm_result($pdo, $point, $effect + ['effect' => 'water_crop'], $now);
        elseif ((string)($effect['effect'] ?? '') === 'restock_market') town_npc_apply_market_result($pdo, $point, $effect + ['effect' => 'restock_market'], $now);
        elseif ((string)($effect['effect'] ?? '') === 'craft_item') {
            $outputId = trim((string)($effect['output_id'] ?? ''));
            if ($outputId !== '') {
                $insert = $pdo->prepare('INSERT INTO town_workshop_outputs (output_id,world_id,point_id,npc_id,task_id,item_id,quantity,produced_at) VALUES (:output,:world,:point,:npc,:task,:item,:quantity,:produced)');
                try { $insert->execute([':output' => $outputId, ':world' => SHARED_TOWN_WORLD_ID, ':point' => $pointId, ':npc' => $actor, ':task' => (string)$payload['task_id'], ':item' => (string)($effect['item_id'] ?? 'crafted_item'), ':quantity' => max(1, (int)($effect['quantity'] ?? 1)), ':produced' => (string)($effect['produced_at'] ?? auth_sql_time($now))]); } catch (PDOException $error) { }
            }
        }
        $income = max(0, (int)($workResult['income'] ?? 0));
        if ($income > 0) town_npc_record_income($pdo, $actor, $income, '重放工作收入', $now);
        if ($workRunId !== '') {
            try { $insertRun = $pdo->prepare('INSERT INTO town_work_runs (run_id,point_id,npc_id,task_id,job,status,result_json,started_at,completed_at) VALUES (:run,:point,:npc,:task,:job,\'completed\',:result,:started,:completed)'); $insertRun->execute([':run' => $workRunId, ':point' => $pointId, ':npc' => $actor, ':task' => (string)$payload['task_id'], ':job' => (string)($payload['job'] ?? ''), ':result' => json_encode($workResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':started' => (string)($event['created_at'] ?? auth_sql_time($now)), ':completed' => (string)($event['created_at'] ?? auth_sql_time($now))]); } catch (PDOException $error) { }
        }
        return;
    }
    if ($socialMatterOpenedReplay || $socialMatterResponseReplay || $socialCommitmentReplay || $socialMatterReplay) {
        $stamp = (string)($event['created_at'] ?? auth_sql_time($now));
        if ($socialMatterOpenedReplay) {
            $matter = $pdo->prepare('SELECT matter_id,state,revision FROM town_social_matters WHERE matter_id=:matter LIMIT 1');
            $matter->execute([':matter' => (string)$payload['matter_id']]);
            $row = $matter->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) throw new ApiError('recovery_event_invalid', 'The social matter is missing for an open event.', 409);
            $pdo->prepare('UPDATE town_social_matters SET kind=:kind,public_summary=:summary,state=CASE WHEN state IN (\'closed\',\'failed\',\'cancelled\') THEN state ELSE \'open\' END,revision=CASE WHEN revision<:revision THEN :revision ELSE revision END,updated_at=:updated WHERE matter_id=:matter')->execute([':kind' => substr((string)$payload['kind'], 0, 32), ':summary' => substr((string)$payload['summary'], 0, 255), ':revision' => (int)$payload['revision'], ':updated' => $stamp, ':matter' => (string)$payload['matter_id']]);
            return;
        }
        if ($socialMatterResponseReplay) {
            $matter = $pdo->prepare('SELECT state FROM town_social_matters WHERE matter_id=:matter LIMIT 1');
            $matter->execute([':matter' => (string)$payload['matter_id']]);
            if ($matter->fetchColumn() === false) throw new ApiError('recovery_event_invalid', 'The social matter is missing for a response event.', 409);
            $idempotency = 'replay:' . (int)$eventId;
            try {
                $insert = $pdo->prepare('INSERT INTO town_social_responses (response_id,matter_id,resident_id,option_id,response_kind,public_text,idempotency_key,created_at) VALUES (:id,:matter,:resident,:option,:kind,:text,:key,:created)');
                $insert->execute([':id' => md5(SHARED_TOWN_WORLD_ID . ':' . (int)$eventId), ':matter' => (string)$payload['matter_id'], ':resident' => (string)$payload['resident_id'], ':option' => (string)$payload['option_id'], ':kind' => (string)($payload['response_kind'] ?? 'support'), ':text' => substr((string)($payload['public_text'] ?? ''), 0, 255), ':key' => $idempotency, ':created' => $stamp]);
            } catch (PDOException $error) {
                $existing = $pdo->prepare('SELECT option_id FROM town_social_responses WHERE matter_id=:matter AND resident_id=:resident LIMIT 1');
                $existing->execute([':matter' => (string)$payload['matter_id'], ':resident' => (string)$payload['resident_id']]);
                if ((string)$existing->fetchColumn() !== (string)$payload['option_id']) throw new ApiError('recovery_event_conflict', 'The resident already has a different social response.', 409);
            }
            $pdo->prepare('UPDATE town_social_matters SET state=CASE WHEN state=\'open\' THEN \'collecting\' ELSE state END,revision=CASE WHEN revision<:revision THEN :revision ELSE revision END,updated_at=:updated WHERE matter_id=:matter')->execute([':revision' => (int)$payload['revision'], ':updated' => $stamp, ':matter' => (string)$payload['matter_id']]);
            return;
        }
        if ($socialCommitmentReplay) {
            $commitment = $pdo->prepare('SELECT commitment_id,matter_id,state FROM town_social_commitments WHERE commitment_id=:commitment AND matter_id=:matter LIMIT 1');
            $commitment->execute([':commitment' => (string)$payload['commitment_id'], ':matter' => (string)$payload['matter_id']]);
            $row = $commitment->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) throw new ApiError('recovery_event_invalid', 'The social commitment is missing for a replay event.', 409);
            $current = (string)$row['state'];
            if (in_array($current, ['completed', 'failed', 'cancelled'], true)) {
                if ($current === $socialCommitmentState) return;
                throw new ApiError('recovery_event_conflict', 'The social commitment already has a different terminal state.', 409);
            }
            $terminal = in_array($socialCommitmentState, ['completed', 'failed', 'cancelled'], true);
            $update = $pdo->prepare('UPDATE town_social_commitments SET state=:state,failure_reason=:reason,updated_at=:updated,completed_at=:completed WHERE commitment_id=:commitment AND state IN (\'assigned\',\'executing\')');
            $update->execute([':state' => $socialCommitmentState, ':reason' => substr((string)($payload['failure_reason'] ?? ''), 0, 255), ':updated' => $stamp, ':completed' => $terminal ? $stamp : null, ':commitment' => (string)$payload['commitment_id']]);
            if ($update->rowCount() < 1) return;
            $pending = $pdo->prepare("SELECT COUNT(*) FROM town_social_commitments WHERE matter_id=:matter AND state IN ('assigned','executing')");
            $pending->execute([':matter' => (string)$payload['matter_id']]);
            $matterState = null;
            if ($terminal && (int)$pending->fetchColumn() === 0) {
                $failed = $pdo->prepare("SELECT COUNT(*) FROM town_social_commitments WHERE matter_id=:matter AND state='failed'"); $failed->execute([':matter' => (string)$payload['matter_id']]);
                $cancelled = $pdo->prepare("SELECT COUNT(*) FROM town_social_commitments WHERE matter_id=:matter AND state='cancelled'"); $cancelled->execute([':matter' => (string)$payload['matter_id']]);
                $matterState = (int)$failed->fetchColumn() > 0 ? 'failed' : ((int)$cancelled->fetchColumn() > 0 ? 'cancelled' : 'closed');
            }
            $sql = 'UPDATE town_social_matters SET revision=:revision,updated_at=:updated'; $params = [':revision' => (int)$payload['revision'], ':updated' => $stamp, ':matter' => (string)$payload['matter_id']];
            if ($matterState !== null) { $sql .= ',state=:state,closed_at=:closed,failure_reason=:matter_reason'; $params[':state'] = $matterState; $params[':closed'] = $stamp; $params[':matter_reason'] = substr((string)($payload['failure_reason'] ?? ''), 0, 255); }
            $sql .= ' WHERE matter_id=:matter'; $pdo->prepare($sql)->execute($params);
            return;
        }
        $matter = $pdo->prepare('SELECT state FROM town_social_matters WHERE matter_id=:matter LIMIT 1'); $matter->execute([':matter' => (string)$payload['matter_id']]);
        $current = $matter->fetchColumn();
        if ($current === false) throw new ApiError('recovery_event_invalid', 'The social matter is missing for a replay event.', 409);
        if (in_array((string)$current, ['closed', 'failed', 'cancelled'], true)) {
            if ((string)$current !== $socialMatterState) throw new ApiError('recovery_event_conflict', 'The social matter already has a different terminal state.', 409);
            $pdo->prepare('UPDATE town_social_matters SET revision=CASE WHEN revision<:revision THEN :revision ELSE revision END,updated_at=:updated WHERE matter_id=:matter')->execute([':revision' => (int)$payload['revision'], ':updated' => $stamp, ':matter' => (string)$payload['matter_id']]);
            return;
        }
        $pdo->prepare('UPDATE town_social_matters SET state=:state,failure_reason=:reason,revision=:revision,updated_at=:updated,closed_at=:closed WHERE matter_id=:matter')->execute([':state' => $socialMatterState, ':reason' => substr((string)($payload['reason'] ?? $payload['failure_reason'] ?? ''), 0, 255), ':revision' => (int)$payload['revision'], ':updated' => $stamp, ':closed' => $stamp, ':matter' => (string)$payload['matter_id']]);
        return;
    }
    $clock = $pdo->prepare('SELECT absolute_minute,calendar_day,revision,weather_code FROM town_world_state WHERE world_id=:world LIMIT 1');
    $clock->execute([':world' => SHARED_TOWN_WORLD_ID]); $row = $clock->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new ApiError('recovery_clock_unavailable', 'The world clock is unavailable while replaying an event.', 409);
    $revision = (int)$row['revision'] + 1;
    if ($type === 'time_advanced') {
        $minutes = filter_var($payload['minutes'] ?? null, FILTER_VALIDATE_INT);
        if ($minutes === false || $minutes < 1 || $minutes > 120) throw new ApiError('recovery_event_invalid', 'The time_advanced payload is invalid.', 409);
        $absolute = (int)$row['absolute_minute'] + (int)$minutes;
        $day = max(1, (int)$row['calendar_day'] + intdiv($absolute, 1440) - intdiv((int)$row['absolute_minute'], 1440));
        $pdo->prepare('UPDATE town_world_state SET absolute_minute=:absolute,calendar_day=:day,revision=:revision,updated_at=:updated WHERE world_id=:world')->execute([':absolute' => $absolute, ':day' => $day, ':revision' => $revision, ':updated' => auth_sql_time($now), ':world' => SHARED_TOWN_WORLD_ID]);
        return;
    }
    $weather = strtolower(trim((string)($payload['weather_code'] ?? '')));
    if (!in_array($weather, ['clear', 'cloudy', 'rain', 'storm', 'snow', 'fog'], true)) throw new ApiError('recovery_event_invalid', 'The weather_changed payload is invalid.', 409);
    $duration = max(0, min(10080, (int)($payload['duration_minutes'] ?? 0)));
    $next = $duration > 0 ? $now->modify('+' . $duration . ' minutes')->format('Y-m-d H:i:s') : null;
    $pdo->prepare('UPDATE town_world_state SET weather_code=:weather,weather_started_at=:started,next_weather_change_at=:next,revision=:revision,updated_at=:updated WHERE world_id=:world')->execute([':weather' => $weather, ':started' => auth_sql_time($now), ':next' => $next, ':revision' => $revision, ':updated' => auth_sql_time($now), ':world' => SHARED_TOWN_WORLD_ID]);
}
