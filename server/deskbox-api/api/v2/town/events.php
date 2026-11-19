<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require_once dirname(__DIR__, 2) . '/_app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/_app/account_data.php';
require_once dirname(__DIR__, 2) . '/_app/shared_town.php';
require_once dirname(__DIR__, 2) . '/_app/town_world_events.php';

api_run('GET', static function (PDO $pdo): array {
    $actor = account_authenticated_user($pdo, 'api_clock');
    shared_town_require_access($pdo, (string)$actor['user_id']);

    $cursor = filter_var($_GET['cursor'] ?? 0, FILTER_VALIDATE_INT);
    $limit = filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT);
    if ($cursor === false || $limit === false || $cursor < 0) {
        throw new ApiError('invalid_event_query', 'The event query is invalid.', 400);
    }

    $types = is_string($_GET['types'] ?? null)
        ? array_values(array_filter(
            array_map('trim', explode(',', (string)$_GET['types'])),
            static fn(string $value): bool => $value !== ''
        ))
        : [];
    $actorNpc = town_world_event_id((string)($_GET['actor_npc_id'] ?? '')) ?: null;
    $targetNpc = town_world_event_id((string)($_GET['target_npc_id'] ?? '')) ?: null;

    return ['ok' => true] + town_world_events_query(
        $pdo,
        (string)$actor['user_id'],
        (int)$cursor,
        (int)$limit,
        $types,
        $actorNpc,
        $targetNpc
    );
});
