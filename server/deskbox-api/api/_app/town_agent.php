<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__ . '/town_npcs.php';

function town_agent_service_token(): string
{
    $configured = getenv('DESKBOX_NPC_WORKER_TOKEN');
    if (is_string($configured) && trim($configured) !== '') return trim($configured);
    $path = dirname(__DIR__) . '/_config.local.php';
    if (is_file($path)) {
        $config = require $path;
        if (is_array($config) && is_string($config['npc_worker_token'] ?? null)) return trim((string)$config['npc_worker_token']);
    }
    return '';
}

function town_agent_require_service(): string
{
    $expected = town_agent_service_token();
    $provided = (string)($_SERVER['HTTP_X_DESKBOX_AGENT_TOKEN'] ?? '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) throw new ApiError('agent_forbidden', 'NPC worker authentication is required.', 403);
    return substr((string)($_SERVER['HTTP_X_DESKBOX_WORKER_ID'] ?? 'worker'), 0, 128);
}

function town_agent_lease(PDO $pdo, string $workerId, bool $renew, ?string $token = null, ?DateTimeImmutable $now = null): array
{
    $now ??= api_clock(); $expires = $now->modify('+30 seconds'); $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME); $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare("SELECT holder_id,lease_token,expires_at FROM town_agent_leases WHERE lease_name='main'{$lock}"); $query->execute(); $row = $query->fetch(PDO::FETCH_ASSOC);
        $expired = !is_array($row) || auth_parse_sql_time((string)$row['expires_at']) <= $now;
        if ($renew) {
            if (!is_array($row) || (string)$row['holder_id'] !== $workerId || $token === null || !hash_equals((string)$row['lease_token'], $token)) throw new ApiError('lease_not_owned', 'The worker does not own the town lease.', 409);
            $nextToken = (string)$row['lease_token'];
        } else {
            if (!$expired && (string)$row['holder_id'] !== $workerId) throw new ApiError('lease_busy', 'Another NPC worker owns the town lease.', 409);
            // Re-acquire is also used as a recovery path by concurrent worker
            // requests. Keep the token stable while this worker still owns a
            // live lease; rotating it here would invalidate requests that were
            // created just before another request noticed a transient 409.
            $nextToken = !$expired && is_array($row) && (string)$row['holder_id'] === $workerId
                ? (string)$row['lease_token']
                : bin2hex(random_bytes(32));
        }
        if (is_array($row)) {
            $update = $pdo->prepare("UPDATE town_agent_leases SET holder_id=:holder,lease_token=:token,expires_at=:expires,updated_at=:updated WHERE lease_name='main'");
            $update->execute([':holder' => $workerId, ':token' => $nextToken, ':expires' => auth_sql_time($expires), ':updated' => auth_sql_time($now)]);
        } else {
            $insert = $pdo->prepare("INSERT INTO town_agent_leases (lease_name,holder_id,lease_token,expires_at,updated_at) VALUES ('main',:holder,:token,:expires,:updated)");
            $insert->execute([':holder' => $workerId, ':token' => $nextToken, ':expires' => auth_sql_time($expires), ':updated' => auth_sql_time($now)]);
        }
        $pdo->commit(); return ['lease_name' => 'main', 'holder_id' => $workerId, 'lease_token' => $nextToken, 'expires_at' => $expires->format(DateTimeInterface::ATOM), 'ttl_seconds' => 30];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function town_agent_assert_lease(PDO $pdo, string $workerId, string $token): void
{
    $query = $pdo->prepare("SELECT holder_id,lease_token,expires_at FROM town_agent_leases WHERE lease_name='main' LIMIT 1"); $query->execute(); $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || (string)$row['holder_id'] !== $workerId || !hash_equals((string)$row['lease_token'], $token) || auth_parse_sql_time((string)$row['expires_at']) <= api_clock()) throw new ApiError('lease_required', 'The NPC worker lease is missing or expired.', 409);
}

