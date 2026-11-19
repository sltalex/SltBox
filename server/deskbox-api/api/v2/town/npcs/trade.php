<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 4) . '/_app/bootstrap.php';
require dirname(__DIR__, 4) . '/_app/town_agent.php';
require dirname(__DIR__, 4) . '/_app/town_npcs.php';

api_run('POST', static function (PDO $pdo): array {
    $worker = town_agent_require_service();
    $lease = (string)($_SERVER['HTTP_X_DESKBOX_AGENT_LEASE'] ?? '');
    town_agent_assert_lease($pdo, $worker, $lease);
    $body = api_body(16384, false, 16384);
    $seller = is_string($body['seller_npc_id'] ?? null) ? trim((string)$body['seller_npc_id']) : '';
    $buyer = is_string($body['buyer_npc_id'] ?? null) ? trim((string)$body['buyer_npc_id']) : '';
    $item = is_string($body['item_id'] ?? null) ? trim((string)$body['item_id']) : '';
    $result = town_npc_trade($pdo, $seller, $buyer, $item, (int)($body['quantity'] ?? 0), (int)($body['amount'] ?? 0), (string)($body['reason'] ?? ''), api_clock());
    return ['ok' => true, 'trade' => $result];
});
