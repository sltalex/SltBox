<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 2) . '/_app/bootstrap.php';
require dirname(__DIR__, 2) . '/_app/account_data.php';
require dirname(__DIR__, 2) . '/_app/fruit.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    api_run('GET', static function (PDO $pdo): array {
        $user = account_authenticated_user($pdo, 'api_clock');
        return ['state' => tree_read_state($pdo, (string)$user['user_id'], api_clock()->format('Y-m-d'))];
    });
}

// This endpoint intentionally applies deltas. The operation id makes retries
// idempotent, while the transaction in tree_apply_progress serializes updates
// from multiple devices for the same account.
api_run('POST', static function (PDO $pdo): array {
    $user = account_authenticated_user($pdo, 'api_clock');
    return tree_apply_progress($pdo, (string)$user['user_id'], api_body(), 'api_clock');
});
