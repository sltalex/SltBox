<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/admin.php';
require dirname(__DIR__, 3) . '/_app/town_npcs.php';

api_run('POST', static function (PDO $pdo): array {
    $admin = admin_require($pdo, api_bearer_token(), 'api_clock');
    $body = api_body(8192, false, 8192);
    $npcId = is_string($body['npc_id'] ?? null) ? strtolower(trim((string)$body['npc_id'])) : '';
    $state = is_string($body['state'] ?? null) ? trim((string)$body['state']) : '';
    $reason = is_string($body['reason_code'] ?? null) ? trim((string)$body['reason_code']) : '';
    $returnAt = array_key_exists('expected_return_at', $body) ? ($body['expected_return_at'] === null ? null : (string)$body['expected_return_at']) : null;
    $lifecycle = town_npc_transition_lifecycle($pdo, $npcId, $state, $reason, $returnAt, api_clock(), (string)$admin['user_id']);
    return ['ok' => true, 'npc_id' => $npcId, 'lifecycle' => $lifecycle];
});
