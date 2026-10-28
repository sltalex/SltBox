<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 2) . '/_app/bootstrap.php';
require dirname(__DIR__, 2) . '/_app/account_data.php';
require dirname(__DIR__, 2) . '/_app/dingtalk_documents.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    api_run('GET', static function (PDO $pdo): array {
        $user = account_authenticated_user($pdo, 'api_clock');
        return dingtalk_documents_read($pdo, (string)$user['user_id']);
    });
}

api_run('PUT', static function (PDO $pdo): array {
    $user = account_authenticated_user($pdo, 'api_clock');
    $body = api_body(8 * 1024 * 1024, true, 32 * 1024 * 1024);
    return dingtalk_documents_apply($pdo, (string)$user['user_id'], $body, api_clock());
});
