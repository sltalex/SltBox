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
$treeShopResult = admin_page_mutate($pdo, ['user_id' => $administrator['user']['id']],
    ['csrf' => $_SESSION['csrf'], 'operation' => 'tree_pack_prices', 'panel' => 'shop',
        'tree_card_pack_single_price' => '260', 'tree_card_pack_ten_price' => '2300']);
$shop = admin_read($pdo, $administrator['user']['id'], 'shop', [], $clock);
web_check($treeShopResult === 'tree_pack_prices' && $shop['tree_card_pack_single_price'] === 260
    && $shop['tree_card_pack_ten_price'] === 2300,
    'Saving web shop prices updates both tree card pack products.');
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

$pdo->exec("CREATE TABLE town_worlds (world_id TEXT PRIMARY KEY, world_version INTEGER NOT NULL, navigation_version INTEGER NOT NULL, updated_at TEXT NOT NULL)");
$pdo->prepare('INSERT INTO town_worlds (world_id,world_version,navigation_version,updated_at) VALUES (?,?,?,?)')
    ->execute(['main', 4, 7, '2026-10-21 10:00:00']);
$navigationResult = admin_page_mutate($pdo, ['user_id' => $administrator['user']['id']],
    ['csrf' => $_SESSION['csrf'], 'operation' => 'town_navigation_rebuild', 'panel' => 'town_assets']);
$navigationRow = $pdo->query("SELECT world_version,navigation_version FROM town_worlds WHERE world_id='main'")->fetch(PDO::FETCH_ASSOC);
web_check($navigationResult === 'town_navigation_rebuild'
    && (int)$navigationRow['world_version'] === 5
    && (int)$navigationRow['navigation_version'] === 8,
    'The web navigation rebuild operation advances both world and navigation versions.');

$adminPage = file_get_contents(__DIR__ . '/../api/admin/index.php');
$adminWeb = file_get_contents(__DIR__ . '/../api/_app/admin_web.php');
$adminTreeView = file_get_contents(__DIR__ . '/../api/admin/views/tree.php');
$adminTownAssetsView = file_get_contents(__DIR__ . '/../api/admin/views/town_assets.php');
web_check(is_string($adminTreeView) && str_contains($adminTreeView, 'fruit_web_asset_url'),
    'The fruit catalogue preview resolves an explicit per-fruit web PNG URL.');
web_check(!str_contains((string)$adminTreeView, 'fruits-atlas.png') && !str_contains((string)$adminTreeView, 'background-position'),
    'The fruit catalogue preview no longer crops the legacy fruit atlas.');
web_check(str_contains((string)$adminTreeView, '<img src="<?= admin_page_escape($fruitImageUrl) ?>"') &&
    str_contains((string)$adminTreeView, 'width="52" height="60"') &&
    str_contains((string)$adminTreeView, 'object-fit:contain'),
    'The fruit catalogue emits an accessible 52-by-60 individual PNG preview.');
web_check(str_contains((string)$adminTownAssetsView, 'town_navigation_rebuild')
    && str_contains((string)$adminTownAssetsView, '当前导航版本'),
    'The shared-town assets page exposes the authenticated navigation rebuild action and version.');
web_check(str_contains((string)$adminWeb, "'sort_mode' => \$post['sort_mode']")
    && str_contains((string)$adminWeb, "'split_y' => \$post['split_y']"),
    'The web asset save path forwards split sorting fields to the asset service.');
web_check(str_contains((string)$adminWeb, "town_asset_save_items_bulk")
    && str_contains((string)$adminPage, 'town_asset_bulk_item'),
    'The web management page exposes the automatic scan bulk-draft operation.');

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
