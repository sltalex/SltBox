<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/account_data.php';

$townEndpointChecks = 0;
function town_http_expect(bool $condition, string $message): void
{
    global $townEndpointChecks;
    $townEndpointChecks++;
    if (!$condition) { throw new RuntimeException($message); }
}

function town_http_request(string $base, string $endpoint, string $method = 'GET',
    ?string $token = null, ?array $body = null): array
{
    $headers = ['Accept: application/json', 'Connection: close'];
    if ($token !== null) { $headers[] = 'Authorization: Bearer ' . $token; }
    if ($body !== null) { $headers[] = 'Content-Type: application/json'; }
    $options = ['method' => $method, 'header' => implode("\r\n", $headers),
        'ignore_errors' => true, 'timeout' => 8];
    if ($body !== null) { $options['content'] = json_encode($body, JSON_THROW_ON_ERROR); }
    $content = @file_get_contents($base . '/api/v1/tree/town-prototype/' . $endpoint,
        false, stream_context_create(['http' => $options]));
    if ($content === false) { throw new RuntimeException('Local town HTTP endpoint did not respond.'); }
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int)($match[1] ?? 0), json_decode($content, true, 32, JSON_THROW_ON_ERROR)];
}

function town_remove_test_directory(string $path): void
{
    if (!is_dir($path)) { return; }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,
        FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isDir()) { rmdir($entry->getPathname()); }
        else { unlink($entry->getPathname()); }
    }
    rmdir($path);
}

