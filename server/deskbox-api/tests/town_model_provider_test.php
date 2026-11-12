<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/town_model_provider.php';

function model_provider_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

putenv('DESKBOX_NPC_WORKER_TOKEN=test-worker-token');
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE town_model_providers (
    provider_id TEXT PRIMARY KEY, name TEXT, base_url TEXT, model_name TEXT,
    api_key_ciphertext TEXT, temperature REAL, timeout_seconds INTEGER,
    monthly_quota INTEGER, enabled INTEGER, updated_at TEXT
)');
$pdo->exec('CREATE TABLE town_agent_runs (
    run_id TEXT PRIMARY KEY, npc_id TEXT, model_provider_id TEXT, total_tokens INTEGER,
    created_at TEXT
)');
$pdo->exec('CREATE TABLE town_agent_token_reservations (
    reservation_id TEXT PRIMARY KEY, provider_id TEXT, worker_id TEXT,
    reserved_tokens INTEGER, actual_tokens INTEGER, status TEXT,
    expires_at TEXT, created_at TEXT, settled_at TEXT
)');
$providerId = str_repeat('c', 32);
$now = new DateTimeImmutable('2026-11-11T12:00:00+00:00');
$ciphertext = function_exists('sodium_crypto_secretbox') ? town_model_encrypt('secret-key') : '';
$insert = $pdo->prepare('INSERT INTO town_model_providers VALUES (?,?,?,?,?,?,?,?,?,?)');
$insert->execute([
    $providerId, '测试模型', 'https://example.test/v1/', 'test-model',
    $ciphertext, 0.4, 12, 1000, 1, auth_sql_time($now),
]);

$request = static function (string $url, string $key, int $timeout): array {
    model_provider_expect($url === 'https://example.test/v1/models', 'The connection probe URL is incorrect.');
    model_provider_expect($key === 'secret-key', 'The connection probe decrypted the wrong key.');
    model_provider_expect($timeout === 12, 'The connection probe used the wrong timeout.');
    return ['status' => 200, 'message' => 'ok'];
};
if ($ciphertext !== '') {
    $result = town_model_provider_test_connection($pdo, $request);
    model_provider_expect($result['ok'] === true && $result['status_code'] === 200, 'A healthy provider should pass the connection test.');
} else {
    try {
        town_model_provider_test_connection($pdo, $request);
        throw new RuntimeException('A provider without a decryptable key unexpectedly passed the connection test.');
    } catch (ApiError $error) {
        model_provider_expect($error->apiCode === 'model_not_configured', 'A provider without a key returned the wrong error.');
    }
}

$run = $pdo->prepare('INSERT INTO town_agent_runs VALUES (?,?,?,?,?)');
$run->execute([str_repeat('d', 32), null, $providerId, 120, auth_sql_time($now)]);
$budget = town_model_provider_budget($pdo, $providerId, $now);
model_provider_expect($budget['used_tokens'] === 120 && $budget['remaining_tokens'] === 880 && $budget['unlimited'] === false, 'Monthly token usage was not calculated correctly.');

$reservation = town_model_provider_reserve_tokens($pdo, $providerId, 400, 'worker-a', $now);
model_provider_expect($reservation['reserved_tokens'] === 400 && $reservation['remaining_tokens'] === 480, 'Token reservation did not account for existing usage.');
$reservedBudget = town_model_provider_budget($pdo, $providerId, $now);
model_provider_expect($reservedBudget['reserved_tokens'] === 400 && $reservedBudget['remaining_tokens'] === 480, 'Reserved tokens were not reflected in the budget.');
try {
    town_model_provider_reserve_tokens($pdo, $providerId, 500, 'worker-b', $now);
    throw new RuntimeException('A concurrent reservation unexpectedly crossed the monthly quota.');
} catch (ApiError $error) {
    model_provider_expect($error->apiCode === 'monthly_quota_exceeded', 'Quota reservation returned the wrong error.');
}
$settled = town_model_provider_settle_tokens($pdo, $reservation['reservation_id'], 200, 'worker-a', $now->modify('+1 second'));
model_provider_expect($settled['status'] === 'settled' && $settled['actual_tokens'] === 200, 'Token reservation did not settle idempotently.');
$settledBudget = town_model_provider_budget($pdo, $providerId, $now->modify('+1 second'));
model_provider_expect($settledBudget['used_tokens'] === 320 && $settledBudget['reserved_tokens'] === 0 && $settledBudget['remaining_tokens'] === 680, 'Settled reservation was not counted exactly once.');

if ($ciphertext !== '') {
    $pdo->exec('UPDATE town_model_providers SET enabled=0');
    try {
        town_model_provider_test_connection($pdo, $request);
        throw new RuntimeException('A disabled provider unexpectedly passed the connection test.');
    } catch (ApiError $error) {
        model_provider_expect($error->apiCode === 'model_disabled', 'A disabled provider returned the wrong error.');
    }
}

echo "town_model_provider_test: checks ok\n";
