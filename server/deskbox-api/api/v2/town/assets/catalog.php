<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/account_data.php';
require dirname(__DIR__, 3) . '/_app/shared_town.php';

api_run('GET', static function (PDO $pdo): array {
    $actor = account_authenticated_user($pdo, 'api_clock');
    shared_town_require_access($pdo, (string)$actor['user_id']);
    $config = town_asset_active_config($pdo);
    return ['ok' => true, 'release_id' => $config['release_id'] ?? null,
        'release_version' => (int)($config['release_version'] ?? 0),
        'config_sha256' => $config['config_sha256'] ?? null,
        'sheets' => $config['sheets'] ?? [], 'items' => $config['items'] ?? []];
});
