<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/account_data.php';
require dirname(__DIR__, 3) . '/_app/town_residents.php';

api_run('GET', static function (PDO $pdo): array {
    $actor = account_authenticated_user($pdo, 'api_clock');
    $npcId = is_string($_GET['npc_id'] ?? null) ? trim((string)$_GET['npc_id']) : '';
    town_resident_require($pdo, (string)$actor['user_id'], $npcId);
    $cursor = filter_var($_GET['cursor'] ?? 0, FILTER_VALIDATE_INT);
    $limit = filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT);
    if ($cursor === false || $limit === false || $cursor < 0) throw new ApiError('invalid_cursor', '数值历史游标无效。', 400);
    return ['ok' => true, 'npc_id' => $npcId] + town_npc_stat_history($pdo, $npcId, (int)$limit, (int)$cursor);
});
