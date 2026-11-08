<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/account_data.php';
require dirname(__DIR__, 3) . '/_app/shared_town.php';

api_run('GET', static function (PDO $pdo): array {
    $actor = account_authenticated_user($pdo, 'api_clock');
    shared_town_require_access($pdo, (string)$actor['user_id']);
    $raw = (string)($_GET['chunks'] ?? ''); $pairs = $raw === '' ? [] : explode(',', $raw);
    if ($pairs === []) {
        $x = filter_var($_GET['chunk_x'] ?? null, FILTER_VALIDATE_INT);
        $y = filter_var($_GET['chunk_y'] ?? null, FILTER_VALIDATE_INT);
        if ($x === false || $y === false) { throw new ApiError('invalid_chunk', 'A chunk coordinate is required.', 400); }
        $pairs = [(int)$x . ':' . (int)$y];
    }
    if (count($pairs) > SHARED_TOWN_MAX_CHUNKS) { throw new ApiError('too_many_chunks', 'Too many chunks requested.', 413); }
    $chunks = [];
    foreach ($pairs as $pair) {
        $parts = explode(':', trim($pair));
        if (count($parts) !== 2 || filter_var($parts[0], FILTER_VALIDATE_INT) === false || filter_var($parts[1], FILTER_VALIDATE_INT) === false) {
            throw new ApiError('invalid_chunk', 'Chunk coordinates are invalid.', 400);
        }
        $chunks[] = shared_town_chunk($pdo, (int)$parts[0], (int)$parts[1]);
    }
    return ['ok' => true, 'world' => shared_town_world($pdo), 'chunks' => $chunks];
});
