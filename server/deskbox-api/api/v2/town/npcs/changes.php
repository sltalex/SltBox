<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/account_data.php';
require dirname(__DIR__, 3) . '/_app/town_agent.php';

api_run('GET', static function (PDO $pdo): array {
    if (isset($_SERVER['HTTP_X_DESKBOX_AGENT_TOKEN'])) town_agent_require_service();
    else { $actor = account_authenticated_user($pdo, 'api_clock'); shared_town_require_access($pdo, (string)$actor['user_id']); }
    $cursor = filter_var($_GET['cursor'] ?? 0, FILTER_VALIDATE_INT);
    if ($cursor === false || $cursor < 0) throw new ApiError('invalid_cursor', 'NPC event cursor is invalid.', 400);
    return ['ok' => true] + town_npc_changes($pdo, (int)$cursor);
});
