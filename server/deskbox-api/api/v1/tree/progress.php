<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 2) . '/_app/bootstrap.php';

// Retired in favour of the optimistic-concurrency full-state endpoint. Keeping
// this route writable would allow old clients to bypass state_version checks.
api_run('POST', static function (PDO $pdo): array {
    $user = account_authenticated_user($pdo, 'api_clock');
    return tree_apply_progress($pdo, (string)$user['user_id'], api_body(), 'api_clock');
});
