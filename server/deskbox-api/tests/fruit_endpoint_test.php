<?php
declare(strict_types=1);

if (($argv[1] ?? '') === '--probe') {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['SCRIPT_NAME'] = '/api/v1/fruit/pick.php';
    register_shutdown_function(static function (): void {
        echo "\nauth_helper_loaded=" . (function_exists('account_authenticated_user') ? 'yes' : 'no');
    });
    require dirname(__DIR__) . '/api/v1/fruit/pick.php';
    exit;
}

$pipes = [];
$process = proc_open([PHP_BINARY, __FILE__, '--probe'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) throw new RuntimeException('Could not probe fruit pick endpoint.');
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($process) !== 0 || !str_contains($output, 'auth_helper_loaded=yes'))
    throw new RuntimeException("Fruit pick endpoint lacks its account authentication helper: {$error} {$output}");

echo "fruit_endpoint_test: OK\n";
