<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__ . '/town_assets.php';
require_once __DIR__ . '/town_player_positions.php';

const SHARED_TOWN_WORLD_ID = 'main';
const SHARED_TOWN_VERSION = 2;
const SHARED_TOWN_MAP_SIZE = 768;
const SHARED_TOWN_TILE_SIZE = 16;
const SHARED_TOWN_CHUNK_SIZE = 24;
const SHARED_TOWN_MAX_CHUNKS = 16;
const SHARED_TOWN_MAX_CHANGES = 500;
const SHARED_TOWN_PRESENCE_TTL = 30;
const SHARED_TOWN_CATALOG_SIZE = 1500;

/**
 * Shared-town uses the same decoration catalog as the tree screen, but it is
 * loaded by endpoints that do not include tree_decorations.php. Keep the
 * allow-list here so a malformed or accidentally reintroduced theme entry
 * cannot be placed in the global map.
 */
function shared_town_allowed_subcategories(): array
{
    return [
        'tree' => ['lanterns', 'hanging_garden', 'bird_home', 'wind_chime', 'ribbons_signs',
            'fruit_seed', 'carving_masks', 'bird_cages', 'celestial', 'glass_charms'],
        'ground' => ['flora', 'stones', 'water', 'containers', 'furniture', 'lighting',
            'signs', 'paths', 'play', 'craft', 'garden', 'water_features'],
        'large' => ['gates', 'pavilions', 'bridges', 'seating', 'shelters', 'greenhouses',
            'carts', 'towers', 'water_features', 'sculptures', 'building', 'furniture',
            'pathways', 'pavilion'],
    ];
}

function shared_town_catalog(): array
{
    static $catalog = null;
    if ($catalog !== null) { return $catalog; }
    $path = dirname(__DIR__, 2) . '/config/tree-decorations.json';
    $source = file_get_contents($path);
    if ($source === false) { throw new RuntimeException('Tree decoration catalog is unavailable.'); }
    $document = json_decode($source, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($document) || !is_array($document['items'] ?? null) || !array_is_list($document['items'])) {
        throw new RuntimeException('Tree decoration catalog is invalid.');
    }
    $items = [];
    $byId = [];
    $names = [];
    $assetKeys = [];
    $allowedSubcategories = shared_town_allowed_subcategories();
    foreach ($document['items'] as $item) {
        if (!is_array($item)) { throw new RuntimeException('Tree decoration catalog item is invalid.'); }
        foreach (['id', 'name', 'category', 'rarity', 'asset_key'] as $field) {
            if (!is_string($item[$field] ?? null) || trim($item[$field]) === '') {
                throw new RuntimeException('Tree decoration catalog item has a missing field.');
            }
        }
        $id = trim($item['id']);
        $category = trim($item['category']);
        $name = trim($item['name']);
        $assetKey = trim($item['asset_key']);
        $subcategory = is_string($item['subcategory'] ?? null) ? trim((string)$item['subcategory']) : '';
        if (!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $id)
            || !preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $assetKey)
            || isset($byId[$id]) || isset($names[$name]) || isset($assetKeys[$assetKey])
            || !isset($allowedSubcategories[$category]) || $subcategory === ''
            || !preg_match('/\A[a-z0-9]+(?:[_-][a-z0-9]+)*\z/D', $subcategory)
            || !in_array($subcategory, $allowedSubcategories[$category], true)
            || !in_array(trim($item['rarity']), ['common', 'rare'], true)
            || $category === 'theme' || $subcategory === 'theme') {
            throw new RuntimeException('Tree decoration catalog contains a duplicate or invalid item.');
        }
        $footprint = $item['footprint'] ?? ['width' => 1, 'height' => 1];
        if (!is_array($footprint) || !is_int($footprint['width'] ?? null) || !is_int($footprint['height'] ?? null)
            || $footprint['width'] < 1 || $footprint['width'] > 16 || $footprint['height'] < 1 || $footprint['height'] > 16) {
            throw new RuntimeException('Tree decoration footprint is invalid.');
        }
        $entry = [
            'id' => $id, 'name' => $name, 'category' => $category,
            'subcategory' => $subcategory,
            'rarity' => trim($item['rarity']), 'asset_key' => $assetKey,
            'footprint' => ['width' => $footprint['width'], 'height' => $footprint['height']],
            'anchor' => is_array($item['anchor'] ?? null) ? $item['anchor'] : ['x' => .5, 'y' => 1],
            'collision' => is_array($item['collision'] ?? null) ? $item['collision'] : $footprint,
        ];
        $items[] = $entry;
        $byId[$id] = $entry;
        $names[$name] = true;
        $assetKeys[$assetKey] = true;
    }
    if (count($items) !== SHARED_TOWN_CATALOG_SIZE) {
        throw new RuntimeException('Shared town catalog must contain exactly ' . SHARED_TOWN_CATALOG_SIZE . ' items.');
    }
    $catalog = ['catalog_version' => max(4, (int)($document['catalog_version'] ?? 0)),
        'items' => $items, 'by_id' => $byId];
    return $catalog;
}

