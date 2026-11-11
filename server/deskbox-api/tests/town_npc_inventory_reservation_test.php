<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/shared_town.php';
require dirname(__DIR__) . '/api/_app/town_navigation.php';
require dirname(__DIR__) . '/api/_app/town_npcs.php';

$checks = 0;
function item_expect(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$stamp = auth_sql_time(new DateTimeImmutable('2026-11-11T10:00:00Z'));
$pdo->exec("CREATE TABLE town_worlds (world_id TEXT PRIMARY KEY,world_version INTEGER,terrain_version INTEGER,map_width INTEGER,map_height INTEGER,tile_size INTEGER,chunk_size INTEGER,change_cursor INTEGER,navigation_version INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE town_objects (object_id TEXT PRIMARY KEY,world_id TEXT,asset_id TEXT,owner_user_id TEXT,chunk_x INTEGER,chunk_y INTEGER,tile_x INTEGER,tile_y INTEGER,footprint_json TEXT,layer INTEGER,object_version INTEGER,deleted_at TEXT);
CREATE TABLE town_asset_items (asset_id TEXT PRIMARY KEY,navigation_json TEXT);
CREATE TABLE town_work_points (point_id TEXT PRIMARY KEY,world_id TEXT,point_key TEXT,name TEXT,kind TEXT,tile_x INTEGER,tile_y INTEGER,interaction_json TEXT,result_json TEXT,enabled INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE town_npcs (npc_id TEXT PRIMARY KEY,world_id TEXT,name TEXT,avatar_asset_id TEXT,appearance_json TEXT,occupation TEXT,enabled INTEGER,agent_paused INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE town_npc_profiles (npc_id TEXT PRIMARY KEY,personality_json TEXT,family_background TEXT,short_term_goal TEXT,long_term_goal TEXT,speech_style TEXT,values_json TEXT,fears_json TEXT);
CREATE TABLE town_npc_runtime_states (npc_id TEXT PRIMARY KEY,tile_x INTEGER,tile_y INTEGER,state TEXT,current_task_id TEXT,dialogue TEXT,mood_json TEXT,capabilities_json TEXT,state_version INTEGER,updated_at TEXT);
CREATE TABLE town_npc_schedules (schedule_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,weekday INTEGER,start_minute INTEGER,end_minute INTEGER,action TEXT,target_id TEXT,job TEXT,priority INTEGER);
CREATE TABLE town_npc_tasks (task_id TEXT PRIMARY KEY,npc_id TEXT,action TEXT,target_id TEXT,payload_json TEXT,status TEXT,attempts INTEGER,scheduled_at TEXT,started_at TEXT,completed_at TEXT,failure_reason TEXT);
CREATE TABLE town_npc_events (event_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,event_type TEXT,payload_json TEXT,created_at TEXT);
CREATE TABLE town_npc_activity_logs (log_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,run_id TEXT,log_type TEXT,summary TEXT,detail_json TEXT,created_at TEXT);
CREATE TABLE town_work_runs (run_id TEXT PRIMARY KEY,point_id TEXT,npc_id TEXT,task_id TEXT,job TEXT,status TEXT,result_json TEXT,started_at TEXT,completed_at TEXT);
CREATE TABLE town_npc_inventory (npc_id TEXT,item_id TEXT,quantity INTEGER,updated_at TEXT,PRIMARY KEY(npc_id,item_id));
CREATE TABLE town_work_point_reservations (reservation_id TEXT PRIMARY KEY,point_id TEXT,npc_id TEXT,task_id TEXT,status TEXT,reserved_at TEXT,expires_at TEXT,released_at TEXT);");
$pdo->prepare('INSERT INTO town_worlds VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute(['main',1,1,768,768,16,24,0,1,$stamp,$stamp]);
$pointId = str_repeat('1', 32); $point = [$pointId,'main','water-work','河边工作点','workshop',65,20,'{}','{"effect":"craft_item"}',1,$stamp,$stamp];
$pdo->prepare('INSERT INTO town_work_points VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute($point);
$npc = str_repeat('a', 32); $pdo->prepare('INSERT INTO town_npcs VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$npc,'main','道具测试村民','', '{}','craftsman',1,0,$stamp,$stamp]);
$pdo->prepare('INSERT INTO town_npc_runtime_states VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$npc,64,20,'rest',null,'','{}','[]',1,$stamp]);
$now = new DateTimeImmutable('2026-11-11T10:00:00Z');
$blocked = false;
try { town_npc_apply_action($pdo, ['npc_id' => $npc, 'action' => 'work', 'payload' => ['target_id' => 'water-work', 'duration_minutes' => 0]], $now, true); }
catch (ApiError $error) { $blocked = $error->apiCode === 'no_route'; }
item_expect($blocked, 'A water crossing without bridge kit must be rejected.');
$pdo->prepare('INSERT INTO town_npc_inventory VALUES (?,?,?,?)')->execute([$npc,'bridge_kit',1,$stamp]);
$action = town_npc_apply_action($pdo, ['npc_id' => $npc, 'action' => 'work', 'payload' => ['target_id' => 'water-work', 'duration_minutes' => 0]], $now, true);
item_expect($action['task_status'] === 'running' && ($action['route'][1]['x'] ?? null) === 65 && ($action['route'][1]['y'] ?? null) === 20, 'A bridge kit must make the water target reachable.');
item_expect((int)$pdo->query("SELECT quantity FROM town_npc_inventory WHERE npc_id='{$npc}' AND item_id='bridge_kit'")->fetchColumn() === 0, 'A successful crossing must consume one bridge kit.');
$taskId = (string)$action['task_id'];
$arrival = town_npc_advance_task($pdo, $npc, $now->modify('+1 second'), true);
item_expect($arrival['state'] === 'work' && $arrival['task_status'] === 'running', 'The NPC must arrive at the water work point before completing the interaction.');
$complete = town_npc_advance_task($pdo, $npc, $now->modify('+2 seconds'), true);
item_expect($complete['task_status'] === 'completed', 'A zero-duration work interaction must complete after arrival.');
item_expect((int)$pdo->query("SELECT COUNT(*) FROM town_work_point_reservations WHERE task_id='{$taskId}' AND status='completed'")->fetchColumn() === 1, 'Completed work must release its point reservation.');
$pdo->prepare("UPDATE town_npc_inventory SET quantity=1,updated_at=? WHERE npc_id=? AND item_id='bridge_kit'")->execute([$stamp, $npc]);
$pdo->prepare('UPDATE town_npc_runtime_states SET tile_x=64,tile_y=20,state=\'rest\',current_task_id=NULL WHERE npc_id=?')->execute([$npc]);
$expired = town_npc_apply_action($pdo, ['npc_id' => $npc, 'action' => 'work', 'payload' => ['target_id' => 'water-work', 'duration_minutes' => 0]], $now->modify('+10 seconds'), true);
$expiredTask = (string)$expired['task_id'];
$pdo->prepare("UPDATE town_work_point_reservations SET expires_at='2026-11-11 09:00:00' WHERE task_id=?")->execute([$expiredTask]);
$failed = town_npc_advance_task($pdo, $npc, $now->modify('+20 seconds'), true);
item_expect($failed['task_status'] === 'failed' && $failed['failure_reason'] === 'work_point_expired', 'An expired reservation must stop the abandoned task.');
item_expect((int)$pdo->query("SELECT quantity FROM town_npc_inventory WHERE npc_id='{$npc}' AND item_id='bridge_kit'")->fetchColumn() === 1, 'A failed expired task must refund its bridge kit.');

echo "town_npc_inventory_reservation_test: {$checks} checks ok\n";
