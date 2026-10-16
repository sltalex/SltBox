<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 2) . '/_app/bootstrap.php';
require dirname(__DIR__, 2) . '/_app/account_data.php';
require dirname(__DIR__, 2) . '/_app/admin.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    api_run('GET', static function (PDO $pdo): array {
        $actor = admin_require($pdo, api_bearer_token(), 'api_clock');
        return admin_read($pdo, (string)$actor['user_id'], (string)($_GET['view'] ?? ''), $_GET, 'api_clock');
    });
}
api_run('POST', static function (PDO $pdo): array {
    $actor = admin_require($pdo, api_bearer_token(), 'api_clock');
    return admin_mutate($pdo, (string)$actor['user_id'], api_body(), 'api_clock');
});
