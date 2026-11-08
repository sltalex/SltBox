<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }

/**
 * The account-scoped town prototype was replaced by the shared v2 world.
 * Keep the helper available for rollback tooling and legacy unit tests, but
 * make the public v1 routes read-only at the migration boundary.
 */
function town_prototype_migrated(): never
{
    throw new ApiError('town_migrated', 'The town prototype moved to the shared town.', 410);
}

const TOWN_PROTOTYPE_VERSION = 1;
const TOWN_PROTOTYPE_MAX_PLACEMENTS = 48;
const TOWN_PROTOTYPE_ITEM_QUANTITY = 3;

function town_prototype_catalog(): array
{
    static $catalog = null;
    if ($catalog !== null) { return $catalog; }
    $path = dirname(__DIR__, 2) . '/config/town-prototype-catalog.json';
    $source = file_get_contents($path);
    if ($source === false) { throw new RuntimeException('Town prototype catalog is unavailable.'); }
    $document = json_decode($source, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($document) || ($document['version'] ?? null) !== TOWN_PROTOTYPE_VERSION
        || ($document['map_width'] ?? null) !== 48 || ($document['map_height'] ?? null) !== 48
        || ($document['tile_size'] ?? null) !== 16 || !is_array($document['items'] ?? null)
        || !array_is_list($document['items']) || count($document['items']) !== 16) {
        throw new RuntimeException('Town prototype catalog is invalid.');
    }
    $byId = [];
    foreach ($document['items'] as $item) {
        $id = $item['id'] ?? null;
        $size = $item['footprint'] ?? null;
        if (!is_string($id) || !preg_match('/\A[a-z][a-z0-9_-]{0,79}\z/D', $id)
            || isset($byId[$id]) || !is_array($size)
            || !is_int($size['width'] ?? null) || !is_int($size['height'] ?? null)
            || $size['width'] < 1 || $size['width'] > 12 || $size['height'] < 1 || $size['height'] > 12) {
            throw new RuntimeException('Town prototype footprint catalog is invalid.');
        }
        $byId[$id] = $item;
    }
    foreach (['pond', 'tree', 'spawn'] as $name) {
        $region = $document['world'][$name] ?? null;
        if (!is_array($region)) { throw new RuntimeException('Town prototype reserved regions are invalid.'); }
        foreach (['x', 'y', 'width', 'height'] as $field) {
            if (!is_int($region[$field] ?? null) || $region[$field] < 0
                || (in_array($field, ['width', 'height'], true) && $region[$field] < 1)) {
                throw new RuntimeException('Town prototype reserved regions are invalid.');
            }
        }
        if ($region['x'] + $region['width'] > 48 || $region['y'] + $region['height'] > 48) {
            throw new RuntimeException('Town prototype reserved regions are outside the map.');
        }
    }
    $document['by_id'] = $byId;
    return $catalog = $document;
}

function town_prototype_object(mixed $value): array
{
    if ($value instanceof stdClass) { return get_object_vars($value); }
    if (is_array($value) && !array_is_list($value)) { return $value; }
    throw new ApiError('invalid_scene', 'Town layout objects are invalid.', 400);
}

function town_prototype_keys(array $value, array $expected): bool
{
    $keys = array_keys($value);
    sort($keys);
    sort($expected);
    return $keys === $expected;
}

function town_prototype_intersects(array $left, array $right): bool
{
    return $left['x'] < $right['x'] + $right['width']
        && $left['x'] + $left['width'] > $right['x']
        && $left['y'] < $right['y'] + $right['height']
        && $left['y'] + $left['height'] > $right['y'];
}

function town_prototype_validate_layout(mixed $value): array
{
    $layout = town_prototype_object($value);
    if (!town_prototype_keys($layout, ['template_version', 'catalog_version', 'demo_stage', 'placements'])
        || ($layout['template_version'] ?? null) !== TOWN_PROTOTYPE_VERSION
        || ($layout['catalog_version'] ?? null) !== TOWN_PROTOTYPE_VERSION
        || !is_int($layout['demo_stage'] ?? null) || $layout['demo_stage'] < 1 || $layout['demo_stage'] > 5
        || !is_array($layout['placements'] ?? null) || !array_is_list($layout['placements'])
        || count($layout['placements']) > TOWN_PROTOTYPE_MAX_PLACEMENTS) {
        throw new ApiError('invalid_scene', 'Town layout version, stage or placements are invalid.', 400);
    }
    $catalog = town_prototype_catalog();
    $placements = [];
    $ids = [];
    $counts = [];
    $occupied = [];
    foreach ($layout['placements'] as $source) {
        $placement = town_prototype_object($source);
        if (!town_prototype_keys($placement, ['instance_id', 'asset_id', 'tile_x', 'tile_y'])) {
            throw new ApiError('invalid_scene', 'Town placement fields are invalid.', 400);
        }
        $id = $placement['instance_id'] ?? null;
        $assetId = $placement['asset_id'] ?? null;
        if (!is_string($id) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $id) || isset($ids[$id])
            || !is_string($assetId) || !isset($catalog['by_id'][$assetId])
            || !is_int($placement['tile_x'] ?? null) || !is_int($placement['tile_y'] ?? null)) {
            throw new ApiError('invalid_scene', 'Town placement identity or coordinates are invalid.', 400);
        }
        $count = ($counts[$assetId] ?? 0) + 1;
        if ($count > TOWN_PROTOTYPE_ITEM_QUANTITY) {
            throw new ApiError('invalid_scene', 'Town placements exceed the demo inventory.', 400);
        }
        $footprint = $catalog['by_id'][$assetId]['footprint'];
        $rectangle = ['x' => $placement['tile_x'], 'y' => $placement['tile_y'],
            'width' => $footprint['width'], 'height' => $footprint['height']];
        if ($rectangle['x'] < 0 || $rectangle['y'] < 0
            || $rectangle['x'] + $rectangle['width'] > $catalog['map_width']
            || $rectangle['y'] + $rectangle['height'] > $catalog['map_height']) {
            throw new ApiError('invalid_scene', 'Town placement is outside the map.', 400);
        }
        foreach (array_merge(array_values($catalog['world']), $occupied) as $other) {
            if (town_prototype_intersects($rectangle, $other)) {
                throw new ApiError('invalid_scene', 'Town placements overlap or cover a reserved region.', 400);
            }
        }
        $ids[$id] = true;
        $counts[$assetId] = $count;
        $occupied[] = $rectangle;
        $placements[] = ['instance_id' => $id, 'asset_id' => $assetId,
            'tile_x' => $rectangle['x'], 'tile_y' => $rectangle['y']];
    }
    return ['template_version' => TOWN_PROTOTYPE_VERSION, 'catalog_version' => TOWN_PROTOTYPE_VERSION,
        'demo_stage' => $layout['demo_stage'], 'placements' => $placements];
}