function shared_town_require_access(PDO $pdo, string $userId): void
{
    try {
        $query = $pdo->prepare('SELECT 1 FROM town_graylist WHERE user_id=:user LIMIT 1');
        $query->execute([':user' => $userId]);
        if ($query->fetchColumn() === false) {
            throw new ApiError('town_not_open', 'Shared town access is not enabled for this account.', 403);
        }
    } catch (PDOException $error) {
        // A missing migration must fail closed. It must never expose the world.
        throw new ApiError('town_not_open', 'Shared town access is not enabled.', 403);
    }
}

function shared_town_world(PDO $pdo): array
{
    try {
        $query = $pdo->prepare('SELECT world_id,world_version,terrain_version,navigation_version,map_width,map_height,tile_size,chunk_size,change_cursor '
            . 'FROM town_worlds WHERE world_id=:world LIMIT 1');
        $query->execute([':world' => SHARED_TOWN_WORLD_ID]);
    } catch (PDOException) {
        $query = $pdo->prepare('SELECT world_id,world_version,terrain_version,map_width,map_height,tile_size,chunk_size,change_cursor '
            . 'FROM town_worlds WHERE world_id=:world LIMIT 1');
        $query->execute([':world' => SHARED_TOWN_WORLD_ID]);
    }
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) { throw new ApiError('town_unavailable', 'The shared town is not initialized.', 503); }
    return [
        'world_id' => (string)$row['world_id'], 'world_version' => (int)$row['world_version'],
        'terrain_version' => (int)$row['terrain_version'], 'navigation_version' => max(1, (int)($row['navigation_version'] ?? 1)), 'map_width' => (int)$row['map_width'],
        'map_height' => (int)$row['map_height'], 'tile_size' => (int)$row['tile_size'],
        'chunk_size' => (int)$row['chunk_size'], 'chunk_columns' => intdiv((int)$row['map_width'], (int)$row['chunk_size']),
        'chunk_rows' => intdiv((int)$row['map_height'], (int)$row['chunk_size']),
        'change_cursor' => (int)$row['change_cursor'], 'catalog_version' => shared_town_catalog()['catalog_version'],
        'asset_release' => town_asset_active_summary($pdo),
    ];
}

function shared_town_tile_type(int $x, int $y): string
{
    if ($x < 0 || $x >= SHARED_TOWN_MAP_SIZE || $y < 0 || $y >= SHARED_TOWN_MAP_SIZE) { return 'void'; }
    // Keep the server collision map in lockstep with the packaged catalog:
    // river x=64..75, bridge x=61..78/y=88..99 and the north-east forest.
    $bridge = $x >= 61 && $x <= 78 && $y >= 88 && $y <= 99;
    if ($x >= 64 && $x <= 75) { return $bridge ? 'bridge' : 'river'; }
    if (($x >= 62 && $x <= 63) || ($x >= 76 && $x <= 77)) { return $bridge ? 'bridge' : 'riverbank'; }
    if ($x >= 136 && $x <= 180 && $y >= 18 && $y <= 87) { return 'forest'; }
    if ($x >= 84 && $x <= 107 && $y >= 84 && $y <= 107) {
        return 'plaza';
    }
    // The client paints a cross-shaped road outside the center plaza. Keep
    // the server placement collision map aligned with that fixed terrain.
    if (($x >= 88 && $x <= 103) || ($y >= 88 && $y <= 103)) { return 'road'; }
    return 'plain';
}

function shared_town_tile_allowed(int $x, int $y): bool
{
    return in_array(shared_town_tile_type($x, $y), ['plain', 'road', 'plaza'], true);
}

function shared_town_chunk_coords(int $tileX, int $tileY): array
{
    return [intdiv($tileX, SHARED_TOWN_CHUNK_SIZE), intdiv($tileY, SHARED_TOWN_CHUNK_SIZE)];
}

/** @return list<array{0:int,1:int}> */
function shared_town_parse_chunks(mixed $value): array
{
    if ($value === null || $value === '') { return []; }
    if (!is_string($value)) { throw new ApiError('invalid_chunks', 'The chunk filter is invalid.', 400); }
    $chunks = [];
    foreach (explode(',', $value) as $part) {
        if (!preg_match('/\A([0-9]{1,2}):([0-9]{1,2})\z/D', trim($part), $match)
            || (int)$match[1] >= intdiv(SHARED_TOWN_MAP_SIZE, SHARED_TOWN_CHUNK_SIZE)
            || (int)$match[2] >= intdiv(SHARED_TOWN_MAP_SIZE, SHARED_TOWN_CHUNK_SIZE)) {
            throw new ApiError('invalid_chunks', 'The chunk filter is invalid.', 400);
        }
        $key = $match[1] . ':' . $match[2];
        if (!isset($chunks[$key])) { $chunks[$key] = [(int)$match[1], (int)$match[2]]; }
        if (count($chunks) > SHARED_TOWN_MAX_CHUNKS) {
            throw new ApiError('invalid_chunks', 'Too many chunks were requested.', 400);
        }
    }
    return array_values($chunks);
}

