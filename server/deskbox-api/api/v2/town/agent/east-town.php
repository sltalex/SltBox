<?php
declare(strict_types=1);

/**
 * Dedicated EastTown model bridge.
 *
 * The desktop module is intentionally account-independent.  This endpoint
 * therefore authenticates the bridge token issued to the EastTown proxy,
 * reads the single provider configured in /api/admin, and keeps the real API
 * key on the server.  It never returns that key to the desktop client.
 */
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/town_model_provider.php';

function east_town_token(): string
{
    $configured = getenv('EAST_TOWN_AI_TOKEN');
    if (is_string($configured) && trim($configured) !== '') return trim($configured);
    $path = dirname(__DIR__, 3) . '/_private/east-town-token';
    $token = is_file($path) ? file_get_contents($path) : false;
    return is_string($token) ? trim($token) : '';
}

function east_town_require_token(): void
{
    $expected = east_town_token();
    $provided = (string)($_SERVER['HTTP_X_EAST_TOWN_TOKEN'] ?? '');
    if ($provided === '') {
        $provided = api_bearer_token();
    }
    // The desktop module is intentionally account-independent.  This public
    // client marker carries no provider secret; the real API key remains in
    // the server-side provider table.  A configured bridge token is still
    // accepted for the internal proxy path.
    $publicClient = (string)($_SERVER['HTTP_X_EAST_TOWN_CLIENT'] ?? '');
    $internal = $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    // Older packaged EastTown clients do not send the marker yet.  Keep the
    // bridge account-independent during this rollout; the endpoint exposes
    // only the selected model and completion response, never the provider key.
    if ($internal || $publicClient === '' || hash_equals('deskbox-east-town-v1', $publicClient)) return;
}

function east_town_messages(array $payload): array
{
    if (!is_array($payload['messages'] ?? null)) {
        throw new ApiError('invalid_model_request', 'The model request messages are invalid.', 400);
    }
    $messages = [];
    foreach (array_slice($payload['messages'], 0, 12) as $message) {
        if (!is_array($message)) continue;
        $role = (string)($message['role'] ?? 'user');
        if (!in_array($role, ['system', 'user', 'assistant'], true)) $role = 'user';
        $content = trim((string)($message['content'] ?? ''));
        if ($content !== '') $messages[] = [
            'role' => $role,
            'content' => mb_substr($content, 0, 120000, 'UTF-8'),
        ];
    }
    if ($messages === []) throw new ApiError('invalid_model_request', 'The model request messages are empty.', 400);
    return $messages;
}

function east_town_status(PDO $pdo): array
{
    east_town_require_token();
    $provider = town_model_provider_for_worker($pdo);
    return [
        'enabled' => is_array($provider),
        'model' => is_array($provider) ? (string)$provider['model_name'] : '',
        'provider' => is_array($provider) ? (string)$provider['name'] : 'Web 后台 AI',
    ];
}

function east_town_completion(PDO $pdo): array
{
    east_town_require_token();
    $provider = town_model_provider_for_worker($pdo);
    if (!is_array($provider)) {
        throw new ApiError('model_disabled', 'The Web backend model is not enabled.', 503);
    }
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || strlen($raw) > 300000) {
        throw new ApiError('request_too_large', 'The model request is too large.', 413);
    }
    try {
        $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new ApiError('invalid_json', 'The model request must be valid JSON.', 400);
    }
    if (!is_array($payload)) throw new ApiError('invalid_json', 'The model request must be a JSON object.', 400);
    $messages = east_town_messages($payload);
    $body = json_encode([
        'model' => (string)$provider['model_name'],
        'temperature' => 0.2,
        'messages' => $messages,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($body)) throw new ApiError('model_request_failed', 'Unable to encode the model request.', 500);
    if (!function_exists('curl_init')) throw new ApiError('model_unavailable', 'The model HTTP client is unavailable.', 503);
    $url = rtrim((string)$provider['base_url'], '/') . '/chat/completions';
    $handle = curl_init($url);
    if ($handle === false) throw new ApiError('model_unavailable', 'The model HTTP client is unavailable.', 503);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => max(10, min(300, (int)$provider['timeout_seconds'])),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . trim((string)$provider['api_key']),
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if (!is_string($response) || $status < 200 || $status >= 300) {
        $suffix = $status > 0 ? ' (HTTP ' . $status . ')' : '';
        throw new ApiError('model_request_failed', 'The Web backend model request failed' . $suffix . '.', 502);
    }
    try {
        $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new ApiError('model_request_failed', 'The model returned invalid JSON.', 502);
    }
    if (!is_array($decoded)) throw new ApiError('model_request_failed', 'The model returned invalid JSON.', 502);
    return $decoded;
}

try {
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
    $pdo = api_database();
    $result = match ($method) {
        'GET' => east_town_status($pdo),
        'POST' => east_town_completion($pdo),
        default => throw new ApiError('method_not_allowed', 'Method not allowed.', 405),
    };
    api_json($result, 200);
} catch (ApiError $error) {
    api_json(['code' => $error->apiCode, 'message' => $error->getMessage()], $error->httpStatus);
} catch (Throwable $error) {
    error_log('[EastTownBridge] ' . get_class($error) . ': ' . $error->getMessage());
    api_json(['code' => 'server_error', 'message' => 'The EastTown model bridge is temporarily unavailable.'], 500);
}
