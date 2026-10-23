<?php
declare(strict_types=1);

// api_run exits, so each method needs a separate PHP process.
if (($argv[1] ?? '') === '--http') {
    $_SERVER['SCRIPT_NAME'] = '/api/v1/dingtalk/favorites.php';
    $_SERVER['REQUEST_METHOD'] = $argv[2] ?? '';
    require dirname(__DIR__) . '/api/v1/dingtalk/favorites.php';
    exit;
}
if (($argv[1] ?? '') === '--validate-document') {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    register_shutdown_function(static function (): void {
        try {
            $validated = dingtalk_favorites_validate_documents([
                [
                    'group_name' => '项目群',
                    'title' => '说明文档',
                    'url' => 'https://alidocs.dingtalk.com/i/nodes/example',
                    'last_seen_at' => '2026-10-23T00:00:00+00:00',
                    'conversation_id' => 'conversation-1',
                ],
                [
                    'groupName' => '旧版群',
                    'title' => '旧版文档',
                    'url' => 'https://alidocs.dingtalk.com/i/nodes/legacy',
                    'lastSeenAt' => '2026-10-22T00:00:00+00:00',
                    'conversationId' => 'conversation-2',
                ],
            ]);
            echo "\nvalidated=" . json_encode($validated, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (ApiError $error) {
            echo "\nvalidation_error={$error->apiCode}";
        }
    });
    require dirname(__DIR__) . '/api/v1/dingtalk/favorites.php';
    exit;
}

function favorites_endpoint_request(string $method): array
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--http', $method],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start favorites endpoint test worker.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException("Favorites endpoint worker failed: {$error} {$output}");
    }
    $body = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
    return $body;
}

function favorites_endpoint_validation(): array
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--validate-document'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start favorites validation worker.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException("Favorites validation worker failed: {$error} {$output}");
    }
    if (!preg_match('/(?:^|\n)validated=(.*)$/D', $output, $matches)) {
        throw new RuntimeException('Client-shaped favorite document was rejected: ' . $output);
    }
    return json_decode($matches[1], true, 8, JSON_THROW_ON_ERROR);
}

$getCode = favorites_endpoint_request('GET')['code'] ?? null;
$putCode = favorites_endpoint_request('PUT')['code'] ?? null;
if (!in_array($getCode, ['invalid_session', 'server_error'], true)) {
    throw new RuntimeException("GET did not reach authentication: {$getCode}");
}
if ($putCode !== $getCode) {
    throw new RuntimeException("PUT did not reach the same authentication path as GET: {$putCode}");
}
$postCode = favorites_endpoint_request('POST')['code'] ?? null;
if ($postCode !== 'method_not_allowed') {
    throw new RuntimeException("Unsupported method was not rejected: {$postCode}");
}
$documents = favorites_endpoint_validation();
if (count($documents) !== 2 ||
    ($documents[0]['group_name'] ?? null) !== '项目群' ||
    ($documents[0]['last_seen_at'] ?? null) !== '2026-10-23T00:00:00+00:00' ||
    ($documents[0]['conversation_id'] ?? null) !== 'conversation-1' ||
    ($documents[1]['group_name'] ?? null) !== '旧版群' ||
    ($documents[1]['conversation_id'] ?? null) !== 'conversation-2') {
    throw new RuntimeException('Favorite document shape was not preserved.');
}

echo "dingtalk_favorites_endpoint_test: ok\n";
