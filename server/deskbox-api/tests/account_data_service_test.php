<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/auth.php';
require dirname(__DIR__) . '/api/_app/account_data.php';

function data_expect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function data_expect_error(string $code, callable $operation): void
{
    try { $operation(); }
    catch (ApiError $error) {
        data_expect($error->apiCode === $code, "Expected {$code}, got {$error->apiCode}");
        return;
    }
    throw new RuntimeException("Expected API error {$code}");
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
$clock = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-14T08:00:00Z');
$alice = auth_register($pdo, 'Alice_Cloud', 'secret1', 'device-a', '127.0.0.10', $clock);
$bob = auth_register($pdo, 'Bob_Cloud', 'secret1', 'device-b', '127.0.0.11', $clock);
$aliceId = $alice['user']['id'];
$bobId = $bob['user']['id'];

$makeFiles = static fn(int $index): array => [[
    'path' => 'settings.json',
    'content' => json_encode(['index' => $index], JSON_THROW_ON_ERROR),
    'bytes' => strlen(json_encode(['index' => $index], JSON_THROW_ON_ERROR)),
    'sha256' => hash('sha256', json_encode(['index' => $index], JSON_THROW_ON_ERROR)),
]];

$ids = [];
for ($index = 1; $index <= 6; $index++) {
    $snapshotClock = static fn(): DateTimeImmutable => new DateTimeImmutable("2026-10-14T08:0{$index}:00Z");
    $ids[] = backup_create_snapshot($pdo, $aliceId, 'device-' . $index, '1.0.' . $index, $makeFiles($index), $snapshotClock)['snapshot']['id'];
}
$listed = backup_list_snapshots($pdo, $aliceId);
data_expect(count($listed['snapshots']) === 5, 'Only the newest five snapshots remain across devices.');
data_expect(!in_array($ids[0], array_column($listed['snapshots'], 'id'), true), 'Oldest snapshot was pruned.');
$download = backup_download_snapshot($pdo, $aliceId, $ids[5]);
data_expect($download['snapshot']['files'][0]['path'] === 'settings.json', 'Download returns file manifest.');
data_expect($download['snapshot']['files'][0]['content'] === '{"index":6}', 'Download preserves original JSON.');
data_expect_error('snapshot_not_found', static fn() => backup_download_snapshot($pdo, $bobId, $ids[5]));

$badJson = [['path' => 'settings.json', 'content' => '{', 'bytes' => 1, 'sha256' => hash('sha256', '{')]];
data_expect_error('invalid_backup_json', static fn() => backup_create_snapshot($pdo, $aliceId, 'd', '1', $badJson, $clock));
$badHash = $makeFiles(7); $badHash[0]['sha256'] = str_repeat('0', 64);
data_expect_error('backup_hash_mismatch', static fn() => backup_create_snapshot($pdo, $aliceId, 'd', '1', $badHash, $clock));
$badPath = $makeFiles(8); $badPath[0]['path'] = '../settings.json';
data_expect_error('invalid_backup_path', static fn() => backup_create_snapshot($pdo, $aliceId, 'd', '1', $badPath, $clock));

$first = tree_apply_progress($pdo, $aliceId, [
    'operation_id' => 'operation-alice-0001', 'device_id' => 'device-a', 'day' => '2026-10-14',
    'energy_delta' => 40000, 'day_growth_delta' => 40000, 'fruit_delta' => 3, 'harvested_delta' => 1,
], $clock);
data_expect($first['duplicate'] === false && $first['profile']['total_energy'] === 40000, 'First tree delta is applied.');
$duplicate = tree_apply_progress($pdo, $aliceId, [
    'operation_id' => 'operation-alice-0001', 'device_id' => 'device-a', 'day' => '2026-10-14',
    'energy_delta' => 40000, 'day_growth_delta' => 40000, 'fruit_delta' => 3, 'harvested_delta' => 1,
], $clock);
data_expect($duplicate['duplicate'] === true && $duplicate['profile']['total_energy'] === 40000, 'Duplicate operation is idempotent.');
tree_apply_progress($pdo, $aliceId, [
    'operation_id' => 'operation-alice-0002', 'device_id' => 'device-b', 'day' => '2026-10-14',
    'energy_delta' => 5000, 'day_growth_delta' => 5000, 'fruit_delta' => 2, 'harvested_delta' => 0,
], $clock);
$aliceAggregate = tree_read_state($pdo, $aliceId, '2026-10-14');
data_expect($aliceAggregate['total_energy'] === 45000, 'Two devices add energy deltas to one aggregate.');
$duplicateSecond = tree_apply_progress($pdo, $aliceId, [
    'operation_id' => 'operation-alice-0002', 'device_id' => 'device-b', 'day' => '2026-10-14',
    'energy_delta' => 5000, 'day_growth_delta' => 5000, 'fruit_delta' => 2, 'harvested_delta' => 0,
], $clock);
data_expect($duplicateSecond['duplicate'] === true && $duplicateSecond['state']['total_energy'] === 45000,
    'Repeated device operation is idempotent.');
tree_apply_progress($pdo, $bobId, [
    'operation_id' => 'operation-bob-000001', 'device_id' => 'device-c', 'day' => '2026-10-14',
    'energy_delta' => 1000, 'day_growth_delta' => 1000, 'fruit_delta' => 0, 'harvested_delta' => 0,
], $clock);

$authoritative = tree_apply_state($pdo, $aliceId, [
    'operation_id' => 'state-alice-000001', 'device_id' => 'device-a', 'base_version' => 2,
    'day' => '2026-10-14', 'today_growth' => 45000, 'total_energy' => 45000,
    'stage' => 2, 'fruit_energy' => 500, 'fruit_total' => 5, 'harvested_total' => 1,
], $clock);
data_expect($authoritative['state']['state_version'] === 3, 'Authoritative state increments its version.');
data_expect($authoritative['state']['fruit_inventory'] === 4, 'Fruit inventory is derived from total and harvested fruit.');
$duplicateState = tree_apply_state($pdo, $aliceId, [
    'operation_id' => 'state-alice-000001', 'device_id' => 'device-a', 'base_version' => 2,
    'day' => '2026-10-14', 'today_growth' => 45000, 'total_energy' => 45000,
    'stage' => 2, 'fruit_energy' => 500, 'fruit_total' => 5, 'harvested_total' => 1,
], $clock);
data_expect($duplicateState['duplicate'] === true, 'Authoritative state retry is idempotent.');
data_expect_error('state_conflict', static fn() => tree_apply_state($pdo, $aliceId, [
    'operation_id' => 'state-alice-000002', 'device_id' => 'device-b', 'base_version' => 2,
    'day' => '2026-10-14', 'today_growth' => 45001, 'total_energy' => 45001,
    'stage' => 2, 'fruit_energy' => 501, 'fruit_total' => 5, 'harvested_total' => 1,
], $clock));

$leaderboard = tree_leaderboard($pdo, $aliceId, '2026-10-14');
data_expect(count($leaderboard['entries']) === 2, 'Leaderboard includes participating users.');
data_expect($leaderboard['entries'][0]['username'] === 'Alice_Cloud', 'Leaderboard sorts by energy descending.');
data_expect($leaderboard['entries'][0]['today_growth'] === 45000, 'Daily growth is summed across devices.');
data_expect($leaderboard['entries'][0]['stage'] === 2, 'Stage is derived from aggregate energy.');
data_expect($leaderboard['current_user']['rank'] === 1, 'Current user actual rank is returned.');

tree_update_profile($pdo, $aliceId, false, $clock);
$privateBoard = tree_leaderboard($pdo, $bobId, '2026-10-14');
data_expect(count($privateBoard['entries']) === 1 && $privateBoard['entries'][0]['username'] === 'Bob_Cloud', 'Private user is hidden.');
data_expect($privateBoard['current_user']['rank'] === 1, 'Viewer can still see their own participating rank.');
$alicePrivateView = tree_leaderboard($pdo, $aliceId, '2026-10-14');
data_expect($alicePrivateView['current_user']['rank'] === null, 'Opted-out current user has no public rank.');

echo "account data service tests passed\n";
