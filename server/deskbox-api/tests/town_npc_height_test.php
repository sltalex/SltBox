<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/account_data.php';
require dirname(__DIR__) . '/api/_app/shared_town.php';
require dirname(__DIR__) . '/api/_app/town_navigation.php';
require dirname(__DIR__) . '/api/_app/town_npcs.php';

$checks = 0;
function height_expect(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = new DateTimeImmutable('2026-11-12T10:00:00Z'); $stamp = auth_sql_time($now);
$pdo->exec("CREATE TABLE town_worlds (world_id TEXT PRIMARY KEY,world_version INTEGER,terrain_version INTEGER,map_width INTEGER,map_height INTEGER,tile_size INTEGER,chunk_size INTEGER,change_cursor INTEGER,navigation_version INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE town_objects (object_id TEXT PRIMARY KEY,world_id TEXT,asset_id TEXT,owner_user_id TEXT,chunk_x INTEGER,chunk_y INTEGER,tile_x INTEGER,tile_y INTEGER,footprint_json TEXT,layer INTEGER,object_version INTEGER,deleted_at TEXT);
CREATE TABLE town_asset_items (asset_id TEXT PRIMARY KEY,navigation_json TEXT);
CREATE TABLE town_npcs (npc_id TEXT PRIMARY KEY,world_id TEXT,name TEXT,avatar_asset_id TEXT,appearance_json TEXT,occupation TEXT,enabled INTEGER,agent_paused INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE town_npc_profiles (npc_id TEXT PRIMARY KEY,personality_json TEXT,family_background TEXT,short_term_goal TEXT,long_term_goal TEXT,speech_style TEXT,values_json TEXT,fears_json TEXT);
CREATE TABLE town_npc_runtime_states (npc_id TEXT PRIMARY KEY,tile_x INTEGER,tile_y INTEGER,height INTEGER,state TEXT,current_task_id TEXT,dialogue TEXT,mood_json TEXT,capabilities_json TEXT,state_version INTEGER,updated_at TEXT);
CREATE TABLE town_npc_tasks (task_id TEXT PRIMARY KEY,npc_id TEXT,action TEXT,target_id TEXT,payload_json TEXT,status TEXT,attempts INTEGER,scheduled_at TEXT,started_at TEXT,completed_at TEXT,failure_reason TEXT);
CREATE TABLE town_npc_events (event_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,event_type TEXT,payload_json TEXT,created_at TEXT);
CREATE TABLE town_npc_activity_logs (log_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,run_id TEXT,log_type TEXT,summary TEXT,detail_json TEXT,created_at TEXT);
CREATE TABLE town_agent_leases (lease_name TEXT PRIMARY KEY,holder_id TEXT,lease_token TEXT,expires_at TEXT,updated_at TEXT);");
$pdo->prepare('INSERT INTO town_worlds VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute(['main',1,1,768,768,16,24,0,1,$stamp,$stamp]);
$npc = str_repeat('c', 32);
$pdo->prepare('INSERT INTO town_npcs VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$npc,'main','高度测试村民','', '{}','farmer',1,0,$stamp,$stamp]);
$pdo->prepare('INSERT INTO town_npc_runtime_states VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$npc,199,200,0,'rest',null,'','{}','[]',1,$stamp]);

$insertAsset = $pdo->prepare('INSERT INTO town_asset_items (asset_id,navigation_json) VALUES (?,?)');
$insertObject = $pdo->prepare('INSERT INTO town_objects VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
$insertAsset->execute(['upper-ground', '{"type":"plain","height":1,"blocked_npc":false}']);
$insertObject->execute(['upper-ground-object','main','upper-ground','owner',8,8,201,200,'{"width":1,"height":1}',1,1,null]);
town_navigation_rebuild($pdo, $now);
$legacyStairs = town_navigation_normalize_semantics(['kind' => 'stairs', 'elevation' => 2, 'blocks_walk' => false, 'transitions' => [['from' => 'east', 'to' => 'west', 'requires' => []]]]);
height_expect($legacyStairs['type'] === 'stairs' && $legacyStairs['height'] === 2 && $legacyStairs['blocked_npc'] === false, 'Legacy kind/elevation navigation metadata must normalize to the current contract.');
$legacyCliff = town_navigation_normalize_semantics(['kind' => 'cliff', 'elevation' => 1, 'blocks_walk' => true]);
height_expect($legacyCliff['type'] === 'blocked' && $legacyCliff['required_capabilities'] === ['ladder'], 'Legacy cliff metadata must require a ladder instead of becoming an unknown tile.');
$withoutStairs = town_navigation_find_route($pdo, 199, 200, 201, 200);
height_expect($withoutStairs['found'] === false, 'A height change without stairs must be unreachable.');

$insertAsset->execute(['stairs-east', '{"type":"stairs","height":0,"connections":["east","west"],"blocked_npc":false}']);
$insertObject->execute(['stairs-east-object','main','stairs-east','owner',8,8,200,200,'{"width":1,"height":1}',2,1,null]);
town_navigation_rebuild($pdo, $now->modify('+1 second'));
$withStairs = town_navigation_find_route($pdo, 199, 200, 201, 200);
height_expect($withStairs['found'] === true, 'A configured stairs cell must connect adjacent heights.');
height_expect(array_column($withStairs['path'], 'height') === [0, 0, 1], 'Route nodes must expose authoritative cell heights.');

$action = town_npc_apply_action($pdo, ['npc_id' => $npc, 'action' => 'walk', 'payload' => ['target' => ['tile_x' => 201, 'tile_y' => 200]]], $now->modify('+2 seconds'), true);
height_expect(($action['route'][1]['height'] ?? null) === 0 && ($action['route'][2]['height'] ?? null) === 1, 'NPC task routes must retain height per node.');
$step = town_npc_advance_task($pdo, $npc, $now->modify('+3 seconds'), true);
height_expect($step['tile_x'] === 200 && $step['height'] === 0 && $step['task_status'] === 'running', 'NPC must reach the stairs at the lower height.');
$step = town_npc_advance_task($pdo, $npc, $now->modify('+4 seconds'), true);
height_expect($step['tile_x'] === 201 && $step['height'] === 1 && $step['task_status'] === 'completed', 'NPC must cross the stairs and complete at the upper height.');
height_expect((int)$pdo->query('SELECT height FROM town_npc_runtime_states WHERE npc_id=' . $pdo->quote($npc))->fetchColumn() === 1, 'Authoritative runtime height must be persisted after movement.');

$insertAsset->execute(['too-high', '{"type":"plain","height":3,"blocked_npc":false}']);
$insertObject->execute(['too-high-object','main','too-high','owner',8,8,202,200,'{"width":1,"height":1}',1,1,null]);
town_navigation_rebuild($pdo, $now->modify('+5 seconds'));
$tooHigh = town_navigation_find_route($pdo, 201, 200, 202, 200);
height_expect($tooHigh['found'] === false, 'A height difference greater than one must remain unreachable.');

echo "town_npc_height_test: {$checks} checks ok\n";