function town_agent_record_run(PDO $pdo, array $body, DateTimeImmutable $now): array
{
    $npcId = is_string($body['npc_id'] ?? null) ? trim((string)$body['npc_id']) : '';
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $npcId)) throw new ApiError('invalid_agent_run', 'NPC identifier is invalid.', 400);
    $runId = is_string($body['run_id'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/D', (string)$body['run_id']) ? (string)$body['run_id'] : town_npc_id();
    $status = is_string($body['status'] ?? null) ? strtolower(trim((string)$body['status'])) : 'completed';
    if (!in_array($status, ['started', 'completed', 'fallback', 'failed', 'skipped'], true)) $status = 'failed';
    $prompt = max(0, min(4294967295, (int)($body['prompt_tokens'] ?? 0)));
    $completion = max(0, min(4294967295, (int)($body['completion_tokens'] ?? 0)));
    $total = max(0, min(4294967295, (int)($body['total_tokens'] ?? ($prompt + $completion))));
    $output = town_npc_array($body['output'] ?? []);
    $action = substr((string)($body['action'] ?? ''), 0, 16);
    $reason = substr(trim((string)($body['reason'] ?? '')), 0, 500);
    $errorCode = substr(trim((string)($body['error_code'] ?? '')), 0, 64);
    $errorMessage = substr(trim((string)($body['error_message'] ?? '')), 0, 255);
    $reservationId = is_string($body['reservation_id'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/D', (string)$body['reservation_id']) ? (string)$body['reservation_id'] : '';
    $insert = $pdo->prepare('INSERT INTO town_agent_runs (run_id,npc_id,model_provider_id,model_name,status,prompt_tokens,completion_tokens,total_tokens,latency_ms,action,reason,output_json,error_code,error_message,created_at,completed_at) VALUES (:run,:npc,:provider,:model,:status,:prompt,:completion,:total,:latency,:action,:reason,:output,:error_code,:error_message,:created,:completed)');
    $insert->execute([
        ':run' => $runId, ':npc' => $npcId, ':provider' => is_string($body['provider_id'] ?? null) ? trim((string)$body['provider_id']) : null,
        ':model' => substr((string)($body['model_name'] ?? ''), 0, 128), ':status' => $status, ':prompt' => $prompt,
        ':completion' => $completion, ':total' => $total, ':latency' => max(0, min(4294967295, (int)($body['latency_ms'] ?? 0))),
        ':action' => $action, ':reason' => $reason, ':output' => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ':error_code' => $errorCode, ':error_message' => $errorMessage, ':created' => auth_sql_time($now),
        ':completed' => $status === 'completed' || $status === 'fallback' || $status === 'failed' ? auth_sql_time($now) : null,
    ]);
    town_npc_activity_log($pdo, $npcId, 'agent_run', $status . ($action !== '' ? ' · ' . $action : ''), [
        'run_id' => $runId, 'status' => $status, 'model_name' => (string)($body['model_name'] ?? ''),
        'prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'total_tokens' => $total,
        'latency_ms' => (int)($body['latency_ms'] ?? 0), 'action' => $action, 'reason' => $reason,
        'error_code' => $errorCode, 'error_message' => $errorMessage, 'reservation_id' => $reservationId,
    ], $now, $runId);
    if ((string)($body['provider_id'] ?? '') !== '' && $runId !== '') {
        require_once __DIR__ . '/town_model_provider.php';
        town_model_provider_record_run_cost($pdo, $runId, (string)$body['provider_id'], $prompt, $completion, $now);
    }
    if ($reservationId !== '' && is_string($body['_worker_id'] ?? null) && trim((string)$body['_worker_id']) !== '') {
        try {
            $record = $pdo->prepare("UPDATE town_agent_token_reservations SET status='recorded',actual_tokens=:actual,settled_at=COALESCE(settled_at,:settled) WHERE reservation_id=:reservation AND worker_id=:worker AND status IN ('settled','released')");
            $record->execute([':actual' => $total, ':settled' => auth_sql_time($now), ':reservation' => $reservationId, ':worker' => substr(trim((string)$body['_worker_id']), 0, 128)]);
        } catch (PDOException) { /* Old installations without migration 035 remain compatible. */ }
    }
    return ['ok' => true, 'run_id' => $runId];
}

function town_agent_update_run(PDO $pdo, array $body, DateTimeImmutable $now): array
{
    $runId = is_string($body['run_id'] ?? null) ? trim((string)$body['run_id']) : '';
    $npcId = is_string($body['npc_id'] ?? null) ? trim((string)$body['npc_id']) : '';
    $status = is_string($body['status'] ?? null) ? strtolower(trim((string)$body['status'])) : 'failed';
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $runId) || !preg_match('/\A[a-f0-9]{32}\z/D', $npcId) || !in_array($status, ['completed', 'fallback', 'failed', 'skipped'], true)) throw new ApiError('invalid_agent_run', 'Agent run update is invalid.', 400);
    $errorCode = substr(trim((string)($body['error_code'] ?? '')), 0, 64); $errorMessage = substr(trim((string)($body['error_message'] ?? '')), 0, 255);
    $update = $pdo->prepare('UPDATE town_agent_runs SET status=:status,error_code=:error_code,error_message=:error_message,completed_at=:completed WHERE run_id=:run AND npc_id=:npc');
    $update->execute([':status' => $status, ':error_code' => $errorCode, ':error_message' => $errorMessage, ':completed' => auth_sql_time($now), ':run' => $runId, ':npc' => $npcId]);
    if ($update->rowCount() === 0) throw new ApiError('agent_run_not_found', 'Agent run was not found.', 404);
    town_npc_activity_log($pdo, $npcId, 'agent_run', $status, ['run_id' => $runId, 'status' => $status, 'error_code' => $errorCode, 'error_message' => $errorMessage], $now, $runId);
    return ['ok' => true, 'run_id' => $runId, 'status' => $status];
}

function town_agent_admin_report(PDO $pdo, int $days = 7, ?string $npcId = null, int $limit = 300): array
{
    $days = max(1, min(90, $days)); $limit = max(1, min(1000, $limit));
    $empty = ['days' => $days, 'summary' => ['runs' => 0, 'total_tokens' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0, 'completed' => 0, 'fallback' => 0, 'failed' => 0, 'avg_latency_ms' => 0, 'cost_amount' => 0.0, 'cost_currency' => 'USD'], 'quota' => ['month_start' => '', 'quota_tokens' => 0, 'used_tokens' => 0, 'remaining_tokens' => 0, 'unlimited' => true], 'by_npc' => [], 'logs' => [], 'provider_revisions' => [], 'cost_rows' => []];
    try {
        $since = auth_sql_time(api_clock()->modify('-' . $days . ' days'));
        $filter = ' WHERE created_at>=:since'; $params = [':since' => $since];
        if ($npcId !== null && preg_match('/\A[a-f0-9]{32}\z/D', $npcId)) { $filter .= ' AND npc_id=:npc'; $params[':npc'] = $npcId; }
        $query = $pdo->prepare('SELECT COUNT(*) AS runs,COALESCE(SUM(total_tokens),0) AS total_tokens,COALESCE(SUM(prompt_tokens),0) AS prompt_tokens,COALESCE(SUM(completion_tokens),0) AS completion_tokens,SUM(status=\'completed\') AS completed,SUM(status=\'fallback\') AS fallback,SUM(status=\'failed\') AS failed,COALESCE(AVG(NULLIF(latency_ms,0)),0) AS avg_latency_ms FROM town_agent_runs' . $filter); $query->execute($params); $summary = $query->fetch(PDO::FETCH_ASSOC) ?: $empty['summary'];
        foreach (['runs', 'total_tokens', 'prompt_tokens', 'completion_tokens', 'completed', 'fallback', 'failed'] as $key) $summary[$key] = (int)$summary[$key]; $summary['avg_latency_ms'] = (int)round((float)$summary['avg_latency_ms']);
        $summary['cost_amount'] = 0.0; $summary['cost_currency'] = 'USD';
        try {
            $costFilter = ' WHERE r.created_at>=:since'; $costParams = [':since' => $since];
            if ($npcId !== null && preg_match('/\A[a-f0-9]{32}\z/D', $npcId)) { $costFilter .= ' AND r.npc_id=:npc'; $costParams[':npc'] = $npcId; }
            $costQuery = $pdo->prepare('SELECT COALESCE(SUM(c.amount),0) AS amount,MAX(c.currency) AS currency FROM town_agent_run_costs c INNER JOIN town_agent_runs r ON r.run_id=c.run_id' . $costFilter);
            $costQuery->execute($costParams); $cost = $costQuery->fetch(PDO::FETCH_ASSOC) ?: [];
            $summary['cost_amount'] = (float)($cost['amount'] ?? 0); $summary['cost_currency'] = strtoupper((string)($cost['currency'] ?? 'USD')) ?: 'USD';
        } catch (PDOException) { /* Migration 036 is optional during rolling deployment. */ }
        $query = $pdo->prepare('SELECT n.npc_id,n.name,COUNT(r.run_id) AS runs,COALESCE(SUM(r.total_tokens),0) AS total_tokens,COALESCE(SUM(r.prompt_tokens),0) AS prompt_tokens,COALESCE(SUM(r.completion_tokens),0) AS completion_tokens,MAX(r.created_at) AS last_run FROM town_npcs n LEFT JOIN town_agent_runs r ON r.npc_id=n.npc_id AND r.created_at>=:since' . ($npcId !== null && preg_match('/\A[a-f0-9]{32}\z/D', $npcId) ? ' AND r.npc_id=:npc' : '') . ' GROUP BY n.npc_id,n.name ORDER BY total_tokens DESC,n.name'); $query->execute($params); $byNpc = array_map(static function (array $row): array { foreach (['runs', 'total_tokens', 'prompt_tokens', 'completion_tokens'] as $key) $row[$key] = (int)$row[$key]; return $row; }, $query->fetchAll(PDO::FETCH_ASSOC));
        $logFilter = ' WHERE l.created_at>=:since'; $logParams = [':since' => $since]; if ($npcId !== null && preg_match('/\A[a-f0-9]{32}\z/D', $npcId)) { $logFilter .= ' AND l.npc_id=:npc'; $logParams[':npc'] = $npcId; }
        $query = $pdo->prepare('SELECT l.log_id,l.npc_id,n.name,l.run_id,l.log_type,l.summary,l.detail_json,l.created_at FROM town_npc_activity_logs l LEFT JOIN town_npcs n ON n.npc_id=l.npc_id' . $logFilter . ' ORDER BY l.log_id DESC LIMIT ' . $limit); $query->execute($logParams); $logs = []; foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) { $row['log_id'] = (int)$row['log_id']; $row['detail'] = town_npc_decode($row['detail_json'], []); unset($row['detail_json']); $logs[] = $row; }
        $providerRevisions = [];
        $costRows = [];
        try {
            $revisionQuery = $pdo->prepare('SELECT r.revision_id,r.provider_id,p.name AS provider_name,r.action,r.snapshot_json,r.created_at FROM town_model_provider_revisions r LEFT JOIN town_model_providers p ON p.provider_id=r.provider_id WHERE r.created_at>=:since ORDER BY r.revision_id DESC LIMIT ' . min(300, $limit));
            $revisionQuery->execute([':since' => $since]);
            foreach ($revisionQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $row['revision_id'] = (int)$row['revision_id'];
                $row['snapshot'] = town_npc_decode($row['snapshot_json'] ?? [], []);
                unset($row['snapshot_json']);
                $providerRevisions[] = $row;
            }
            $costQuery = $pdo->prepare('SELECT c.run_id,c.provider_id,c.currency,c.prompt_tokens,c.completion_tokens,c.input_per_million,c.output_per_million,c.amount,c.created_at,r.npc_id,n.name AS npc_name FROM town_agent_run_costs c LEFT JOIN town_agent_runs r ON r.run_id=c.run_id LEFT JOIN town_npcs n ON n.npc_id=r.npc_id WHERE c.created_at>=:since' . ($npcId !== null && preg_match('/\A[a-f0-9]{32}\z/D', $npcId) ? ' AND r.npc_id=:npc' : '') . ' ORDER BY c.created_at DESC,c.run_id DESC LIMIT ' . min(300, $limit));
            $costParams = [':since' => $since];
            if ($npcId !== null && preg_match('/\A[a-f0-9]{32}\z/D', $npcId)) $costParams[':npc'] = $npcId;
            $costQuery->execute($costParams);
            foreach ($costQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
                foreach (['prompt_tokens', 'completion_tokens'] as $key) $row[$key] = (int)$row[$key];
                foreach (['input_per_million', 'output_per_million', 'amount'] as $key) $row[$key] = (float)$row[$key];
                $costRows[] = $row;
            }
        } catch (PDOException) { /* Migration 036 is optional during rolling deployment. */ }
        $quota = function_exists('town_model_provider_budget') ? town_model_provider_budget($pdo) : $empty['quota'];
        return ['days' => $days, 'summary' => $summary, 'quota' => $quota, 'by_npc' => $byNpc, 'logs' => $logs, 'provider_revisions' => $providerRevisions, 'cost_rows' => $costRows];
    } catch (PDOException) { return $empty; }
}
