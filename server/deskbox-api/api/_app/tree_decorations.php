<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }

const TREE_DECORATION_CATALOG_VERSION = 1;
const TREE_DECORATION_SINGLE_PRICE = 200;
const TREE_DECORATION_TEN_PRICE = 1800;
const TREE_DECORATION_MAX_PLACEMENTS = 20;

function tree_decoration_catalog(): array
{
    return [
        'catalog_version' => TREE_DECORATION_CATALOG_VERSION,
        'single_price' => TREE_DECORATION_SINGLE_PRICE,
        'ten_price' => TREE_DECORATION_TEN_PRICE,
        'rates' => ['common' => 70, 'rare' => 25, 'theme' => 5],
        'items' => [
            tree_decoration_entry('red-lantern', '红灯笼', 'tree', 'common'),
            tree_decoration_entry('wishing-ribbons', '祈愿带', 'tree', 'common'),
            tree_decoration_entry('wind-chime', '风铃', 'tree', 'common'),
            tree_decoration_entry('birdhouse', '鸟屋', 'tree', 'common'),
            tree_decoration_entry('mushroom-cluster', '蘑菇簇', 'ground', 'common'),
            tree_decoration_entry('wildflower-bed', '野花圃', 'ground', 'common'),
            tree_decoration_entry('stepping-stones', '汀步石', 'ground', 'common'),
            tree_decoration_entry('watering-can', '浇水壶', 'ground', 'common'),
            tree_decoration_entry('fruit-basket', '果篮', 'ground', 'common'),
            tree_decoration_entry('stone-lantern', '石灯', 'ground', 'common'),
            tree_decoration_entry('low-fence', '矮栅栏', 'ground', 'common'),
            tree_decoration_entry('small-mailbox', '小邮箱', 'ground', 'common'),
            tree_decoration_entry('star-lights', '星星彩灯', 'tree', 'rare'),
            tree_decoration_entry('tree-door', '树洞小门', 'tree', 'rare'),
            tree_decoration_entry('evergreen-ivy', '常青藤', 'tree', 'rare'),
            tree_decoration_entry('wooden-nameplate', '木制铭牌', 'tree', 'rare'),
            tree_decoration_entry('wooden-bench', '长椅', 'large', 'rare'),
            tree_decoration_entry('branch-swing', '秋千', 'large', 'rare'),
            tree_decoration_entry('picnic-rug', '野餐垫', 'large', 'rare'),
            tree_decoration_entry('small-fountain', '小喷泉', 'large', 'rare'),
            tree_decoration_entry('spring-garden', '春日花园', 'theme', 'theme'),
            tree_decoration_entry('midsummer-camp', '仲夏露营', 'theme', 'theme'),
            tree_decoration_entry('autumn-harvest', '秋收庭院', 'theme', 'theme'),
            tree_decoration_entry('winter-starlight', '冬日星光', 'theme', 'theme'),
        ],
    ];
}

function tree_decoration_entry(string $id, string $name, string $category, string $rarity): array
{
    return ['id' => $id, 'name' => $name, 'category' => $category,
        'rarity' => $rarity, 'asset_key' => $id];
}

function tree_decoration_item(string $id): array
{
    foreach (tree_decoration_catalog()['items'] as $item) {
        if ($item['id'] === $id) { return $item; }
    }
    throw new ApiError('invalid_item', 'Unknown tree decoration.', 400);
}

function tree_decoration_read_state(PDO $pdo, string $userId): array
{
    $tree = $pdo->prepare('SELECT stage FROM tree_profiles WHERE user_id = :user');
    $tree->execute([':user' => $userId]);
    $stage = (int)($tree->fetchColumn() ?: 1);
    $wallet = $pdo->prepare('SELECT initialized, coins FROM aquarium_profiles WHERE user_id = :user');
    $wallet->execute([':user' => $userId]);
    $walletState = $wallet->fetch(PDO::FETCH_ASSOC);
    $profile = $pdo->prepare('SELECT shards, scene_version, scene_json, active_theme_id '
        . 'FROM tree_decoration_profiles WHERE user_id = :user');
    $profile->execute([':user' => $userId]);
    $decoration = $profile->fetch(PDO::FETCH_ASSOC);
    $owned = $pdo->prepare('SELECT item_id FROM tree_decoration_inventory WHERE user_id = :user ORDER BY item_id');
    $owned->execute([':user' => $userId]);
    $placements = [];
    if (is_array($decoration)) {
        $decoded = json_decode((string)$decoration['scene_json'], true);
        if (is_array($decoded)) { $placements = $decoded; }
    }
    return [
        'mature' => $stage >= 5,
        'coins' => is_array($walletState) && (bool)$walletState['initialized'] ? (int)$walletState['coins'] : 0,
        'shards' => is_array($decoration) ? (int)$decoration['shards'] : 0,
        'scene_version' => is_array($decoration) ? (int)$decoration['scene_version'] : 0,
        'owned_ids' => array_map('strval', $owned->fetchAll(PDO::FETCH_COLUMN)),
        'placements' => $placements,
        'active_theme_id' => is_array($decoration) && is_string($decoration['active_theme_id'])
            ? $decoration['active_theme_id'] : null,
    ];
}

