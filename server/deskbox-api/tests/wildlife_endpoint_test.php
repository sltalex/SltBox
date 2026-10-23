<?php
declare(strict_types=1);

if (($argv[1] ?? '') === '--probe') {
    $route = $argv[2] ?? '';
    $method = $route === 'state' ? 'GET' : 'POST';
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['SCRIPT_NAME'] = "/api/v1/wildlife/{$route}.php";
    register_shutdown_function(static function (): void {
        echo "\nauth_helper_loaded=" . (function_exists('account_authenticated_user') ? 'yes' : 'no');
    });
    require dirname(__DIR__) . "/api/v1/wildlife/{$route}.php";
    exit;
}

foreach (['state', 'encounter', 'capture', 'restore'] as $route) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--probe', $route],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException("Could not probe {$route}.");
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || !str_contains($output, 'auth_helper_loaded=yes'))
        throw new RuntimeException("Wildlife {$route} lacks its account authentication helper: {$error} {$output}");
}

echo "wildlife_endpoint_test: OK\n";
