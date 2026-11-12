<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/town_model_provider.php';

api_run('POST', static function (PDO $pdo): array {
    $worker = town_agent_require_service();
    $body = api_body(8192);
    $lease = (string)($_SERVER['HTTP_X_DESKBOX_AGENT_LEASE'] ?? ($body['lease_token'] ?? ''));
    town_agent_assert_lease($pdo, $worker, $lease);
    $operation = strtolower(trim((string)($body['operation'] ?? 'reserve')));
    if ($operation === 'reserve') {
        return ['ok' => true, 'reservation' => town_model_provider_reserve_tokens(
            $pdo,
            (string)($body['provider_id'] ?? ''),
            max(1, (int)($body['estimated_tokens'] ?? 0)),
            $worker,
            api_clock(),
        )];
    }
    if ($operation === 'settle') {
        return ['ok' => true, 'reservation' => town_model_provider_settle_tokens(
            $pdo,
            (string)($body['reservation_id'] ?? ''),
            max(0, (int)($body['actual_tokens'] ?? 0)),
            $worker,
            api_clock(),
        )];
    }
    throw new ApiError('invalid_quota_operation', 'The quota operation is invalid.', 400);
});
