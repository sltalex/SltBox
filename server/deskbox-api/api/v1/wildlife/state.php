<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 2) . '/_app/bootstrap.php';
require dirname(__DIR__, 2) . '/_app/account_data.php';
require dirname(__DIR__, 2) . '/_app/wildlife.php';
api_run('GET', static function(PDO $pdo): array {
    $user = account_authenticated_user($pdo, 'api_clock');
    $version = wildlife_catalog_version($_GET['catalog_version'] ?? null);
    return wildlife_state_read($pdo, (string)$user['user_id'], api_clock(), false, $version);
});
