<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/town_agent.php';
require dirname(__DIR__, 3) . '/_app/town_world_snapshots.php';

api_run('POST', static function (PDO $pdo): array {
    $worker = town_agent_require_service();
    $lease = (string)($_SERVER['HTTP_X_DESKBOX_AGENT_LEASE'] ?? '');
    town_agent_assert_lease($pdo, $worker, $lease);
    return ['ok' => true, 'snapshot' => town_world_snapshot_create($pdo, $worker)];
});
