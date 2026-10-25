<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }

const TREE_DECORATION_CATALOG_VERSION = 2;
const TREE_DECORATION_MAX_PLACEMENTS = 20;

function tree_decoration_catalog(?PDO $pdo = null): array
{
    $prices = tree_decoration_pack_prices($pdo);
    return [
        'catalog_version' => TREE_DECORATION_CATALOG_VERSION,
        'single_price' => $prices['single_price'],
        'ten_price' => $prices['ten_price'],
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

function tree_decoration_pack_prices(?PDO $pdo = null): array
{
    if ($pdo === null) { return ['single_price' => 200, 'ten_price' => 1800]; }
    $row = $pdo->query('SELECT tree_card_pack_single_price, tree_card_pack_ten_price '
        . 'FROM aquarium_catalog_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    return [
        'single_price' => (int)($row['tree_card_pack_single_price'] ?? 200),
        'ten_price' => (int)($row['tree_card_pack_ten_price'] ?? 1800),
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

/** @return array<string,int> */
function tree_decoration_inventory_quantities(PDO $pdo, string $userId): array
{
    $query = $pdo->prepare('SELECT item_id, quantity FROM tree_decoration_inventory '
        . 'WHERE user_id = :user ORDER BY item_id');
    $query->execute([':user' => $userId]);
    $inventory = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $quantity = max(0, (int)$row['quantity']);
        if ($quantity > 0) { $inventory[(string)$row['item_id']] = $quantity; }
    }
    return $inventory;
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
    $quantities = tree_decoration_inventory_quantities($pdo, $userId);
    $inventory = [];
    foreach ($quantities as $itemId => $quantity) {
        $inventory[] = ['item_id' => $itemId, 'quantity' => $quantity];
    }
    $packs = $pdo->prepare('SELECT id, draw_count, created_at FROM tree_decoration_card_packs '
        . 'WHERE user_id = :user ORDER BY created_at, id');
    $packs->execute([':user' => $userId]);
    $cardPacks = [];
    foreach ($packs->fetchAll(PDO::FETCH_ASSOC) as $pack) {
        $cardPacks[] = ['id' => (string)$pack['id'], 'draw_count' => (int)$pack['draw_count'],
            'created_at' => auth_parse_sql_time((string)$pack['created_at'])->format(DateTimeInterface::ATOM)];
    }
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
        'owned_ids' => array_keys($quantities),
        'inventory' => $inventory,
        'card_packs' => $cardPacks,
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
    if (!in_array($action, ['buy_pack', 'open_pack', 'redeem', 'save_scene'], true)) {
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
        if ($action === 'buy_pack') {
            $extra['purchased_pack_id'] = tree_decoration_buy_pack($pdo, $userId, $operationId,
                $walletState, $body, $now);
        } elseif ($action === 'open_pack') {
            $extra['draw_results'] = tree_decoration_open_pack($pdo, $userId, $operationId,
                $walletState, $body, $now, $selector, $driver);
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

function tree_decoration_buy_pack(PDO $pdo, string $userId, string $operationId, array $wallet,
    array $body, DateTimeImmutable $now): string
{
    $count = $body['draw_count'] ?? null;
    if (!is_int($count) || !in_array($count, [1, 10], true)) {
        throw new ApiError('invalid_count', 'Draw count must be one or ten.', 400);
    }
    if (($body['catalog_version'] ?? null) !== TREE_DECORATION_CATALOG_VERSION) {
        throw new ApiError('catalog_conflict', 'The tree decoration catalog changed.', 409);
    }
    $prices = tree_decoration_pack_prices($pdo);
    $price = $count === 10 ? $prices['ten_price'] : $prices['single_price'];
    $balance = (int)$wallet['coins'] - $price;
    if ($balance < 0) { throw new ApiError('insufficient_coins', 'Not enough coins.', 409); }
    $packId = auth_random_id();
    $pdo->prepare('INSERT INTO tree_decoration_card_packs (id,user_id,draw_count,created_at) '
        . 'VALUES (:id,:user,:draws,:created)')
        ->execute([':id' => $packId, ':user' => $userId, ':draws' => $count,
            ':created' => auth_sql_time($now)]);
    $pdo->prepare('UPDATE aquarium_profiles SET coins=:coins,updated_at=:now WHERE user_id=:user')
        ->execute([':coins' => $balance, ':now' => auth_sql_time($now), ':user' => $userId]);
    aquarium_record_wallet($pdo, $userId, $operationId, -$price, $balance,
        'tree_decoration_pack_purchase', 'tree-decoration-pack-' . $count, $now);
    return $packId;
}

/** @param null|callable(array, int):string $selector */
function tree_decoration_open_pack(PDO $pdo, string $userId, string $operationId, array $wallet,
    array $body, DateTimeImmutable $now, ?callable $selector, string $driver): array
{
    $packId = $body['pack_instance_id'] ?? null;
    if (!is_string($packId) || !preg_match('/\A[a-f0-9]{32}\z/D', $packId)) {
        throw new ApiError('invalid_pack', 'A valid tree card pack instance is required.', 400);
    }
    $pack = $pdo->prepare('SELECT draw_count FROM tree_decoration_card_packs '
        . 'WHERE user_id=:user AND id=:id' . ($driver === 'mysql' ? ' FOR UPDATE' : ''));
    $pack->execute([':user' => $userId, ':id' => strtolower($packId)]);
    $count = $pack->fetchColumn();
    if ($count === false || !in_array((int)$count, [1, 10], true)) {
        throw new ApiError('pack_not_found', 'The tree card pack is no longer in the backpack.', 409);
    }
    $deleted = $pdo->prepare('DELETE FROM tree_decoration_card_packs WHERE user_id=:user AND id=:id');
    $deleted->execute([':user' => $userId, ':id' => strtolower($packId)]);
    if ($deleted->rowCount() !== 1) {
        throw new ApiError('pack_not_found', 'The tree card pack is no longer in the backpack.', 409);
    }
    $results = tree_decoration_draw_rewards($pdo, $userId, (int)$count, $now, $selector);
    aquarium_record_wallet($pdo, $userId, $operationId, 0, (int)$wallet['coins'],
        'tree_decoration_pack_open', 'tree-decoration-pack-' . (int)$count, $now);
    return $results;
}

/** @param null|callable(array, int):string $selector */
function tree_decoration_draw_rewards(PDO $pdo, string $userId, int $count,
    DateTimeImmutable $now, ?callable $selector): array
{
    $catalog = tree_decoration_catalog()['items'];
    $owned = tree_decoration_inventory_quantities($pdo, $userId);
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
            $pdo->prepare('INSERT INTO tree_decoration_inventory (user_id,item_id,quantity,acquired_at) '
                . 'VALUES (:user,:item,1,:created)')
                ->execute([':user' => $userId, ':item' => $itemId, ':created' => auth_sql_time($now)]);
            $owned[$itemId] = 1;
        } elseif ($item['category'] !== 'theme') {
            $pdo->prepare('UPDATE tree_decoration_inventory SET quantity=quantity+1 '
                . 'WHERE user_id=:user AND item_id=:item')
                ->execute([':user' => $userId, ':item' => $itemId]);
            $owned[$itemId]++;
        } else {
            $awarded = tree_decoration_duplicate_shards($item['rarity']);
            $shards += $awarded;
        }
        $results[] = ['item_id' => $itemId, 'rarity' => $item['rarity'],
            'is_new' => $isNew, 'shards_awarded' => $awarded, 'quantity' => $owned[$itemId]];
    }
    if ($shards > 0) {
        $pdo->prepare('UPDATE tree_decoration_profiles SET shards=shards+:shards,updated_at=:now WHERE user_id=:user')
            ->execute([':shards' => $shards, ':now' => auth_sql_time($now), ':user' => $userId]);
    }
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
    $owned = $pdo->prepare('SELECT quantity FROM tree_decoration_inventory WHERE user_id=:user AND item_id=:item');
    $owned->execute([':user' => $userId, ':item' => $item['id']]);
    $ownedQuantity = $owned->fetchColumn();
    if ($ownedQuantity !== false && $item['category'] === 'theme') {
        throw new ApiError('already_owned', 'Theme is already owned.', 409);
    }
    $profile = $pdo->prepare('SELECT shards FROM tree_decoration_profiles WHERE user_id=:user');
    $profile->execute([':user' => $userId]);
    if ((int)$profile->fetchColumn() < $cost) { throw new ApiError('insufficient_shards', 'Not enough shards.', 409); }
    $pdo->prepare('UPDATE tree_decoration_profiles SET shards=shards-:cost,updated_at=:now WHERE user_id=:user')
        ->execute([':cost' => $cost, ':now' => auth_sql_time($now), ':user' => $userId]);
    if ($ownedQuantity === false) {
        $pdo->prepare('INSERT INTO tree_decoration_inventory (user_id,item_id,quantity,acquired_at) '
            . 'VALUES (:user,:item,1,:created)')
            ->execute([':user' => $userId, ':item' => $item['id'], ':created' => auth_sql_time($now)]);
    } else {
        $pdo->prepare('UPDATE tree_decoration_inventory SET quantity=quantity+1 '
            . 'WHERE user_id=:user AND item_id=:item')
            ->execute([':user' => $userId, ':item' => $item['id']]);
    }
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
    $owned = tree_decoration_inventory_quantities($pdo, $userId);
    $normalized = [];
    $placedCounts = [];
    $placementIds = [];
    foreach ($placements as $placement) {
        if (!is_array($placement)) { throw new ApiError('invalid_scene', 'Invalid placement.', 400); }
        $id = isset($placement['item_id']) && is_string($placement['item_id']) ? $placement['item_id'] : '';
        $item = tree_decoration_item($id);
        $placedCounts[$id] = ($placedCounts[$id] ?? 0) + 1;
        if (!isset($owned[$id]) || $placedCounts[$id] > $owned[$id] || $item['category'] === 'theme') {
            throw new ApiError('invalid_scene', 'Placed decorations cannot exceed the owned quantity.', 400);
        }
        $placementId = $placement['placement_id'] ?? null;
        if ($placementId === null || $placementId === '') { $placementId = auth_random_id(); }
        if (!is_string($placementId)
            || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $placementId)
            || isset($placementIds[$placementId])) {
            throw new ApiError('invalid_scene', 'Each decoration placement needs a unique identifier.', 400);
        }
        $x = tree_decoration_number($placement, 'x', 0, 1);
        $y = tree_decoration_number($placement, 'y', 0, 1);
        $scale = tree_decoration_number($placement, 'scale', .5, 1.5);
        $rotation = tree_decoration_number($placement, 'rotation', -45, 45);
        $layer = $placement['layer'] ?? null;
        if (!is_int($layer) || $layer < -2 || $layer > 2) {
            throw new ApiError('invalid_scene', 'Decoration layer must be from -2 to 2.', 400);
        }
        $placementIds[$placementId] = true;
        $normalized[] = ['placement_id' => $placementId, 'item_id' => $id, 'x' => $x, 'y' => $y,
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
