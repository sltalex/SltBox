<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/town_npcs.php';

$checks = 0;
function global_policy_expect(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE town_worlds (world_id TEXT PRIMARY KEY);
INSERT INTO town_worlds VALUES ('main');
CREATE TABLE town_npcs (npc_id TEXT PRIMARY KEY,enabled INTEGER);
INSERT INTO town_npcs VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1);
CREATE TABLE town_agent_global_policies (world_id TEXT PRIMARY KEY,enabled INTEGER,decision_interval_seconds INTEGER,observation_interval_seconds INTEGER,dialogue_cooldown_seconds INTEGER,max_decisions_per_hour INTEGER,updated_at TEXT);
CREATE TABLE town_npc_decision_policies (npc_id TEXT PRIMARY KEY,decision_interval_seconds INTEGER,observation_interval_seconds INTEGER,dialogue_cooldown_seconds INTEGER,max_decisions_per_hour INTEGER,updated_at TEXT);");
$pdo->exec("INSERT INTO town_agent_global_policies VALUES ('main',1,120,600,180,2,'2026-01-01 00:00:00');");
$pdo->exec("INSERT INTO town_npc_decision_policies VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',30,300,60,10,'2026-01-01 00:00:00');");

$global = town_npc_life_global_policy($pdo);
global_policy_expect($global === ['enabled' => true, 'decision_interval_seconds' => 120, 'observation_interval_seconds' => 600, 'dialogue_cooldown_seconds' => 180, 'max_decisions_per_hour' => 2], 'Global policy should be read from the world row.');
$effective = town_npc_life_policy($pdo, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
global_policy_expect($effective === ['decision_interval_seconds' => 120, 'observation_interval_seconds' => 600, 'dialogue_cooldown_seconds' => 180, 'max_decisions_per_hour' => 2], 'Global intervals and hourly limit should cap a faster NPC override.');

$saved = town_npc_save_global_policy($pdo, ['enabled' => false, 'decision_interval_seconds' => 5, 'observation_interval_seconds' => 5, 'dialogue_cooldown_seconds' => 0, 'max_decisions_per_hour' => 100], new DateTimeImmutable('2026-01-01T00:00:00Z'));
global_policy_expect($saved['enabled'] === false && $saved['decision_interval_seconds'] === 5 && $saved['max_decisions_per_hour'] === 100, 'Global policy save should clamp, disable and persist the requested values.');
$effective = town_npc_life_policy($pdo, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
global_policy_expect($effective['decision_interval_seconds'] === 30 && $effective['observation_interval_seconds'] === 300 && $effective['dialogue_cooldown_seconds'] === 60 && $effective['max_decisions_per_hour'] === 10, 'A slower NPC override should remain effective when the global policy is relaxed.');

echo "town_npc_global_policy_test: {$checks} checks ok\n";
