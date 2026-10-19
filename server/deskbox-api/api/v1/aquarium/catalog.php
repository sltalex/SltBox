<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 2) . '/_app/bootstrap.php';
require dirname(__DIR__, 2) . '/_app/admin.php';
require_once dirname(__DIR__, 2) . '/_app/aquarium.php';

api_run('GET', static function (PDO $pdo): array {
    admin_require($pdo, api_bearer_token(), 'api_clock');
    return aquarium_catalog();
});