function shared_town_rectangle(array $object): array
{
    $footprint = $object['footprint'] ?? $object['footprint_json'] ?? null;
    if (is_string($footprint)) { $footprint = json_decode($footprint, true); }
    $footprint = is_array($footprint) ? $footprint : ['width' => 1, 'height' => 1];
    return ['x' => (int)$object['tile_x'], 'y' => (int)$object['tile_y'],
        'width' => max(1, (int)($footprint['width'] ?? 1)), 'height' => max(1, (int)($footprint['height'] ?? 1))];
}

function shared_town_intersects(array $left, array $right): bool
{
    return $left['x'] < $right['x'] + $right['width'] && $left['x'] + $left['width'] > $right['x']
        && $left['y'] < $right['y'] + $right['height'] && $left['y'] + $left['height'] > $right['y'];
}

function shared_town_validate_rectangle(int $x, int $y, array $footprint): array
{
    $width = (int)($footprint['width'] ?? 1); $height = (int)($footprint['height'] ?? 1);
    if ($width < 1 || $height < 1 || $width > 16 || $height > 16 || $x < 0 || $y < 0
        || $x + $width > SHARED_TOWN_MAP_SIZE || $y + $height > SHARED_TOWN_MAP_SIZE) {
        throw new ApiError('invalid_position', 'The decoration is outside the shared town.', 400);
    }
    $rect = ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height];
    for ($yy = $y; $yy < $y + $height; $yy++) {
        for ($xx = $x; $xx < $x + $width; $xx++) {
            if (!shared_town_tile_allowed($xx, $yy)) {
                throw new ApiError('invalid_position', 'The decoration covers fixed terrain.', 409);
            }
        }
    }
    return $rect;
}

function shared_town_object_public(array $row): array
{
    $footprint = is_string($row['footprint_json'] ?? null) ? json_decode((string)$row['footprint_json'], true) : ($row['footprint'] ?? null);
    $layer = (int)($row['layer'] ?? 1);
    // Older rows used 0 and negative values. Keep those rows visible in the
    // first editor layer while exposing the new six-layer contract.
    $layer = max(1, min(6, $layer));
    return ['object_id' => (string)$row['object_id'], 'asset_id' => (string)$row['asset_id'],
        'asset_source' => (string)($row['asset_source'] ?? 'legacy'),
        'owner_user_id' => (string)$row['owner_user_id'], 'chunk_x' => (int)$row['chunk_x'],
        'chunk_y' => (int)$row['chunk_y'], 'tile_x' => (int)$row['tile_x'], 'tile_y' => (int)$row['tile_y'],
        'footprint' => is_array($footprint) ? $footprint : ['width' => 1, 'height' => 1],
        'layer' => $layer, 'object_version' => (int)$row['object_version']];
}

function shared_town_terrain_chunk(int $chunkX, int $chunkY): array
{
    $cells = [];
    $originX = $chunkX * SHARED_TOWN_CHUNK_SIZE; $originY = $chunkY * SHARED_TOWN_CHUNK_SIZE;
    for ($y = 0; $y < SHARED_TOWN_CHUNK_SIZE; $y++) {
        for ($x = 0; $x < SHARED_TOWN_CHUNK_SIZE; $x++) {
            $type = shared_town_tile_type($originX + $x, $originY + $y);
            if ($type !== 'plain') { $cells[] = ['x' => $x, 'y' => $y, 'type' => $type]; }
        }
    }
    return $cells;
}

function shared_town_chunk(PDO $pdo, int $chunkX, int $chunkY): array
{
    $chunkCount = intdiv(SHARED_TOWN_MAP_SIZE, SHARED_TOWN_CHUNK_SIZE);
    if ($chunkX < 0 || $chunkX >= $chunkCount || $chunkY < 0 || $chunkY >= $chunkCount) {
        throw new ApiError('invalid_chunk', 'The requested town chunk is outside the map.', 400);
    }
    $query = $pdo->prepare('SELECT object_id,asset_id,asset_source,owner_user_id,chunk_x,chunk_y,tile_x,tile_y,footprint_json,layer,object_version '
        . 'FROM town_objects WHERE world_id=:world AND chunk_x=:x AND chunk_y=:y AND deleted_at IS NULL ORDER BY tile_y,tile_x,object_id');
    $query->execute([':world' => SHARED_TOWN_WORLD_ID, ':x' => $chunkX, ':y' => $chunkY]);
    return ['chunk_x' => $chunkX, 'chunk_y' => $chunkY, 'terrain' => shared_town_terrain_chunk($chunkX, $chunkY),
        'objects' => array_map('shared_town_object_public', $query->fetchAll(PDO::FETCH_ASSOC))];
}

