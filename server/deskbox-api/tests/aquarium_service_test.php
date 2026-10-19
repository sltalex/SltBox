<?php
declare(strict_types=1);

// Endpoint subprocesses must load their own bootstrap so api_run can exit normally.
if (($argv[1] ?? '') === '--http') {
    $_SERVER['SCRIPT_NAME'] = '/api/v1/aquarium/' . $argv[2] . '.php';
    $_SERVER['REQUEST_METHOD'] = $argv[2] === 'actions' ? 'POST' : 'GET';
    $_SERVER['HTTPS'] = 'off';
    $_SERVER['SERVER_PORT'] = '80';
    register_shutdown_function(static function (): void { fwrite(STDERR, (string)http_response_code()); });
    require dirname(__DIR__) . '/api/v1/aquarium/' . $argv[2] . '.php';
    exit;
}

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/admin.php';
require_once dirname(__DIR__) . '/api/_app/aquarium.php';

function aquarium_test_database(string $dsn = 'sqlite::memory:'): PDO
{
    $pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 10000');
    return $pdo;
}

$clock = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-17T12:00:00Z');
if (($argv[1] ?? '') === '--worker') {
    $pdo = aquarium_test_database('sqlite:' . $argv[2]);
    $body = json_decode(base64_decode($argv[4], true), true, 32, JSON_THROW_ON_ERROR);
    $wait = max(0, (int)(((float)$argv[5] - microtime(true)) * 1000000));
    if ($wait > 0) { usleep($wait); }
    try {
        aquarium_mutate($pdo, $argv[3], $body, $clock);
        echo 'ok';
    } catch (ApiError $error) {
        echo $error->apiCode;
    }
    exit;
}

function aquarium_expect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function aquarium_error(string $code, int $status, callable $operation): void
{
    try { $operation(); }
    catch (ApiError $error) {
        aquarium_expect($error->apiCode === $code, "Expected {$code}, got {$error->apiCode}");
        aquarium_expect($error->httpStatus === $status, "Expected HTTP {$status}, got {$error->httpStatus}");
        return;
    }
    throw new RuntimeException('Expected API error ' . $code);
}

function aquarium_request(string $action, array $extra = []): array
{
    return ['action' => $action, 'operation_id' => auth_random_id()] + $extra;
}

