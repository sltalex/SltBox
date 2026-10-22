<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/account_data.php';
require dirname(__DIR__) . '/api/_app/admin.php';

function check(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException($message); }
}
function fails(string $code, callable $action): void
{
    try { $action(); }
    catch (ApiError $error) {
        check($error->apiCode === $code, "Expected {$code}, received {$error->apiCode}");
        return;
    }
    throw new RuntimeException("Expected {$code}");
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
$clock = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-16T12:00:00Z');
$admin = auth_register($pdo, 'first_user', 'secret1', 'device-a', '127.0.0.1', $clock);
$member = auth_register($pdo, 'second_user', 'secret2', 'device-b', '127.0.0.2', $clock);
$adminId = $admin['user']['id'];
$memberId = $member['user']['id'];
check($admin['user']['role'] === 'admin', 'First registration is administrator.');
check($member['user']['role'] === 'user', 'Later registration is ordinary user.');
check(admin_require($pdo, $admin['session']['access_token'], $clock)['user_id'] === $adminId, 'Admin token works.');
fails('admin_forbidden', static fn() => admin_require($pdo, $member['session']['access_token'], $clock));
$summary = admin_read($pdo, $adminId, 'overview', [], $clock);
check($summary['overview']['users'] === 2, 'Summary includes both users.');
$list = admin_read($pdo, $adminId, 'users', ['q' => 'second'], $clock);
check(count($list['users']) === 1, 'User search filters results.');
$paged = admin_read($pdo, $adminId, 'users', ['page' => 2, 'page_size' => 1], $clock);
check($paged['total'] === 2 && $paged['page'] === 2 && $paged['page_count'] === 2 && count($paged['users']) === 1,
    'User list returns server-side paging metadata.');
$detail = admin_read($pdo, $adminId, 'user', ['id' => $memberId], $clock);
check(count($detail['sessions']) === 1, 'Device sessions are visible.');
check((int)$pdo->query('SELECT COUNT(*) FROM tree_profiles')->fetchColumn() === 0,
    'Reading a user must not enlist them in the forest.');
check($detail['tree']['state']['state_version'] === 0 && !$detail['tree']['participating'],
    'An untouched tree is presented read-only.');
check(api_is_https(['HTTPS' => 'on']) && api_is_https(['SERVER_PORT' => '443'])
    && !api_is_https(['HTTPS' => 'off', 'SERVER_PORT' => '80']), 'HTTPS detection requires a secure transport.');

$common = ['target_id' => $memberId, 'reason' => 'Test operation'];
admin_mutate($pdo, $adminId, ['target_id' => $memberId, 'action' => 'update_user',
    'username' => 'second_user', 'status' => 'active'], $clock);
check((string)$pdo->query("SELECT username FROM auth_users WHERE id = '{$memberId}'")->fetchColumn() === 'second_user',
    'A user row can be edited without an operation reason.');
try {
    admin_mutate($pdo, $adminId, ['target_id' => $adminId, 'action' => 'set_fruit_recycle_price',
        'price_per_fruit' => 20], $clock);
    throw new RuntimeException('Legacy fixed fruit price action must be unavailable.');
} catch (ApiError $error) {
    check($error->apiCode === 'invalid_action', 'Legacy fixed fruit price action is removed.');
}
admin_mutate($pdo, $adminId, ['target_id' => $adminId, 'action' => 'set_aquarium_card_pack_price',
    'card_pack_price' => 275], $clock);
check(admin_read($pdo, $adminId, 'shop', [], $clock)['card_pack_price'] === 275,
    'A non-negative integer aquarium card pack price can be saved by the web administrator.');
check(aquarium_catalog($pdo)['items'][array_search(AQUARIUM_CARD_PACK_ID,
    array_column(aquarium_catalog($pdo)['items'], 'id'), true)]['price'] === 275,
    'The user-facing aquarium catalog reads the administrator card pack price.');
admin_mutate($pdo, $adminId, ['target_id' => $adminId, 'action' => 'set_aquarium_card_pack_price',
    'card_pack_price' => 200], $clock);
admin_mutate($pdo, $adminId, $common + ['action' => 'disable'], $clock);
fails('invalid_session', static fn() => auth_me($pdo, $member['session']['access_token'], $clock));
fails('account_disabled', static fn() => auth_login($pdo, 'second_user', 'secret2', 'device-b', '127.0.0.2', $clock));
admin_mutate($pdo, $adminId, $common + ['action' => 'enable'], $clock);
$member = auth_login($pdo, 'second_user', 'secret2', 'device-b', '127.0.0.2', $clock);

$state = admin_read($pdo, $adminId, 'user', ['id' => $memberId], $clock)['tree']['state'];
fails('state_conflict', static fn() => admin_mutate($pdo, $adminId, $common + [
    'action' => 'tree_reset', 'day' => '2026-10-15', 'base_version' => $state['state_version']], $clock));
fails('invalid_state', static fn() => admin_mutate($pdo, $adminId, $common + [
    'action' => 'tree_correct', 'day' => '2026-10-16', 'base_version' => $state['state_version'],
    'total_energy' => 40000, 'today_growth' => 100, 'fruit_energy' => 1000,
    'fruit_total' => 2, 'harvested_total' => 1], $clock));
admin_mutate($pdo, $adminId, $common + ['action' => 'tree_correct', 'day' => '2026-10-16', 'base_version' => $state['state_version'],
    'total_energy' => 151000, 'today_growth' => 100, 'fruit_energy' => 1000,
    'fruit_total' => 2, 'harvested_total' => 1], $clock);
$state = tree_read_state($pdo, $memberId, '2026-10-16');
check($state['stage'] === 5 && $state['state_version'] === 1, 'Correction derives stage and increments version.');
fails('state_conflict', static fn() => admin_mutate($pdo, $adminId, $common + [
    'action' => 'tree_reset', 'day' => '2026-10-16', 'base_version' => 0], $clock));
admin_mutate($pdo, $adminId, $common + ['action' => 'tree_reset', 'day' => '2026-10-16', 'base_version' => 1], $clock);
check(tree_read_state($pdo, $memberId, '2026-10-16')['total_energy'] === 0, 'Reset clears cloud tree state.');

$coinDetail = admin_read($pdo, $adminId, 'user', ['id' => $memberId], $clock);
check(!$coinDetail['aquarium']['initialized'] && $coinDetail['aquarium']['coins'] === 0,
    'An untouched aquarium has no editable balance yet.');
admin_mutate($pdo, $adminId, $common + ['action' => 'set_aquarium_coins', 'coins' => 260], $clock);
$coinDetail = admin_read($pdo, $adminId, 'user', ['id' => $memberId], $clock);
check($coinDetail['aquarium']['initialized'] && $coinDetail['aquarium']['coins'] === 260,
    'Administrators can set an uninitialized account to an exact aquarium coin balance.');
$wallet = $pdo->prepare('SELECT delta, balance, reason FROM aquarium_wallet_entries WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$wallet->execute([$memberId]);
check($wallet->fetch(PDO::FETCH_ASSOC) === ['delta' => 260, 'balance' => 260, 'reason' => 'admin_adjustment'],
    'The first administrator balance change creates an accountable wallet entry.');
admin_mutate($pdo, $adminId, $common + ['action' => 'set_aquarium_coins', 'coins' => 40], $clock);
$wallet->execute([$memberId]);
check($wallet->fetch(PDO::FETCH_ASSOC) === ['delta' => -220, 'balance' => 40, 'reason' => 'admin_adjustment'],
    'Subsequent administrator balance changes record the exact negative adjustment.');
fails('invalid_coins', static fn() => admin_mutate($pdo, $adminId, $common + [
    'action' => 'set_aquarium_coins', 'coins' => -1], $clock));
fails('invalid_coins', static fn() => admin_mutate($pdo, $adminId, $common + [
    'action' => 'set_aquarium_coins', 'coins' => 2147483648], $clock));

$backupId = auth_random_id();
$pdo->prepare('INSERT INTO account_backup_snapshots (id,user_id,device_id,app_version,file_count,total_bytes,sha256,created_at) '
    . 'VALUES (?,?,?,?,?,?,?,?)')->execute([$backupId, $memberId, 'device-b', '1', 1, 13, str_repeat('a', 64), '2026-10-16 12:00:00']);
$pdo->prepare('INSERT INTO account_backup_files (snapshot_id,relative_path,byte_count,sha256,json_content) '
    . 'VALUES (?,?,?,?,?)')->execute([$backupId, 'private.json', 13, str_repeat('b', 64), 'private secret']);
$detail = admin_read($pdo, $adminId, 'user', ['id' => $memberId], $clock);
check(count($detail['backups']) === 1, 'Backup metadata visible.');
check(!str_contains(json_encode($detail, JSON_THROW_ON_ERROR), 'private secret'), 'Backup body excluded.');
admin_mutate($pdo, $adminId, $common + ['action' => 'delete_backup', 'snapshot_id' => $backupId], $clock);
check((int)$pdo->query('SELECT COUNT(*) FROM account_backup_files')->fetchColumn() === 0, 'Backup files cascade.');

$pdo->prepare('INSERT INTO account_identities (id,user_id,provider,corp_id,dingtalk_user_id,display_name,organization_name,department_name,avatar_url,created_at,updated_at) '
    . "VALUES (?,?,'dingtalk',?,?,?,?,?,?,?,?)")
    ->execute([auth_random_id(), $memberId, 'corp', 'ding-user', 'Person', 'Org', 'Department', '', '2026-10-16 12:00:00', '2026-10-16 12:00:00']);
admin_mutate($pdo, $adminId, $common + ['action' => 'unlink_dingtalk'], $clock);
check((int)$pdo->query('SELECT COUNT(*) FROM account_identities')->fetchColumn() === 0, 'DingTalk link removed.');

$sessionId = $pdo->query("SELECT id FROM auth_sessions WHERE user_id = '{$memberId}' AND revoked_at IS NULL LIMIT 1")->fetchColumn();
admin_mutate($pdo, $adminId, $common + ['action' => 'revoke_session', 'session_id' => $sessionId], $clock);
fails('invalid_session', static fn() => auth_me($pdo, $member['session']['access_token'], $clock));

fails('protected_admin', static fn() => admin_mutate($pdo, $adminId, [
    'target_id' => $adminId, 'action' => 'delete_user', 'reason' => 'test'], $clock));
fails('delete_confirmation', static fn() => admin_mutate($pdo, $adminId, $common + [
    'action' => 'delete_user', 'confirm_username' => 'wrong', 'admin_password' => 'secret1'], $clock));
admin_mutate($pdo, $adminId, $common + ['action' => 'delete_user',
    'confirm_username' => 'second_user', 'admin_password' => 'secret1'], $clock);
check((int)$pdo->query('SELECT COUNT(*) FROM auth_users')->fetchColumn() === 1, 'Account deleted.');
check((int)$pdo->query("SELECT COUNT(*) FROM auth_sessions WHERE user_id = '{$memberId}'")->fetchColumn() === 0, 'Deleted sessions cascade.');
check((int)$pdo->query('SELECT COUNT(*) FROM admin_audit')->fetchColumn() === 20, 'Operations and failures audited.');
check((int)$pdo->query("SELECT COUNT(*) FROM admin_audit WHERE result = 'failed'")->fetchColumn() === 7, 'Failed operations are audited.');
echo "admin_service_test: ok\n";
