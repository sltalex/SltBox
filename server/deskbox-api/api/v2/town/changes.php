<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 2) . '/_app/bootstrap.php';
require dirname(__DIR__, 2) . '/_app/account_data.php';
require dirname(__DIR__, 2) . '/_app/shared_town.php';

api_run('GET', static function (PDO $pdo): array {
    $actor = account_authenticated_user($pdo, 'api_clock');
    shared_town_require_access($pdo, (string)$actor['user_id']);
    $cursor = filter_var($_GET['cursor'] ?? 0, FILTER_VALIDATE_INT);
    if ($cursor === false || $cursor < 0) { throw new ApiError('invalid_cursor', 'The change cursor is invalid.', 400); }
    $chunks = shared_town_parse_chunks($_GET['chunks'] ?? null);
    return ['ok' => true] + shared_town_changes($pdo, (int)$cursor, $chunks);
});
