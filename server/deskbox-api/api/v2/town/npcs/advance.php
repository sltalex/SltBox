<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/town_agent.php';

api_run('POST', static function (PDO $pdo): array {
    $worker = town_agent_require_service();
    $token = (string)($_SERVER['HTTP_X_DESKBOX_AGENT_LEASE'] ?? '');
    town_agent_assert_lease($pdo, $worker, $token);
    $body = api_body(8192, false, 8192);
    $npcId = is_string($body['npc_id'] ?? null) ? trim((string)$body['npc_id']) : '';
    return town_npc_advance_task($pdo, $npcId, api_clock(), true);
});
