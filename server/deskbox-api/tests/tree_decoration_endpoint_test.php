<?php
declare(strict_types=1);

if (($argv[1] ?? '') === '--probe') {
    $endpoint = (string)$argv[2];
    $_SERVER['REQUEST_METHOD'] = $endpoint === 'actions' ? 'POST' : 'GET';
    $_SERVER['SCRIPT_NAME'] = '/api/v1/tree/decorations/' . $endpoint . '.php';
    register_shutdown_function(static function (): void {
        echo "\nauth_helper_loaded=" . (function_exists('account_authenticated_user') ? 'yes' : 'no');
        echo "\ndecoration_helper_loaded=" . (function_exists('tree_decoration_catalog') ? 'yes' : 'no');
    });
    require dirname(__DIR__) . '/api/v1/tree/decorations/' . $endpoint . '.php';
    exit;
}

foreach (['catalog', 'state', 'actions'] as $endpoint) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--probe', $endpoint],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Could not probe decoration endpoint.'); }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0
        || !str_contains($output, 'auth_helper_loaded=yes')
        || !str_contains($output, 'decoration_helper_loaded=yes')) {
        throw new RuntimeException("Decoration {$endpoint} endpoint contract failed: {$error} {$output}");
    }
}

echo "tree decoration endpoint tests passed\n";
