<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require_once dirname(__DIR__, 3) . '/_app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/_app/town_agent.php';
require_once dirname(__DIR__, 3) . '/_app/town_world_event_journal.php';

api_run('GET', static function (PDO $pdo): array {
    $worker = town_agent_require_service();
    $lease = (string)($_SERVER['HTTP_X_DESKBOX_AGENT_LEASE'] ?? '');
    town_agent_assert_lease($pdo, $worker, $lease);
    $after = filter_var($_GET['after_sequence'] ?? 0, FILTER_VALIDATE_INT);
    $limit = filter_var($_GET['limit'] ?? 200, FILTER_VALIDATE_INT);
    if ($after === false) throw new ApiError('invalid_journal_cursor', 'The journal cursor is invalid.', 400);
    if ($limit === false) $limit = 200;
    return ['ok' => true] + town_world_event_journal_since($pdo, SHARED_TOWN_WORLD_ID, (int)$after, (int)$limit);
});
