<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 2) . '/_app/bootstrap.php';
require dirname(__DIR__, 2) . '/_app/admin.php';
require dirname(__DIR__, 2) . '/_app/aquarium.php';

api_run('POST', static function (PDO $pdo): array {
    $actor = admin_require($pdo, api_bearer_token(), 'api_clock');
    return aquarium_mutate($pdo, (string)$actor['user_id'], api_body(1_048_576, false, 1_048_576), 'api_clock');
});
