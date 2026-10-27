<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 2) . '/_app/bootstrap.php';
require dirname(__DIR__, 2) . '/_app/account_data.php';
require dirname(__DIR__, 2) . '/_app/tree_visit.php';

api_run('POST', static function (PDO $pdo): array {
    $user = account_authenticated_user($pdo, 'api_clock');
    $body = api_body();
    if (!isset($body['liked']) || !is_bool($body['liked'])) {
        throw new ApiError('invalid_like', 'A like choice is required.', 400);
    }
    return tree_visit_set_like($pdo, api_string($body, 'account'),
        (string)$user['user_id'], $body['liked']);
});
