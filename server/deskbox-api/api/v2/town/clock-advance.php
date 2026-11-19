<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require_once dirname(__DIR__, 2) . '/_app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/_app/town_agent.php';
require_once dirname(__DIR__, 2) . '/_app/town_world_clock.php';

api_run('POST', static function (PDO $pdo): array {
    $worker = town_agent_require_service();
    $body = api_body(4096);
    $lease = (string)($_SERVER['HTTP_X_DESKBOX_AGENT_LEASE'] ?? ($body['lease_token'] ?? ''));
    town_agent_assert_lease($pdo, $worker, $lease);
    $revision = filter_var($body['expected_revision'] ?? null, FILTER_VALIDATE_INT);
    $minutes = filter_var($body['minutes'] ?? 15, FILTER_VALIDATE_INT);
    if ($revision === false || $minutes === false) throw new ApiError('invalid_clock_advance', 'The clock advance is invalid.', 400);
    return ['ok' => true, 'clock' => town_world_clock_advance($pdo, (int)$revision, (int)$minutes)];
});