function aquarium_test_process(array $arguments): array
{
    $pipes = [];
    $process = proc_open(array_merge([PHP_BINARY, __FILE__], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Could not start aquarium test worker.'); }
    fclose($pipes[0]);
    return [$process, $pipes];
}

function aquarium_finish_process(array $worker): array
{
    [$process, $pipes] = $worker;
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    aquarium_expect($exit === 0, 'Aquarium worker failed: ' . $error . ' ' . $output);
    return [$output, $error];
}

function aquarium_parallel(string $database, string $userId, array $requests): array
{
    $start = (string)(microtime(true) + 0.3);
    $workers = [];
    foreach ($requests as $request) {
        $workers[] = aquarium_test_process(['--worker', $database, $userId,
            base64_encode(json_encode($request, JSON_THROW_ON_ERROR)), $start]);
    }
    $results = [];
    foreach ($workers as $worker) {
        [$output, $error] = aquarium_finish_process($worker);
        aquarium_expect($error === '', 'Concurrent request should not produce PHP errors: ' . $error);
        $results[] = $output;
    }
    return $results;
}

$pdo = aquarium_test_database();
$pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
$admin = auth_register($pdo, 'aquarium_admin', 'secret1', 'device-a', '127.0.0.1', $clock);
$member = auth_register($pdo, 'aquarium_member', 'secret2', 'device-b', '127.0.0.2', $clock);
$userId = $admin['user']['id'];
$memberId = $member['user']['id'];
aquarium_expect(admin_require($pdo, $admin['session']['access_token'], $clock)['user_id'] === $userId,
    'Aquarium uses the server-verified administrator session.');
aquarium_error('admin_forbidden', 403,
    static fn() => admin_require($pdo, $member['session']['access_token'], $clock));
aquarium_error('invalid_session', 401, static fn() => admin_require($pdo, '', $clock));

foreach (['catalog', 'state', 'actions'] as $endpoint) {
    [$output, $status] = aquarium_finish_process(aquarium_test_process(['--http', $endpoint]));
    aquarium_expect((bool)preg_match('/500\s*$/', $status), 'HTTP aquarium requests reach normal endpoint handling.');
    aquarium_expect(json_decode($output, true, 8, JSON_THROW_ON_ERROR)['code'] === 'server_error',
        'HTTP requests must reach normal endpoint handling.');
}

$catalog = aquarium_catalog();
aquarium_expect($catalog['catalog_version'] === 7 && $catalog['catalog_version'] === AQUARIUM_CATALOG_VERSION,
    'The catalog advertises the version checked by mutations.');
$catalogItemsBeforePack = count($catalog['items']);
aquarium_expect($catalogItemsBeforePack === 341,
    'The catalog contains only the prepared aquarium choices.');
foreach (['fish' => 150, 'plant' => 50, 'shrimp' => 10, 'crab' => 10, 'shellfish' => 10,
    'coral' => 20, 'algae' => 20, 'snail' => 20, 'decoration' => 50, 'pack' => 1] as $category => $expected) {
    aquarium_expect(count(array_filter($catalog['items'],
        static fn(array $item): bool => $item['category'] === $category)) === $expected,
        "The catalog preserves the prepared {$category} choices.");
}
foreach (['id', 'asset_key'] as $field) {
    aquarium_expect(count(array_unique(array_column($catalog['items'], $field))) === count($catalog['items']),
        "Every catalog {$field} must be unique.");
}
$cartoonPlan = json_decode(file_get_contents(__DIR__ . '/../../../docs/design/aquarium-cartoon-expansion-plan.json'), true, 16, JSON_THROW_ON_ERROR);
foreach (['shrimp' => 'shrimp', 'crabs' => 'crab', 'shellfish' => 'shellfish'] as $group => $category) {
    aquarium_expect(count($cartoonPlan[$group]) === 10, 'Each cartoon artwork group has ten choices.');
    foreach ($cartoonPlan[$group] as $entry) {
        $item = aquarium_item($entry['id']);
        aquarium_expect($item['category'] === $category && $item['name'] === $entry['name']
            && $item['price'] === $entry['price'] && $item['asset_key'] === $entry['id'],
            'The cartoon artwork manifest and server catalog stay aligned.');
        aquarium_expect(is_file(__DIR__ . '/../../../src/DeskBox/Assets/Aquarium/Svg/' . $item['asset_key'] . '.svg')
            && is_file(__DIR__ . '/../../../src/DeskBox/Assets/Aquarium/Vector/' . $item['asset_key'] . '.png'),
            'Every cartoon choice has both its original SVG and runtime transparent export.');
    }
}
foreach ($catalog['items'] as $item) {
    aquarium_expect((bool)preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $item['id'])
        && $item['asset_key'] === $item['id'] && $item['price'] > 0,
        'Every catalog item has a safe stable resource key and positive server price.');
}
$fishHabitats = [];
foreach (['aquarium-fish-expansion-plan.json' => ['fish' => 'fish'],
    'aquarium-reef-expansion-plan.json' => ['corals' => 'coral', 'algae' => 'algae'],
    'aquarium-snail-expansion-plan.json' => ['snails' => 'snail']] as $manifest => $groups) {
    $plan = json_decode(file_get_contents(__DIR__ . '/../../../docs/design/' . $manifest), true, 32, JSON_THROW_ON_ERROR);
    foreach ($groups as $group => $category) {
        foreach ($plan[$group] as $entry) {
            $item = aquarium_item($entry['id']);
            aquarium_expect($item['category'] === $category && $item['price'] === $entry['price']
                && $item['name'] === $entry['name'] && $item['asset_key'] === $entry['id'],
                'Expanded catalog metadata matches the reviewed artwork manifest.');
            aquarium_expect(is_file(__DIR__ . '/../../../src/DeskBox/Assets/Aquarium/Svg/' . $item['id'] . '.svg')
                && is_file(__DIR__ . '/../../../src/DeskBox/Assets/Aquarium/Vector/' . $item['id'] . '.png'),
                'Every expanded item ships its SVG source and transparent runtime export.');
            if ($category === 'fish') {
                aquarium_expect($item['habitat'] === $entry['habitat']
                    && in_array($item['habitat'], ['freshwater', 'tropical', 'marine'], true),
                    'Every fish has exactly one supported browsing habitat.');
                $fishHabitats[$item['habitat']] = true;
            }
        }
    }
}
aquarium_expect(count($fishHabitats) === 3, 'Freshwater, tropical and marine fish are all represented.');
$packCatalogItem = aquarium_item(AQUARIUM_CARD_PACK_ID);
aquarium_expect($packCatalogItem['category'] === 'pack' && $packCatalogItem['price'] === 200
    && $packCatalogItem['asset_key'] === AQUARIUM_CARD_PACK_ID,
    'The water-aquarium card pack is a priced, non-placeable catalogue item.');
$state = aquarium_read_state($pdo, $userId);
aquarium_expect(!$state['initialized'] && $state['coins'] === 0 && $state['scene'] === [],
    'An uninitialized aquarium is empty.');
aquarium_expect((int)$pdo->query('SELECT COUNT(*) FROM aquarium_profiles')->fetchColumn() === 0,
    'Reading does not initialize or grant coins.');
aquarium_error('aquarium_not_initialized', 409, static fn() => aquarium_mutate($pdo, $userId,
    aquarium_request('buy', ['catalog_version' => 2, 'item_id' => 'clownfish']), $clock));

$initialize = aquarium_request('initialize');
$state = aquarium_mutate($pdo, $userId, $initialize, $clock);
aquarium_expect($state['initialized'] && $state['coins'] === 1000 && count($state['wallet']) === 1,
    'Initialization credits exactly one grant with its ledger entry.');
aquarium_expect($state['inventory'] === [] && $state['scene'] === [] && $state['scene_version'] === 0,
    'Initial aquarium contains no free inventory or placed items.');
aquarium_mutate($pdo, $userId, $initialize, $clock);
$state = aquarium_mutate($pdo, $userId, aquarium_request('initialize'), $clock);
aquarium_expect($state['coins'] === 1000 && count($state['wallet']) === 1,
    'Retries, new initialization identifiers and other devices cannot claim the grant again.');

$buy = aquarium_request('buy', ['catalog_version' => 2, 'item_id' => 'clownfish']);
$state = aquarium_mutate($pdo, $userId, $buy, $clock);
$fishId = $state['inventory'][0]['id'];
aquarium_expect($state['coins'] === 900 && count($state['inventory']) === 1 && $state['scene_version'] === 0,
    'Purchase debits the server price and adds an instance without changing the layout.');
aquarium_expect($state['scene'] === [] && $state['wallet'][0]['delta'] === -100
    && $state['wallet'][0]['balance'] === 900 && $state['wallet'][0]['reason'] === 'purchase',
    'Purchase stays in the backpack and records a complete wallet audit.');
$reorderedBuy = array_reverse($buy, true);
$state = aquarium_mutate($pdo, $userId, $reorderedBuy, $clock);
aquarium_expect($state['coins'] === 900 && count($state['inventory']) === 1 && count($state['wallet']) === 2,
    'The same request with reordered JSON keys is still idempotent.');
aquarium_error('operation_conflict', 409, static fn() => aquarium_mutate($pdo, $userId,
    array_replace($buy, ['item_id' => 'blue-tang']), $clock));
aquarium_error('operation_conflict', 409, static fn() => aquarium_mutate($pdo, $userId,
    array_replace($buy, ['action' => 'initialize']), $clock));
aquarium_error('catalog_conflict', 409, static fn() => aquarium_mutate($pdo, $userId,
    aquarium_request('buy', ['catalog_version' => 0, 'item_id' => 'clownfish']), $clock));
aquarium_error('invalid_item', 400, static fn() => aquarium_mutate($pdo, $userId,
    aquarium_request('buy', ['catalog_version' => 2, 'item_id' => 'free-coins']), $clock));
aquarium_error('invalid_operation', 400, static fn() => aquarium_mutate($pdo, $userId,
    ['action' => 'initialize', 'operation_id' => 'short'], $clock));

$placement = ['instance_id' => $fishId, 'x' => 0.25, 'y' => 0.5, 'scale' => 1.0, 'layer' => 1];
$save = aquarium_request('save_scene', ['base_scene_version' => 0, 'scene' => [$placement]]);
$state = aquarium_mutate($pdo, $userId, $save, $clock);
aquarium_expect($state['scene_version'] === 1 && count($state['scene']) === 1 && $state['coins'] === 900,
    'Placement updates the version without spending or removing owned inventory.');
aquarium_expect($state['scene'][0]['layer'] === 1, 'Placement preserves the explicit aquarium layer.');
$state = aquarium_mutate($pdo, $userId, $save, $clock);
aquarium_expect($state['scene_version'] === 1, 'Retrying a successful layout save does not increment the version.');
aquarium_error('scene_conflict', 409, static fn() => aquarium_mutate($pdo, $userId,
    aquarium_request('save_scene', ['base_scene_version' => 0, 'scene' => []]), $clock));
aquarium_error('invalid_scene', 400, static fn() => aquarium_mutate($pdo, $userId,
    aquarium_request('save_scene', ['base_scene_version' => 1, 'scene' => [$placement, $placement]]), $clock));
foreach (['x' => -0.01, 'y' => 1.01, 'scale' => 1.51] as $field => $value) {
    aquarium_error('invalid_scene', 400, static fn() => aquarium_mutate($pdo, $userId,
        aquarium_request('save_scene', ['base_scene_version' => 1,
            'scene' => [array_replace($placement, [$field => $value])]]), $clock));
}
aquarium_error('invalid_scene', 400, static fn() => aquarium_mutate($pdo, $userId,
    aquarium_request('save_scene', ['base_scene_version' => 1,
        'scene' => [array_replace($placement, ['scale' => '1'])]]), $clock));
aquarium_error('invalid_scene', 400, static fn() => aquarium_mutate($pdo, $userId,
    aquarium_request('save_scene', ['base_scene_version' => 1,
        'scene' => [array_replace($placement, ['layer' => 9])]]), $clock));
aquarium_error('invalid_scene', 400, static fn() => aquarium_mutate($pdo, $userId,
    aquarium_request('save_scene', ['base_scene_version' => 1,
        'scene' => [array_replace($placement, ['layer' => -2])]]), $clock));

// A second account is seeded directly to verify ownership isolation below the API boundary.
aquarium_mutate($pdo, $memberId, aquarium_request('initialize'), $clock);
$other = aquarium_mutate($pdo, $memberId,
    aquarium_request('buy', ['catalog_version' => 2, 'item_id' => 'reef-rock']), $clock);
aquarium_error('invalid_scene', 400, static fn() => aquarium_mutate($pdo, $userId,
    aquarium_request('save_scene', ['base_scene_version' => 1,
        'scene' => [array_replace($placement, ['instance_id' => $other['inventory'][0]['id']])]]), $clock));
$state = aquarium_mutate($pdo, $userId,
    aquarium_request('save_scene', ['base_scene_version' => 1, 'scene' => []]), $clock);
aquarium_expect($state['scene'] === [] && count($state['inventory']) === 1 && $state['scene_version'] === 2,
    'Returning an instance to the backpack clears placement and preserves ownership.');

$launchItems = ['blue-tang', 'butterflyfish', 'seagrass', 'fan-algae', 'reef-rock', 'rock-arch'];
foreach ($launchItems as $itemId) {
    aquarium_mutate($pdo, $userId,
        aquarium_request('buy', ['catalog_version' => 2, 'item_id' => $itemId, 'price' => 0]), $clock);
}
$beforeFailure = aquarium_read_state($pdo, $userId);
aquarium_expect($beforeFailure['coins'] === 0 && count($beforeFailure['inventory']) === 7,
    'The server catalog controls price even when a caller supplies a false price.');
aquarium_error('insufficient_coins', 409, static fn() => aquarium_mutate($pdo, $userId,
    aquarium_request('buy', ['catalog_version' => 2, 'item_id' => 'seagrass']), $clock));
aquarium_expect(aquarium_read_state($pdo, $userId) === $beforeFailure,
    'Insufficient funds roll back the wallet, inventory and operation journal.');
aquarium_expect(array_sum(array_column($beforeFailure['wallet'], 'delta')) === 0,
    'The grant and purchases reconcile to the authoritative balance.');

// All owned instances can be placed, even when the collection exceeds the old scene limit.
$fishScene = [];
$decorationScene = [];
foreach (['clownfish', 'seagrass'] as $item) {
    for ($index = 0; $index < 13; $index++) {
        $id = auth_random_id();
        $pdo->prepare('INSERT INTO aquarium_inventory (id, user_id, item_id, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$id, $userId, $item, '2026-10-17 12:00:00']);
        $instance = ['instance_id' => $id, 'x' => 0.5, 'y' => 0.5, 'scale' => 1];
        if ($item === 'clownfish') { $fishScene[] = $instance; } else { $decorationScene[] = $instance; }
    }
}
$fullScene = array_merge($fishScene, $decorationScene);
$state = aquarium_mutate($pdo, $userId,
    aquarium_request('save_scene', ['base_scene_version' => 2, 'scene' => $fullScene]), $clock);
aquarium_expect(count($state['scene']) === 26 && $state['scene_version'] === 3,
    'Every owned fish and plant can be placed, beyond the old 24-item cap.');
$state = aquarium_mutate($pdo, $userId,
    aquarium_request('save_scene', ['base_scene_version' => 3, 'scene' => array_slice($fullScene, 1)]), $clock);
aquarium_expect(count($state['scene']) === 25 && count($state['inventory']) === 33,
    'Recalling one item keeps the remaining scene and all owned inventory.');

// The ledger response remains bounded while keeping the newest entries first.
for ($index = 0; $index < 105; $index++) {
    aquarium_record_wallet($pdo, $memberId, auth_random_id(), 0, 880, 'test', null, $clock());
}
$memberState = aquarium_read_state($pdo, $memberId);
aquarium_expect(count($memberState['wallet']) === 100
    && (int)$memberState['wallet'][0]['id'] > (int)$memberState['wallet'][99]['id'],
    'Wallet returns the latest 100 records, in descending order, for the current account only.');
$pdo->prepare('DELETE FROM auth_users WHERE id = :id')->execute([':id' => $memberId]);
foreach (['aquarium_profiles', 'aquarium_inventory', 'aquarium_wallet_entries', 'aquarium_operations'] as $table) {
    $count = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE user_id = :user');
    $count->execute([':user' => $memberId]);
    aquarium_expect((int)$count->fetchColumn() === 0, 'Deleting an account cascades aquarium data: ' . $table);
}

// Old queued purchases and the expanded shop share the same inventory contract.
$expandedUser = auth_register($pdo, 'svg_catalog_user', 'secret3', 'device-svg', '127.0.0.4', $clock);
$expandedId = $expandedUser['user']['id'];
aquarium_mutate($pdo, $expandedId, aquarium_request('initialize'), $clock);
$oldPurchase = aquarium_request('buy', ['catalog_version' => 3, 'item_id' => 'clownfish']);
aquarium_mutate($pdo, $expandedId, $oldPurchase, $clock);
aquarium_mutate($pdo, $expandedId, $oldPurchase, $clock);
foreach (['great-white-shark', 'sardine'] as $newFish) {
    aquarium_mutate($pdo, $expandedId,
        aquarium_request('buy', ['catalog_version' => 4, 'item_id' => $newFish]), $clock);
}
$expandedState = aquarium_read_state($pdo, $expandedId);
aquarium_expect(count($expandedState['inventory']) === 3 && $expandedState['coins'] === 160,
    'Version 3 retries are idempotent and version 4 offers both large sharks and small schooling fish.');
aquarium_expect($expandedState['scene'] === [] && $expandedState['scene_version'] === 0,
    'The expanded catalog never places purchases or overwrites the saved layout automatically.');

// Version 7 life categories share authoritative prices, idempotent purchases
// and the same versioned placement/recall contract as existing inventory.
$lifeUser = auth_register($pdo, 'aquarium_life_user', 'secret6', 'device-life', '127.0.0.7', $clock);
$lifeUserId = $lifeUser['user']['id'];
$lifeState = aquarium_mutate($pdo, $lifeUserId, aquarium_request('initialize'), $clock);
$lifeBalance = 1000;
$lifePurchases = ['rainbow-trout' => 280, 'staghorn-coral' => 180,
    'sea-lettuce-algae' => 80, 'mystery-snail' => 90];
$lifePurchaseCount = 0;
foreach ($lifePurchases as $itemId => $serverPrice) {
    $lifeBuy = aquarium_request('buy', ['catalog_version' => 7, 'item_id' => $itemId, 'price' => 0]);
    $lifeState = aquarium_mutate($pdo, $lifeUserId, $lifeBuy, $clock);
    $lifeBalance -= $serverPrice;
    $lifePurchaseCount++;
    aquarium_expect($lifeState['coins'] === $lifeBalance
        && count($lifeState['inventory']) === $lifePurchaseCount
        && count($lifeState['wallet']) === $lifePurchaseCount + 1
        && $lifeState['wallet'][0]['delta'] === -$serverPrice
        && $lifeState['wallet'][0]['balance'] === $lifeBalance
        && $lifeState['wallet'][0]['item_id'] === $itemId,
        'Expanded life purchases ignore caller prices and reconcile server balance, inventory and ledger.');
    aquarium_expect($lifeState['scene'] === [] && $lifeState['scene_version'] === 0,
        'New fish, coral, algae and snails remain in the backpack until explicitly placed.');
    aquarium_expect(aquarium_mutate($pdo, $lifeUserId, array_reverse($lifeBuy, true), $clock) === $lifeState,
        'Retrying a version 7 purchase cannot duplicate its instance, debit or wallet entry.');
}
aquarium_expect($lifeState['coins'] === 370, 'Four version 7 representative purchases cost exactly 630 coins.');
$queuedVersionSix = aquarium_request('buy', ['catalog_version' => 6, 'item_id' => 'clownfish']);
$lifeState = aquarium_mutate($pdo, $lifeUserId, $queuedVersionSix, $clock);
aquarium_expect($lifeState['coins'] === 270 && count($lifeState['inventory']) === 5
    && count($lifeState['wallet']) === 6 && $lifeState['scene'] === [],
    'Queued version 6 purchases remain accepted after the version 7 expansion.');
aquarium_expect(aquarium_mutate($pdo, $lifeUserId, $queuedVersionSix, $clock) === $lifeState,
    'A retried queued version 6 purchase still charges and delivers only once.');
$lifeOwnedByItem = array_column($lifeState['inventory'], 'id', 'item_id');
$lifeScene = [
    ['instance_id' => $lifeOwnedByItem['rainbow-trout'], 'x' => 0.25, 'y' => 0.4, 'scale' => 1.0, 'layer' => 1],
    ['instance_id' => $lifeOwnedByItem['staghorn-coral'], 'x' => 0.4, 'y' => 0.88, 'scale' => 1.0, 'layer' => 0],
    ['instance_id' => $lifeOwnedByItem['sea-lettuce-algae'], 'x' => 0.6, 'y' => 0.88, 'scale' => 0.8, 'layer' => 0],
    ['instance_id' => $lifeOwnedByItem['mystery-snail'], 'x' => 0.75, 'y' => 0.93, 'scale' => 1.2, 'layer' => 2],
];
$lifeSave = aquarium_request('save_scene', ['base_scene_version' => 0, 'scene' => $lifeScene]);
$lifePlaced = aquarium_mutate($pdo, $lifeUserId, $lifeSave, $clock);
aquarium_expect($lifePlaced['scene'] == $lifeScene && $lifePlaced['scene_version'] === 1
    && $lifePlaced['inventory'] === $lifeState['inventory'] && $lifePlaced['wallet'] === $lifeState['wallet']
    && $lifePlaced['coins'] === 270,
    'New life instances can share a versioned layout without changing their ownership or wallet.');
aquarium_expect(aquarium_mutate($pdo, $lifeUserId, $lifeSave, $clock) === $lifePlaced,
    'Retrying a new-category scene save cannot advance the layout version twice.');
$lifeRecall = aquarium_request('save_scene', ['base_scene_version' => 1, 'scene' => array_slice($lifeScene, 0, 3)]);
$lifeRecalled = aquarium_mutate($pdo, $lifeUserId, $lifeRecall, $clock);
aquarium_expect($lifeRecalled['scene_version'] === 2 && count($lifeRecalled['scene']) === 3
    && !in_array($lifeOwnedByItem['mystery-snail'], array_column($lifeRecalled['scene'], 'instance_id'), true)
    && $lifeRecalled['inventory'] === $lifePlaced['inventory']
    && $lifeRecalled['wallet'] === $lifePlaced['wallet'] && $lifeRecalled['coins'] === 270,
    'Recalling a snail removes only its placement while preserving all five owned instances.');
aquarium_expect(aquarium_mutate($pdo, $lifeUserId, $lifeRecall, $clock) === $lifeRecalled,
    'Retrying a recall does not remove another item or increment the scene version again.');
aquarium_error('scene_conflict', 409, static fn() => aquarium_mutate($pdo, $lifeUserId,
    aquarium_request('save_scene', ['base_scene_version' => 1, 'scene' => $lifeScene]), $clock));
aquarium_expect(aquarium_read_state($pdo, $lifeUserId) === $lifeRecalled,
    'A stale device cannot silently put a recalled new-category item back into the layout.');
$lifeOperations = $pdo->prepare('SELECT COUNT(*) FROM aquarium_operations WHERE user_id = :user');
$lifeOperations->execute([':user' => $lifeUserId]);
aquarium_expect((int)$lifeOperations->fetchColumn() === 8
    && array_sum(array_column($lifeRecalled['wallet'], 'delta')) === 270,
    'Initialization, five purchases and two layout changes each journal once; retries and conflicts add nothing.');

// Card packs are ordinary inventory instances until explicitly opened. The
// server chooses the reward, consumes exactly one pack and returns the same
// reward on an idempotent retry.
$packUser = auth_register($pdo, 'aquarium_pack_user', 'secret4', 'device-pack', '127.0.0.5', $clock);
$packUserId = $packUser['user']['id'];
aquarium_mutate($pdo, $packUserId, aquarium_request('initialize'), $clock);
$packPurchase = aquarium_mutate($pdo, $packUserId,
    aquarium_request('buy', ['catalog_version' => 5, 'item_id' => AQUARIUM_CARD_PACK_ID]), $clock);
aquarium_expect($packPurchase['coins'] === 800 && count($packPurchase['inventory']) === 1
    && $packPurchase['inventory'][0]['item_id'] === AQUARIUM_CARD_PACK_ID,
    'Buying a card pack debits its server price and stores one backpack instance.');
$packInstanceId = $packPurchase['inventory'][0]['id'];
$foreignStateBefore = aquarium_read_state($pdo, $expandedId);
$operationsBefore = (int)$pdo->query('SELECT COUNT(*) FROM aquarium_operations')->fetchColumn();
aquarium_error('pack_not_found', 409, static fn() => aquarium_mutate($pdo, $expandedId,
    aquarium_request('open_pack', ['pack_instance_id' => $packInstanceId]), $clock));
aquarium_expect(aquarium_read_state($pdo, $expandedId) === $foreignStateBefore
    && aquarium_read_state($pdo, $packUserId) === $packPurchase
    && (int)$pdo->query('SELECT COUNT(*) FROM aquarium_operations')->fetchColumn() === $operationsBefore,
    'Opening another account\'s pack leaves both accounts and the operation journal unchanged.');
aquarium_error('invalid_scene', 400, static fn() => aquarium_mutate($pdo, $packUserId,
    aquarium_request('save_scene', ['base_scene_version' => 0, 'scene' => [
        ['instance_id' => $packInstanceId, 'x' => 0.5, 'y' => 0.5, 'scale' => 1],
    ]]), $clock));
aquarium_expect(aquarium_read_state($pdo, $packUserId) === $packPurchase
    && (int)$pdo->query('SELECT COUNT(*) FROM aquarium_operations')->fetchColumn() === $operationsBefore,
    'A card pack cannot be placed in the scene or change its version, inventory, wallet or operation journal.');
$nonPackInstanceId = $foreignStateBefore['inventory'][0]['id'];
aquarium_error('pack_not_found', 409, static fn() => aquarium_mutate($pdo, $expandedId,
    aquarium_request('open_pack', ['pack_instance_id' => $nonPackInstanceId]), $clock));
aquarium_expect(aquarium_read_state($pdo, $expandedId) === $foreignStateBefore
    && (int)$pdo->query('SELECT COUNT(*) FROM aquarium_operations')->fetchColumn() === $operationsBefore,
    'Opening an owned non-pack instance cannot consume it, grant a reward or record an operation.');
$openPack = aquarium_request('open_pack', ['pack_instance_id' => $packInstanceId]);
$opened = aquarium_mutate($pdo, $packUserId, $openPack, $clock);
aquarium_expect(isset($opened['opened_item']) && is_array($opened['opened_item'])
    && $opened['opened_item']['category'] !== 'pack' && $opened['coins'] === 800
    && $opened['opened_item_id'] === $opened['opened_item']['item_id']
    && count($opened['inventory']) === 1 && $opened['inventory'][0]['item_id'] === $opened['opened_item']['item_id'],
    'Opening a card pack keeps the balance, consumes the pack and grants one random aquarium item.');
aquarium_expect($opened['wallet'][0]['reason'] === 'pack_open'
    && $opened['wallet'][0]['item_id'] === $opened['opened_item']['item_id'],
    'Pack opening records the granted item in the wallet ledger.');
$openedAgain = aquarium_mutate($pdo, $packUserId, $openPack, $clock);
aquarium_expect($openedAgain['opened_item']['item_id'] === $opened['opened_item']['item_id']
    && count($openedAgain['inventory']) === 1 && count($openedAgain['wallet']) === 3,
    'Retrying a successful pack opening is idempotent and reveals the same reward.');
$afterOpen = aquarium_read_state($pdo, $packUserId);
$operationsAfterOpen = (int)$pdo->query('SELECT COUNT(*) FROM aquarium_operations')->fetchColumn();
aquarium_error('pack_not_found', 409, static fn() => aquarium_mutate($pdo, $packUserId,
    aquarium_request('open_pack', ['pack_instance_id' => $packInstanceId]), $clock));
aquarium_expect(aquarium_read_state($pdo, $packUserId) === $afterOpen
    && (int)$pdo->query('SELECT COUNT(*) FROM aquarium_operations')->fetchColumn() === $operationsAfterOpen,
    'A distinct operation cannot consume the same pack twice or change the committed reward.');

$database = tempnam(sys_get_temp_dir(), 'aquarium-test-');
if ($database === false) { throw new RuntimeException('Could not create concurrency-test database.'); }
try {
    $parallelPdo = aquarium_test_database('sqlite:' . $database);
    $parallelPdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
    $parallelAdmin = auth_register($parallelPdo, 'concurrent_admin', 'secret1', 'device-a', '127.0.0.3', $clock);
    $parallelId = $parallelAdmin['user']['id'];
    $results = aquarium_parallel($database, $parallelId,
        [aquarium_request('initialize'), aquarium_request('initialize')]);
    aquarium_expect($results === ['ok', 'ok'], 'Concurrent first-use requests both complete.');
    $state = aquarium_read_state($parallelPdo, $parallelId);
    aquarium_expect($state['coins'] === 1000 && count($state['wallet']) === 1,
        'Concurrent initialization cannot double the grant.');
    $retry = aquarium_request('buy', ['catalog_version' => 2, 'item_id' => 'clownfish']);
    $results = aquarium_parallel($database, $parallelId, [$retry, $retry]);
    aquarium_expect($results === ['ok', 'ok'], 'Concurrent duplicate requests both acknowledge success.');
    $state = aquarium_read_state($parallelPdo, $parallelId);
    aquarium_expect($state['coins'] === 900 && count($state['inventory']) === 1,
        'Concurrent duplicate purchase has exactly one debit and one item.');
    $requests = [];
    for ($index = 0; $index < 5; $index++) {
        $requests[] = aquarium_request('buy', ['catalog_version' => 2, 'item_id' => 'butterflyfish']);
    }
    $results = array_count_values(aquarium_parallel($database, $parallelId, $requests));
    aquarium_expect(($results['ok'] ?? 0) === 3 && ($results['insufficient_coins'] ?? 0) === 2,
        'Concurrent purchases serialize and reject spending beyond the current balance.');
    $state = aquarium_read_state($parallelPdo, $parallelId);
    aquarium_expect($state['coins'] === 120 && count($state['inventory']) === 4 && count($state['wallet']) === 5,
        'Concurrent purchase state reconciles inventory, balance and wallet history.');
    $instance = ['instance_id' => $state['inventory'][0]['id'], 'x' => 0.1, 'y' => 0.7, 'scale' => 1];
    $results = array_count_values(aquarium_parallel($database, $parallelId, [
        aquarium_request('save_scene', ['base_scene_version' => 0, 'scene' => [$instance]]),
        aquarium_request('save_scene', ['base_scene_version' => 0, 'scene' => []]),
    ]));
    aquarium_expect(($results['ok'] ?? 0) === 1 && ($results['scene_conflict'] ?? 0) === 1,
        'Concurrent layouts using one base version allow only one commit.');
    aquarium_expect(aquarium_read_state($parallelPdo, $parallelId)['scene_version'] === 1,
        'The losing layout cannot silently overwrite the committed layout.');

    $parallelPackUser = auth_register($parallelPdo, 'concurrent_pack', 'secret5', 'device-pack', '127.0.0.6', $clock);
    $parallelPackId = $parallelPackUser['user']['id'];
    aquarium_mutate($parallelPdo, $parallelPackId, aquarium_request('initialize'), $clock);
    $parallelPackState = aquarium_mutate($parallelPdo, $parallelPackId,
        aquarium_request('buy', ['catalog_version' => 5, 'item_id' => AQUARIUM_CARD_PACK_ID]), $clock);
    $parallelPackInstance = $parallelPackState['inventory'][0]['id'];
    $requests = [
        aquarium_request('open_pack', ['pack_instance_id' => $parallelPackInstance]),
        aquarium_request('open_pack', ['pack_instance_id' => $parallelPackInstance]),
    ];
    $results = array_count_values(aquarium_parallel($database, $parallelPackId, $requests));
    aquarium_expect(($results['ok'] ?? 0) === 1 && ($results['pack_not_found'] ?? 0) === 1,
        'Concurrent distinct operations opening one pack allow exactly one reward transaction.');
    $parallelOpened = aquarium_read_state($parallelPdo, $parallelPackId);
    $operationQuery = $parallelPdo->prepare('SELECT COUNT(*) FROM aquarium_operations WHERE user_id = :user AND action = :action');
    $operationQuery->execute([':user' => $parallelPackId, ':action' => 'open_pack']);
    aquarium_expect($parallelOpened['coins'] === $parallelPackState['coins']
        && count($parallelOpened['inventory']) === 1
        && $parallelOpened['inventory'][0]['item_id'] !== AQUARIUM_CARD_PACK_ID
        && $parallelOpened['inventory'][0]['id'] !== $parallelPackInstance
        && $parallelOpened['scene'] === [] && $parallelOpened['scene_version'] === 0
        && count($parallelOpened['wallet']) === 3
        && $parallelOpened['wallet'][0]['reason'] === 'pack_open'
        && $parallelOpened['wallet'][0]['item_id'] === $parallelOpened['inventory'][0]['item_id']
        && (int)$operationQuery->fetchColumn() === 1,
        'Concurrent pack opening consumes one pack, grants one item and writes one operation without changing balance or scene.');
    $operationQuery = null;
    $parallelPdo = null;
} finally {
    $operationQuery = null;
    $parallelPdo = null;
    if (is_file($database)) { unlink($database); }
}

echo "aquarium_service_test: ok (service, HTTP guards, concurrent SQLite processes)\n";