function shared_town_personal_counts(PDO $pdo, string $userId): array
{
    $query = $pdo->prepare('SELECT scene_json FROM tree_decoration_profiles WHERE user_id=:user');
    $query->execute([':user' => $userId]);
    $json = $query->fetchColumn(); $counts = [];
    if (!is_string($json)) { return $counts; }
    $placements = json_decode($json, true);
    if (!is_array($placements)) { return $counts; }
    foreach ($placements as $placement) {
        if (is_array($placement) && is_string($placement['item_id'] ?? null)) {
            $id = (string)$placement['item_id']; $counts[$id] = ($counts[$id] ?? 0) + 1;
        }
    }
    return $counts;
}

/** Inventory view used by the shared-town editor.  quantity is the account
 * total; available_quantity excludes personal-tree and town reservations. */
function shared_town_inventory_payload(PDO $pdo, string $userId): array
{
    $rows = $pdo->prepare('SELECT item_id,quantity FROM tree_decoration_inventory WHERE user_id=:user ORDER BY item_id');
    $rows->execute([':user' => $userId]);
    $personal = shared_town_personal_counts($pdo, $userId); $result = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $item = (string)$row['item_id']; $total = max(0, (int)$row['quantity']);
        $reserved = shared_town_reservation($pdo, $userId, $item);
        $result[] = ['item_id' => $item, 'quantity' => $total,
            'reserved_quantity' => $reserved + ($personal[$item] ?? 0),
            'available_quantity' => max(0, $total - $reserved - ($personal[$item] ?? 0))];
    }
    return ['items' => $result];
}

function shared_town_reservation(PDO $pdo, string $userId, string $itemId, bool $lock = false): int
{
    $sql = 'SELECT reserved_quantity FROM town_decoration_reservations WHERE user_id=:user AND item_id=:item'
        . ($lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '');
    $query = $pdo->prepare($sql); $query->execute([':user' => $userId, ':item' => $itemId]);
    return (int)($query->fetchColumn() ?: 0);
}

function shared_town_adjust_reservation(PDO $pdo, string $userId, string $itemId, int $delta, DateTimeImmutable $now): void
{
    $current = shared_town_reservation($pdo, $userId, $itemId, true); $next = $current + $delta;
    if ($next < 0) { throw new ApiError('inventory_conflict', 'Decoration inventory changed. Reload and retry.', 409); }
    if ($next === 0) {
        $pdo->prepare('DELETE FROM town_decoration_reservations WHERE user_id=:user AND item_id=:item')
            ->execute([':user' => $userId, ':item' => $itemId]); return;
    }
    if ($current === 0) {
        $pdo->prepare('INSERT INTO town_decoration_reservations (user_id,item_id,reserved_quantity,updated_at) '
            . 'VALUES (:user,:item,:quantity,:updated)')->execute([':user' => $userId, ':item' => $itemId,
                ':quantity' => $next, ':updated' => auth_sql_time($now)]);
    } else {
        $pdo->prepare('UPDATE town_decoration_reservations SET reserved_quantity=:quantity,updated_at=:updated '
            . 'WHERE user_id=:user AND item_id=:item')->execute([':user' => $userId, ':item' => $itemId,
                ':quantity' => $next, ':updated' => auth_sql_time($now)]);
    }
}

function shared_town_lock_inventory(PDO $pdo, string $userId, string $itemId): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { return; }
    $query = $pdo->prepare('SELECT quantity FROM tree_decoration_inventory WHERE user_id=:user AND item_id=:item FOR UPDATE');
    $query->execute([':user' => $userId, ':item' => $itemId]);
    $query->fetchColumn();
}

function shared_town_change(PDO $pdo, array $object, string $action, DateTimeImmutable $now): int
{
    $payload = $action === 'delete' ? ['object_id' => $object['object_id']] : shared_town_object_public($object);
    $insert = $pdo->prepare('INSERT INTO town_change_log (world_id,chunk_x,chunk_y,action,object_id,payload_json,created_at) '
        . 'VALUES (:world,:x,:y,:action,:object,:payload,:created)');
    $insert->execute([':world' => SHARED_TOWN_WORLD_ID, ':x' => (int)$object['chunk_x'], ':y' => (int)$object['chunk_y'],
        ':action' => $action, ':object' => (string)$object['object_id'],
        ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ':created' => auth_sql_time($now)]);
    $cursor = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE town_worlds SET change_cursor=:cursor,updated_at=:updated WHERE world_id=:world')
        ->execute([':cursor' => $cursor, ':updated' => auth_sql_time($now), ':world' => SHARED_TOWN_WORLD_ID]);
    return $cursor;
}

