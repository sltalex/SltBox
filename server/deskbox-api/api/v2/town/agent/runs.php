<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/town_agent.php';

api_run('POST', static function (PDO $pdo): array {
    $worker = town_agent_require_service();
    $lease = (string)($_SERVER['HTTP_X_DESKBOX_AGENT_LEASE'] ?? '');
    town_agent_assert_lease($pdo, $worker, $lease);
    $body = api_body(65536, false, 65536);
    $body['_worker_id'] = $worker;
    if (($body['operation'] ?? null) === 'update') return town_agent_update_run($pdo, $body, api_clock());
    return town_agent_record_run($pdo, $body, api_clock());
});
