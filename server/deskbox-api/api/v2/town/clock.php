<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require_once dirname(__DIR__, 2) . '/_app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/_app/account_data.php';
require_once dirname(__DIR__, 2) . '/_app/shared_town.php';
require_once dirname(__DIR__, 2) . '/_app/town_agent.php';
require_once dirname(__DIR__, 2) . '/_app/town_world_clock.php';

api_run('GET', static function (PDO $pdo): array {
    // The browser read is account-scoped, while the NPC Worker must read the
    // same revision before it can submit its lease-protected advance. Keep the
    // two authentication paths explicit: a supplied service token is never
    // treated as a user session, and an invalid token is rejected by the
    // worker guard instead of falling through to a misleading 401.
    if (trim((string)($_SERVER['HTTP_X_DESKBOX_AGENT_TOKEN'] ?? '')) !== '') {
        town_agent_require_service();
    } else {
        $actor = account_authenticated_user($pdo, 'api_clock');
        shared_town_require_access($pdo, (string)$actor['user_id']);
    }
    return ['ok' => true, 'clock' => town_world_clock_read($pdo)];
});