function shared_town_canonical(mixed $value): mixed
{
    if (!is_array($value)) { return $value; }
    if (array_is_list($value)) { return array_map('shared_town_canonical', $value); }
    ksort($value); foreach ($value as $key => $item) { $value[$key] = shared_town_canonical($item); }
    return $value;
}

function shared_town_operation_hash(array $body): string
{
    return hash('sha256', json_encode(shared_town_canonical($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

/** @return array<string,mixed> */
function shared_town_action(PDO $pdo, string $userId, array $body, ?DateTimeImmutable $now = null): array
{
    $action = $body['action'] ?? null; $operationId = $body['operation_id'] ?? null;
    if (!is_string($action) || !in_array($action, ['place', 'move', 'delete'], true)
        || !is_string($operationId) || !preg_match('/\A[A-Za-z0-9_-]{16,128}\z/D', $operationId)) {
        throw new ApiError('invalid_operation', 'A valid town operation is required.', 400);
    }
    $hash = shared_town_operation_hash($body); $now ??= api_clock(); $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    try {
        $pdo->beginTransaction();
        $world = $pdo->prepare('SELECT * FROM town_worlds WHERE world_id=:world' . ($driver === 'mysql' ? ' FOR UPDATE' : ''));
        $world->execute([':world' => SHARED_TOWN_WORLD_ID]); $worldRow = $world->fetch(PDO::FETCH_ASSOC);
        if (!is_array($worldRow)) { throw new ApiError('town_unavailable', 'The shared town is not initialized.', 503); }
        $existing = $pdo->prepare('SELECT request_hash,response_json FROM town_operations WHERE user_id=:user AND operation_id=:operation');
        $existing->execute([':user' => $userId, ':operation' => $operationId]); $prior = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($prior)) {
            if (!hash_equals((string)$prior['request_hash'], $hash)) { throw new ApiError('operation_conflict', 'This operation identifier was already used.', 409); }
            $response = json_decode((string)$prior['response_json'], true, 32, JSON_THROW_ON_ERROR); $pdo->commit(); return $response + ['duplicate' => true];
        }
        $catalog = shared_town_catalog(); $assetId = is_string($body['asset_id'] ?? null) ? (string)$body['asset_id'] : '';
        $objectId = is_string($body['object_id'] ?? null) ? (string)$body['object_id'] : '';
        $object = null; $oldObject = null;
        if ($action === 'place') {
            $townItem = town_asset_published_item($pdo, $assetId);
            $legacyItem = $catalog['by_id'][$assetId] ?? null;
            if (!is_array($townItem) && !is_array($legacyItem)) { throw new ApiError('invalid_item', 'Unknown town asset.', 400); }
            if (!is_int($body['tile_x'] ?? null) || !is_int($body['tile_y'] ?? null)) { throw new ApiError('invalid_position', 'A tile position is required.', 400); }
            $item = is_array($townItem) ? $townItem : $legacyItem; $source = is_array($townItem) ? 'town' : 'legacy';
            $rect = shared_town_validate_rectangle((int)$body['tile_x'], (int)$body['tile_y'], $item['footprint']);
            $occupied = $pdo->query('SELECT asset_id,tile_x,tile_y,footprint_json FROM town_objects WHERE world_id=' . $pdo->quote(SHARED_TOWN_WORLD_ID) . ' AND deleted_at IS NULL')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($occupied as $other) { if (shared_town_intersects($rect, shared_town_rectangle($other))) { throw new ApiError('position_conflict', 'The selected tiles are already occupied.', 409); } }
            if ($source === 'legacy') {
                $inventory = $pdo->prepare('SELECT quantity FROM tree_decoration_inventory WHERE user_id=:user AND item_id=:item' . ($driver === 'mysql' ? ' FOR UPDATE' : ''));
                $inventory->execute([':user' => $userId, ':item' => $assetId]); $quantity = (int)($inventory->fetchColumn() ?: 0);
                $personal = shared_town_personal_counts($pdo, $userId)[$assetId] ?? 0;
                $reserved = shared_town_reservation($pdo, $userId, $assetId, true);
                if ($quantity - $personal - $reserved < 1) { throw new ApiError('insufficient_inventory', 'This decoration is already in use.', 409); }
            }
            [$chunkX, $chunkY] = shared_town_chunk_coords((int)$body['tile_x'], (int)$body['tile_y']);
            $objectId = auth_random_id(); $layer = array_key_exists('layer', $body) ? (is_int($body['layer']) ? (int)$body['layer'] : -1) : 1;
            if ($layer < 1 || $layer > 6) { throw new ApiError('invalid_object', 'Object layer must be from 1 to 6.', 400); }
            $object = ['object_id' => $objectId, 'asset_id' => $assetId, 'asset_source' => $source, 'owner_user_id' => $userId, 'chunk_x' => $chunkX, 'chunk_y' => $chunkY,
                'tile_x' => (int)$body['tile_x'], 'tile_y' => (int)$body['tile_y'], 'footprint_json' => json_encode($item['footprint'], JSON_THROW_ON_ERROR), 'layer' => $layer, 'object_version' => 1];
            $pdo->prepare('INSERT INTO town_objects (object_id,world_id,asset_id,asset_source,owner_user_id,chunk_x,chunk_y,tile_x,tile_y,footprint_json,layer,object_version,created_at,updated_at) '
                . 'VALUES (:id,:world,:asset,:source,:user,:cx,:cy,:tx,:ty,:footprint,:layer,1,:created,:updated)')->execute([':id' => $objectId, ':world' => SHARED_TOWN_WORLD_ID,
                    ':asset' => $assetId, ':source' => $source, ':user' => $userId, ':cx' => $chunkX, ':cy' => $chunkY, ':tx' => (int)$body['tile_x'], ':ty' => (int)$body['tile_y'],
                    ':footprint' => $object['footprint_json'], ':layer' => $layer, ':created' => auth_sql_time($now), ':updated' => auth_sql_time($now)]);
            if ($source === 'legacy') shared_town_adjust_reservation($pdo, $userId, $assetId, 1, $now); $cursor = shared_town_change($pdo, $object, 'place', $now);
            $response = ['action' => 'place', 'object' => shared_town_object_public($object), 'change_cursor' => $cursor];
        } else {
            if (!preg_match('/\A[a-f0-9]{32}\z/D', $objectId)) { throw new ApiError('invalid_object', 'A valid town object is required.', 400); }
            $query = $pdo->prepare('SELECT * FROM town_objects WHERE object_id=:id AND world_id=:world AND deleted_at IS NULL' . ($driver === 'mysql' ? ' FOR UPDATE' : ''));
            $query->execute([':id' => $objectId, ':world' => SHARED_TOWN_WORLD_ID]); $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) { throw new ApiError('object_not_found', 'The town object no longer exists.', 404); }
            if ((string)$row['owner_user_id'] !== $userId) { throw new ApiError('object_forbidden', 'Only the owner can edit this object.', 403); }
            if (!is_int($body['object_version'] ?? null) || (int)$body['object_version'] !== (int)$row['object_version']) { throw new ApiError('object_conflict', 'The town object changed. Reload the chunk.', 409); }
            $oldObject = $row; $assetId = (string)$row['asset_id'];
            $assetSource = isset($row['asset_source']) && (string)$row['asset_source'] === 'town' ? 'town' : 'legacy';
            if ($action === 'delete') {
                if ($assetSource === 'legacy') shared_town_lock_inventory($pdo, $userId, $assetId);
                $pdo->prepare('UPDATE town_objects SET deleted_at=:deleted,updated_at=:updated WHERE object_id=:id')->execute([':deleted' => auth_sql_time($now), ':updated' => auth_sql_time($now), ':id' => $objectId]);
                if ($assetSource === 'legacy') shared_town_adjust_reservation($pdo, $userId, $assetId, -1, $now);
                $cursor = shared_town_change($pdo, $row, 'delete', $now);
                $response = ['action' => 'delete', 'object_id' => $objectId, 'deleted_object_id' => $objectId, 'change_cursor' => $cursor];
            } else {
                if (!is_int($body['tile_x'] ?? null) || !is_int($body['tile_y'] ?? null)) { throw new ApiError('invalid_position', 'A tile position is required.', 400); }
                $item = $assetSource === 'town' ? town_asset_published_item($pdo, $assetId) : ($catalog['by_id'][$assetId] ?? null);
                if (!is_array($item)) $item = [];
                $item['footprint'] = json_decode((string)$row['footprint_json'], true, 16, JSON_THROW_ON_ERROR);
                $rect = shared_town_validate_rectangle((int)$body['tile_x'], (int)$body['tile_y'], $item['footprint']);
                $occupied = $pdo->query('SELECT * FROM town_objects WHERE world_id=' . $pdo->quote(SHARED_TOWN_WORLD_ID) . ' AND deleted_at IS NULL AND object_id<>' . $pdo->quote($objectId))->fetchAll(PDO::FETCH_ASSOC);
                foreach ($occupied as $other) { if (shared_town_intersects($rect, shared_town_rectangle($other))) { throw new ApiError('position_conflict', 'The selected tiles are already occupied.', 409); } }
                $layer = array_key_exists('layer', $body) ? (is_int($body['layer']) ? (int)$body['layer'] : -1) : max(1, min(6, (int)($row['layer'] ?? 1)));
                if ($layer < 1 || $layer > 6) { throw new ApiError('invalid_object', 'Object layer must be from 1 to 6.', 400); }
                [$chunkX, $chunkY] = shared_town_chunk_coords((int)$body['tile_x'], (int)$body['tile_y']); $version = (int)$row['object_version'] + 1;
                $oldChunkX = (int)$row['chunk_x']; $oldChunkY = (int)$row['chunk_y'];
                $pdo->prepare('UPDATE town_objects SET chunk_x=:cx,chunk_y=:cy,tile_x=:tx,tile_y=:ty,layer=:layer,object_version=:version,updated_at=:updated WHERE object_id=:id')->execute([':cx' => $chunkX, ':cy' => $chunkY, ':tx' => (int)$body['tile_x'], ':ty' => (int)$body['tile_y'], ':layer' => $layer, ':version' => $version, ':updated' => auth_sql_time($now), ':id' => $objectId]);
                $row['chunk_x'] = $chunkX; $row['chunk_y'] = $chunkY; $row['tile_x'] = (int)$body['tile_x']; $row['tile_y'] = (int)$body['tile_y']; $row['layer'] = $layer; $row['object_version'] = $version;
                // A cross-chunk move must remove the old instance before the new
                // chunk receives it; otherwise a viewport that unloads/reloads
                // chunks can retain a stale copy indefinitely.
                if ($oldChunkX !== $chunkX || $oldChunkY !== $chunkY) {
                    $cursor = shared_town_change($pdo, $oldObject, 'delete', $now);
                }
                $cursor = shared_town_change($pdo, $row, 'move', $now); $response = ['action' => 'move', 'object' => shared_town_object_public($row), 'change_cursor' => $cursor];
            }
        }
        $response['operation_id'] = $operationId;
        $response['inventory'] = shared_town_inventory_payload($pdo, $userId);
        $responseJson = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $pdo->prepare('INSERT INTO town_operations (user_id,operation_id,action,request_hash,response_json,created_at) VALUES (:user,:operation,:action,:hash,:response,:created)')->execute([':user' => $userId, ':operation' => $operationId, ':action' => $action, ':hash' => $hash, ':response' => $responseJson, ':created' => auth_sql_time($now)]);
        $pdo->commit(); return $response + ['duplicate' => false];
    } catch (Throwable $error) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $error; }
}

function shared_town_changes(PDO $pdo, int $cursor, array $chunks = []): array
{
    $sql = 'SELECT change_cursor,chunk_x,chunk_y,action,object_id,payload_json FROM town_change_log WHERE world_id=:world AND change_cursor>:cursor';
    $params = [':world' => SHARED_TOWN_WORLD_ID, ':cursor' => max(0, $cursor)];
    if ($chunks !== []) {
        $clauses = [];
        foreach (array_values($chunks) as $index => $chunk) {
            $clauses[] = '(chunk_x=:chunk_x_' . $index . ' AND chunk_y=:chunk_y_' . $index . ')';
            $params[':chunk_x_' . $index] = (int)$chunk[0]; $params[':chunk_y_' . $index] = (int)$chunk[1];
        }
        $sql .= ' AND (' . implode(' OR ', $clauses) . ')';
    }
    $query = $pdo->prepare($sql . ' ORDER BY change_cursor LIMIT ' . SHARED_TOWN_MAX_CHANGES);
    $query->execute($params); $items = []; $next = $cursor;
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) { $rowCursor = (int)$row['change_cursor']; $next = max($next, $rowCursor); $items[] = ['cursor' => $rowCursor, 'chunk_x' => (int)$row['chunk_x'], 'chunk_y' => (int)$row['chunk_y'], 'action' => (string)$row['action'], 'object_id' => (string)$row['object_id'], 'object' => json_decode((string)$row['payload_json'], true)]; }
    return ['changes' => $items, 'next_cursor' => $next];
}

