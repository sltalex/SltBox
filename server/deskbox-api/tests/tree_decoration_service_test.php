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
    $pdo->exec('PRAGMA busy_timeout = 10000');
    return $pdo;
}

function decor_process(array $arguments): array
{
    $pipes = [];
    $process = proc_open(array_merge([PHP_BINARY, __FILE__], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Could not start decoration test worker.'); }
    fclose($pipes[0]);
    return [$process, $pipes];
}

function decor_parallel(string $database, string $userId, array $requests): array
{
    $start = (string)(microtime(true) + 0.3);
    $workers = [];
    foreach ($requests as $request) {
        $workers[] = decor_process(['--worker', $database, $userId,
            base64_encode(json_encode($request, JSON_THROW_ON_ERROR)), $start]);
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        decor_expect($exit === 0 && $error === '', 'Decoration worker failed: ' . $error);
        $results[] = $output;
    }
    return $results;
}

$clock = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-25T08:00:00Z');
if (($argv[1] ?? '') === '--worker') {
    $workerPdo = decor_database('sqlite:' . $argv[2]);
    $body = json_decode(base64_decode($argv[4], true), true, 32, JSON_THROW_ON_ERROR);
    $wait = max(0, (int)(((float)$argv[5] - microtime(true)) * 1000000));
    if ($wait > 0) { usleep($wait); }
    try {
        tree_decoration_mutate($workerPdo, $argv[3], $body, $clock);
        echo 'ok';
    } catch (ApiError $error) { echo $error->apiCode; }
    exit;
}
$pdo = decor_database();
$pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
$account = auth_register($pdo, 'tree_decorator', 'secret1', 'decor-device', '127.0.0.1', $clock);
$userId = $account['user']['id'];
$pdo->prepare("INSERT INTO tree_profiles (user_id,participation_enabled,total_energy,fruit_total,harvested_total,stage,state_version,fruit_energy,updated_at) VALUES (?,1,180000,0,0,5,0,0,?)")
    ->execute([$userId, auth_sql_time($clock())]);
aquarium_mutate($pdo, $userId, ['action' => 'initialize', 'operation_id' => 'initialize-decoration-wallet'], $clock);

$catalog = tree_decoration_catalog();
decor_expect($catalog['catalog_version'] === 1 && count($catalog['items']) === 24,
    'Tree decoration catalog exposes exactly 24 versioned items.');
decor_expect(count(array_filter($catalog['items'], fn(array $item): bool => $item['rarity'] === 'common')) === 12,
    'Catalog contains twelve common decorations.');
decor_expect(count(array_filter($catalog['items'], fn(array $item): bool => $item['rarity'] === 'rare')) === 8,
    'Catalog contains eight rare decorations.');
decor_expect(count(array_filter($catalog['items'], fn(array $item): bool => $item['rarity'] === 'theme')) === 4,
    'Catalog contains four themes.');

$drawCommon = static fn(array $items, int $draw): string => (string)$items[0]['id'];
$single = tree_decoration_mutate($pdo, $userId, [
    'action' => 'draw', 'operation_id' => 'single-draw-operation', 'catalog_version' => 1, 'count' => 1,
], $clock, $drawCommon);
decor_expect($single['coins'] === 800 && count($single['draw_results']) === 1 && $single['draw_results'][0]['is_new'],
    'Single draw atomically costs 200 coins and awards one new item.');
$replayed = tree_decoration_mutate($pdo, $userId, [
    'action' => 'draw', 'operation_id' => 'single-draw-operation', 'catalog_version' => 1, 'count' => 1,
], $clock, $drawCommon);
decor_expect($replayed === $single, 'Idempotent replay returns the original draw result.');
decor_error('operation_conflict', static fn() => tree_decoration_mutate($pdo, $userId, [
    'action' => 'draw', 'operation_id' => 'single-draw-operation', 'catalog_version' => 1, 'count' => 10,
], $clock, $drawCommon));

$duplicate = tree_decoration_mutate($pdo, $userId, [
    'action' => 'draw', 'operation_id' => 'duplicate-draw-op', 'catalog_version' => 1, 'count' => 1,
], $clock, $drawCommon);
decor_expect($duplicate['coins'] === 600 && $duplicate['shards'] === 10
    && !$duplicate['draw_results'][0]['is_new'] && $duplicate['draw_results'][0]['shards_awarded'] === 10,
    'Duplicate common decoration converts into ten shards.');

$pdo->exec('UPDATE aquarium_profiles SET coins=2000 WHERE user_id=' . $pdo->quote($userId));
$drawRare = static fn(array $items, int $draw): string => (string)$items[12]['id'];
tree_decoration_mutate($pdo, $userId, [
    'action' => 'draw', 'operation_id' => 'rare-first-draw-op', 'catalog_version' => 1, 'count' => 1,
], $clock, $drawRare);
$rareDuplicate = tree_decoration_mutate($pdo, $userId, [
    'action' => 'draw', 'operation_id' => 'rare-duplicate-draw', 'catalog_version' => 1, 'count' => 1,
], $clock, $drawRare);
decor_expect($rareDuplicate['draw_results'][0]['shards_awarded'] === 30,
    'Duplicate rare decoration converts into thirty shards.');
$drawTheme = static fn(array $items, int $draw): string => (string)$items[20]['id'];
tree_decoration_mutate($pdo, $userId, [
    'action' => 'draw', 'operation_id' => 'theme-first-draw', 'catalog_version' => 1, 'count' => 1,
], $clock, $drawTheme);
$themeDuplicate = tree_decoration_mutate($pdo, $userId, [
    'action' => 'draw', 'operation_id' => 'theme-duplicate-draw', 'catalog_version' => 1, 'count' => 1,
], $clock, $drawTheme);
decor_expect($themeDuplicate['draw_results'][0]['shards_awarded'] === 100,
    'Duplicate theme converts into one hundred shards.');

$pdo->exec('UPDATE aquarium_profiles SET coins=100 WHERE user_id=' . $pdo->quote($userId));
$inventoryBeforeFailure = (int)$pdo->query('SELECT COUNT(*) FROM tree_decoration_inventory WHERE user_id=' . $pdo->quote($userId))->fetchColumn();
$shardsBeforeFailure = (int)$pdo->query('SELECT shards FROM tree_decoration_profiles WHERE user_id=' . $pdo->quote($userId))->fetchColumn();
decor_error('insufficient_coins', static fn() => tree_decoration_mutate($pdo, $userId, [
    'action' => 'draw', 'operation_id' => 'insufficient-coins-draw', 'catalog_version' => 1, 'count' => 10,
], $clock, $drawCommon));
decor_expect((int)$pdo->query('SELECT coins FROM aquarium_profiles WHERE user_id=' . $pdo->quote($userId))->fetchColumn() === 100
    && (int)$pdo->query('SELECT COUNT(*) FROM tree_decoration_inventory WHERE user_id=' . $pdo->quote($userId))->fetchColumn() === $inventoryBeforeFailure
    && (int)$pdo->query('SELECT shards FROM tree_decoration_profiles WHERE user_id=' . $pdo->quote($userId))->fetchColumn() === $shardsBeforeFailure,
    'Insufficient balance rolls back coins, inventory, and shards atomically.');

$pdo->exec('UPDATE aquarium_profiles SET coins=2400 WHERE user_id=' . $pdo->quote($userId));
$ten = tree_decoration_mutate($pdo, $userId, [
    'action' => 'draw', 'operation_id' => 'ten-draw-operation', 'catalog_version' => 1, 'count' => 10,
], $clock, $drawCommon);
decor_expect($ten['coins'] === 600 && count($ten['draw_results']) === 10,
    'Ten draw atomically costs 1800 coins and returns ten ordered results.');

$pdo->exec('UPDATE tree_decoration_profiles SET shards=800 WHERE user_id=' . $pdo->quote($userId));
$redeemed = tree_decoration_mutate($pdo, $userId, [
    'action' => 'redeem', 'operation_id' => 'redeem-theme-operation', 'item_id' => 'midsummer-camp',
], $clock);
decor_expect($redeemed['shards'] === 0 && in_array('midsummer-camp', $redeemed['owned_ids'], true),
    'Eight hundred shards redeem an unowned theme.');
decor_error('already_owned', static fn() => tree_decoration_mutate($pdo, $userId, [
    'action' => 'redeem', 'operation_id' => 'redeem-owned-operation', 'item_id' => 'midsummer-camp',
], $clock));

$saved = tree_decoration_mutate($pdo, $userId, [
    'action' => 'save_scene', 'operation_id' => 'save-decoration-scene', 'base_scene_version' => 0,
    'active_theme_id' => 'midsummer-camp',
    'placements' => [[
        'item_id' => $single['draw_results'][0]['item_id'], 'x' => 0.35, 'y' => 0.42,
        'scale' => 1.2, 'rotation' => -15.0, 'layer' => 1,
    ]],
], $clock);
decor_expect($saved['scene_version'] === 1 && count($saved['placements']) === 1
    && $saved['active_theme_id'] === 'midsummer-camp',
    'Owned decorations and one owned theme save as an authoritative versioned scene.');
decor_error('scene_conflict', static fn() => tree_decoration_mutate($pdo, $userId, [
    'action' => 'save_scene', 'operation_id' => 'stale-decoration-scene', 'base_scene_version' => 0,
    'active_theme_id' => null, 'placements' => [],
], $clock));

$pdo->prepare('UPDATE tree_profiles SET stage=4 WHERE user_id=?')->execute([$userId]);
decor_error('tree_not_mature', static fn() => tree_decoration_mutate($pdo, $userId, [
    'action' => 'draw', 'operation_id' => 'immature-draw-operation', 'catalog_version' => 1, 'count' => 1,
], $clock, $drawCommon));
decor_expect(tree_decoration_mutate($pdo, $userId, [
    'action' => 'draw', 'operation_id' => 'single-draw-operation', 'catalog_version' => 1, 'count' => 1,
], $clock, $drawCommon) === $single,
    'A completed operation remains replayable after the tree is no longer mature.');

$second = auth_register($pdo, 'tree_decorator_two', 'secret2', 'decor-device-two', '127.0.0.2', $clock);
$secondUserId = $second['user']['id'];
$pdo->prepare("INSERT INTO tree_profiles (user_id,participation_enabled,total_energy,fruit_total,harvested_total,stage,state_version,fruit_energy,updated_at) VALUES (?,1,180000,0,0,5,0,0,?)")
    ->execute([$secondUserId, auth_sql_time($clock())]);
aquarium_mutate($pdo, $secondUserId, ['action' => 'initialize', 'operation_id' => 'initialize-second-decoration-wallet'], $clock);
$drawSecondOnly = static fn(array $items, int $draw): string => (string)$items[2]['id'];
tree_decoration_mutate($pdo, $secondUserId, [
    'action' => 'draw', 'operation_id' => 'second-account-draw', 'catalog_version' => 1, 'count' => 1,
], $clock, $drawSecondOnly);
decor_expect(in_array('wind-chime', tree_decoration_read_state($pdo, $secondUserId)['owned_ids'], true)
    && !in_array('wind-chime', tree_decoration_read_state($pdo, $userId)['owned_ids'], true),
    'Decoration ownership is isolated by account.');

$database = tempnam(sys_get_temp_dir(), 'tree-decoration-test-');
if ($database === false) { throw new RuntimeException('Could not create decoration concurrency database.'); }
try {
    $parallelPdo = decor_database('sqlite:' . $database);
    $parallelPdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
    $parallelAccount = auth_register($parallelPdo, 'parallel_decorator', 'secret3',
        'decor-device-three', '127.0.0.3', $clock);
    $parallelId = $parallelAccount['user']['id'];
    $parallelPdo->prepare("INSERT INTO tree_profiles (user_id,participation_enabled,total_energy,fruit_total,harvested_total,stage,state_version,fruit_energy,updated_at) VALUES (?,1,180000,0,0,5,0,0,?)")
        ->execute([$parallelId, auth_sql_time($clock())]);
    aquarium_mutate($parallelPdo, $parallelId,
        ['action' => 'initialize', 'operation_id' => 'parallel-wallet-initialize'], $clock);
    $same = ['action' => 'draw', 'operation_id' => 'parallel-same-draw-operation',
        'catalog_version' => 1, 'count' => 1];
    decor_expect(decor_parallel($database, $parallelId, [$same, $same]) === ['ok', 'ok'],
        'Concurrent replay requests both return success.');
    decor_expect(tree_decoration_read_state($parallelPdo, $parallelId)['coins'] === 800
        && (int)$parallelPdo->query('SELECT COUNT(*) FROM tree_decoration_operations')->fetchColumn() === 1,
        'Concurrent identical draws debit once and record one operation.');
    $parallelPdo->prepare('UPDATE aquarium_profiles SET coins=300 WHERE user_id=?')->execute([$parallelId]);
    $concurrentResults = array_count_values(decor_parallel($database, $parallelId, [[
        'action' => 'draw', 'operation_id' => 'parallel-distinct-draw-one',
        'catalog_version' => 1, 'count' => 1,
    ], [
        'action' => 'draw', 'operation_id' => 'parallel-distinct-draw-two',
        'catalog_version' => 1, 'count' => 1,
    ]]));
    decor_expect(($concurrentResults['ok'] ?? 0) === 1
        && ($concurrentResults['insufficient_coins'] ?? 0) === 1
        && tree_decoration_read_state($parallelPdo, $parallelId)['coins'] === 100,
        'Concurrent draws serialize shared-wallet deduction and cannot overspend.');
    $parallelPdo = null;
} finally {
    $parallelPdo = null;
    if (is_file($database)) { unlink($database); }
}

echo "tree decoration service tests passed\n";
