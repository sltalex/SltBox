<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/account_data.php';
require dirname(__DIR__, 3) . '/_app/shared_town.php';

api_run('GET', static function (PDO $pdo): array {
    $actor = account_authenticated_user($pdo, 'api_clock');
    shared_town_require_access($pdo, (string)$actor['user_id']);
    $world = shared_town_world($pdo);
    return ['ok' => true, 'enabled' => true, 'graylisted' => true, 'authenticated' => true, 'can_save' => true,
        'account_id' => (string)$actor['user_id'], 'account' => (string)($actor['username'] ?? ''),
        'layout' => ['template_version' => SHARED_TOWN_VERSION, 'catalog_version' => $world['catalog_version'],
            'world_id' => SHARED_TOWN_WORLD_ID, 'demo_stage' => 5, 'placements' => [], 'objects' => []], 'world' => $world,
        'terrain' => ['types' => ['plain', 'road', 'river', 'riverbank', 'bridge', 'forest', 'plaza', 'main_tree'],
            'fixed' => true], 'catalog' => shared_town_catalog()['items']];
});
