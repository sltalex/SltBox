<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/town_agent.php';
require dirname(__DIR__, 3) . '/_app/town_story_events.php';

api_run('POST', static function (PDO $pdo): array {
    $worker = town_agent_require_service();
    $lease = (string)($_SERVER['HTTP_X_DESKBOX_AGENT_LEASE'] ?? '');
    town_agent_assert_lease($pdo, $worker, $lease);
    $body = api_body(8192, false, 8192); $eventId = is_string($body['event_id'] ?? null) ? trim((string)$body['event_id']) : ''; $outcome = is_string($body['outcome'] ?? null) ? trim((string)$body['outcome']) : 'success';
    return town_story_event_resolve($pdo, $eventId, api_clock(), $outcome);
});
