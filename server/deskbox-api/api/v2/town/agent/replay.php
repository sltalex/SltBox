<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/town_agent.php';
require dirname(__DIR__, 3) . '/_app/town_world_replay.php';

api_run('POST', static function (PDO $pdo): array {
    $worker = town_agent_require_service();
    $lease = (string)($_SERVER['HTTP_X_DESKBOX_AGENT_LEASE'] ?? '');
    town_agent_assert_lease($pdo, $worker, $lease);
    $body = api_body(8192, false, 8192);
    $snapshotId = is_string($body['snapshot_id'] ?? null) ? trim((string)$body['snapshot_id']) : null;
    if ($snapshotId !== null && $snapshotId !== '' && !preg_match('/\A[a-f0-9]{32}\z/D', $snapshotId)) throw new ApiError('invalid_snapshot', 'The snapshot identifier is invalid.', 400);
    $limit = filter_var($body['limit'] ?? 500, FILTER_VALIDATE_INT);
    if ($limit === false) $limit = 500;
    return ['ok' => true, 'report' => town_world_replay_dry_run($pdo, $snapshotId ?: null, (int)$limit)];
});
