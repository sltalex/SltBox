<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require_once dirname(__DIR__, 3) . '/_app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/_app/account_data.php';
require_once dirname(__DIR__, 3) . '/_app/shared_town.php';
require_once dirname(__DIR__, 3) . '/_app/town_world_snapshots.php';

api_run('GET', static function (PDO $pdo): array {
    $actor = account_authenticated_user($pdo, 'api_world_snapshot');
    shared_town_require_access($pdo, (string)$actor['user_id']);
    return ['ok' => true] + town_world_snapshot_latest($pdo, true);
});
