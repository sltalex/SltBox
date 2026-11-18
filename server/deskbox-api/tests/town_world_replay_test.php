<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/town_world_replay.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE town_world_snapshots (snapshot_id TEXT PRIMARY KEY,world_id TEXT,world_version INTEGER,change_cursor INTEGER,npc_cursor INTEGER,event_cursor INTEGER,navigation_version INTEGER,state_json TEXT,state_sha256 TEXT,created_by TEXT,created_at TEXT)');
$pdo->exec('CREATE TABLE town_world_event_journal (sequence INTEGER PRIMARY KEY AUTOINCREMENT,world_id TEXT,event_type TEXT,payload_json TEXT,schema_version INTEGER,idempotency_key TEXT,created_at TEXT,UNIQUE(world_id,idempotency_key))');
$pdo->exec('CREATE TABLE town_world_events (event_id INTEGER PRIMARY KEY,world_id TEXT,event_type TEXT,source_key TEXT,public_payload_json TEXT)');
$state = ['schema_version' => 1, 'world' => ['world_id' => 'main']];
[$json, $sha] = town_world_snapshot_encode($state);
$insert = $pdo->prepare('INSERT INTO town_world_snapshots VALUES (?,?,?,?,?,?,?,?,?,?,?)');
$insert->execute(['snap0000000000000000000000000001', 'main', 1, 1, 1, 0, 1, $json, $sha, 'worker', '2026-11-18 12:00:00']);
$pdo->exec("INSERT INTO town_world_events VALUES (1,'main','time_advanced','world:1','{" . '"minutes":10' . "}')");
$pdo->exec("INSERT INTO town_world_event_journal VALUES (1,'main','public_event','{" . '"event_id":1,"event_type":"time_advanced","source_key":"world:1","payload":{"minutes":10}' . "}',1,'public_event:1','2026-11-18 12:01:00')");
$report = town_world_replay_dry_run($pdo, null, 50);
if (!$report['ok'] || $report['writes_applied'] || $report['journal']['event_count'] !== 1) throw new RuntimeException('Valid replay report should pass without writes.');
$pdo->exec("UPDATE town_world_snapshots SET state_sha256='bad' WHERE snapshot_id='snap0000000000000000000000000001'");
$invalid = town_world_replay_dry_run($pdo, null, 50);
if ($invalid['ok'] || $invalid['writes_applied'] === true || ($invalid['errors'][0]['code'] ?? '') !== 'snapshot_invalid') throw new RuntimeException('Corrupt snapshots must fail closed in dry-run.');
echo "town_world_replay_test: checks ok\n";