function town_prototype_default_layout(): array
{
    $source = file_get_contents(dirname(__DIR__, 2) . '/config/town-prototype-default.json');
    if ($source === false) { throw new RuntimeException('Town prototype default is unavailable.'); }
    return town_prototype_validate_layout(json_decode($source, true, 32, JSON_THROW_ON_ERROR));
}

function town_prototype_state_from_row(array|false $row): array
{
    if ($row === false) {
        return ['scene_version' => 0, 'layout' => town_prototype_default_layout(), 'updated_at' => null];
    }
    return ['scene_version' => (int)$row['scene_version'],
        'layout' => town_prototype_validate_layout(json_decode((string)$row['layout_json'], true, 32, JSON_THROW_ON_ERROR)),
        'updated_at' => (string)$row['updated_at']];
}

function town_prototype_read_state(PDO $pdo, string $userId): array
{
    $statement = $pdo->prepare('SELECT scene_version,layout_json,updated_at '
        . 'FROM tree_town_prototype_profiles WHERE user_id=:user');
    $statement->execute([':user' => $userId]);
    return town_prototype_state_from_row($statement->fetch(PDO::FETCH_ASSOC));
}

function town_prototype_save_scene(PDO $pdo, string $userId, array $body, ?DateTimeImmutable $now = null): array
{
    if (!town_prototype_keys($body, ['action', 'base_scene_version', 'operation_id', 'layout'])
        || ($body['action'] ?? null) !== 'save_scene'
        || !is_string($body['operation_id'] ?? null)
        || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $body['operation_id'])
        || !is_int($body['base_scene_version'] ?? null) || $body['base_scene_version'] < 0) {
        throw new ApiError('invalid_operation', 'A versioned town save operation is required.', 400);
    }
    $layout = town_prototype_validate_layout($body['layout']);
    $json = json_encode($layout, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $hash = hash('sha256', json_encode(['base_scene_version' => $body['base_scene_version'],
        'layout' => $layout], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($pdo->inTransaction()) { throw new LogicException('Town save owns its transaction.'); }
    try {
        $pdo->beginTransaction();
        // Lock an existing owner row even before their first map exists, serializing first saves.
        $owner = $pdo->prepare('SELECT id FROM auth_users WHERE id=:user' . ($mysql ? ' FOR UPDATE' : ''));
        $owner->execute([':user' => $userId]);
        if ($owner->fetchColumn() === false) {
            throw new ApiError('invalid_session', 'The session is invalid or expired.', 401);
        }
        $statement = $pdo->prepare('SELECT scene_version,layout_json,last_operation_id,last_request_hash,updated_at '
            . 'FROM tree_town_prototype_profiles WHERE user_id=:user' . ($mysql ? ' FOR UPDATE' : ''));
        $statement->execute([':user' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row !== false && $row['last_operation_id'] === $body['operation_id']) {
            if (!hash_equals((string)$row['last_request_hash'], $hash)) {
                throw new ApiError('operation_conflict', 'This town save identifier was already used.', 409);
            }
            $state = town_prototype_state_from_row($row);
            $pdo->commit();
            return $state + ['duplicate' => true];
        }
        $version = $row === false ? 0 : (int)$row['scene_version'];
        if ($version !== $body['base_scene_version']) {
            throw new ApiError('scene_conflict', 'The cloud town changed. Reload before saving.', 409);
        }
        $time = auth_sql_time($now ?? api_clock());
        $parameters = [':user' => $userId, ':version' => $version + 1, ':layout' => $json,
            ':operation' => $body['operation_id'], ':hash' => $hash, ':updated' => $time];
        if ($row === false) {
            $parameters[':created'] = $time;
            $save = $pdo->prepare('INSERT INTO tree_town_prototype_profiles '
                . '(user_id,scene_version,layout_json,last_operation_id,last_request_hash,created_at,updated_at) '
                . 'VALUES (:user,:version,:layout,:operation,:hash,:created,:updated)');
        } else {
            $parameters[':base'] = $version;
            $save = $pdo->prepare('UPDATE tree_town_prototype_profiles SET scene_version=:version,layout_json=:layout,'
                . 'last_operation_id=:operation,last_request_hash=:hash,updated_at=:updated '
                . 'WHERE user_id=:user AND scene_version=:base');
        }
        $save->execute($parameters);
        if ($row !== false && $save->rowCount() !== 1) {
            throw new ApiError('scene_conflict', 'The cloud town changed. Reload before saving.', 409);
        }
        $pdo->commit();
        return ['scene_version' => $version + 1, 'layout' => $layout, 'updated_at' => $time, 'duplicate' => false];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}
