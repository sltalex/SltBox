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
