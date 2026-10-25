<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/account_data.php';
require_once dirname(__DIR__, 3) . '/_app/aquarium.php';
require_once dirname(__DIR__, 3) . '/_app/tree_decorations.php';

api_run('GET', static function (PDO $pdo): array {
    account_authenticated_user($pdo, 'api_clock');
    return tree_decoration_catalog($pdo);
});
