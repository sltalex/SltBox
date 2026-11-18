<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/town_world_events.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE town_world_event_journal (sequence INTEGER PRIMARY KEY AUTOINCREMENT,world_id TEXT,event_type TEXT,payload_json TEXT,schema_version INTEGER,idempotency_key TEXT,created_at TEXT,UNIQUE(world_id,idempotency_key))');
$now = new DateTimeImmutable('2026-11-18 12:00:00', new DateTimeZone('UTC'));
$first = town_world_event_journal_append($pdo, 'main', 'public_event', ['event_id' => 7], 'public_event:7', $now);
$duplicate = town_world_event_journal_append($pdo, 'main', 'public_event', ['event_id' => 7], 'public_event:7', $now);
if ($first !== 1 || $duplicate !== 1 || town_world_event_journal_latest_sequence($pdo, 'main') !== 1) throw new RuntimeException('Journal append must be idempotent and expose a stable cursor.');
echo "town_world_event_journal_test: checks ok\n";
