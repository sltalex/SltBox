<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/town_agent.php';
require dirname(__DIR__, 3) . '/_app/town_story_events.php';

api_run('GET', static function (PDO $pdo): array {
    $worker = town_agent_require_service();
    $lease = (string)($_SERVER['HTTP_X_DESKBOX_AGENT_LEASE'] ?? '');
    town_agent_assert_lease($pdo, $worker, $lease);
    return ['ok' => true, 'events' => town_story_events_active($pdo, api_clock()), 'generation' => town_story_event_policy_state($pdo, api_clock())];
});
