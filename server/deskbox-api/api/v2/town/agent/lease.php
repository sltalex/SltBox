<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/town_agent.php';

api_run('POST', static function (PDO $pdo): array {
    $worker = town_agent_require_service(); $body = api_body(4096);
    $renew = (string)($_GET['action'] ?? ($body['action'] ?? 'acquire')) === 'renew';
    return ['ok' => true, 'lease' => town_agent_lease($pdo, $worker, $renew, is_string($body['lease_token'] ?? null) ? $body['lease_token'] : null)];
});
