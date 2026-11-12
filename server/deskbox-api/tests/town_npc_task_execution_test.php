<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/account_data.php';
require dirname(__DIR__) . '/api/_app/shared_town.php';
require dirname(__DIR__) . '/api/_app/town_navigation.php';
require dirname(__DIR__) . '/api/_app/town_npcs.php';
require dirname(__DIR__) . '/api/_app/town_agent.php';

$checks = 0;
function task_expect(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = new DateTimeImmutable('2026-11-11T10:00:00Z'); $stamp = auth_sql_time($now);
$pdo->exec("CREATE TABLE town_worlds (world_id TEXT PRIMARY KEY,world_version INTEGER,terrain_version INTEGER,map_width INTEGER,map_height INTEGER,tile_size INTEGER,chunk_size INTEGER,change_cursor INTEGER,navigation_version INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE town_objects (object_id TEXT PRIMARY KEY,world_id TEXT,asset_id TEXT,owner_user_id TEXT,chunk_x INTEGER,chunk_y INTEGER,tile_x INTEGER,tile_y INTEGER,footprint_json TEXT,layer INTEGER,object_version INTEGER,deleted_at TEXT);
CREATE TABLE town_asset_items (asset_id TEXT PRIMARY KEY,navigation_json TEXT);
CREATE TABLE town_npcs (npc_id TEXT PRIMARY KEY,world_id TEXT,name TEXT,avatar_asset_id TEXT,appearance_json TEXT,occupation TEXT,enabled INTEGER,agent_paused INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE town_npc_profiles (npc_id TEXT PRIMARY KEY,personality_json TEXT,family_background TEXT,short_term_goal TEXT,long_term_goal TEXT,speech_style TEXT,values_json TEXT,fears_json TEXT);
CREATE TABLE town_npc_runtime_states (npc_id TEXT PRIMARY KEY,tile_x INTEGER,tile_y INTEGER,state TEXT,current_task_id TEXT,dialogue TEXT,mood_json TEXT,capabilities_json TEXT,state_version INTEGER,updated_at TEXT);
CREATE TABLE town_npc_schedules (schedule_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,weekday INTEGER,start_minute INTEGER,end_minute INTEGER,action TEXT,target_id TEXT,job TEXT,priority INTEGER);
CREATE TABLE town_work_points (point_id TEXT PRIMARY KEY,world_id TEXT,point_key TEXT,name TEXT,kind TEXT,tile_x INTEGER,tile_y INTEGER,interaction_json TEXT,result_json TEXT,enabled INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE town_work_runs (run_id TEXT PRIMARY KEY,point_id TEXT,npc_id TEXT,task_id TEXT,job TEXT,status TEXT,result_json TEXT,started_at TEXT,completed_at TEXT);
CREATE TABLE town_npc_tasks (task_id TEXT PRIMARY KEY,npc_id TEXT,action TEXT,target_id TEXT,payload_json TEXT,status TEXT,attempts INTEGER,scheduled_at TEXT,started_at TEXT,completed_at TEXT,failure_reason TEXT);
CREATE TABLE town_npc_events (event_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,event_type TEXT,payload_json TEXT,created_at TEXT);
CREATE TABLE town_npc_activity_logs (log_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,run_id TEXT,log_type TEXT,summary TEXT,detail_json TEXT,created_at TEXT);
CREATE TABLE town_npc_memories (memory_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,memory_type TEXT,content TEXT,importance INTEGER,created_at TEXT,expires_at TEXT);
CREATE TABLE town_agent_leases (lease_name TEXT PRIMARY KEY,holder_id TEXT,lease_token TEXT,expires_at TEXT,updated_at TEXT);");
$pdo->prepare('INSERT INTO town_worlds VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute(['main',1,1,768,768,16,24,0,1,$stamp,$stamp]);
$npc = str_repeat('b', 32); $pdo->prepare('INSERT INTO town_npcs VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$npc,'main','执行测试村民','', '{}','farmer',1,0,$stamp,$stamp]);
$pdo->prepare('INSERT INTO town_npc_runtime_states VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$npc,90,90,'rest',null,'','{}','[]',1,$stamp]);
$pdo->prepare('INSERT INTO town_work_points VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute(['10000000000000000000000000000001','main','farm-zone-3','三号农田','farm',93,90,'{"radius":1,"occupations":["farmer"]}','{"effect":"water_crop"}',1,$stamp,$stamp]);
task_expect(town_npc_target_point('farm-zone-3', $pdo)['tile_x'] === 93, 'Configured work points must override the legacy demo coordinate.');

$action = town_npc_apply_action($pdo, ['npc_id' => $npc, 'action' => 'walk', 'payload' => ['target' => ['tile_x' => 93, 'tile_y' => 90]]], $now, true);
task_expect($action['task_status'] === 'running' && $action['tile_x'] === 90 && $action['tile_y'] === 90, 'Action must create a running task without teleporting.');
$progress = town_npc_advance_task($pdo, $npc, $now->modify('+1 second'), true);
task_expect($progress['task_status'] === 'running' && $progress['tile_x'] === 91, 'Advance must move exactly one tile.');
$progress = town_npc_advance_task($pdo, $npc, $now->modify('+2 seconds'), true);
task_expect($progress['tile_x'] === 92 && $progress['task_status'] === 'running', 'Second advance must continue the route.');
$progress = town_npc_advance_task($pdo, $npc, $now->modify('+3 seconds'), true);
task_expect($progress['tile_x'] === 93 && $progress['task_status'] === 'completed', 'Arrival must complete a walk task.');

$action = town_npc_apply_action($pdo, ['npc_id' => $npc, 'action' => 'work', 'payload' => ['target_id' => 'farm-zone-3', 'target' => ['tile_x' => 93, 'tile_y' => 90], 'duration_minutes' => 1]], $now->modify('+10 seconds'), true);
$progress = town_npc_advance_task($pdo, $npc, $now->modify('+11 seconds'), true);
task_expect($progress['state'] === 'work' && $progress['task_status'] === 'running', 'Arrival must enter the work state first.');
$progress = town_npc_advance_task($pdo, $npc, $now->modify('+40 seconds'), true);
task_expect($progress['task_status'] === 'running', 'Work must remain active before its duration ends.');
$progress = town_npc_advance_task($pdo, $npc, $now->modify('+80 seconds'), true);
task_expect($progress['task_status'] === 'completed' && $progress['state'] === 'rest', 'Work must complete after its duration.');
task_expect((int)$pdo->query('SELECT COUNT(*) FROM town_work_runs')->fetchColumn() === 1, 'Completed work must create a durable work run.');

$action = town_npc_apply_action($pdo, ['npc_id' => $npc, 'action' => 'walk', 'payload' => ['target' => ['tile_x' => 96, 'tile_y' => 90]]], $now->modify('+100 seconds'), true);
$progress = town_npc_advance_task($pdo, $npc, $now->modify('+101 seconds'), true);
task_expect($progress['tile_x'] === 94 && $progress['task_status'] === 'running', 'Replan fixture must first move onto the original route.');
$pdo->prepare('INSERT INTO town_asset_items (asset_id,navigation_json) VALUES (?,?)')->execute(['blocked-asset', '{"type":"blocked","blocked_npc":true}']);
$pdo->prepare('INSERT INTO town_objects (object_id,world_id,asset_id,owner_user_id,chunk_x,chunk_y,tile_x,tile_y,footprint_json,layer,object_version,deleted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute(['blocked-object','main','blocked-asset','owner',3,3,95,90,'{"width":1,"height":1}',1,1,null]);
town_navigation_rebuild($pdo, $now->modify('+102 seconds'));
$progress = town_npc_advance_task($pdo, $npc, $now->modify('+103 seconds'), true);
task_expect($progress['task_status'] === 'running' && !empty($progress['route_replanned']) && ($progress['replan_count'] ?? 0) === 1, 'Navigation changes must trigger an active route replan.');
$replannedComplete = false;
for ($step = 4; $step < 20; $step++) {
    $progress = town_npc_advance_task($pdo, $npc, $now->modify('+' . $step . ' seconds'), true);
    if (($progress['task_status'] ?? '') === 'completed') { $replannedComplete = true; break; }
}
task_expect($replannedComplete, 'A replanned route must still complete when an alternate path exists.');

$pdo->prepare('INSERT INTO town_npc_memories (npc_id,memory_type,content,importance,created_at) VALUES (?,?,?,?,?)')->execute([$npc, 'fact', '测试记忆', 3, $stamp]);
$pdo->prepare('UPDATE town_npcs SET agent_paused=1 WHERE npc_id=?')->execute([$npc]);
$forced = town_npc_admin_force_action($pdo, ['npc_id' => $npc, 'action' => 'work', 'payload' => ['target' => ['tile_x' => 93, 'tile_y' => 90], 'duration_minutes' => 0]], $now->modify('+110 seconds'));
task_expect($forced['task_status'] === 'running', 'Administrator force task must use the authoritative action path even when Agent is paused.');
$cleared = town_npc_clear_memories($pdo, $npc, $now->modify('+111 seconds'));
task_expect($cleared === 1 && (int)$pdo->query('SELECT COUNT(*) FROM town_npc_memories')->fetchColumn() === 0, 'Administrator memory clearing must delete durable memories and report the count.');
$scheduleCount = town_npc_replace_schedule($pdo, $npc, [['weekday' => 1, 'start_minute' => '08:00', 'end_minute' => '12:00', 'action' => 'work', 'target_id' => 'farm-zone-3', 'job' => 'water_crop'], ['weekday' => 1, 'start_minute' => '12:00', 'end_minute' => '13:00', 'action' => 'rest']], $now->modify('+112 seconds'));
task_expect($scheduleCount === 2 && (int)$pdo->query('SELECT COUNT(*) FROM town_npc_schedules')->fetchColumn() === 2, 'Administrator schedule editor must save time inputs and discard empty rows.');

echo "town_npc_task_execution_test: {$checks} checks ok\n";
