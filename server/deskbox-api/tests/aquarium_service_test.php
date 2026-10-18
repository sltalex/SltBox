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
require dirname(__DIR__) . '/api/_app/aquarium.php';

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
aquarium_expect($catalog['catalog_version'] === 4 && $catalog['catalog_version'] === AQUARIUM_CATALOG_VERSION,
    'The catalog advertises the version checked by mutations.');
aquarium_expect(count($catalog['items']) === 158,
    'The catalog contains only the prepared aquarium choices.');
foreach (['fish' => 50, 'plant' => 50, 'shrimp' => 2, 'crab' => 4, 'shellfish' => 2, 'decoration' => 50] as $category => $expected) {
    aquarium_expect(count(array_filter($catalog['items'],
        static fn(array $item): bool => $item['category'] === $category)) === $expected,
        "The catalog preserves the prepared {$category} choices.");
}
foreach (['id', 'asset_key'] as $field) {
    aquarium_expect(count(array_unique(array_column($catalog['items'], $field))) === count($catalog['items']),
        "Every catalog {$field} must be unique.");
}
foreach ($catalog['items'] as $item) {
    aquarium_expect((bool)preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $item['id'])
        && $item['asset_key'] === $item['id'] && $item['price'] > 0,
        'Every catalog item has a safe stable resource key and positive server price.');
}
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
        'scene' => [array_replace($placement, ['layer' => 2])]]), $clock));
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
    $parallelPdo = null;
} finally {
    $parallelPdo = null;
    if (is_file($database)) { unlink($database); }
}

echo "aquarium_service_test: ok (service, HTTP guards, concurrent SQLite processes)\n";
