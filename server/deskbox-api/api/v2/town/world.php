<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 2) . '/_app/bootstrap.php';
require dirname(__DIR__, 2) . '/_app/account_data.php';
require dirname(__DIR__, 2) . '/_app/shared_town.php';
require dirname(__DIR__, 2) . '/_app/town_npcs.php';

api_run('GET', static function (PDO $pdo): array {
    $actor = account_authenticated_user($pdo, 'api_clock');
    shared_town_require_access($pdo, (string)$actor['user_id']);
    $world = shared_town_world($pdo);
    return ['ok' => true, 'enabled' => true, 'graylisted' => true, 'authenticated' => true, 'can_save' => true,
        'account_id' => (string)$actor['user_id'], 'account' => (string)($actor['username'] ?? ''),
        'player' => shared_town_player_position($pdo, (string)$actor['user_id'], api_clock()),
        'inventory' => shared_town_inventory_payload($pdo, (string)$actor['user_id']),
        'layout' => ['template_version' => SHARED_TOWN_VERSION, 'catalog_version' => $world['catalog_version'],
            'world_id' => SHARED_TOWN_WORLD_ID, 'demo_stage' => 5, 'placements' => [], 'objects' => []], 'world' => $world,
        'farm' => ['plots' => town_farm_snapshot($pdo)],
        'economy' => town_economy_snapshot($pdo),
        'terrain' => ['types' => ['plain', 'road', 'river', 'riverbank', 'bridge', 'forest', 'plaza', 'main_tree'],
            'fixed' => true]];
});
