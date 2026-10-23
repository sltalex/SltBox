<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/account_data.php';
require dirname(__DIR__) . '/api/_app/admin.php';
require dirname(__DIR__) . '/api/_app/admin_web.php';

function web_check(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException($message); }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
$clock = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-21T10:00:00Z');
$administrator = auth_register($pdo, 'web_admin', 'secret1', 'web-admin', '127.0.0.1', $clock);
$member = auth_register($pdo, 'web_member', 'secret2', 'web-member', '127.0.0.2', $clock);

$adminSessionId = web_admin_find_session_id($pdo, $administrator['session']['access_token']);
$memberSessionId = web_admin_find_session_id($pdo, $member['session']['access_token']);
web_check(is_string($adminSessionId) && strlen($adminSessionId) === 32,
    'The web login can retain an opaque server session identifier without retaining an access token.');
web_check(web_admin_validate_session($pdo, $administrator['user']['id'], $adminSessionId, $clock)['username'] === 'web_admin',
    'The bootstrap administrator has a valid web management session.');

$_SESSION = ['csrf' => str_repeat('a', 64)];
$shopResult = admin_page_mutate($pdo, ['user_id' => $administrator['user']['id']],
    ['csrf' => $_SESSION['csrf'], 'operation' => 'shop_price', 'panel' => 'shop', 'card_pack_price' => '275']);
web_check($shopResult === 'shop_price' && admin_read($pdo, $administrator['user']['id'], 'shop', [], $clock)['card_pack_price'] === 275,
    'Saving the web shop price updates the card pack price.');
$coinsResult = admin_page_mutate($pdo, ['user_id' => $administrator['user']['id']],
    ['csrf' => $_SESSION['csrf'], 'operation' => 'set_coins', 'target_id' => $member['user']['id'], 'coins' => '125']);
web_check($coinsResult === 'set_coins'
    && admin_read($pdo, $administrator['user']['id'], 'user', ['id' => $member['user']['id']], $clock)['aquarium']['coins'] === 125,
    'Saving coins through web user management updates the selected account.');
$updateResult = admin_page_mutate($pdo, ['user_id' => $administrator['user']['id']],
    ['csrf' => $_SESSION['csrf'], 'operation' => 'update_user', 'target_id' => $member['user']['id'],
        'username' => 'web_member_new', 'status' => 'active']);
web_check($updateResult === 'update_user'
    && admin_read($pdo, $administrator['user']['id'], 'user', ['id' => $member['user']['id']], $clock)['user']['username'] === 'web_member_new',
    'Saving a user through web management updates the selected account.');

$adminPage = file_get_contents(__DIR__ . '/../api/admin/index.php');
web_check(is_string($adminPage) && str_contains($adminPage, 'fruit_web_asset_url'),
    'The fruit catalogue preview resolves an explicit per-fruit web PNG URL.');
web_check(!str_contains((string)$adminPage, 'fruits-atlas.png') && !str_contains((string)$adminPage, 'background-position'),
    'The fruit catalogue preview no longer crops the legacy fruit atlas.');
web_check(str_contains((string)$adminPage, '<img src="<?= admin_page_escape($fruitImageUrl) ?>"') &&
    str_contains((string)$adminPage, 'width="52" height="60"') &&
    str_contains((string)$adminPage, 'object-fit:contain'),
    'The fruit catalogue emits an accessible 52-by-60 individual PNG preview.');

try {
    web_admin_validate_session($pdo, $member['user']['id'], $memberSessionId, $clock);
    throw new RuntimeException('A non-administrator must not reach web management.');
} catch (ApiError $error) {
    web_check($error->apiCode === 'admin_forbidden', 'Web management rejects ordinary account sessions.');
}

admin_revoke_sessions($pdo, $administrator['user']['id'], $adminSessionId, $clock());
try {
    web_admin_validate_session($pdo, $administrator['user']['id'], $adminSessionId, $clock);
    throw new RuntimeException('A revoked login must not retain web access.');
} catch (ApiError $error) {
    web_check($error->apiCode === 'invalid_session', 'Web management follows session revocation.');
}

echo "web_admin_service_test: ok\n";
