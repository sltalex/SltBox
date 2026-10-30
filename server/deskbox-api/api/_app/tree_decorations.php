<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }

const TREE_DECORATION_CATALOG_VERSION = 3;
const TREE_DECORATION_MAX_PLACEMENTS = 20;
const TREE_DECORATION_RATES = ['common' => 75, 'rare' => 25];
const TREE_DECORATION_SUBCATEGORIES = [
    'tree' => [
        'lanterns', 'hanging_garden', 'bird_home', 'wind_chime', 'ribbons_signs',
        'fruit_seed', 'carving_masks', 'bird_cages', 'celestial', 'glass_charms',
    ],
    'ground' => [
        'flora', 'stones', 'water', 'containers', 'furniture', 'lighting',
        'signs', 'paths', 'play', 'craft',
    ],
    'large' => [
        'gates', 'pavilions', 'bridges', 'seating', 'shelters', 'greenhouses',
        'carts', 'towers', 'water_features', 'sculptures',
    ],
];
const TREE_DECORATION_LEGACY_IDS = [
    'red-lantern', 'wishing-ribbons', 'wind-chime', 'birdhouse', 'mushroom-cluster',
    'wildflower-bed', 'stepping-stones', 'watering-can', 'fruit-basket', 'stone-lantern', 'low-fence',
    'small-mailbox', 'star-lights', 'tree-door', 'evergreen-ivy', 'wooden-nameplate', 'wooden-bench',
    'branch-swing', 'picnic-rug', 'small-fountain',
];

/** @return array{items:array,by_id:array,by_rarity:array,legacy_items:array,legacy_by_rarity:array} */
function tree_decoration_catalog_definition(): array
{
    static $definition = null;
    if ($definition !== null) { return $definition; }
    $path = dirname(__DIR__, 2) . '/config/tree-decorations.json';
    $contents = file_get_contents($path);
    if ($contents === false) { throw new RuntimeException('Tree decoration catalog is unavailable.'); }
    $document = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($document) || ($document['catalog_version'] ?? null) !== TREE_DECORATION_CATALOG_VERSION
        || !is_array($document['items'] ?? null) || !array_is_list($document['items'])) {
        throw new RuntimeException('Tree decoration catalog is invalid.');
    }
    $items = [];
    $byId = [];
    $byRarity = ['common' => [], 'rare' => []];
    $legacyItems = [];
    $legacyByRarity = ['common' => [], 'rare' => []];
    $legacyIds = array_fill_keys(TREE_DECORATION_LEGACY_IDS, true);
    $names = [];
    foreach ($document['items'] as $item) {
        if (!is_array($item)) { throw new RuntimeException('Tree decoration catalog item is invalid.'); }
        foreach (['id', 'name', 'category', 'rarity', 'asset_key'] as $field) {
            if (!is_string($item[$field] ?? null) || trim($item[$field]) === '') {
                throw new RuntimeException('Tree decoration catalog item has a missing field.');
            }
        }
        // Subcategories were added to catalog v3 after the initial expansion.
        // Keep a category fallback while old v3 catalogs are being rolled out,
        // but require the new catalog to provide a stable slug when present.
        $subcategory = tree_decoration_subcategory($item);
        if (!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $item['id'])
            || !preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $item['asset_key'])
            || !in_array($item['category'], ['tree', 'ground', 'large'], true)
            || !isset($byRarity[$item['rarity']]) || isset($byId[$item['id']]) || isset($names[$item['name']])) {
            throw new RuntimeException('Tree decoration catalog contains an invalid or duplicate item.');
        }
        if (array_key_exists('subcategory', $item)
            && !in_array($subcategory, TREE_DECORATION_SUBCATEGORIES[$item['category']], true)) {
            throw new RuntimeException('Tree decoration catalog contains an invalid subcategory.');
        }
        $entry = array_intersect_key($item, array_flip(['id', 'name', 'category', 'subcategory', 'rarity', 'asset_key']));
        $entry['subcategory'] = $subcategory;
        $items[] = $entry;
        $byId[$entry['id']] = $entry;
        $byRarity[$entry['rarity']][] = $entry['id'];
        if (isset($legacyIds[$entry['id']])) {
            $legacyItems[] = $entry;
            $legacyByRarity[$entry['rarity']][] = $entry['id'];
        }
        $names[$entry['name']] = true;
    }
    if (count($legacyItems) !== count(TREE_DECORATION_LEGACY_IDS)) {
        throw new RuntimeException('Tree decoration catalog is missing legacy products.');
    }
    foreach (array_merge(array_values($byRarity), array_values($legacyByRarity)) as $pool) {
        if ($pool === []) { throw new RuntimeException('Tree decoration rarity pool is empty.'); }
    }
    $definition = ['items' => $items, 'by_id' => $byId, 'by_rarity' => $byRarity,
        'legacy_items' => $legacyItems, 'legacy_by_rarity' => $legacyByRarity];
    return $definition;
}