function shared_town_presence_upsert(PDO $pdo, string $userId, array $body, DateTimeImmutable $now, bool $persist = true): array
{
    foreach (['tile_x', 'tile_y'] as $key) { if (!is_int($body[$key] ?? null) || $body[$key] < 0 || $body[$key] >= SHARED_TOWN_MAP_SIZE) { throw new ApiError('invalid_position', 'Presence position is invalid.', 400); } }
    $x=$body['pixel_x'] ?? ($body['tile_x']*16+8);
    $y=$body['pixel_y'] ?? ($body['tile_y']*16+15);
    if ((!is_int($x) && !is_float($x)) || (!is_int($y) && !is_float($y)) || !is_finite((float)$x) || !is_finite((float)$y)
        || (int)floor($x/16)!==$body['tile_x'] || (int)floor($y/16)!==$body['tile_y']
        || !shared_town_player_can_stand((float)$x,(float)$y,shared_town_player_obstacles($pdo))) {
        throw new ApiError('invalid_position', '人物位置不可站立，请重新载入小镇。', 400);
    }
    [$chunkX, $chunkY] = shared_town_chunk_coords((int)$body['tile_x'], (int)$body['tile_y']); $nameQuery = $pdo->prepare('SELECT username FROM auth_users WHERE id=:user'); $nameQuery->execute([':user' => $userId]); $name = (string)($nameQuery->fetchColumn() ?: '玩家');
    $last = auth_sql_time($now); $expires = auth_sql_time($now->modify('+' . SHARED_TOWN_PRESENCE_TTL . ' seconds')); $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sql = $driver === 'sqlite'
        ? 'INSERT INTO town_presence (world_id,user_id,display_name,chunk_x,chunk_y,tile_x,tile_y,last_seen_at,expires_at) VALUES (:world,:user,:name,:cx,:cy,:tx,:ty,:last,:expires) ON CONFLICT(world_id,user_id) DO UPDATE SET display_name=excluded.display_name,chunk_x=excluded.chunk_x,chunk_y=excluded.chunk_y,tile_x=excluded.tile_x,tile_y=excluded.tile_y,last_seen_at=excluded.last_seen_at,expires_at=excluded.expires_at'
        : 'INSERT INTO town_presence (world_id,user_id,display_name,chunk_x,chunk_y,tile_x,tile_y,last_seen_at,expires_at) VALUES (:world,:user,:name,:cx,:cy,:tx,:ty,:last,:expires) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),chunk_x=VALUES(chunk_x),chunk_y=VALUES(chunk_y),tile_x=VALUES(tile_x),tile_y=VALUES(tile_y),last_seen_at=VALUES(last_seen_at),expires_at=VALUES(expires_at)';
    $ownTransaction=$persist && !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        if ($persist) {
            shared_town_player_write($pdo,$userId,(float)$x,(float)$y,$now);
            $pdo->prepare($sql)->execute([':world' => SHARED_TOWN_WORLD_ID, ':user' => $userId, ':name' => $name, ':cx' => $chunkX, ':cy' => $chunkY, ':tx' => (int)$body['tile_x'], ':ty' => (int)$body['tile_y'], ':last' => $last, ':expires' => $expires]);
        }
        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return ['user_id' => $userId, 'display_name' => $name, 'chunk_x' => $chunkX, 'chunk_y' => $chunkY, 'tile_x' => (int)$body['tile_x'], 'tile_y' => (int)$body['tile_y'], 'pixel_x' => (float)$x, 'pixel_y' => (float)$y, 'expires_at' => $now->modify('+' . SHARED_TOWN_PRESENCE_TTL . ' seconds')->format(DateTimeInterface::ATOM)];
}

