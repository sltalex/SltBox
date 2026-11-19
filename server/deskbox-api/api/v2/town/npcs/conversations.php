<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/account_data.php';
require dirname(__DIR__, 3) . '/_app/town_residents.php';
api_run('GET', static function (PDO $pdo): array {
    $actor = account_authenticated_user($pdo, 'api_clock');
    $npc = is_string($_GET['npc_id'] ?? null) ? $_GET['npc_id'] : '';
    $cursor = filter_var($_GET['cursor'] ?? 0, FILTER_VALIDATE_INT);
    $limit = filter_var($_GET['limit'] ?? 30, FILTER_VALIDATE_INT);
    if ($cursor === false || $cursor < 0) throw new ApiError('invalid_cursor', '对话游标无效。', 400);
    if ($limit === false || $limit < 1 || $limit > 100) throw new ApiError('invalid_limit', '对话页大小必须为 1～100。', 400);
    return ['ok' => true] + town_resident_conversations($pdo, (string)$actor['user_id'], $npc, $cursor, $limit);
});
