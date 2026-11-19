<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require_once dirname(__DIR__, 3) . '/_app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/_app/town_agent.php';
require_once dirname(__DIR__, 3) . '/_app/town_social_matters.php';

api_run('POST', static function (PDO $pdo): array {
    $worker = town_agent_require_service();
    $body = api_body(32768, false, 32768);
    $lease = (string)($_SERVER['HTTP_X_DESKBOX_AGENT_LEASE'] ?? ($body['lease_token'] ?? ''));
    town_agent_assert_lease($pdo, $worker, $lease);
    $operation = strtolower(trim((string)($body['operation'] ?? '')));
    $now = api_clock();
    switch ($operation) {
        case 'create': return town_social_create_matter($pdo, $body, $now, true);
        case 'respond': return town_social_submit_response($pdo, $body, $now, true);
        case 'assign': return town_social_assign_commitment($pdo, $body, $now, true);
        case 'commitment_update': return town_social_update_commitment($pdo, $body, $now, true);
        case 'close': return town_social_close_matter($pdo, $body, $now, true);
        case 'expire': return town_social_expire($pdo, $now, (int)($body['limit'] ?? 50));
        case 'list': return town_social_list($pdo, town_social_world_id($body['world_id'] ?? null), is_string($body['state'] ?? null) ? trim((string)$body['state']) : null, (int)($body['limit'] ?? 50), true);
        default: throw new ApiError('invalid_social_operation', '社会事务操作无效。', 400);
    }
});
