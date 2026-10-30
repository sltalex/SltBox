<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/account_data.php';
require_once dirname(__DIR__) . '/api/_app/aquarium.php';
require_once dirname(__DIR__) . '/api/_app/tree_decorations.php';

function decor_expect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function decor_error(string $code, callable $operation): void
{
    try { $operation(); }
    catch (ApiError $error) {
        decor_expect($error->apiCode === $code, "Expected {$code}, got {$error->apiCode}");
        return;
    }
    throw new RuntimeException('Expected API error ' . $code);
}

function decor_database(string $dsn = 'sqlite::memory:'): PDO
{
    $pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

function decor_create_user(PDO $pdo, string $name, string $device, callable $clock): string
{
    $account = auth_register($pdo, $name, 'secret1', $device, '127.0.0.1', $clock);
    $userId = $account['user']['id'];
    $pdo->prepare("INSERT INTO tree_profiles (user_id,participation_enabled,total_energy,fruit_total,harvested_total,stage,state_version,fruit_energy,updated_at) VALUES (?,1,180000,0,0,5,0,0,?)")
        ->execute([$userId, auth_sql_time($clock())]);
    aquarium_mutate($pdo, $userId,
        ['action' => 'initialize', 'operation_id' => 'initialize-' . $device], $clock);
    return $userId;
}

function decor_buy(PDO $pdo, string $userId, int $drawCount, string $operation, callable $clock): array
{
    return tree_decoration_mutate($pdo, $userId, [
        'action' => 'buy_pack', 'operation_id' => $operation,
        'catalog_version' => TREE_DECORATION_CATALOG_VERSION, 'draw_count' => $drawCount,
    ], $clock);
}

function decor_open(PDO $pdo, string $userId, string $packId, string $operation,
    callable $clock, callable $selector, ?int $assetVersion = 3): array
{
    $request = [
        'action' => 'open_pack', 'operation_id' => $operation, 'pack_instance_id' => $packId,
    ];
    if ($assetVersion !== null) { $request['asset_version'] = $assetVersion; }
    return tree_decoration_mutate($pdo, $userId, $request, $clock, $selector);
}

$clock = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-25T08:00:00Z');
$pdo = decor_database();
$pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
$userId = decor_create_user($pdo, 'tree_decorator', 'decor-device', $clock);

$catalog = tree_decoration_catalog($pdo);
decor_expect($catalog['catalog_version'] === 3 && count($catalog['items']) === 20,
    'Tree decoration catalog v3 contains only the original twenty decorations.');
decor_expect($catalog['single_price'] === 200 && $catalog['ten_price'] === 1800,
    'Tree pack prices expose the independently configured one-draw and ten-draw products.');
foreach (['tree' => 8, 'ground' => 8, 'large' => 4] as $category => $expected) {
    decor_expect(count(array_filter($catalog['items'], fn(array $item): bool => $item['category'] === $category)) === $expected,
        "Catalog keeps only the original {$category} decorations.");
}
decor_expect($catalog['rates'] === ['common' => 75, 'rare' => 25] && array_sum($catalog['rates']) === 100,
    'Retired theme probability moves to common while rare keeps its existing twenty-five percent.');
decor_expect(count(array_filter($catalog['items'], fn(array $item): bool =>
    $item['category'] === 'theme' || !in_array($item['rarity'], ['common', 'rare'], true))) === 0,
    'Neither the active catalog nor draw rates contains themes.');
decor_expect(count(array_unique(array_column($catalog['items'], 'id'))) === 20
    && count(array_unique(array_column($catalog['items'], 'name'))) === 20,
    'Every catalog entry has a distinct stable identifier and name.');
decor_expect(count(array_unique(array_column($catalog['items'], 'subcategory'))) >= 3
    && count(array_filter($catalog['items'], static fn(array $item): bool =>
        is_string($item['subcategory'] ?? null)
        && preg_match('/\A[a-z0-9]+(?:[_-][a-z0-9]+)*\z/D', $item['subcategory']) === 1)) === 20,
    'Every decoration exposes a stable slug subcategory for the encyclopedia and backpack filters.');
decor_expect(tree_decoration_subcategory(['category' => 'tree']) === 'tree'
    && tree_decoration_subcategory(['category' => 'tree', 'subcategory' => 'hanging-lights']) === 'hanging-lights',
    'Subcategory parsing falls back to the placement category for old catalogs and preserves new slugs.');
try {
    tree_decoration_subcategory(['category' => 'tree', 'subcategory' => 'Bad Name']);
    throw new RuntimeException('Expected invalid subcategory to fail.');
} catch (RuntimeException $error) {
    decor_expect(str_contains($error->getMessage(), 'invalid subcategory'),
        'Invalid subcategory values are rejected before catalog output.');
}
$legacyIds = ['red-lantern', 'wishing-ribbons', 'wind-chime', 'birdhouse', 'mushroom-cluster',
    'wildflower-bed', 'stepping-stones', 'watering-can', 'fruit-basket', 'stone-lantern', 'low-fence',
    'small-mailbox', 'star-lights', 'tree-door', 'evergreen-ivy', 'wooden-nameplate', 'wooden-bench',
    'branch-swing', 'picnic-rug', 'small-fountain'];
foreach ($legacyIds as $id) { decor_expect(tree_decoration_item($id)['id'] === $id, "Legacy {$id} remains usable."); }
$byRarity = tree_decoration_catalog_definition()['by_rarity'];
$legacyCatalog = tree_decoration_catalog($pdo, tree_decoration_asset_version(null));
decor_expect($legacyCatalog['catalog_version'] === 3 && count($legacyCatalog['items']) === 20
    && array_column($legacyCatalog['items'], 'id') === $legacyIds,
    'Clients without expanded assets receive the original twenty non-theme products with a purchasable current catalog version.');
foreach ([null, 0, 2, '2', 'invalid', []] as $version) {
    decor_expect(count(tree_decoration_catalog($pdo, tree_decoration_asset_version($version))['items']) === 20,
        'Absent, malformed and old asset versions use the safe legacy catalog.');
}
decor_expect(count(tree_decoration_catalog($pdo, tree_decoration_asset_version('3'))['items']) === 20,
    'The HTTP asset-version parameter serves the current catalog.');
for ($roll = 1; $roll <= 100; $roll++) {
    foreach ([0, 3] as $assetVersion) foreach ([false, true] as $last) {
        $calls = 0;
        $id = tree_decoration_random_item_id(static function (int $minimum, int $maximum) use ($roll, $last, &$calls): int {
            return $calls++ === 0 ? $roll : ($last ? $maximum : $minimum);
        }, $assetVersion);
        $rarity = $roll <= 75 ? 'common' : 'rare';
        $pool = tree_decoration_catalog_definition()[$assetVersion < 3 ? 'legacy_by_rarity' : 'by_rarity'][$rarity];
        decor_expect($id === ($last ? $pool[count($pool) - 1] : $pool[0]),
            "Every probability roll and both item-pool boundaries select an active {$rarity} decoration for asset version {$assetVersion}.");
    }
}
$serverCatalog = file_get_contents(dirname(__DIR__) . '/config/tree-decorations.json');
$clientCatalog = file_get_contents(dirname(__DIR__, 3) . '/src/DeskBox/Assets/Tree/Decorations/catalog.json');
decor_expect($serverCatalog === $clientCatalog, 'Server and client use the exact same catalog document.');

$bought = decor_buy($pdo, $userId, 1, 'buy-single-tree-pack', $clock);
decor_expect($bought['coins'] === 800 && count($bought['card_packs']) === 1
    && $bought['card_packs'][0]['draw_count'] === 1 && $bought['owned_ids'] === []
    && $bought['inventory'] === [],
    'Buying a single tree pack deducts coins and only adds a backpack pack.');
$replayedBuy = decor_buy($pdo, $userId, 1, 'buy-single-tree-pack', $clock);
decor_expect($replayedBuy === $bought, 'Idempotent pack purchase returns the original result.');
decor_error('operation_conflict', static fn() => decor_buy(
    $pdo, $userId, 10, 'buy-single-tree-pack', $clock));

$drawCommon = static fn(array $items, int $draw): string => (string)$items[0]['id'];
$packId = $bought['card_packs'][0]['id'];
$opened = decor_open($pdo, $userId, $packId, 'open-single-tree-pack', $clock, $drawCommon);
decor_expect(count($opened['card_packs']) === 0 && count($opened['draw_results']) === 1
    && $opened['draw_results'][0]['is_new'] && $opened['draw_results'][0]['quantity'] === 1
    && in_array('red-lantern', $opened['owned_ids'], true)
    && $opened['inventory'][0] === ['item_id' => 'red-lantern', 'quantity' => 1],
    'Opening consumes the selected pack and only then awards a decoration.');
$replayedOpen = decor_open($pdo, $userId, $packId, 'open-single-tree-pack', $clock, $drawCommon);
decor_expect($replayedOpen === $opened, 'Idempotent pack opening returns the original draw result.');
decor_error('pack_not_found', static fn() => decor_open(
    $pdo, $userId, $packId, 'open-consumed-tree-pack', $clock, $drawCommon));

$pdo->exec('UPDATE aquarium_profiles SET coins=2000 WHERE user_id=' . $pdo->quote($userId));
$duplicatePack = decor_buy($pdo, $userId, 1, 'buy-duplicate-pack', $clock);
$duplicate = decor_open($pdo, $userId, $duplicatePack['card_packs'][0]['id'],
    'open-duplicate-pack', $clock, $drawCommon);
decor_expect(!$duplicate['draw_results'][0]['is_new']
    && $duplicate['draw_results'][0]['shards_awarded'] === 0
    && $duplicate['draw_results'][0]['quantity'] === 2
    && $duplicate['inventory'][0]['quantity'] === 2,
    'Duplicate placeable decoration increments its backpack quantity.');

$pdo->exec('UPDATE aquarium_catalog_settings SET tree_card_pack_single_price=250, '
    . 'tree_card_pack_ten_price=2200 WHERE id=1');
$pdo->exec('UPDATE aquarium_profiles SET coins=3000 WHERE user_id=' . $pdo->quote($userId));
$pricedCatalog = tree_decoration_catalog($pdo);
decor_expect($pricedCatalog['single_price'] === 250 && $pricedCatalog['ten_price'] === 2200,
    'Changing the two tree pack prices updates both store products.');
$tenBought = decor_buy($pdo, $userId, 10, 'buy-ten-tree-pack', $clock);
decor_expect($tenBought['coins'] === 800 && $tenBought['card_packs'][0]['draw_count'] === 10,
    'Ten-draw tree pack atomically costs its independently configured price.');
$tenOpened = decor_open($pdo, $userId, $tenBought['card_packs'][0]['id'],
    'open-ten-tree-pack', $clock, $drawCommon);
decor_expect(count($tenOpened['draw_results']) === 10 && $tenOpened['coins'] === 800,
    'Opening a ten-draw pack returns ten ordered results without another debit.');
decor_expect($tenOpened['inventory'][0]['quantity'] === 12,
    'Repeated placeable rewards remain available as twelve independent backpack items.');

$pdo->exec('UPDATE aquarium_profiles SET coins=100 WHERE user_id=' . $pdo->quote($userId));
$packsBefore = count(tree_decoration_read_state($pdo, $userId)['card_packs']);
decor_error('insufficient_coins', static fn() => decor_buy(
    $pdo, $userId, 10, 'insufficient-tree-pack', $clock));
decor_expect(tree_decoration_read_state($pdo, $userId)['coins'] === 100
    && count(tree_decoration_read_state($pdo, $userId)['card_packs']) === $packsBefore,
    'Insufficient balance rolls back the purchase without adding a pack.');

$pdo->exec('UPDATE tree_decoration_profiles SET shards=800 WHERE user_id=' . $pdo->quote($userId));
decor_error('invalid_item', static fn() => tree_decoration_mutate($pdo, $userId, [
    'action' => 'redeem', 'operation_id' => 'redeem-retired-theme', 'item_id' => 'midsummer-camp',
], $clock));
$redeemId = $catalog['items'][count($catalog['items']) - 1]['id'];
$redeemCost = tree_decoration_item($redeemId)['rarity'] === 'common' ? 80 : 240;
$redeemed = tree_decoration_mutate($pdo, $userId, [
    'action' => 'redeem', 'operation_id' => 'redeem-original-item', 'item_id' => $redeemId,
    'asset_version' => 3,
], $clock);
decor_expect($redeemed['shards'] === 800 - $redeemCost && in_array($redeemId, $redeemed['owned_ids'], true),
    'An original catalog decoration redeems with its existing rarity price.');

$pdo->prepare('INSERT INTO tree_decoration_inventory (user_id,item_id,quantity,acquired_at) VALUES (?, ?, 1, ?)')
    ->execute([$userId, 'midsummer-camp', auth_sql_time($clock())]);
$pdo->prepare("UPDATE tree_decoration_profiles SET active_theme_id='midsummer-camp' WHERE user_id=?")
    ->execute([$userId]);
$legacyState = tree_decoration_read_state($pdo, $userId);
decor_expect($legacyState['active_theme_id'] === null
    && !in_array('midsummer-camp', $legacyState['owned_ids'], true)
    && count(array_filter($legacyState['inventory'], fn(array $entry): bool => $entry['item_id'] === 'midsummer-camp')) === 0,
    'Historical themes stay hidden from the state, owned IDs and inventory.');
decor_expect((int)$pdo->query("SELECT COUNT(*) FROM tree_decoration_inventory WHERE item_id='midsummer-camp'")->fetchColumn() === 1
    && $pdo->query("SELECT active_theme_id FROM tree_decoration_profiles WHERE user_id=" . $pdo->quote($userId))->fetchColumn() === 'midsummer-camp',
    'Reading current state does not delete historical inventory or change the saved theme field.');

$saveRequest = api_decode_body(json_encode([
    'action' => 'save_scene', 'operation_id' => 'save-decoration-scene', 'base_scene_version' => 0,
    'active_theme_id' => 'midsummer-camp',
    'placements' => [[
        'placement_id' => 'first-lantern', 'item_id' => 'red-lantern', 'x' => 0.35, 'y' => 0.42,
        'scale' => 1.2, 'rotation' => -15.0, 'layer' => 1,
    ], [
        'placement_id' => 'second-lantern', 'item_id' => 'red-lantern', 'x' => 0.65, 'y' => 0.58,
        'scale' => 0.9, 'rotation' => 10.0, 'layer' => 0,
    ]],
], JSON_THROW_ON_ERROR));
$saved = tree_decoration_mutate($pdo, $userId, $saveRequest, $clock);
decor_expect($saved['scene_version'] === 1 && count($saved['placements']) === 2
    && $saved['active_theme_id'] === null
    && $saved['placements'][0]['placement_id'] === 'first-lantern'
    && $saved['placements'][1]['placement_id'] === 'second-lantern',
    'Multiple owned copies save as independently addressable authoritative placements.');
decor_expect($pdo->query('SELECT active_theme_id FROM tree_decoration_profiles WHERE user_id=' . $pdo->quote($userId))->fetchColumn() === null,
    'Saving an older client request ignores its retired active theme without rejecting valid placements.');
$legacyResponse = $saved;
$legacyResponse['active_theme_id'] = 'midsummer-camp';
$legacyResponse['owned_ids'][] = 'midsummer-camp';
$legacyResponse['inventory'][] = ['item_id' => 'midsummer-camp', 'quantity' => 1];
$legacyResponse['draw_results'] = [['item_id' => 'midsummer-camp', 'rarity' => 'theme', 'is_new' => true]];
$legacyBody = ['action' => 'open_pack', 'operation_id' => 'historical-theme-operation', 'pack_instance_id' => 'old-pack'];
$pdo->prepare('INSERT INTO tree_decoration_operations (user_id,operation_id,action,request_hash,response_json,created_at) VALUES (?,?,?,?,?,?)')
    ->execute([$userId, $legacyBody['operation_id'], 'open_pack',
        hash('sha256', json_encode(aquarium_canonical_body($legacyBody), JSON_THROW_ON_ERROR)),
        json_encode($legacyResponse, JSON_THROW_ON_ERROR), auth_sql_time($clock())]);
$historicalReplay = tree_decoration_mutate($pdo, $userId, $legacyBody, $clock);
decor_expect($historicalReplay['active_theme_id'] === null && $historicalReplay['draw_results'] === []
    && !in_array('midsummer-camp', $historicalReplay['owned_ids'], true)
    && count($historicalReplay['inventory']) === count($saved['inventory']),
    'Replaying a completed legacy operation also hides retired theme data without granting anything again.');
$tooManyPlacements = [];
for ($index = 0; $index < 13; $index++) {
    $tooManyPlacements[] = [
        'placement_id' => 'extra-lantern-' . $index, 'item_id' => 'red-lantern',
        'x' => 0.5, 'y' => 0.5, 'scale' => 1.0, 'rotation' => 0.0, 'layer' => 0,
    ];
}
decor_error('invalid_scene', static fn() => tree_decoration_mutate($pdo, $userId, [
    'action' => 'save_scene', 'operation_id' => 'too-many-decoration-copies', 'base_scene_version' => 1,
    'active_theme_id' => null, 'placements' => $tooManyPlacements,
], $clock));
decor_error('scene_conflict', static fn() => tree_decoration_mutate($pdo, $userId, [
    'action' => 'save_scene', 'operation_id' => 'stale-decoration-scene', 'base_scene_version' => 0,
    'active_theme_id' => null, 'placements' => [],
], $clock));

$pdo->prepare('UPDATE tree_profiles SET stage=4 WHERE user_id=?')->execute([$userId]);
decor_error('tree_not_mature', static fn() => decor_buy(
    $pdo, $userId, 1, 'immature-pack-purchase', $clock));
decor_expect(decor_open($pdo, $userId, $packId, 'open-single-tree-pack', $clock, $drawCommon) === $opened,
    'A completed operation remains replayable after the tree is no longer mature.');

$secondUserId = decor_create_user($pdo, 'tree_decorator_two', 'decor-device-two', $clock);
$secondPack = decor_buy($pdo, $secondUserId, 1, 'second-account-tree-pack', $clock);
decor_expect(count($secondPack['card_packs']) === 1
    && count(tree_decoration_read_state($pdo, $userId)['card_packs']) === 0,
    'Tree card pack inventory is isolated by account.');
decor_error('invalid_item', static fn() => tree_decoration_item('tree-jade-knot-01'));
decor_error('invalid_item', static fn() => tree_decoration_draw_rewards($pdo, $secondUserId, 1, $clock(),
    static fn(array $items, int $draw): string => 'tree-jade-knot-01'));
decor_error('invalid_item', static fn() => tree_decoration_draw_rewards($pdo, $secondUserId, 1, $clock(),
    static fn(array $items, int $draw): string => 'spring-garden'));
$legacyOpened = decor_open($pdo, $secondUserId, $secondPack['card_packs'][0]['id'],
    'legacy-client-open-existing', $clock, static function (array $items, int $draw) use ($legacyIds): string {
        decor_expect(array_column($items, 'id') === $legacyIds, 'Legacy pack opening receives only original asset IDs.');
        return $items[count($items) - 1]['id'];
    }, null);
decor_expect($legacyOpened['draw_results'][0]['item_id'] === 'small-fountain',
    'An existing card pack opens on an older client after the catalog upgrade without granting a missing asset.');

echo "tree decoration service tests passed\n";
