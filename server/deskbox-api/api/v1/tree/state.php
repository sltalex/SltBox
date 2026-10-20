<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 2) . '/_app/bootstrap.php';
require dirname(__DIR__, 2) . '/_app/account_data.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    api_run('GET', static function (PDO $pdo): array {
        $user = account_authenticated_user($pdo, 'api_clock');
        return ['state' => tree_read_state($pdo, (string)$user['user_id'], api_clock()->format('Y-m-d'))];
    });
}

api_json(['code' => 'endpoint_retired', 'message' => 'Use tree/progress.php.'], 410);
