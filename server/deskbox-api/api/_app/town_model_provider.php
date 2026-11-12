<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__ . '/town_agent.php';

function town_model_crypto_key(): string
{
    $token = town_agent_service_token();
    if ($token === '') throw new RuntimeException('NPC worker token is not configured.');
    return hash('sha256', 'deskbox-town-model-provider|' . $token, true);
}

function town_model_encrypt(string $value): string
{
    if (!function_exists('sodium_crypto_secretbox')) throw new RuntimeException('The server sodium extension is required.');
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, town_model_crypto_key()));
}

function town_model_decrypt(string $value): string
{
    if (!str_starts_with($value, 'v1:')) throw new RuntimeException('The model provider secret format is invalid.');
    $packed = base64_decode(substr($value, 3), true);
    if (!is_string($packed) || strlen($packed) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new RuntimeException('The model provider secret is invalid.');
    $nonce = substr($packed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open(substr($packed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, town_model_crypto_key());
    if ($plain === false) throw new RuntimeException('The model provider secret cannot be decrypted.');
    return $plain;
}

function town_model_provider_row(PDO $pdo): ?array
{
    $query = $pdo->query('SELECT provider_id,name,base_url,model_name,api_key_ciphertext,temperature,timeout_seconds,monthly_quota,enabled,updated_at FROM town_model_providers ORDER BY updated_at DESC,provider_id LIMIT 1');
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** Read pricing separately so rolling deployments without migration 036 remain usable. */
function town_model_provider_pricing(PDO $pdo, ?string $providerId = null): array
{
    try {
        $sql = 'SELECT provider_id,currency,input_per_million,output_per_million,updated_at FROM town_model_provider_pricing';
        $params = [];
        if ($providerId !== null && preg_match('/\A[a-f0-9]{32}\z/D', $providerId)) {
            $sql .= ' WHERE provider_id=:provider'; $params[':provider'] = $providerId;
        } else $sql .= ' ORDER BY updated_at DESC,provider_id LIMIT 1';
        $query = $pdo->prepare($sql); $query->execute($params); $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return ['currency' => 'USD', 'input_per_million' => 0.0, 'output_per_million' => 0.0];
        return [
            'provider_id' => (string)$row['provider_id'],
            'currency' => strtoupper(substr((string)$row['currency'], 0, 3)) ?: 'USD',
            'input_per_million' => max(0.0, (float)$row['input_per_million']),
            'output_per_million' => max(0.0, (float)$row['output_per_million']),
            'updated_at' => (string)$row['updated_at'],
        ];
    } catch (PDOException) {
        return ['currency' => 'USD', 'input_per_million' => 0.0, 'output_per_million' => 0.0];
    }
}

/** Keep an audit snapshot without ever including the encrypted API key. */
function town_model_provider_record_revision(PDO $pdo, string $providerId, string $action, array $snapshot, DateTimeImmutable $now): void
{
    try {
        $query = $pdo->prepare('INSERT INTO town_model_provider_revisions (provider_id,action,snapshot_json,created_at) VALUES (:provider,:action,:snapshot,:created)');
        $query->execute([
            ':provider' => $providerId, ':action' => substr($action, 0, 24),
            ':snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':created' => auth_sql_time($now),
        ]);
    } catch (PDOException) { /* Migration 036 is optional during rolling deployment. */ }
}

/** Record cost using the price that was active when the run was completed. */
function town_model_provider_record_run_cost(PDO $pdo, string $runId, string $providerId, int $promptTokens, int $completionTokens, DateTimeImmutable $now): void
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $runId) || !preg_match('/\A[a-f0-9]{32}\z/D', $providerId)) return;
    $pricing = town_model_provider_pricing($pdo, $providerId);
    try {
        $amount = ($promptTokens / 1000000.0) * (float)$pricing['input_per_million']
            + ($completionTokens / 1000000.0) * (float)$pricing['output_per_million'];
        $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INSERT OR REPLACE INTO town_agent_run_costs (run_id,provider_id,currency,prompt_tokens,completion_tokens,input_per_million,output_per_million,amount,created_at) VALUES (:run,:provider,:currency,:prompt,:completion,:input,:output,:amount,:created)'
            : 'INSERT INTO town_agent_run_costs (run_id,provider_id,currency,prompt_tokens,completion_tokens,input_per_million,output_per_million,amount,created_at) VALUES (:run,:provider,:currency,:prompt,:completion,:input,:output,:amount,:created) ON DUPLICATE KEY UPDATE prompt_tokens=VALUES(prompt_tokens),completion_tokens=VALUES(completion_tokens),input_per_million=VALUES(input_per_million),output_per_million=VALUES(output_per_million),amount=VALUES(amount)';
        $query = $pdo->prepare($sql);
        $query->execute([
            ':run' => $runId, ':provider' => $providerId, ':currency' => $pricing['currency'],
            ':prompt' => max(0, $promptTokens), ':completion' => max(0, $completionTokens),
            ':input' => (float)$pricing['input_per_million'], ':output' => (float)$pricing['output_per_million'],
            ':amount' => max(0.0, $amount), ':created' => auth_sql_time($now),
        ]);
    } catch (PDOException) { /* Keep agent runs usable before migration 036. */ }
}

function town_model_provider_public(PDO $pdo): array
{
    $row = town_model_provider_row($pdo);
    if (!is_array($row)) return ['configured' => false, 'enabled' => false];
    $cipher = (string)$row['api_key_ciphertext'];
    $budget = town_model_provider_budget($pdo, (string)$row['provider_id']);
    $pricing = town_model_provider_pricing($pdo, (string)$row['provider_id']);
    return [
        'configured' => $cipher !== '', 'enabled' => (bool)$row['enabled'],
        'provider_id' => (string)$row['provider_id'], 'name' => (string)$row['name'],
        'base_url' => (string)$row['base_url'], 'model_name' => (string)$row['model_name'],
        'temperature' => (float)$row['temperature'], 'timeout_seconds' => (int)$row['timeout_seconds'],
        'monthly_quota' => (int)$row['monthly_quota'], 'updated_at' => (string)$row['updated_at'],
        'api_key_set' => $cipher !== '',
        'pricing' => $pricing,
        'quota' => $budget,
    ];
}

function town_model_provider_for_worker(PDO $pdo): ?array
{
    $row = town_model_provider_row($pdo);
    if (!is_array($row) || !(bool)$row['enabled'] || (string)$row['api_key_ciphertext'] === '') return null;
    $budget = town_model_provider_budget($pdo, (string)$row['provider_id']);
    return [
        'provider_id' => (string)$row['provider_id'],
        'name' => (string)$row['name'], 'base_url' => (string)$row['base_url'],
        'model_name' => (string)$row['model_name'], 'api_key' => town_model_decrypt((string)$row['api_key_ciphertext']),
        'temperature' => (float)$row['temperature'], 'timeout_seconds' => (int)$row['timeout_seconds'],
        'monthly_quota' => $budget['quota_tokens'], 'used_tokens' => $budget['used_tokens'],
        'reserved_tokens' => $budget['reserved_tokens'] ?? 0, 'remaining_tokens' => $budget['remaining_tokens'],
    ];
}

function town_model_provider_budget(PDO $pdo, ?string $providerId = null, ?DateTimeImmutable $now = null): array
{
    $now ??= api_clock();
    $empty = [
        'month_start' => $now->setTimezone(new DateTimeZone('UTC'))->modify('first day of this month')->setTime(0, 0)->format('Y-m-d H:i:s'),
        'quota_tokens' => 0, 'used_tokens' => 0, 'reserved_tokens' => 0, 'remaining_tokens' => 0, 'unlimited' => true,
    ];
    try {
        if ($providerId === null || !preg_match('/\A[a-f0-9]{32}\z/D', $providerId)) {
            $row = town_model_provider_row($pdo);
            $providerId = is_array($row) ? (string)$row['provider_id'] : null;
        }
        if ($providerId === null || $providerId === '') return $empty;
        $row = $pdo->prepare('SELECT monthly_quota FROM town_model_providers WHERE provider_id=:provider LIMIT 1');
        $row->execute([':provider' => $providerId]);
        $quota = (int)($row->fetchColumn() ?: 0);
        $monthStart = $now->setTimezone(new DateTimeZone('UTC'))->modify('first day of this month')->setTime(0, 0);
        $usage = $pdo->prepare('SELECT COALESCE(SUM(total_tokens),0) FROM town_agent_runs WHERE model_provider_id=:provider AND created_at>=:since');
        $usage->execute([':provider' => $providerId, ':since' => auth_sql_time($monthStart)]);
        $used = max(0, (int)$usage->fetchColumn());
        try {
            $settledQuery = $pdo->prepare("SELECT COALESCE(SUM(actual_tokens),0) FROM town_agent_token_reservations WHERE provider_id=:provider AND status='settled' AND created_at>=:since");
            $settledQuery->execute([':provider' => $providerId, ':since' => auth_sql_time($monthStart)]);
            $used += max(0, (int)$settledQuery->fetchColumn());
        } catch (PDOException) { /* Migration 035 is additive; old installations have no reservations. */ }
        $reserved = 0;
        try {
            $reservationQuery = $pdo->prepare("SELECT COALESCE(SUM(reserved_tokens),0) FROM town_agent_token_reservations WHERE provider_id=:provider AND status='reserved' AND created_at>=:since AND expires_at>:now");
            $reservationQuery->execute([':provider' => $providerId, ':since' => auth_sql_time($monthStart), ':now' => auth_sql_time($now)]);
            $reserved = max(0, (int)$reservationQuery->fetchColumn());
        } catch (PDOException) { $reserved = 0; }
        return [
            'month_start' => $monthStart->format('Y-m-d H:i:s'),
            'quota_tokens' => $quota, 'used_tokens' => $used, 'reserved_tokens' => $reserved,
            'remaining_tokens' => $quota > 0 ? max(0, $quota - $used - $reserved) : 0,
            'unlimited' => $quota <= 0,
        ];
    } catch (PDOException) {
        return $empty;
    }
}

/**
 * Atomically reserve a bounded token envelope for one model request. The
 * worker lease serializes normal callers, but the database lock also protects
 * the quota when a second worker or an operator starts during a renewal race.
 * @return array{reservation_id:string,provider_id:string,reserved_tokens:int,remaining_tokens:int,expires_at:string}
 */
function town_model_provider_reserve_tokens(PDO $pdo, string $providerId, int $estimatedTokens, string $workerId, DateTimeImmutable $now, int $ttlSeconds = 120): array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $providerId)) throw new ApiError('invalid_provider', 'The model provider identifier is invalid.', 400);
    $estimatedTokens = max(1, min(32768, $estimatedTokens));
    $workerId = substr(trim($workerId), 0, 128);
    if ($workerId === '') throw new ApiError('invalid_worker', 'The worker identifier is invalid.', 400);
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $nowSql = auth_sql_time($now);
    $expires = $now->modify('+' . max(30, min(300, $ttlSeconds)) . ' seconds');
    $pdo->beginTransaction();
    try {
        $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
        $provider = $pdo->prepare('SELECT monthly_quota FROM town_model_providers WHERE provider_id=:provider LIMIT 1' . $lock);
        $provider->execute([':provider' => $providerId]);
        $quota = $provider->fetchColumn();
        if ($quota === false) throw new ApiError('provider_not_found', 'The model provider was not found.', 404);
        $pdo->prepare("UPDATE town_agent_token_reservations SET status='released',settled_at=:released_at WHERE status='reserved' AND expires_at<=:expires_at")->execute([':released_at' => $nowSql, ':expires_at' => $nowSql]);
        $monthStart = $now->setTimezone(new DateTimeZone('UTC'))->modify('first day of this month')->setTime(0, 0);
        $usage = $pdo->prepare('SELECT COALESCE(SUM(total_tokens),0) FROM town_agent_runs WHERE model_provider_id=:provider AND created_at>=:since');
        $usage->execute([':provider' => $providerId, ':since' => auth_sql_time($monthStart)]);
        $used = max(0, (int)$usage->fetchColumn());
        $reservedQuery = $pdo->prepare("SELECT COALESCE(SUM(reserved_tokens),0) FROM town_agent_token_reservations WHERE provider_id=:provider AND status='reserved' AND created_at>=:since AND expires_at>:now");
        $reservedQuery->execute([':provider' => $providerId, ':since' => auth_sql_time($monthStart), ':now' => $nowSql]);
        $reserved = max(0, (int)$reservedQuery->fetchColumn());
        $quota = (int)$quota;
        if ($quota > 0 && $used + $reserved + $estimatedTokens > $quota) {
            throw new ApiError('monthly_quota_exceeded', '本月模型 Token 额度已用尽。', 409);
        }
        $reservationId = bin2hex(random_bytes(16));
        $insert = $pdo->prepare('INSERT INTO town_agent_token_reservations (reservation_id,provider_id,worker_id,reserved_tokens,actual_tokens,status,expires_at,created_at,settled_at) VALUES (:id,:provider,:worker,:reserved,0,\'reserved\',:expires,:created,NULL)');
        $insert->execute([':id' => $reservationId, ':provider' => $providerId, ':worker' => $workerId, ':reserved' => $estimatedTokens, ':expires' => auth_sql_time($expires), ':created' => $nowSql]);
        $pdo->commit();
        return ['reservation_id' => $reservationId, 'provider_id' => $providerId, 'reserved_tokens' => $estimatedTokens, 'remaining_tokens' => $quota > 0 ? max(0, $quota - $used - $reserved - $estimatedTokens) : 0, 'expires_at' => $expires->format(DateTimeInterface::ATOM)];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/**
 * Settle or release one reservation. Repeated settlement is idempotent so a
 * retry after a transient HTTP failure cannot double count the reservation.
 * @return array{reservation_id:string,status:string,actual_tokens:int}
 */
function town_model_provider_settle_tokens(PDO $pdo, string $reservationId, int $actualTokens, string $workerId, DateTimeImmutable $now): array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $reservationId)) throw new ApiError('invalid_reservation', 'The token reservation identifier is invalid.', 400);
    $actualTokens = max(0, min(4294967295, $actualTokens));
    $workerId = substr(trim($workerId), 0, 128);
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME); $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT reservation_id,worker_id,reserved_tokens,actual_tokens,status FROM town_agent_token_reservations WHERE reservation_id=:id LIMIT 1' . $lock);
        $query->execute([':id' => $reservationId]); $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new ApiError('reservation_not_found', 'The token reservation was not found.', 404);
        if ((string)$row['worker_id'] !== $workerId) throw new ApiError('reservation_forbidden', 'The token reservation belongs to another worker.', 403);
        $status = (string)$row['status'];
        if ($status !== 'reserved') { $pdo->commit(); return ['reservation_id' => $reservationId, 'status' => $status, 'actual_tokens' => (int)$row['actual_tokens']]; }
        $nextStatus = $actualTokens > 0 ? 'settled' : 'released';
        $update = $pdo->prepare('UPDATE town_agent_token_reservations SET actual_tokens=:actual,status=:status,settled_at=:settled WHERE reservation_id=:id');
        $update->execute([':actual' => $actualTokens, ':status' => $nextStatus, ':settled' => auth_sql_time($now), ':id' => $reservationId]);
        $pdo->commit();
        return ['reservation_id' => $reservationId, 'status' => $nextStatus, 'actual_tokens' => $actualTokens];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

