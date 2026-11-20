<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require_once dirname(__DIR__) . '/api/_app/town_agent.php';

$checks = 0;
function slot_expect(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE town_agent_global_policies (world_id TEXT PRIMARY KEY,enabled INTEGER,decision_interval_seconds INTEGER,observation_interval_seconds INTEGER,dialogue_cooldown_seconds INTEGER,max_decisions_per_hour INTEGER,updated_at TEXT);
CREATE TABLE town_agent_decision_slots (slot_id TEXT PRIMARY KEY,npc_id TEXT,worker_id TEXT,created_at TEXT);
INSERT INTO town_agent_global_policies VALUES ('main',1,60,300,60,2,'2026-01-01 00:00:00');");
$npc = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'; $now = new DateTimeImmutable('2026-01-01T00:10:00Z');
$first = town_agent_reserve_decision_slot($pdo, $npc, 'worker-1', $now);
$second = town_agent_reserve_decision_slot($pdo, $npc, 'worker-1', $now->modify('+1 second'));
slot_expect(($first['reserved'] ?? false) && ($second['reserved'] ?? false), 'The first two global decision slots should be accepted.');
try { town_agent_reserve_decision_slot($pdo, $npc, 'worker-1', $now->modify('+2 seconds')); throw new RuntimeException('Expected the global decision limit.'); }
catch (ApiError $error) { slot_expect($error->apiCode === 'global_decision_limit' && $error->httpStatus === 429, 'The third slot should be rejected by the global limit.'); }
$pdo->exec("UPDATE town_agent_global_policies SET enabled=0 WHERE world_id='main'");
$disabled = town_agent_reserve_decision_slot($pdo, $npc, 'worker-1', $now->modify('+3 seconds'));
slot_expect(($disabled['enforced'] ?? true) === false, 'A disabled global policy should preserve compatibility.');
echo "town_agent_decision_slot_test: {$checks} checks ok\n";
