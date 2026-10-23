<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 2) . '/_app/bootstrap.php';
require dirname(__DIR__, 2) . '/_app/wildlife.php';
api_run('GET', static fn(PDO $pdo): array => ['items' => wildlife_catalog()]);
