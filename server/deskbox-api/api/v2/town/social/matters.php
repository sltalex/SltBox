<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require_once dirname(__DIR__, 3) . '/_app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/_app/account_data.php';
require_once dirname(__DIR__, 3) . '/_app/shared_town.php';
require_once dirname(__DIR__, 3) . '/_app/town_social_matters.php';

api_run('GET', static function (PDO $pdo): array {
    $actor = account_authenticated_user($pdo, 'api_clock');
    shared_town_require_access($pdo, (string)$actor['user_id']);
    $state = is_string($_GET['state'] ?? null) ? trim((string)$_GET['state']) : null;
    $limit = filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT);
    if ($limit === false) throw new ApiError('invalid_social_query', '社会事务查询参数无效。', 400);
    return ['ok' => true] + town_social_list($pdo, defined('SHARED_TOWN_WORLD_ID') ? SHARED_TOWN_WORLD_ID : 'main', $state, (int)$limit, false);
});