function tree_decoration_subcategory(array $item): string
{
    $subcategory = $item['subcategory'] ?? $item['category'] ?? null;
    if (!is_string($subcategory) || trim($subcategory) === ''
        || !preg_match('/\A[a-z0-9]+(?:[_-][a-z0-9]+)*\z/D', $subcategory)) {
        throw new RuntimeException('Tree decoration catalog contains an invalid subcategory.');
    }
    return trim($subcategory);
}

function tree_decoration_catalog(?PDO $pdo = null, int $assetVersion = TREE_DECORATION_CATALOG_VERSION): array
{
    $prices = tree_decoration_pack_prices($pdo);
    return [
        'catalog_version' => TREE_DECORATION_CATALOG_VERSION,
        'single_price' => $prices['single_price'],
        'ten_price' => $prices['ten_price'],
        'rates' => TREE_DECORATION_RATES,
        'items' => tree_decoration_catalog_definition()[
            $assetVersion >= TREE_DECORATION_CATALOG_VERSION ? 'items' : 'legacy_items'],
    ];
}

function tree_decoration_asset_version(mixed $value): int
{
    if (is_int($value)) { return max(0, $value); }
    if (is_string($value) && preg_match('/\A[0-9]{1,6}\z/D', $value)) { return (int)$value; }
    return 0;
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

function tree_decoration_item(string $id): array
{
    $item = tree_decoration_catalog_definition()['by_id'][$id] ?? null;
    if ($item !== null) { return $item; }
    throw new ApiError('invalid_item', 'Unknown tree decoration.', 400);
}

/** @return array<string,int> */
function tree_decoration_inventory_quantities(PDO $pdo, string $userId): array
{
    $query = $pdo->prepare('SELECT item_id, quantity FROM tree_decoration_inventory '
        . 'WHERE user_id = :user ORDER BY item_id');
    $query->execute([':user' => $userId]);
    $activeItems = tree_decoration_catalog_definition()['by_id'];
    $inventory = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $quantity = max(0, (int)$row['quantity']);
        if ($quantity > 0 && isset($activeItems[(string)$row['item_id']])) {
            $inventory[(string)$row['item_id']] = $quantity;
        }
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
        if (is_array($decoded)) {
            $activeItems = tree_decoration_catalog_definition()['by_id'];
            $placements = array_values(array_filter($decoded,
                static fn($placement): bool => is_array($placement)
                    && isset($activeItems[$placement['item_id'] ?? ''])));
        }
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
        'active_theme_id' => null,
    ];
}

/** Completed operations remain idempotent while retired products stay out of current client state. */
function tree_decoration_filter_retired_items(array $response, int $assetVersion = TREE_DECORATION_CATALOG_VERSION): array
{
    $definition = tree_decoration_catalog_definition();
    $activeItems = $assetVersion >= TREE_DECORATION_CATALOG_VERSION
        ? $definition['by_id'] : array_fill_keys(TREE_DECORATION_LEGACY_IDS, true);
    foreach (['inventory', 'placements', 'draw_results'] as $field) {
        if (isset($response[$field]) && is_array($response[$field])) {
            $response[$field] = array_values(array_filter($response[$field],
                static fn($entry): bool => is_array($entry) && isset($activeItems[$entry['item_id'] ?? ''])));
        }
    }
    if (isset($response['owned_ids']) && is_array($response['owned_ids'])) {
        $response['owned_ids'] = array_values(array_filter($response['owned_ids'],
            static fn($id): bool => is_string($id) && isset($activeItems[$id])));
    }
    if (isset($response['redeemed_item_id']) && !isset($activeItems[$response['redeemed_item_id']])) {
        unset($response['redeemed_item_id']);
    }
    $response['active_theme_id'] = null;
    return $response;
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
            return tree_decoration_filter_retired_items($response,
                tree_decoration_asset_version($body['asset_version'] ?? null));
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
        $response = tree_decoration_filter_retired_items(
            tree_decoration_read_state($pdo, $userId) + $extra,
            tree_decoration_asset_version($body['asset_version'] ?? null));
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
    $results = tree_decoration_draw_rewards($pdo, $userId, (int)$count, $now, $selector,
        tree_decoration_asset_version($body['asset_version'] ?? null));
    aquarium_record_wallet($pdo, $userId, $operationId, 0, (int)$wallet['coins'],
        'tree_decoration_pack_open', 'tree-decoration-pack-' . (int)$count, $now);
    return $results;
}