$root = dirname(__DIR__);
$sandbox = sys_get_temp_dir() . '/deskbox-town-endpoints-' . bin2hex(random_bytes(8));
$server = null;
$pdo = null;
try {
    $copies = [
        'api/_app/bootstrap.php', 'api/_app/auth.php', 'api/_app/account_data.php',
        'api/_app/tree_town_prototype.php', 'api/v1/tree/town-prototype/state.php',
        'api/v1/tree/town-prototype/actions.php', 'config/town-prototype-catalog.json',
        'config/town-prototype-default.json',
    ];
    foreach ($copies as $relative) {
        $destination = $sandbox . '/' . $relative;
        if (!is_dir(dirname($destination))) { mkdir(dirname($destination), 0777, true); }
        if (!copy($root . '/' . $relative, $destination)) {
            throw new RuntimeException('Unable to stage the town endpoint sandbox.');
        }
    }
    $database = $sandbox . '/test.sqlite';
    $pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
    $migration = str_replace(['CHARACTER SET ascii COLLATE ascii_bin', ' UNSIGNED'], '',
        file_get_contents($root . '/database/021_tree_town_prototype.sql'));
    $pdo->exec(preg_replace('/ENGINE=InnoDB[^;]+;/s', ';', $migration));
    $clock = static fn(): DateTimeImmutable => api_clock();
    $a = auth_register($pdo, 'town_http_a', 'secret1', 'http-device-a', '127.0.0.1', $clock);
    $b = auth_register($pdo, 'town_http_b', 'secret1', 'http-device-b', '127.0.0.2', $clock);
    file_put_contents($sandbox . '/api/_config.local.php', '<?php return ' . var_export([
        'dsn' => 'sqlite:' . $database, 'username' => null, 'password' => null,
    ], true) . ';');
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($listener === false) { throw new RuntimeException('Unable to allocate local endpoint port.'); }
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $server = proc_open([PHP_BINARY, '-S', $address, '-t', $sandbox],
        [0 => ['pipe', 'r'], 1 => ['file', $sandbox . '/server.log', 'a'],
            2 => ['file', $sandbox . '/server.log', 'a']], $pipes);
    if (!is_resource($server)) { throw new RuntimeException('Unable to start local endpoint test server.'); }
    fclose($pipes[0]);
    $base = 'http://' . $address;
    $ready = false;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.1);
        if ($connection !== false) { fclose($connection); $ready = true; break; }
        usleep(100000);
    }
    if (!$ready) { throw new RuntimeException('Local endpoint test server did not start.'); }

    [$status, $response] = town_http_request($base, 'state.php');
    town_http_expect($status === 401 && $response['code'] === 'invalid_session', 'State endpoint rejects missing authentication.');
    [$status, $response] = town_http_request($base, 'actions.php', 'POST', null, []);
    town_http_expect($status === 401 && $response['code'] === 'invalid_session', 'Save endpoint rejects missing authentication.');
    [$status] = town_http_request($base, 'state.php', 'POST', $a['session']['access_token'], []);
    town_http_expect($status === 405, 'State endpoint enforces GET.');
    [$status] = town_http_request($base, 'actions.php', 'GET', $a['session']['access_token']);
    town_http_expect($status === 405, 'Save endpoint enforces POST.');

    // The account-scoped prototype is retired.  Authenticated callers receive
    // an explicit migration response and the legacy table remains untouched.
    [$status, $response] = town_http_request($base, 'state.php', 'GET', $a['session']['access_token']);
    town_http_expect($status === 410 && $response['code'] === 'town_migrated',
        'Legacy state endpoint is sealed after the shared-town migration.');
    [$status, $response] = town_http_request($base, 'actions.php', 'POST', $a['session']['access_token'], []);
    town_http_expect($status === 410 && $response['code'] === 'town_migrated',
        'Legacy action endpoint is sealed after the shared-town migration.');
    town_http_expect((int)$pdo->query('SELECT COUNT(*) FROM tree_town_prototype_profiles')->fetchColumn() === 0,
        'The retired endpoint cannot recreate old demo layouts.');
    echo "town prototype endpoint migration checks passed ({$townEndpointChecks} checks)\n";
    return;

    [$status, $initial] = town_http_request($base, 'state.php', 'GET', $a['session']['access_token']);
    town_http_expect($status === 200 && $initial['scene_version'] === 0 && count($initial['layout']['placements']) === 16,
        'Authenticated state returns the complete example map.');
    town_http_expect((int)$pdo->query('SELECT COUNT(*) FROM tree_town_prototype_profiles')->fetchColumn() === 0,
        'HTTP state reads do not insert default layouts.');
    $layout = $initial['layout'];
    $layout['demo_stage'] = 3;
    $save = ['action' => 'save_scene', 'base_scene_version' => 0, 'operation_id' => 'http-a-save', 'layout' => $layout];
    [$status, $saved] = town_http_request($base, 'actions.php', 'POST', $a['session']['access_token'], $save);
    town_http_expect($status === 200 && $saved['scene_version'] === 1 && !$saved['duplicate'],
        'Authenticated save writes the current account map.');
    [$status, $readBack] = town_http_request($base, 'state.php', 'GET', $a['session']['access_token']);
    town_http_expect($status === 200 && $readBack['layout'] === $layout && $readBack['scene_version'] === 1,
        'A separate authenticated HTTP request reads the saved layout.');
    [$status, $replay] = town_http_request($base, 'actions.php', 'POST', $a['session']['access_token'], $save);
    town_http_expect($status === 200 && $replay['scene_version'] === 1 && $replay['duplicate'],
        'Retrying a lost response is idempotent through the real endpoint.');
    [$status, $other] = town_http_request($base, 'state.php?user_id=' . $a['user']['id'], 'GET', $b['session']['access_token']);
    town_http_expect($status === 200 && $other['scene_version'] === 0 && $other['layout']['demo_stage'] === 5,
        'A query-string owner cannot expose another account map.');
    $forged = $save; $forged['user_id'] = $a['user']['id'];
    [$status, $response] = town_http_request($base, 'actions.php', 'POST', $b['session']['access_token'], $forged);
    town_http_expect($status === 400 && $response['code'] === 'invalid_operation', 'A forged body owner is rejected.');
    $conflict = $save; $conflict['operation_id'] = 'http-stale-save'; $conflict['layout']['demo_stage'] = 4;
    [$status, $response] = town_http_request($base, 'actions.php', 'POST', $a['session']['access_token'], $conflict);
    town_http_expect($status === 409 && $response['code'] === 'scene_conflict'
        && $response['state']['scene_version'] === 1 && $response['state']['layout'] === $layout,
        'Conflict responses include the authoritative cloud state.');
    $otherSave = $save; $otherSave['layout']['demo_stage'] = 2;
    [$status, $response] = town_http_request($base, 'actions.php', 'POST', $b['session']['access_token'], $otherSave);
    town_http_expect($status === 200 && $response['scene_version'] === 1,
        'Another account saves independently even with the same operation id.');
    [$status, $response] = town_http_request($base, 'state.php', 'GET', $a['session']['access_token']);
    town_http_expect($status === 200 && $response['layout']['demo_stage'] === 3,
        'The second account save cannot alter the first account.');
    $oversized = $save; $oversized['padding'] = str_repeat('x', 70000);
    [$status, $response] = town_http_request($base, 'actions.php', 'POST', $a['session']['access_token'], $oversized);
    town_http_expect($status === 413 && $response['code'] === 'request_too_large', 'Oversized payloads are bounded.');
    echo "town prototype endpoint tests passed ({$townEndpointChecks} checks)\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $pdo = null;
    town_remove_test_directory($sandbox);
}
