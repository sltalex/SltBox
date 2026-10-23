<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 2) . '/_app/bootstrap.php';
require dirname(__DIR__, 2) . '/_app/account_data.php';
require dirname(__DIR__, 2) . '/_app/wildlife.php';
api_run('POST', static function(PDO $pdo): array {
    $user = account_authenticated_user($pdo, 'api_clock');
    return wildlife_offer($pdo, (string)$user['user_id'], api_body(), 'api_clock');
});
