<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/town_world_snapshots.php';

function snapshot_expect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$stateA = ['world' => ['b' => 2, 'a' => 1], 'objects' => [['z' => 1, 'a' => 2]]];
$stateB = ['objects' => [['a' => 2, 'z' => 1]], 'world' => ['a' => 1, 'b' => 2]];
[$jsonA, $shaA] = town_world_snapshot_encode($stateA);
[$jsonB, $shaB] = town_world_snapshot_encode($stateB);
snapshot_expect($jsonA === $jsonB && hash_equals($shaA, $shaB), 'Snapshot encoding must be canonical and deterministic.');
$decoded = json_decode($jsonA, true, 16, JSON_THROW_ON_ERROR);
snapshot_expect($decoded['world']['a'] === 1 && $decoded['world']['b'] === 2, 'Snapshot canonicalization must sort object keys.');
echo "town_world_snapshot_test: checks ok\n";
