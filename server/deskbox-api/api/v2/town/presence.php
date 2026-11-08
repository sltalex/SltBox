<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 2) . '/_app/bootstrap.php';
require dirname(__DIR__, 2) . '/_app/account_data.php';
require dirname(__DIR__, 2) . '/_app/shared_town.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    api_run('POST', static function (PDO $pdo): array {
        $actor = account_authenticated_user($pdo, 'api_clock');
        shared_town_require_access($pdo, (string)$actor['user_id']);
        $body = api_body(4096, false, 4096);
        $self = shared_town_presence_upsert($pdo, (string)$actor['user_id'], $body, api_clock());
        $visible = shared_town_presence($pdo, (int)$self['chunk_x'], (int)$self['chunk_y']);
        return ['ok' => true, 'presence' => $visible['presence'], 'self' => $self];
    });
}
api_run('GET', static function (PDO $pdo): array {
    $actor = account_authenticated_user($pdo, 'api_clock');
    shared_town_require_access($pdo, (string)$actor['user_id']);
    $x = filter_var($_GET['chunk_x'] ?? -1, FILTER_VALIDATE_INT); $y = filter_var($_GET['chunk_y'] ?? -1, FILTER_VALIDATE_INT);
    if ($x === false || $y === false || (($x === -1) !== ($y === -1))
        || ($x >= 0 && $y >= 0 && ($x >= 8 || $y >= 8)) || $x < -1 || $y < -1) {
        throw new ApiError('invalid_chunk', 'The presence chunk is invalid.', 400);
    }
    return ['ok' => true] + shared_town_presence($pdo, $x === false ? -1 : (int)$x, $y === false ? -1 : (int)$y);
});
