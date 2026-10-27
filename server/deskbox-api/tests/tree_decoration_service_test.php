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
    callable $clock, callable $selector): array
{
    return tree_decoration_mutate($pdo, $userId, [
        'action' => 'open_pack', 'operation_id' => $operation, 'pack_instance_id' => $packId,
    ], $clock, $selector);
}

$clock = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-25T08:00:00Z');
$pdo = decor_database();
$pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
$userId = decor_create_user($pdo, 'tree_decorator', 'decor-device', $clock);

$catalog = tree_decoration_catalog($pdo);
decor_expect($catalog['catalog_version'] === 2 && count($catalog['items']) === 24,
    'Tree decoration catalog exposes exactly 24 versioned items.');
decor_expect($catalog['single_price'] === 200 && $catalog['ten_price'] === 1800,
    'Tree pack prices expose the independently configured one-draw and ten-draw products.');
decor_expect(count(array_filter($catalog['items'], fn(array $item): bool => $item['rarity'] === 'common')) === 12,
    'Catalog contains twelve common decorations.');
decor_expect(count(array_filter($catalog['items'], fn(array $item): bool => $item['rarity'] === 'rare')) === 8,
    'Catalog contains eight rare decorations.');
decor_expect(count(array_filter($catalog['items'], fn(array $item): bool => $item['rarity'] === 'theme')) === 4,
    'Catalog contains four themes.');

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
$redeemed = tree_decoration_mutate($pdo, $userId, [
    'action' => 'redeem', 'operation_id' => 'redeem-theme-operation', 'item_id' => 'midsummer-camp',
], $clock);
decor_expect($redeemed['shards'] === 0 && in_array('midsummer-camp', $redeemed['owned_ids'], true),
    'Eight hundred shards redeem an unowned theme.');

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
    && $saved['placements'][0]['placement_id'] === 'first-lantern'
    && $saved['placements'][1]['placement_id'] === 'second-lantern',
    'Multiple owned copies save as independently addressable authoritative placements.');
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

echo "tree decoration service tests passed\n";