function shared_town_presence(PDO $pdo, int $chunkX = -1, int $chunkY = -1, ?DateTimeImmutable $now = null): array
{
    $now ??= api_clock(); $current = auth_sql_time($now); $pdo->prepare('DELETE FROM town_presence WHERE world_id=:world AND expires_at<=:now')->execute([':world' => SHARED_TOWN_WORLD_ID, ':now' => $current]);
    $sql = 'SELECT p.user_id,p.display_name,p.chunk_x,p.chunk_y,p.tile_x,p.tile_y,p.expires_at,s.pixel_x,s.pixel_y FROM town_presence p LEFT JOIN town_player_positions s ON s.world_id=p.world_id AND s.user_id=p.user_id WHERE p.world_id=:world AND p.expires_at>:now'; $params = [':world' => SHARED_TOWN_WORLD_ID, ':now' => $current];
    if ($chunkX >= 0 && $chunkY >= 0) { $sql .= ' AND chunk_x BETWEEN :minx AND :maxx AND chunk_y BETWEEN :miny AND :maxy'; $params += [':minx' => $chunkX - 1, ':maxx' => $chunkX + 1, ':miny' => $chunkY - 1, ':maxy' => $chunkY + 1]; }
    $query = $pdo->prepare($sql . ' ORDER BY p.user_id'); $query->execute($params); $items = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) { $items[] = ['user_id' => (string)$row['user_id'], 'display_name' => (string)$row['display_name'], 'chunk_x' => (int)$row['chunk_x'], 'chunk_y' => (int)$row['chunk_y'], 'tile_x' => (int)$row['tile_x'], 'tile_y' => (int)$row['tile_y'], 'pixel_x' => isset($row['pixel_x']) ? (float)$row['pixel_x'] : (int)$row['tile_x'] * 16 + 8, 'pixel_y' => isset($row['pixel_y']) ? (float)$row['pixel_y'] : (int)$row['tile_y'] * 16 + 15, 'expires_at' => auth_parse_sql_time((string)$row['expires_at'])->format(DateTimeInterface::ATOM)]; }
    return ['presence' => $items];
}
