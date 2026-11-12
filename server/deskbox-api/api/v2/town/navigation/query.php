<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/account_data.php';
require dirname(__DIR__, 3) . '/_app/town_navigation.php';

api_run('GET', static function (PDO $pdo): array {
    $actor = account_authenticated_user($pdo, 'api_clock');
    shared_town_require_access($pdo, (string)$actor['user_id']);
    $number = static function(string $key): int { $value = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT); if ($value === false) throw new ApiError('invalid_position', 'Navigation coordinates are invalid.', 400); return (int)$value; };
    $caps = town_navigation_capabilities($_GET['capabilities'] ?? []);
    $result = ['ok' => true, 'navigation_version' => town_navigation_version($pdo)];
    if (isset($_GET['x']) || isset($_GET['y'])) $result['cell'] = town_navigation_can_traverse($pdo, $number('x'), $number('y'), $caps);
    if (isset($_GET['from_x'], $_GET['from_y'], $_GET['to_x'], $_GET['to_y'])) $result['route'] = town_navigation_find_route($pdo, $number('from_x'), $number('from_y'), $number('to_x'), $number('to_y'), $caps);
    if (!isset($result['cell'], $result['route'])) throw new ApiError('invalid_query', 'A cell or route query is required.', 400);
    return $result;
});
