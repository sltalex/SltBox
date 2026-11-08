<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 2) . '/_app/bootstrap.php';
require dirname(__DIR__, 2) . '/_app/account_data.php';
require dirname(__DIR__, 2) . '/_app/shared_town.php';

api_run('POST', static function (PDO $pdo): array {
    $actor = account_authenticated_user($pdo, 'api_clock');
    shared_town_require_access($pdo, (string)$actor['user_id']);
    $now = api_clock();
    return ['ok' => true, 'presence' => shared_town_presence_upsert($pdo, (string)$actor['user_id'], api_body(4096, false, 4096), $now)];
});
