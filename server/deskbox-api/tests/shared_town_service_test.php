<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/account_data.php';
require dirname(__DIR__) . '/api/_app/tree_decorations.php';
require dirname(__DIR__) . '/api/_app/shared_town.php';

$checks = 0;
function town_shared_expect(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) { throw new RuntimeException($message); } }
function town_shared_error(string $code, int $status, callable $callback): void {
    try { $callback(); } catch (ApiError $error) { town_shared_expect($error->apiCode === $code && $error->httpStatus === $status, "Expected {$code}/{$status}; got {$error->apiCode}/{$error->httpStatus}."); return; }
    throw new RuntimeException('Expected ' . $code);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
$pdo->exec("CREATE TABLE town_worlds (world_id TEXT PRIMARY KEY, world_version INTEGER NOT NULL, terrain_version INTEGER NOT NULL, map_width INTEGER NOT NULL, map_height INTEGER NOT NULL, tile_size INTEGER NOT NULL, chunk_size INTEGER NOT NULL, change_cursor INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE town_graylist (user_id TEXT PRIMARY KEY REFERENCES auth_users(id) ON DELETE CASCADE, enabled_at TEXT NOT NULL);
CREATE TABLE town_objects (object_id TEXT PRIMARY KEY, world_id TEXT NOT NULL REFERENCES town_worlds(world_id), asset_id TEXT NOT NULL, asset_source TEXT NOT NULL DEFAULT 'legacy', owner_user_id TEXT NOT NULL REFERENCES auth_users(id), chunk_x INTEGER NOT NULL, chunk_y INTEGER NOT NULL, tile_x INTEGER NOT NULL, tile_y INTEGER NOT NULL, footprint_json TEXT NOT NULL, layer INTEGER NOT NULL DEFAULT 0, object_version INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT NULL);
CREATE TABLE town_operations (user_id TEXT NOT NULL REFERENCES auth_users(id), operation_id TEXT NOT NULL, action TEXT NOT NULL, request_hash TEXT NOT NULL, response_json TEXT NOT NULL, created_at TEXT NOT NULL, PRIMARY KEY(user_id,operation_id));
CREATE TABLE town_change_log (change_cursor INTEGER PRIMARY KEY AUTOINCREMENT, world_id TEXT NOT NULL REFERENCES town_worlds(world_id), chunk_x INTEGER NOT NULL, chunk_y INTEGER NOT NULL, action TEXT NOT NULL, object_id TEXT NOT NULL, payload_json TEXT NOT NULL, created_at TEXT NOT NULL);
CREATE TABLE town_presence (world_id TEXT NOT NULL REFERENCES town_worlds(world_id), user_id TEXT NOT NULL REFERENCES auth_users(id), display_name TEXT NOT NULL, chunk_x INTEGER NOT NULL, chunk_y INTEGER NOT NULL, tile_x INTEGER NOT NULL, tile_y INTEGER NOT NULL, last_seen_at TEXT NOT NULL, expires_at TEXT NOT NULL, PRIMARY KEY(world_id,user_id));
CREATE TABLE town_decoration_reservations (user_id TEXT NOT NULL REFERENCES auth_users(id), item_id TEXT NOT NULL, reserved_quantity INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL, PRIMARY KEY(user_id,item_id));
CREATE TABLE town_asset_sheets (sheet_id TEXT PRIMARY KEY, original_name TEXT NOT NULL, storage_path TEXT NOT NULL, mime_type TEXT NOT NULL, file_size INTEGER NOT NULL, image_width INTEGER NOT NULL, image_height INTEGER NOT NULL, sha256 TEXT NOT NULL UNIQUE, status TEXT NOT NULL DEFAULT 'draft', uploaded_by TEXT NOT NULL REFERENCES auth_users(id), created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE town_asset_items (asset_id TEXT PRIMARY KEY, sheet_id TEXT NOT NULL REFERENCES town_asset_sheets(sheet_id) ON DELETE CASCADE, name TEXT NOT NULL, category TEXT NOT NULL, subcategory TEXT NOT NULL DEFAULT '', tags_json TEXT NOT NULL, x INTEGER NOT NULL, y INTEGER NOT NULL, region_width INTEGER NOT NULL, region_height INTEGER NOT NULL, display_width INTEGER NOT NULL, display_height INTEGER NOT NULL, anchor_json TEXT NOT NULL, footprint_json TEXT NOT NULL, collision_json TEXT NOT NULL, frames_json TEXT NOT NULL, manual_locked INTEGER NOT NULL DEFAULT 1, updated_by TEXT NOT NULL REFERENCES auth_users(id), created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE town_asset_releases (release_id TEXT PRIMARY KEY, release_version INTEGER NOT NULL UNIQUE, config_json TEXT NOT NULL, config_sha256 TEXT NOT NULL, published_by TEXT NOT NULL REFERENCES auth_users(id), published_at TEXT NOT NULL);
CREATE TABLE town_asset_active_release (singleton_id INTEGER PRIMARY KEY, release_id TEXT NOT NULL REFERENCES town_asset_releases(release_id));");
$now = new DateTimeImmutable('2026-11-08T10:00:00Z'); $stamp = auth_sql_time($now);
$pdo->prepare('INSERT INTO town_worlds VALUES (?,?,?,?,?,?,?,?,?,?)')->execute(['main', 2, 2, 192, 192, 16, 24, 0, $stamp, $stamp]);
$a = auth_register($pdo, 'shared_town_a', 'secret1', 'town-a', '127.0.0.1', static fn() => $now); $user = (string)$a['user']['id'];
$pdo->prepare('INSERT INTO town_graylist VALUES (?,?)')->execute([$user, $stamp]);
$pdo->prepare('INSERT INTO tree_profiles (user_id,stage,updated_at) VALUES (?,5,?)')->execute([$user, $stamp]);
$pdo->prepare("INSERT INTO tree_decoration_profiles (user_id,scene_json,created_at,updated_at) VALUES (?, '[]', ?, ?)")->execute([$user, $stamp, $stamp]);
$pdo->prepare('INSERT INTO tree_decoration_inventory (user_id,item_id,quantity,acquired_at) VALUES (?,?,?,?)')->execute([$user, 'mushroom-cluster', 2, $stamp]);
$sheetId = str_repeat('a', 32); $releaseId = str_repeat('b', 32); $townAssetId = 'town-source-test-house';
$pdo->prepare('INSERT INTO town_asset_sheets VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$sheetId, 'town.png', 'town.png', 'image/png', 1, 1, 1, str_repeat('c', 64), 'published', $user, $stamp, $stamp]);
$townItem = ['id' => $townAssetId, 'name' => '测试小屋', 'category' => 'building', 'subcategory' => 'house', 'tags' => [], 'sheet_id' => $sheetId, 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'display_width' => 16, 'display_height' => 16, 'anchor' => ['x' => .5, 'y' => 1], 'footprint' => ['width' => 1, 'height' => 1], 'collision' => ['x' => .1, 'y' => .55, 'width' => .8, 'height' => .4], 'frames' => [], 'manual_locked' => true, 'sheet' => ['sheet_id' => $sheetId, 'width' => 1, 'height' => 1, 'sha256' => str_repeat('c', 64)]];
$config = json_encode(['schema_version' => 1, 'items' => [$townItem], 'sheets' => [['sheet_id' => $sheetId, 'width' => 1, 'height' => 1, 'sha256' => str_repeat('c', 64)]], 'release_id' => $releaseId, 'release_version' => 1, 'config_sha256' => str_repeat('d', 64)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$pdo->prepare('INSERT INTO town_asset_releases VALUES (?,?,?,?,?,?)')->execute([$releaseId, 1, $config, str_repeat('d', 64), $user, $stamp]);
$pdo->prepare('INSERT INTO town_asset_active_release VALUES (1,?)')->execute([$releaseId]);

town_shared_expect(count(shared_town_catalog()['items']) === 1500, 'Shared town uses the complete 1500-item catalog.');
town_shared_expect(count(shared_town_catalog()['by_id']) === 1500, 'Shared town decoration IDs are unique.');
town_shared_expect(shared_town_world($pdo)['map_width'] === 192, 'Shared world metadata exposes 192 tiles.');
town_shared_expect(count(shared_town_terrain_chunk(5, 1)) > 0, 'Terrain chunks contain fixed forest cells.');
town_shared_expect(shared_town_tile_type(64, 20) === 'river'
    && shared_town_tile_type(64, 92) === 'bridge'
    && shared_town_tile_type(90, 20) === 'road'
    && shared_town_tile_type(20, 90) === 'road'
    && shared_town_tile_type(140, 30) === 'forest'
    && shared_town_tile_type(95, 89) === 'main_tree', 'Shared terrain coordinates match the packaged map.');
town_shared_error('invalid_position', 409, static fn() => shared_town_validate_rectangle(64, 20, ['width' => 1, 'height' => 1]));
town_shared_error('invalid_position', 409, static fn() => shared_town_validate_rectangle(140, 30, ['width' => 1, 'height' => 1]));
town_shared_error('town_not_open', 403, static fn() => shared_town_require_access($pdo, 'missing-user'));
$request = ['action' => 'place', 'operation_id' => 'town-operation-000001', 'asset_id' => 'mushroom-cluster', 'tile_x' => 60, 'tile_y' => 60];
$placed = shared_town_action($pdo, $user, $request, $now);
town_shared_expect(($placed['object']['asset_id'] ?? '') === 'mushroom-cluster', 'Place returns the created object.');
town_shared_expect(($placed['operation_id'] ?? '') === $request['operation_id'], 'Action response identifies the operation.');
town_shared_expect(($placed['inventory']['items'][0]['available_quantity'] ?? -1) === 1, 'Action inventory excludes town reservations.');
$duplicate = shared_town_action($pdo, $user, $request, $now);
town_shared_expect(($duplicate['duplicate'] ?? false) === true, 'Repeated operation is idempotent.');
town_shared_expect(($duplicate['operation_id'] ?? '') === $request['operation_id'], 'Duplicate response identifies the operation.');
$object = $placed['object'];
town_shared_error('object_forbidden', 403, static fn() => shared_town_action($pdo, 'another-user', ['action' => 'delete', 'operation_id' => 'town-operation-000005', 'object_id' => $object['object_id'], 'object_version' => 1], $now));
town_shared_error('object_conflict', 409, static fn() => shared_town_action($pdo, $user, ['action' => 'move', 'operation_id' => 'town-operation-000006', 'object_id' => $object['object_id'], 'object_version' => 8, 'tile_x' => 62, 'tile_y' => 60], $now));
town_shared_error('position_conflict', 409, static fn() => shared_town_action($pdo, $user, ['action' => 'place', 'operation_id' => 'town-operation-000002', 'asset_id' => 'mushroom-cluster', 'tile_x' => 60, 'tile_y' => 60], $now));
$moved = shared_town_action($pdo, $user, ['action' => 'move', 'operation_id' => 'town-operation-000003', 'object_id' => $object['object_id'], 'object_version' => 1, 'tile_x' => 61, 'tile_y' => 60], $now);
town_shared_expect(($moved['object']['object_version'] ?? 0) === 2, 'Move increments object version.');
$crossChunkMove = shared_town_action($pdo, $user, ['action' => 'move', 'operation_id' => 'town-operation-000007', 'object_id' => $object['object_id'], 'object_version' => 2, 'tile_x' => 85, 'tile_y' => 60], $now);
town_shared_expect(($crossChunkMove['object']['chunk_x'] ?? -1) === 3, 'Cross-chunk move updates the destination chunk.');
$deleted = shared_town_action($pdo, $user, ['action' => 'delete', 'operation_id' => 'town-operation-000004', 'object_id' => $object['object_id'], 'object_version' => 3], $now);
town_shared_expect(($deleted['action'] ?? '') === 'delete', 'Delete returns a delete result.');
town_shared_expect(shared_town_reservation($pdo, $user, 'mushroom-cluster') === 0, 'Delete returns the decoration reservation.');
$townPlaced = shared_town_action($pdo, $user, ['action' => 'place', 'operation_id' => 'town-operation-town-01', 'asset_id' => $townAssetId, 'tile_x' => 110, 'tile_y' => 60], $now);
town_shared_expect(($townPlaced['object']['asset_source'] ?? '') === 'town' && shared_town_reservation($pdo, $user, $townAssetId) === 0, 'Published town-source assets place without consuming tree decoration inventory.');
$presence = shared_town_presence_upsert($pdo, $user, ['tile_x' => 70, 'tile_y' => 70], $now);
town_shared_expect(($presence['chunk_x'] ?? -1) === 2, 'Presence is assigned to its chunk.');
town_shared_expect(count(shared_town_presence($pdo, 2, 2, $now)['presence']) === 1, 'Presence is visible in the nearby chunk.');
town_shared_expect(count(shared_town_presence($pdo, 2, 2, $now->modify('+31 seconds'))['presence']) === 0, 'Presence expires without new heartbeats.');
town_shared_expect(count(shared_town_changes($pdo, 0)['changes']) === 6, 'Cross-chunk move emits old-chunk removal plus destination update.');
town_shared_expect(count(shared_town_changes($pdo, 0, [[2, 2]])['changes']) === 3, 'Chunk-filtered changes only include the requested chunk.');
echo "shared_town_service_test: {$checks} checks ok\n";
