<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/shared_town.php';
require dirname(__DIR__) . '/api/_app/town_navigation.php';
require dirname(__DIR__) . '/api/_app/town_npcs.php';

function spatial_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = api_clock(); $stamp = auth_sql_time($now); $npcId = str_repeat('c', 32); $otherId = str_repeat('d', 32);
$pdo->exec("CREATE TABLE town_worlds (world_id TEXT PRIMARY KEY,navigation_version INTEGER,world_version INTEGER,updated_at TEXT);
CREATE TABLE town_npcs (npc_id TEXT PRIMARY KEY,name TEXT,world_id TEXT,enabled INTEGER,agent_paused INTEGER);
CREATE TABLE town_npc_runtime_states (npc_id TEXT PRIMARY KEY,tile_x INTEGER,tile_y INTEGER,state TEXT,current_task_id TEXT);
CREATE TABLE town_npc_memories (memory_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,subject_npc_id TEXT,asset_id TEXT,asset_name TEXT,tile_x INTEGER,tile_y INTEGER,navigation_version INTEGER,memory_type TEXT,content TEXT,importance INTEGER,created_at TEXT,expires_at TEXT);
CREATE TABLE town_asset_items (asset_id TEXT PRIMARY KEY,name TEXT,category TEXT,navigation_json TEXT);
CREATE TABLE town_objects (object_id TEXT PRIMARY KEY,world_id TEXT,asset_id TEXT,tile_x INTEGER,tile_y INTEGER,footprint_json TEXT,deleted_at TEXT);
CREATE TABLE town_work_points (point_key TEXT PRIMARY KEY,world_id TEXT,name TEXT,kind TEXT,tile_x INTEGER,tile_y INTEGER,enabled INTEGER);
CREATE TABLE town_npc_homes (npc_id TEXT PRIMARY KEY,home_asset_id TEXT,home_asset_name TEXT,tile_x INTEGER,tile_y INTEGER);
CREATE TABLE town_npc_decision_policies (npc_id TEXT PRIMARY KEY,decision_interval_seconds INTEGER,observation_interval_seconds INTEGER,dialogue_cooldown_seconds INTEGER,max_decisions_per_hour INTEGER);");
$pdo->prepare('INSERT INTO town_worlds VALUES (?,?,?,?)')->execute(['main', 1, 1, $stamp]);
$pdo->prepare('INSERT INTO town_npcs VALUES (?,?,?,?,?)')->execute([$npcId, '观察者', 'main', 1, 0]);
$pdo->prepare('INSERT INTO town_npcs VALUES (?,?,?,?,?)')->execute([$otherId, '邻居', 'main', 1, 0]);
$pdo->prepare('INSERT INTO town_npc_runtime_states VALUES (?,?,?,?,?)')->execute([$npcId, 10, 10, 'rest', null]);
$pdo->prepare('INSERT INTO town_npc_runtime_states VALUES (?,?,?,?,?)')->execute([$otherId, 12, 10, 'rest', null]);
$pdo->prepare('INSERT INTO town_npc_decision_policies VALUES (?,?,?,?,?)')->execute([$npcId, 60, 5, 60, 60]);
$pdo->prepare('INSERT INTO town_asset_items VALUES (?,?,?,?)')->execute(['house-blue', '蓝屋', 'house', '{"type":"plain"}']);
$pdo->prepare('INSERT INTO town_objects VALUES (?,?,?,?,?,?,?)')->execute(['obj-1', 'main', 'house-blue', 11, 10, '{}', null]);
$pdo->prepare('INSERT INTO town_work_points VALUES (?,?,?,?,?,?,?)')->execute(['market', 'main', '集市', 'market', 9, 10, 1]);
$pdo->prepare('INSERT INTO town_npc_homes VALUES (?,?,?,?,?)')->execute([$otherId, 'house-blue', '蓝屋', 12, 10]);

$npc = ['npc_id' => $npcId, 'enabled' => true, 'agent_paused' => false, 'tile_x' => 10, 'tile_y' => 10];
town_npc_observe_environment_if_due($pdo, $npc, $now);
$rows = $pdo->query('SELECT memory_type,subject_npc_id,asset_id,asset_name,tile_x,tile_y,navigation_version FROM town_npc_memories ORDER BY memory_id')->fetchAll(PDO::FETCH_ASSOC);
spatial_expect(count($rows) >= 3, 'Observation should remember assets, work points and homes.');
$asset = array_values(array_filter($rows, static fn(array $row): bool => ($row['asset_id'] ?? '') === 'house-blue'))[0] ?? null;
spatial_expect(is_array($asset) && ($asset['asset_name'] ?? '') === '蓝屋' && (int)$asset['tile_x'] === 11 && (int)$asset['tile_y'] === 10 && (int)$asset['navigation_version'] === 1, 'Asset observation should include name, id, coordinate and navigation version.');
$pdo->exec("UPDATE town_worlds SET navigation_version=2 WHERE world_id='main'");
town_npc_observe_environment_if_due($pdo, $npc, $now->modify('+1 seconds'));
$expired = (int)$pdo->query("SELECT COUNT(*) FROM town_npc_memories WHERE memory_type='environment_observation' AND expires_at IS NOT NULL")->fetchColumn();
spatial_expect($expired > 0, 'Navigation changes should expire previous environment observations before the normal interval.');
$pdo->exec("DELETE FROM town_objects; DELETE FROM town_work_points; DELETE FROM town_npc_homes; UPDATE town_npcs SET enabled=0 WHERE npc_id='" . $otherId . "'; UPDATE town_worlds SET navigation_version=3 WHERE world_id='main'");
town_npc_observe_environment_if_due($pdo, $npc, $now->modify('+12 seconds'));
$stale = (int)$pdo->query("SELECT COUNT(*) FROM town_npc_memories WHERE memory_type='environment_observation' AND navigation_version<>3 AND expires_at IS NOT NULL")->fetchColumn();
spatial_expect($stale >= $expired, 'An empty observation must still expire memories from the previous navigation version.');
echo "town_npc_spatial_memory_test: checks ok\n";
