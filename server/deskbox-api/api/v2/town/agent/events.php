<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/town_agent.php';

api_run('POST', static function (PDO $pdo): array {
    $worker = town_agent_require_service(); $token = (string)($_SERVER['HTTP_X_DESKBOX_AGENT_LEASE'] ?? ''); town_agent_assert_lease($pdo, $worker, $token);
    $body = api_body(32768, false, 32768); $npc = is_string($body['npc_id'] ?? null) ? trim((string)$body['npc_id']) : ''; $type = is_string($body['event_type'] ?? null) ? trim((string)$body['event_type']) : '';
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $npc) || !preg_match('/\A[a-z][a-z0-9_]{1,31}\z/D', $type)) throw new ApiError('invalid_event', 'NPC event is invalid.', 400);
    return ['ok' => true, 'event_id' => town_npc_emit_event($pdo, $npc, $type, is_array($body['payload'] ?? null) ? $body['payload'] : [], api_clock())];
});