/**
 * Probe the saved OpenAI-compatible provider without sending a completion.
 * The /models endpoint is intentionally used so the management test does not
 * consume model tokens or create an agent run. The optional requester keeps
 * this operation deterministic in service tests.
 */
function town_model_provider_test_connection(PDO $pdo, ?callable $requester = null): array
{
    $row = town_model_provider_row($pdo);
    if (!is_array($row) || (string)$row['api_key_ciphertext'] === '') {
        throw new ApiError('model_not_configured', '请先保存包含 API Key 的模型配置。', 400);
    }
    if (!(bool)$row['enabled']) throw new ApiError('model_disabled', '当前模型配置未启用。', 400);

    $baseUrl = rtrim((string)$row['base_url'], '/');
    $url = $baseUrl . '/models';
    $timeout = max(5, min(300, (int)$row['timeout_seconds']));
    $started = microtime(true);
    try {
        if ($requester !== null) {
            $response = $requester($url, town_model_decrypt((string)$row['api_key_ciphertext']), $timeout);
            if (!is_array($response)) throw new RuntimeException('The provider test callback returned an invalid response.');
            $status = (int)($response['status'] ?? 0);
            $remoteMessage = trim((string)($response['message'] ?? ''));
        } else {
            [$status, $remoteMessage] = town_model_provider_http_probe(
                $url,
                town_model_decrypt((string)$row['api_key_ciphertext']),
                $timeout);
        }
    } catch (Throwable) {
        throw new ApiError('model_connection_failed', '模型连接失败，请检查 Base URL、网络和 API Key。', 502);
    }

    $latency = max(0, (int)round((microtime(true) - $started) * 1000));
    if ($status < 200 || $status >= 300) {
        $suffix = $status > 0 ? '（HTTP ' . $status . '）' : '';
        throw new ApiError('model_connection_failed', '模型连接失败' . $suffix . '，请检查配置。', 502);
    }
    return [
        'ok' => true,
        'provider_id' => (string)$row['provider_id'],
        'model_name' => (string)$row['model_name'],
        'status_code' => $status,
        'latency_ms' => $latency,
        'message' => $remoteMessage,
    ];
}