/** @param null|callable(array, int):string $selector */
function tree_decoration_mutate(PDO $pdo, string $userId, array $body, callable $clock,
    ?callable $selector = null): array
{
    $operationId = aquarium_string($body, 'operation_id');
    if (!preg_match('/\A[A-Za-z0-9_-]{16,128}\z/D', $operationId)) {
        throw new ApiError('invalid_operation', 'A stable operation identifier is required.', 400);
    }
    $action = aquarium_string($body, 'action');
    if (!in_array($action, ['draw', 'redeem', 'save_scene'], true)) {
        throw new ApiError('invalid_action', 'Unknown tree decoration action.', 400);
    }
    $requestHash = hash('sha256', json_encode(aquarium_canonical_body($body), JSON_THROW_ON_ERROR));
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $now = $clock();
    try {
        $pdo->beginTransaction();
        if ($driver === 'sqlite') {
            // Tests and local development serialize account wallet writes before taking a read snapshot.
            $pdo->prepare('UPDATE aquarium_profiles SET user_id=user_id WHERE user_id=:user')
                ->execute([':user' => $userId]);
        }
        $tree = $pdo->prepare('SELECT stage FROM tree_profiles WHERE user_id = :user'
            . ($driver === 'mysql' ? ' FOR UPDATE' : ''));
        $tree->execute([':user' => $userId]);
        $stage = (int)($tree->fetchColumn() ?: 1);
        $existing = $pdo->prepare('SELECT request_hash, response_json FROM tree_decoration_operations '
            . 'WHERE user_id = :user AND operation_id = :operation');
        $existing->execute([':user' => $userId, ':operation' => $operationId]);
        $prior = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($prior)) {
            if (!hash_equals((string)$prior['request_hash'], $requestHash)) {
                throw new ApiError('operation_conflict', 'This operation identifier was used for another request.', 409);
            }
            $response = json_decode((string)$prior['response_json'], true, 32, JSON_THROW_ON_ERROR);
            $pdo->commit();
            return $response;
        }
        if ($stage < 5) {
            throw new ApiError('tree_not_mature', 'Tree decorations unlock at stage five.', 409);
        }
        tree_decoration_ensure_profile($pdo, $userId, $now, $driver);
        $wallet = $pdo->prepare('SELECT initialized, coins FROM aquarium_profiles WHERE user_id = :user'
            . ($driver === 'mysql' ? ' FOR UPDATE' : ''));
        $wallet->execute([':user' => $userId]);
        $walletState = $wallet->fetch(PDO::FETCH_ASSOC);
        if (!is_array($walletState) || !(bool)$walletState['initialized']) {
            throw new ApiError('aquarium_not_initialized', 'Initialize the shared coin wallet first.', 409);
        }
        $extra = [];
        if ($action === 'draw') {
            $extra['draw_results'] = tree_decoration_draw($pdo, $userId, $operationId,
                $walletState, $body, $now, $selector);
        } elseif ($action === 'redeem') {
            $extra['redeemed_item_id'] = tree_decoration_redeem($pdo, $userId, $body, $now);
        } else {
            tree_decoration_save_scene($pdo, $userId, $body, $now);
        }
        $response = tree_decoration_read_state($pdo, $userId) + $extra;
        $responseJson = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $pdo->prepare('INSERT INTO tree_decoration_operations '
            . '(user_id, operation_id, action, request_hash, response_json, created_at) '
            . 'VALUES (:user, :operation, :action, :hash, :response, :created)')
            ->execute([':user' => $userId, ':operation' => $operationId, ':action' => $action,
                ':hash' => $requestHash, ':response' => $responseJson, ':created' => auth_sql_time($now)]);
        $pdo->commit();
        return $response;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}

