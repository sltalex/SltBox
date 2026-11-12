<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/shared_town.php';
require dirname(__DIR__) . '/api/_app/town_navigation.php';
require dirname(__DIR__) . '/api/_app/town_npcs.php';
require dirname(__DIR__) . '/api/_app/town_agent.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = api_clock(); $stamp = auth_sql_time($now); $npc = str_repeat('b', 32);
$pdo->exec("CREATE TABLE town_npcs (npc_id TEXT PRIMARY KEY,name TEXT,world_id TEXT,avatar_asset_id TEXT,appearance_json TEXT,occupation TEXT,enabled INTEGER,agent_paused INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE town_npc_profiles (npc_id TEXT PRIMARY KEY,personality_json TEXT,family_background TEXT,short_term_goal TEXT,long_term_goal TEXT,speech_style TEXT,values_json TEXT,fears_json TEXT);
CREATE TABLE town_npc_runtime_states (npc_id TEXT PRIMARY KEY,tile_x INTEGER,tile_y INTEGER,state TEXT,current_task_id TEXT,dialogue TEXT,mood_json TEXT,capabilities_json TEXT,state_version INTEGER,updated_at TEXT);
CREATE TABLE town_npc_schedules (schedule_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,weekday INTEGER,start_minute INTEGER,end_minute INTEGER,action TEXT,target_id TEXT,job TEXT,priority INTEGER);
CREATE TABLE town_npc_events (event_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,event_type TEXT,payload_json TEXT,created_at TEXT);
CREATE TABLE town_npc_tasks (task_id TEXT PRIMARY KEY,npc_id TEXT,action TEXT,target_id TEXT,payload_json TEXT,status TEXT,attempts INTEGER,scheduled_at TEXT,started_at TEXT,completed_at TEXT,failure_reason TEXT);
CREATE TABLE town_agent_runs (run_id TEXT PRIMARY KEY,npc_id TEXT,model_provider_id TEXT,model_name TEXT,status TEXT,prompt_tokens INTEGER,completion_tokens INTEGER,total_tokens INTEGER,latency_ms INTEGER,action TEXT,reason TEXT,output_json TEXT,error_code TEXT,error_message TEXT,created_at TEXT,completed_at TEXT);
CREATE TABLE town_npc_activity_logs (log_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,run_id TEXT,log_type TEXT,summary TEXT,detail_json TEXT,created_at TEXT);
CREATE TABLE town_worlds (world_id TEXT PRIMARY KEY,navigation_version INTEGER);");
$pdo->prepare('INSERT INTO town_worlds VALUES (?,?)')->execute(['main', 1]);
$pdo->prepare('INSERT INTO town_npcs VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$npc, '测试村民', 'main', '', '{}', 'farmer', 1, 0, $stamp, $stamp]);
$pdo->prepare('INSERT INTO town_npc_runtime_states VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$npc, 10, 10, 'rest', null, '', '{}', '[]', 1, $stamp]);
$run = town_agent_record_run($pdo, ['npc_id' => $npc, 'model_name' => 'test-model', 'status' => 'completed', 'prompt_tokens' => 80, 'completion_tokens' => 40, 'total_tokens' => 120, 'latency_ms' => 250, 'action' => 'talk', 'reason' => '测试对话', 'output' => ['dialogue' => '今天阳光很好。']], $now);
town_npc_activity_log($pdo, $npc, 'speech', '今天阳光很好。', ['dialogue' => '今天阳光很好。'], $now, $run['run_id']);
$action = town_npc_apply_action($pdo, ['npc_id' => $npc, 'action' => 'walk', 'payload' => ['target' => ['tile_x' => 12, 'tile_y' => 10], 'run_id' => $run['run_id']],], $now, true);
if ((int)$action['tile_x'] !== 10 || (int)$action['tile_y'] !== 10 || $action['task_status'] !== 'running') throw new RuntimeException('NPC path activity was not queued.');
$progress = town_npc_advance_task($pdo, $npc, $now->modify('+1 second'), true);
$progress = town_npc_advance_task($pdo, $npc, $now->modify('+2 seconds'), true);
if ((int)$progress['tile_x'] !== 12 || (int)$progress['tile_y'] !== 10 || $progress['task_status'] !== 'completed') throw new RuntimeException('NPC path activity was not executed.');
$report = town_agent_admin_report($pdo, 7, null, 30);
if ((int)$report['summary']['total_tokens'] !== 120 || count($report['by_npc']) !== 1 || count($report['logs']) < 4) throw new RuntimeException('Agent observability report is incomplete.');
echo "town_agent_observability_test: checks ok\n";
