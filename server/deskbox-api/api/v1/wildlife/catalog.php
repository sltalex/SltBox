<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 2) . '/_app/bootstrap.php';
require dirname(__DIR__, 2) . '/_app/wildlife.php';
api_run('GET', static function(PDO $pdo): array {
    $version = wildlife_catalog_version($_GET['catalog_version'] ?? null);
    return ['catalog_version' => $version, 'items' => wildlife_catalog($pdo, $version)];
});