function tree_decoration_ensure_profile(PDO $pdo, string $userId, DateTimeImmutable $now, string $driver): void
{
    $sql = 'INSERT INTO tree_decoration_profiles '
        . "(user_id, shards, scene_version, scene_json, active_theme_id, created_at, updated_at) VALUES (:user,0,0,'[]',NULL,:created,:updated) "
        . ($driver === 'sqlite' ? 'ON CONFLICT(user_id) DO NOTHING'
            : 'ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)');
    $pdo->prepare($sql)->execute([':user' => $userId,
        ':created' => auth_sql_time($now), ':updated' => auth_sql_time($now)]);
}

/** @param null|callable(array, int):string $selector */
function tree_decoration_draw(PDO $pdo, string $userId, string $operationId, array $wallet,
    array $body, DateTimeImmutable $now, ?callable $selector): array
{
    $count = $body['count'] ?? null;
    if (!is_int($count) || !in_array($count, [1, 10], true)) {
        throw new ApiError('invalid_count', 'Draw count must be one or ten.', 400);
    }
    if (($body['catalog_version'] ?? null) !== TREE_DECORATION_CATALOG_VERSION) {
        throw new ApiError('catalog_conflict', 'The tree decoration catalog changed.', 409);
    }
    $price = $count === 10 ? TREE_DECORATION_TEN_PRICE : TREE_DECORATION_SINGLE_PRICE;
    $balance = (int)$wallet['coins'] - $price;
    if ($balance < 0) { throw new ApiError('insufficient_coins', 'Not enough coins.', 409); }
    $catalog = tree_decoration_catalog()['items'];
    $ownedQuery = $pdo->prepare('SELECT item_id FROM tree_decoration_inventory WHERE user_id = :user');
    $ownedQuery->execute([':user' => $userId]);
    $owned = array_fill_keys(array_map('strval', $ownedQuery->fetchAll(PDO::FETCH_COLUMN)), true);
    $results = [];
    $shards = 0;
    for ($draw = 0; $draw < $count; $draw++) {
        $itemId = $selector === null
            ? tree_decoration_random_item_id($catalog)
            : (string)$selector($catalog, $draw);
        $item = tree_decoration_item($itemId);
        $isNew = !isset($owned[$itemId]);
        $awarded = 0;
        if ($isNew) {
            $pdo->prepare('INSERT INTO tree_decoration_inventory (user_id,item_id,acquired_at) '
                . 'VALUES (:user,:item,:created)')
                ->execute([':user' => $userId, ':item' => $itemId, ':created' => auth_sql_time($now)]);
            $owned[$itemId] = true;
        } else {
            $awarded = tree_decoration_duplicate_shards($item['rarity']);
            $shards += $awarded;
        }
        $results[] = ['item_id' => $itemId, 'rarity' => $item['rarity'],
            'is_new' => $isNew, 'shards_awarded' => $awarded];
    }
    $pdo->prepare('UPDATE aquarium_profiles SET coins=:coins,updated_at=:now WHERE user_id=:user')
        ->execute([':coins' => $balance, ':now' => auth_sql_time($now), ':user' => $userId]);
    if ($shards > 0) {
        $pdo->prepare('UPDATE tree_decoration_profiles SET shards=shards+:shards,updated_at=:now WHERE user_id=:user')
            ->execute([':shards' => $shards, ':now' => auth_sql_time($now), ':user' => $userId]);
    }
    aquarium_record_wallet($pdo, $userId, $operationId, -$price, $balance,
        'tree_decoration_draw', null, $now);
    return $results;
}

function tree_decoration_random_item_id(array $catalog): string
{
    $roll = random_int(1, 100);
    $rarity = $roll <= 70 ? 'common' : ($roll <= 95 ? 'rare' : 'theme');
    $items = array_values(array_filter($catalog,
        static fn(array $item): bool => $item['rarity'] === $rarity));
    return (string)$items[random_int(0, count($items) - 1)]['id'];
}

function tree_decoration_duplicate_shards(string $rarity): int
{
    return match ($rarity) { 'common' => 10, 'rare' => 30, 'theme' => 100,
        default => throw new ApiError('invalid_rarity', 'Unknown decoration rarity.', 500) };
}

