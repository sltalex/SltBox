<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require_once dirname(__DIR__, 3) . '/_app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/_app/account_data.php';
require_once dirname(__DIR__, 3) . '/_app/shared_town.php';
require_once dirname(__DIR__, 3) . '/_app/town_world_events.php';

api_run('POST', static function (PDO $pdo): array {
    $actor = account_authenticated_user($pdo, 'api_clock');
    shared_town_require_access($pdo, (string)$actor['user_id']);
    $body = api_body(8192, false, 8192);
    $ids = is_array($body['event_ids'] ?? null) ? $body['event_ids'] : [];
    $state = is_string($body['state'] ?? null) ? trim((string)$body['state']) : '';
    return ['ok' => true, 'updated' => town_world_event_acknowledge($pdo, (string)$actor['user_id'], $ids, $state, api_clock())];
});
