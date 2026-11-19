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
    $x = filter_var($_GET['tile_x'] ?? null, FILTER_VALIDATE_INT);
    $y = filter_var($_GET['tile_y'] ?? null, FILTER_VALIDATE_INT);
    $radius = filter_var($_GET['radius'] ?? 8, FILTER_VALIDATE_INT);
    $height = filter_var($_GET['height'] ?? 0, FILTER_VALIDATE_INT);
    if ($x === false || $y === false || $radius === false || $height === false || $x < 0 || $y < 0 || $height < 0 || $height > 32) throw new ApiError('invalid_perception_query', 'The perception area is invalid.', 400);
    $channels = is_string($_GET['channels'] ?? null) ? array_filter(explode(',', (string)$_GET['channels']), static fn(string $value): bool => trim($value) !== '') : [];
    return ['ok' => true, 'tile_x' => (int)$x, 'tile_y' => (int)$y, 'height' => (int)$height, 'radius' => max(1, min(64, (int)$radius)), 'navigation_version' => town_navigation_version($pdo), 'observations' => town_interest_points_nearby($pdo, (int)$x, (int)$y, (int)$radius, $channels, (int)$height)];
});
