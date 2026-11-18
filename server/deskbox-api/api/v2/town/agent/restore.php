<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require_once dirname(__DIR__, 3) . '/_app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/_app/town_agent.php';
require_once dirname(__DIR__, 3) . '/_app/town_world_replay.php';

api_run('POST', static function (PDO $pdo): array {
    $worker = town_agent_require_service();
    $lease = (string)($_SERVER['HTTP_X_DESKBOX_AGENT_LEASE'] ?? '');
    town_agent_assert_lease($pdo, $worker, $lease);
    $body = api_body(8192, false, 8192);
    if (($body['apply'] ?? false) !== true) throw new ApiError('restore_confirmation_required', 'Set apply=true to execute a controlled restore.', 400);
    $snapshotId = is_string($body['snapshot_id'] ?? null) ? trim((string)$body['snapshot_id']) : '';
    $checksum = is_string($body['state_sha256'] ?? null) ? trim((string)$body['state_sha256']) : '';
    $version = filter_var($body['expected_world_version'] ?? null, FILTER_VALIDATE_INT);
    if ($version === false) throw new ApiError('invalid_world_revision', 'expected_world_version is required.', 400);
    return ['ok' => true, 'report' => town_world_snapshot_restore($pdo, $snapshotId, $checksum, (int)$version, $worker)];
});
