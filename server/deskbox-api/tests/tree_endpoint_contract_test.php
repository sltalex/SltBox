<?php
declare(strict_types=1);

$endpoint = file_get_contents(dirname(__DIR__) . '/api/v1/tree/progress.php');
if ($endpoint === false) {
    throw new RuntimeException('Tree progress endpoint is missing.');
}
if (!str_contains($endpoint, 'tree_apply_progress($pdo')) {
    throw new RuntimeException('Tree progress endpoint must use the idempotent delta accumulator.');
}
if (!str_contains($endpoint, "'/_app/account_data.php'")) {
    throw new RuntimeException('Tree progress endpoint must load the tree data service.');
}
if (str_contains($endpoint, "'endpoint_retired'")) {
    throw new RuntimeException('Tree progress endpoint must remain available to current clients.');
}
echo "tree progress endpoint contract passed\n";
