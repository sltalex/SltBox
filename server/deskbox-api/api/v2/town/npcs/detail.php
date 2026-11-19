<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/account_data.php';
require dirname(__DIR__, 3) . '/_app/town_residents.php';
api_run('GET', static function (PDO $pdo): array {
    $actor = account_authenticated_user($pdo, 'api_clock');
    $npc = is_string($_GET['npc_id'] ?? null) ? $_GET['npc_id'] : '';
    return ['ok' => true] + town_resident_detail($pdo, (string)$actor['user_id'], $npc);
});
