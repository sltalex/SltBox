<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/account_data.php';
require dirname(__DIR__, 3) . '/_app/town_agent.php';

api_run('GET', static function (PDO $pdo): array {
    $forAgent = isset($_SERVER['HTTP_X_DESKBOX_AGENT_TOKEN']);
    if ($forAgent) town_agent_require_service();
    else { $actor = account_authenticated_user($pdo, 'api_clock'); shared_town_require_access($pdo, (string)$actor['user_id']); }
    return ['ok' => true] + town_npc_snapshot($pdo, $forAgent);
});
