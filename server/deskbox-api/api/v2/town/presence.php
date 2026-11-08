<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/account_data.php';
require dirname(__DIR__, 3) . '/_app/shared_town.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    api_run('POST', static function (PDO $pdo): array {
        $actor = account_authenticated_user($pdo, 'api_clock');
        shared_town_require_access($pdo, (string)$actor['user_id']);
        return ['ok' => true, 'presence' => shared_town_presence_upsert($pdo, (string)$actor['user_id'], api_body(4096, false, 4096), api_clock())];
    });
}
api_run('GET', static function (PDO $pdo): array {
    $actor = account_authenticated_user($pdo, 'api_clock');
    shared_town_require_access($pdo, (string)$actor['user_id']);
    $x = filter_var($_GET['chunk_x'] ?? -1, FILTER_VALIDATE_INT); $y = filter_var($_GET['chunk_y'] ?? -1, FILTER_VALIDATE_INT);
    return ['ok' => true] + shared_town_presence($pdo, $x === false ? -1 : (int)$x, $y === false ? -1 : (int)$y);
});
