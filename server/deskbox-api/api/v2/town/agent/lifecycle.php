<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require_once dirname(__DIR__, 3) . '/_app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/_app/town_agent.php';

api_run('POST', static function (PDO $pdo): array {
    $worker = town_agent_require_service();
    $token = (string)($_SERVER['HTTP_X_DESKBOX_AGENT_LEASE'] ?? '');
    town_agent_assert_lease($pdo, $worker, $token);
    $body = api_body(8192, false, 8192);
    if (($body['operation'] ?? 'recover_due') !== 'recover_due') throw new ApiError('invalid_lifecycle_operation', '生命周期操作无效。', 400);
    return ['ok' => true, 'changed' => town_npc_recover_due($pdo, api_clock())];
});