/** @return array{0:int,1:string} */
function town_model_provider_http_probe(string $url, string $apiKey, int $timeout): array
{
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('Unable to initialize the HTTP client.');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false) throw new RuntimeException($error !== '' ? $error : 'The provider did not respond.');
        return [$status, ''];
    }

    $context = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true,
        'header' => "Accept: application/json\r\nAuthorization: Bearer {$apiKey}\r\n",
    ]]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) if (preg_match('/\AHTTP\/\S+\s+(\d{3})/', $header, $match)) { $status = (int)$match[1]; break; }
    if ($body === false && $status === 0) throw new RuntimeException('The provider did not respond.');
    return [$status, ''];
}

function town_model_provider_save(PDO $pdo, array $body, DateTimeImmutable $now): void
{
    $name = trim((string)($body['name'] ?? '')); $baseUrl = trim((string)($body['base_url'] ?? '')); $model = trim((string)($body['model_name'] ?? ''));
    if ($name === '' || strlen($name) > 80 || $model === '' || strlen($model) > 128 || !filter_var($baseUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
        throw new ApiError('invalid_model_provider', '模型名称、名称和 Base URL 必须有效。', 400);
    }
    $temperature = (float)($body['temperature'] ?? 0.4); $timeout = (int)($body['timeout_seconds'] ?? 20); $quota = (int)($body['monthly_quota'] ?? 0);
    if ($temperature < 0 || $temperature > 2 || $timeout < 5 || $timeout > 300 || $quota < 0) throw new ApiError('invalid_model_provider', '温度、超时或额度超出范围。', 400);
    $currency = strtoupper(trim((string)($body['currency'] ?? 'USD')));
    $inputRate = (float)($body['input_per_million'] ?? 0); $outputRate = (float)($body['output_per_million'] ?? 0);
    if (!preg_match('/\A[A-Z]{3}\z/', $currency) || $inputRate < 0 || $outputRate < 0 || $inputRate > 100000 || $outputRate > 100000) {
        throw new ApiError('invalid_model_provider', '币种和 Token 单价必须有效。', 400);
    }
    $current = town_model_provider_row($pdo); $apiKey = trim((string)($body['api_key'] ?? ''));
    if ($apiKey === '' && is_array($current)) $cipher = (string)$current['api_key_ciphertext'];
    elseif ($apiKey !== '') $cipher = town_model_encrypt($apiKey);
    else throw new ApiError('invalid_model_provider', '首次保存时必须填写 API Key。', 400);
    $id = is_array($current) ? (string)$current['provider_id'] : bin2hex(random_bytes(16));
    $sql = 'INSERT INTO town_model_providers (provider_id,name,base_url,model_name,api_key_ciphertext,temperature,timeout_seconds,monthly_quota,enabled,updated_at) VALUES (:id,:name,:url,:model,:key,:temperature,:timeout,:quota,:enabled,:updated) ON DUPLICATE KEY UPDATE name=VALUES(name),base_url=VALUES(base_url),model_name=VALUES(model_name),api_key_ciphertext=VALUES(api_key_ciphertext),temperature=VALUES(temperature),timeout_seconds=VALUES(timeout_seconds),monthly_quota=VALUES(monthly_quota),enabled=VALUES(enabled),updated_at=VALUES(updated_at)';
    $pdo->prepare($sql)->execute([':id' => $id, ':name' => $name, ':url' => rtrim($baseUrl, '/') . '/', ':model' => $model, ':key' => $cipher, ':temperature' => $temperature, ':timeout' => $timeout, ':quota' => $quota, ':enabled' => (int)(bool)($body['enabled'] ?? false), ':updated' => auth_sql_time($now)]);
    try {
        $pricing = $pdo->prepare('INSERT INTO town_model_provider_pricing (provider_id,currency,input_per_million,output_per_million,updated_at) VALUES (:provider,:currency,:input,:output,:updated) ON DUPLICATE KEY UPDATE currency=VALUES(currency),input_per_million=VALUES(input_per_million),output_per_million=VALUES(output_per_million),updated_at=VALUES(updated_at)');
        $pricing->execute([':provider' => $id, ':currency' => $currency, ':input' => $inputRate, ':output' => $outputRate, ':updated' => auth_sql_time($now)]);
    } catch (PDOException) { /* Migration 036 can be applied after the provider row. */ }
    town_model_provider_record_revision($pdo, $id, 'save', [
        'name' => $name, 'base_url' => rtrim($baseUrl, '/') . '/', 'model_name' => $model,
        'temperature' => $temperature, 'timeout_seconds' => $timeout, 'monthly_quota' => $quota,
        'enabled' => (bool)($body['enabled'] ?? false), 'currency' => $currency,
        'input_per_million' => $inputRate, 'output_per_million' => $outputRate,
    ], $now);
}
