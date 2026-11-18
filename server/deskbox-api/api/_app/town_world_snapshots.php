<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__ . '/town_world_clock.php';
require_once __DIR__ . '/town_npcs.php';

function town_world_snapshot_canonical(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map('town_world_snapshot_canonical', $value);
    ksort($value);
    foreach ($value as $key => $item) $value[$key] = town_world_snapshot_canonical($item);
    return $value;
}

function town_world_snapshot_encode(array $state): array
{
    $canonical = town_world_snapshot_canonical($state);
    $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return [$json, hash('sha256', $json)];
}

function town_world_snapshot_state(PDO $pdo): array
{
    $world = shared_town_world($pdo);
    $objects = [];
    $query = $pdo->prepare('SELECT object_id,asset_id,asset_source,owner_user_id,chunk_x,chunk_y,tile_x,tile_y,footprint_json,layer,object_version,created_at,updated_at FROM town_objects WHERE world_id=:world AND deleted_at IS NULL ORDER BY object_id');
    $query->execute([':world' => SHARED_TOWN_WORLD_ID]);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $objects[] = shared_town_object_public($row);
    $state = ['schema_version' => 1, 'world' => $world, 'objects' => $objects, 'npcs' => town_npc_snapshot($pdo),
        'clock' => town_world_clock_read($pdo)];
    try { $state['farm'] = ['plots' => town_farm_snapshot($pdo)]; } catch (Throwable $error) { $state['farm'] = ['plots' => []]; }
    try { $state['economy'] = town_economy_snapshot($pdo); } catch (Throwable $error) { $state['economy'] = []; }
    return $state;
}

function town_world_snapshot_create(PDO $pdo, string $createdBy, ?DateTimeImmutable $now = null): array
{
    $now ??= api_clock();
    $state = town_world_snapshot_state($pdo);
    [$json, $sha] = town_world_snapshot_encode($state);
    $world = $state['world']; $npcCursor = (int)($state['npcs']['cursor'] ?? 0);
    $eventCursor = 0;
    try { $eventCursor = (int)($pdo->query('SELECT COALESCE(MAX(event_id),0) FROM town_world_events')->fetchColumn() ?: 0); } catch (Throwable $error) { }
    $find = $pdo->prepare('SELECT snapshot_id,state_json,state_sha256,created_at FROM town_world_snapshots WHERE world_id=:world AND world_version=:version AND change_cursor=:change AND npc_cursor=:npc AND event_cursor=:event LIMIT 1');
    $find->execute([':world' => SHARED_TOWN_WORLD_ID, ':version' => (int)$world['world_version'], ':change' => (int)$world['change_cursor'], ':npc' => $npcCursor, ':event' => $eventCursor]);
    $existing = $find->fetch(PDO::FETCH_ASSOC);
    if (is_array($existing)) return town_world_snapshot_public($existing, $state, false);
    $id = auth_random_id();
    $insert = $pdo->prepare('INSERT INTO town_world_snapshots (snapshot_id,world_id,world_version,change_cursor,npc_cursor,event_cursor,navigation_version,state_json,state_sha256,created_by,created_at) VALUES (:id,:world,:version,:change,:npc,:event,:navigation,:state,:sha,:by,:created)');
    $insert->execute([':id' => $id, ':world' => SHARED_TOWN_WORLD_ID, ':version' => (int)$world['world_version'], ':change' => (int)$world['change_cursor'], ':npc' => $npcCursor, ':event' => $eventCursor, ':navigation' => (int)$world['navigation_version'], ':state' => $json, ':sha' => $sha, ':by' => substr($createdBy, 0, 128), ':created' => auth_sql_time($now)]);
    $pdo->exec('DELETE FROM town_world_snapshots WHERE world_id=' . $pdo->quote(SHARED_TOWN_WORLD_ID) . ' AND snapshot_id NOT IN (SELECT snapshot_id FROM (SELECT snapshot_id FROM town_world_snapshots WHERE world_id=' . $pdo->quote(SHARED_TOWN_WORLD_ID) . ' ORDER BY created_at DESC LIMIT 20) recent)');
    return ['snapshot_id' => $id, 'world_id' => SHARED_TOWN_WORLD_ID, 'world_version' => (int)$world['world_version'], 'change_cursor' => (int)$world['change_cursor'], 'npc_cursor' => $npcCursor, 'event_cursor' => $eventCursor, 'navigation_version' => (int)$world['navigation_version'], 'state' => $state, 'state_sha256' => $sha, 'created_by' => substr($createdBy, 0, 128), 'created_at' => auth_sql_time($now), 'created' => true];
}

function town_world_snapshot_public(array $row, ?array $state = null, bool $includeState = false): array
{
    $state ??= json_decode((string)$row['state_json'], true, 32, JSON_THROW_ON_ERROR);
    [$json, $sha] = town_world_snapshot_encode($state);
    if (!hash_equals((string)$row['state_sha256'], $sha)) throw new ApiError('snapshot_corrupt', 'The world snapshot checksum is invalid.', 500);
    $result = ['snapshot_id' => (string)$row['snapshot_id'], 'world_id' => (string)($row['world_id'] ?? SHARED_TOWN_WORLD_ID), 'state_sha256' => $sha, 'created_at' => (string)$row['created_at']];
    foreach (['world_version','change_cursor','npc_cursor','event_cursor','navigation_version'] as $key) if (array_key_exists($key, $row)) $result[$key] = (int)$row[$key];
    if ($includeState) $result['state'] = $state;
    return $result;
}

function town_world_snapshot_latest(PDO $pdo, bool $includeState = true): array
{
    $query = $pdo->prepare('SELECT * FROM town_world_snapshots WHERE world_id=:world ORDER BY created_at DESC,snapshot_id DESC LIMIT 1');
    $query->execute([':world' => SHARED_TOWN_WORLD_ID]); $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return ['snapshot' => null];
    return ['snapshot' => town_world_snapshot_public($row, null, $includeState)];
}
