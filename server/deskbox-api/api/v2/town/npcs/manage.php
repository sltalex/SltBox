<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/admin.php';
require dirname(__DIR__, 3) . '/_app/town_npcs.php';

api_run($_SERVER['REQUEST_METHOD'] === 'GET' ? 'GET' : 'POST', static function (PDO $pdo): array {
    $admin = admin_require($pdo, api_bearer_token(), 'api_clock');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') return ['ok' => true, 'npcs' => town_npc_rows_safe($pdo, true)];
    $body = api_body(65536, false, 65536); $id = is_string($body['npc_id'] ?? null) && trim((string)$body['npc_id']) !== '' ? trim((string)$body['npc_id']) : null;
    if (($body['action'] ?? '') === 'delete') { if ($id === null) throw new ApiError('invalid_npc', 'NPC identifier is required.', 400); $pdo->prepare('DELETE FROM town_npcs WHERE npc_id=:id')->execute([':id' => $id]); return ['ok' => true, 'deleted' => $id]; }
    return ['ok' => true, 'npc' => town_npc_upsert($pdo, $body, api_clock(), $id)];
});