/** @param null|callable(array, int):string $selector */
function tree_decoration_draw_rewards(PDO $pdo, string $userId, int $count,
    DateTimeImmutable $now, ?callable $selector, int $assetVersion = TREE_DECORATION_CATALOG_VERSION): array
{
    $catalog = tree_decoration_catalog(null, $assetVersion)['items'];
    $allowedIds = array_fill_keys(array_column($catalog, 'id'), true);
    $owned = tree_decoration_inventory_quantities($pdo, $userId);
    $results = [];
    for ($draw = 0; $draw < $count; $draw++) {
        $itemId = $selector === null
            ? tree_decoration_random_item_id(null, $assetVersion)
            : (string)$selector($catalog, $draw);
        if (!isset($allowedIds[$itemId])) {
            throw new ApiError('invalid_item', 'Unknown tree decoration for this client asset version.', 400);
        }
        $item = tree_decoration_item($itemId);
        $isNew = !isset($owned[$itemId]);
        if ($isNew) {
            $pdo->prepare('INSERT INTO tree_decoration_inventory (user_id,item_id,quantity,acquired_at) '
                . 'VALUES (:user,:item,1,:created)')
                ->execute([':user' => $userId, ':item' => $itemId, ':created' => auth_sql_time($now)]);
            $owned[$itemId] = 1;
        } else {
            $pdo->prepare('UPDATE tree_decoration_inventory SET quantity=quantity+1 '
                . 'WHERE user_id=:user AND item_id=:item')
                ->execute([':user' => $userId, ':item' => $itemId]);
            $owned[$itemId]++;
        }
        $results[] = ['item_id' => $itemId, 'rarity' => $item['rarity'],
            'is_new' => $isNew, 'shards_awarded' => 0, 'quantity' => $owned[$itemId]];
    }
    return $results;
}

/** @param null|callable(int,int):int $random */
function tree_decoration_random_item_id(?callable $random = null, int $assetVersion = TREE_DECORATION_CATALOG_VERSION): string
{
    $random ??= 'random_int';
    $roll = $random(1, 100);
    $rarity = $roll <= TREE_DECORATION_RATES['common'] ? 'common' : 'rare';
    $items = tree_decoration_catalog_definition()[
        $assetVersion >= TREE_DECORATION_CATALOG_VERSION ? 'by_rarity' : 'legacy_by_rarity'][$rarity];
    return $items[$random(0, count($items) - 1)];
}

function tree_decoration_redeem(PDO $pdo, string $userId, array $body, DateTimeImmutable $now): string
{
    $item = tree_decoration_item(aquarium_string($body, 'item_id'));
    $cost = match ($item['rarity']) { 'common' => 80, 'rare' => 240 };
    $owned = $pdo->prepare('SELECT quantity FROM tree_decoration_inventory WHERE user_id=:user AND item_id=:item');
    $owned->execute([':user' => $userId, ':item' => $item['id']]);
    $ownedQuantity = $owned->fetchColumn();
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
        if ($placement instanceof stdClass) { $placement = get_object_vars($placement); }
        if (!is_array($placement)) { throw new ApiError('invalid_scene', 'Invalid placement.', 400); }
        $id = isset($placement['item_id']) && is_string($placement['item_id']) ? $placement['item_id'] : '';
        $item = tree_decoration_item($id);
        $placedCounts[$id] = ($placedCounts[$id] ?? 0) + 1;
        if (!isset($owned[$id]) || $placedCounts[$id] > $owned[$id]) {
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
    $pdo->prepare('UPDATE tree_decoration_profiles SET scene_json=:scene,active_theme_id=:theme,scene_version=scene_version+1,updated_at=:now WHERE user_id=:user')
        ->execute([':scene' => json_encode($normalized, JSON_THROW_ON_ERROR), ':theme' => null,
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
