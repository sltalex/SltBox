<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/town_agent.php';

api_run('POST', static function (PDO $pdo): array {
    $worker = town_agent_require_service();
    $body = api_body(8192);
    $lease = (string)($_SERVER['HTTP_X_DESKBOX_AGENT_LEASE'] ?? ($body['lease_token'] ?? ''));
    town_agent_assert_lease($pdo, $worker, $lease);
    $npcId = is_string($body['npc_id'] ?? null) ? trim((string)$body['npc_id']) : '';
    return ['ok' => true, 'slot' => town_agent_reserve_decision_slot($pdo, $npcId, $worker, api_clock())];
});
