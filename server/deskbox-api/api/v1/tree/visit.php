<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 2) . '/_app/bootstrap.php';
require dirname(__DIR__, 2) . '/_app/account_data.php';
require dirname(__DIR__, 2) . '/_app/fruit.php';
require dirname(__DIR__, 2) . '/_app/tree_visit.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    api_run('POST', static function (PDO $pdo): array {
        $user = account_authenticated_user($pdo, 'api_clock');
        $body = api_body();
        return tree_visit_record($pdo, api_string($body, 'account'),
            (string)$user['user_id'], api_string($body, 'operation_id'));
    });
}
api_run('GET', static function (PDO $pdo): array {
    $user = account_authenticated_user($pdo, 'api_clock');
    $account = isset($_GET['account']) && is_string($_GET['account']) ? $_GET['account'] : '';
    tree_visit_assert_other($account, (string)$user['user_id']);
    return tree_visit_snapshot($pdo, $account, (string)$user['user_id']);
});
