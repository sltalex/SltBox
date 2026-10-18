<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }
require __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function api_database(): PDO
{
    $configPath = dirname(__DIR__) . '/_config.local.php';
    if (!is_file($configPath)) { throw new RuntimeException('Account service configuration is missing.'); }
    $config = require $configPath;
    if (!is_array($config)) { throw new RuntimeException('Account service configuration is invalid.'); }
    return new PDO((string)$config['dsn'], (string)$config['username'], (string)$config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function api_run(string $method, callable $handler): never
{
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
            header('Allow: ' . $method);
            throw new ApiError('method_not_allowed', 'Method not allowed.', 405);
        }
        $result = $handler(api_database());
        $created = $method === 'POST' && str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', '/register.php');
        api_json($result ?? ['ok' => true], $created ? 201 : 200);
    } catch (ApiError $error) {
        api_json(['code' => $error->apiCode, 'message' => $error->getMessage()], $error->httpStatus);
    } catch (Throwable $error) {
        error_log('[DeskBoxAuth] ' . get_class($error) . ': ' . $error->getMessage());
        api_json(['code' => 'server_error', 'message' => 'The account service is temporarily unavailable.'], 500);
    }
}

function api_is_https(array $server): bool
{
    return in_array(strtolower((string)($server['HTTPS'] ?? '')), ['on', '1'], true)
        || (string)($server['SERVER_PORT'] ?? '') === '443';
}

function api_json(array $body, int $status): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function api_body(int $maximumBytes = 8192, bool $allowGzip = false, int $maximumExpandedBytes = 8192): array
{
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maximumBytes) { throw new ApiError('request_too_large', 'Request body is too large.', 413); }
    $raw = file_get_contents('php://input', false, null, 0, $maximumBytes + 1);
    if ($raw === false || strlen($raw) > $maximumBytes) { throw new ApiError('request_too_large', 'Request body is too large.', 413); }
    $encoding = strtolower(trim((string)($_SERVER['HTTP_CONTENT_ENCODING'] ?? '')));
    if ($encoding !== '') {
        if (!$allowGzip || $encoding !== 'gzip') { throw new ApiError('unsupported_content_encoding', 'Unsupported content encoding.', 415); }
        $expanded = gzdecode($raw, $maximumExpandedBytes);
        if ($expanded === false || strlen($expanded) > $maximumExpandedBytes) {
            throw new ApiError('request_too_large', 'Expanded request body is too large.', 413);
        }
        $raw = $expanded;
    }
    return api_decode_body($raw, $maximumExpandedBytes);
}

function api_decode_body(string $raw, int $maximumBytes = 8192): array
{
    if (strlen($raw) > $maximumBytes) { throw new ApiError('request_too_large', 'Request body is too large.', 413); }
    try { $body = json_decode($raw, false, 16, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new ApiError('invalid_json', 'Request body must be valid JSON.', 400); }
    if (!is_object($body)) { throw new ApiError('invalid_json', 'Request body must be a JSON object.', 400); }
    return get_object_vars($body);
}

function api_string(array $body, string $key): string { return isset($body[$key]) && is_string($body[$key]) ? $body[$key] : ''; }
function api_bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    return preg_match('/\ABearer\s+([^\s]+)\z/i', $header, $matches) ? $matches[1] : '';
}
function api_clock(): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone('UTC')); }
function api_remote_address(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64); }