function tree_decoration_redeem(PDO $pdo, string $userId, array $body, DateTimeImmutable $now): string
{
    $item = tree_decoration_item(aquarium_string($body, 'item_id'));
    $cost = match ($item['rarity']) { 'common' => 80, 'rare' => 240, 'theme' => 800 };
    $owned = $pdo->prepare('SELECT 1 FROM tree_decoration_inventory WHERE user_id=:user AND item_id=:item');
    $owned->execute([':user' => $userId, ':item' => $item['id']]);
    if ($owned->fetchColumn() !== false) { throw new ApiError('already_owned', 'Decoration is already owned.', 409); }
    $profile = $pdo->prepare('SELECT shards FROM tree_decoration_profiles WHERE user_id=:user');
    $profile->execute([':user' => $userId]);
    if ((int)$profile->fetchColumn() < $cost) { throw new ApiError('insufficient_shards', 'Not enough shards.', 409); }
    $pdo->prepare('UPDATE tree_decoration_profiles SET shards=shards-:cost,updated_at=:now WHERE user_id=:user')
        ->execute([':cost' => $cost, ':now' => auth_sql_time($now), ':user' => $userId]);
    $pdo->prepare('INSERT INTO tree_decoration_inventory (user_id,item_id,acquired_at) VALUES (:user,:item,:created)')
        ->execute([':user' => $userId, ':item' => $item['id'], ':created' => auth_sql_time($now)]);
    return (string)$item['id'];
}

function tree_decoration_save_scene(PDO $pdo, string $userId, array $body, DateTimeImmutable $now): void
{
    $baseVersion = $body['base_scene_version'] ?? null;
    if (!is_int($baseVersion) || $baseVersion < 0) { throw new ApiError('invalid_scene', 'Invalid scene version.', 400); }
    $profile = $pdo->prepare('SELECT scene_version FROM tree_decoration_profiles WHERE user_id=:user');
    $profile->execute([':user' => $userId]);
    if ((int)$profile->fetchColumn() !== $baseVersion) { throw new ApiError('scene_conflict', 'Cloud scene changed.', 409); }
    $placements = $body['placements'] ?? null;
    if (!is_array($placements) || count($placements) > TREE_DECORATION_MAX_PLACEMENTS) {
        throw new ApiError('invalid_scene', 'Tree decoration scene is invalid.', 400);
    }
    $ownedQuery = $pdo->prepare('SELECT item_id FROM tree_decoration_inventory WHERE user_id=:user');
    $ownedQuery->execute([':user' => $userId]);
    $owned = array_fill_keys(array_map('strval', $ownedQuery->fetchAll(PDO::FETCH_COLUMN)), true);
    $normalized = [];
    $seen = [];
    foreach ($placements as $placement) {
        if (!is_array($placement)) { throw new ApiError('invalid_scene', 'Invalid placement.', 400); }
        $id = isset($placement['item_id']) && is_string($placement['item_id']) ? $placement['item_id'] : '';
        $item = tree_decoration_item($id);
        if (!isset($owned[$id]) || isset($seen[$id]) || $item['category'] === 'theme') {
            throw new ApiError('invalid_scene', 'Only owned placeable decorations may be used once.', 400);
        }
        $x = tree_decoration_number($placement, 'x', 0, 1);
        $y = tree_decoration_number($placement, 'y', 0, 1);
        $scale = tree_decoration_number($placement, 'scale', .5, 1.5);
        $rotation = tree_decoration_number($placement, 'rotation', -45, 45);
        $layer = $placement['layer'] ?? null;
        if (!is_int($layer) || $layer < -2 || $layer > 2) {
            throw new ApiError('invalid_scene', 'Decoration layer must be from -2 to 2.', 400);
        }
        $seen[$id] = true;
        $normalized[] = ['item_id' => $id, 'x' => $x, 'y' => $y,
            'scale' => $scale, 'rotation' => $rotation, 'layer' => $layer];
    }
    $theme = $body['active_theme_id'] ?? null;
    if ($theme !== null) {
        if (!is_string($theme) || !isset($owned[$theme]) || tree_decoration_item($theme)['category'] !== 'theme') {
            throw new ApiError('invalid_scene', 'Active theme must be an owned theme.', 400);
        }
    }
    $pdo->prepare('UPDATE tree_decoration_profiles SET scene_json=:scene,active_theme_id=:theme,scene_version=scene_version+1,updated_at=:now WHERE user_id=:user')
        ->execute([':scene' => json_encode($normalized, JSON_THROW_ON_ERROR), ':theme' => $theme,
            ':now' => auth_sql_time($now), ':user' => $userId]);
}

function tree_decoration_number(array $body, string $key, float $minimum, float $maximum): float
{
    $value = $body[$key] ?? null;
    if (!is_int($value) && !is_float($value) || !is_finite((float)$value)
        || (float)$value < $minimum || (float)$value > $maximum) {
        throw new ApiError('invalid_scene', 'Decoration coordinate is out of range.', 400);
    }
    return (float)$value;
}
