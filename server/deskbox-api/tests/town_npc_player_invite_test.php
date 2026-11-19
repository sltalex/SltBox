<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/shared_town.php';
require dirname(__DIR__) . '/api/_app/town_navigation.php';
require dirname(__DIR__) . '/api/_app/town_npcs.php';

$checks = 0;
function invite_expect(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = new DateTimeImmutable('2026-11-13T10:00:00Z'); $stamp = auth_sql_time($now);
$pdo->exec("CREATE TABLE town_worlds (world_id TEXT PRIMARY KEY,navigation_version INTEGER,world_version INTEGER DEFAULT 1,updated_at TEXT);
CREATE TABLE town_presence (world_id TEXT,user_id TEXT,display_name TEXT,chunk_x INTEGER,chunk_y INTEGER,tile_x INTEGER,tile_y INTEGER,last_seen_at TEXT,expires_at TEXT,PRIMARY KEY(world_id,user_id));
CREATE TABLE town_objects (object_id TEXT PRIMARY KEY,world_id TEXT,asset_id TEXT,owner_user_id TEXT,chunk_x INTEGER,chunk_y INTEGER,tile_x INTEGER,tile_y INTEGER,footprint_json TEXT,layer INTEGER,object_version INTEGER,deleted_at TEXT);
CREATE TABLE town_asset_items (asset_id TEXT PRIMARY KEY,navigation_json TEXT);
CREATE TABLE town_npcs (npc_id TEXT PRIMARY KEY,world_id TEXT,name TEXT,avatar_asset_id TEXT,appearance_json TEXT,occupation TEXT,enabled INTEGER,agent_paused INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE town_npc_profiles (npc_id TEXT PRIMARY KEY,personality_json TEXT,family_background TEXT,short_term_goal TEXT,long_term_goal TEXT,speech_style TEXT,values_json TEXT,fears_json TEXT);
CREATE TABLE town_npc_runtime_states (npc_id TEXT PRIMARY KEY,tile_x INTEGER,tile_y INTEGER,state TEXT,current_task_id TEXT,dialogue TEXT,mood_json TEXT,capabilities_json TEXT,state_version INTEGER,updated_at TEXT);
CREATE TABLE town_npc_tasks (task_id TEXT PRIMARY KEY,npc_id TEXT,action TEXT,target_id TEXT,payload_json TEXT,status TEXT,attempts INTEGER,scheduled_at TEXT,started_at TEXT,completed_at TEXT,failure_reason TEXT);
CREATE TABLE town_npc_events (event_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,event_type TEXT,payload_json TEXT,created_at TEXT);
CREATE TABLE town_npc_activity_logs (log_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,run_id TEXT,log_type TEXT,summary TEXT,detail_json TEXT,created_at TEXT);
CREATE TABLE town_npc_memories (memory_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,memory_type TEXT,content TEXT,importance INTEGER,created_at TEXT,expires_at TEXT);
CREATE TABLE town_npc_schedules (schedule_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,weekday INTEGER,start_minute INTEGER,end_minute INTEGER,action TEXT,target_id TEXT,job TEXT,priority INTEGER);
CREATE TABLE town_agent_leases (lease_name TEXT PRIMARY KEY,holder_id TEXT,lease_token TEXT,expires_at TEXT,updated_at TEXT);");
$pdo->prepare('INSERT INTO town_worlds VALUES (?,?,?,?)')->execute(['main', 1, 1, $stamp]);
$npc = str_repeat('a', 32); $user = str_repeat('b', 32); $otherUser = str_repeat('c', 32);
$pdo->prepare('INSERT INTO town_npcs VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$npc,'main','邀请测试村民','', '{}','farmer',1,0,$stamp,$stamp]);
$pdo->prepare('INSERT INTO town_npc_runtime_states VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$npc,10,10,'rest',null,'','{}','[]',1,$stamp]);
$restTask = str_repeat('d', 32);
$pdo->prepare('INSERT INTO town_npc_tasks (task_id,npc_id,action,target_id,payload_json,status,attempts,scheduled_at,started_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$restTask,$npc,'rest','npc-home','{}','running',1,$stamp,$stamp]);
$pdo->prepare('UPDATE town_npc_runtime_states SET current_task_id=? WHERE npc_id=?')->execute([$restTask, $npc]);
$presence = $pdo->prepare('INSERT INTO town_presence VALUES (?,?,?,?,?,?,?,?,?)');
$presence->execute(['main',$user,'测试玩家',0,0,12,10,$stamp,auth_sql_time($now->modify('+30 seconds'))]);

$follow = town_npc_apply_action($pdo, ['npc_id' => $npc, 'action' => 'follow', 'payload' => ['target_player_user_id' => $user]], $now, true, false, true);
invite_expect(($follow['task_status'] ?? '') === 'running' && ($follow['action'] ?? '') === 'follow', '玩家邀请跟随应创建运行中的任务。');
$restStatus = $pdo->query("SELECT status,failure_reason FROM town_npc_tasks WHERE task_id='{$restTask}'")->fetch(PDO::FETCH_ASSOC);
invite_expect(($restStatus['status'] ?? '') === 'cancelled' && ($restStatus['failure_reason'] ?? '') === 'player_invitation', '玩家邀请应能中断休息任务。');
$pdo->prepare('UPDATE town_presence SET tile_x=14,tile_y=10,expires_at=? WHERE user_id=?')->execute([auth_sql_time($now->modify('+30 seconds')), $user]);
$replanned = town_npc_advance_task($pdo, $npc, $now->modify('+1 second'), true);
$replanned = town_npc_advance_task($pdo, $npc, $now->modify('+2 seconds'), true);
invite_expect(!empty($replanned['route_replanned']) && ($replanned['action'] ?? '') === 'follow', '玩家移动后跟随任务应重新规划。');
try { town_npc_cancel_player_invitation($pdo, $npc, $otherUser, $now->modify('+2 seconds')); throw new RuntimeException('Other players must not cancel someone else\'s invitation.'); }
catch (ApiError $error) { invite_expect($error->apiCode === 'npc_invitation_forbidden', 'Other players must receive npc_invitation_forbidden.'); }
$cancelled = town_npc_cancel_player_invitation($pdo, $npc, $user, $now->modify('+2 seconds'));
invite_expect(($cancelled['task_status'] ?? '') === 'cancelled' && ($cancelled['state'] ?? '') === 'rest', '玩家应能结束自己发起的跟随。');

$pdo->prepare('UPDATE town_presence SET tile_x=12,tile_y=10,expires_at=? WHERE user_id=?')->execute([auth_sql_time($now->modify('+300 seconds')), $user]);
$talk = town_npc_apply_action($pdo, ['npc_id' => $npc, 'action' => 'player_talk', 'payload' => ['target_player_user_id' => $user, 'dialogue' => '你好，今天一起散步吗？']], $now->modify('+3 seconds'), true);
invite_expect(str_contains((string)($talk['dialogue'] ?? ''), '你好'), 'Initial player invitation must return the NPC reply text for immediate rendering.');
$arrived = town_npc_advance_task($pdo, $npc, $now->modify('+4 seconds'), true);
invite_expect(($arrived['task_status'] ?? '') === 'running' && ($arrived['state'] ?? '') === 'player_talk', '到达玩家旁边后应进入玩家交谈状态。');
$continued = town_npc_continue_player_talk($pdo, $npc, $user, '我想问问今天的天气。', $now->modify('+5 seconds'));
invite_expect(($continued['continued'] ?? false) === true && ($continued['state'] ?? '') === 'player_talk' && str_contains((string)($continued['dialogue'] ?? ''), '天气'), '面对面交谈期间可以连续发送下一句并收到 NPC 回复。');
$dialogue = $pdo->query('SELECT dialogue FROM town_npc_runtime_states WHERE npc_id=' . $pdo->quote($npc))->fetchColumn();
invite_expect(str_contains((string)$dialogue, '天气'), 'NPC 气泡应显示 NPC 回复而不是交谈状态文字。');
$done = town_npc_advance_task($pdo, $npc, $now->modify('+65 seconds'), true);
invite_expect(($done['task_status'] ?? '') === 'completed', '玩家交谈应在一分钟后完成。');

$talk = town_npc_apply_action($pdo, ['npc_id' => $npc, 'action' => 'player_talk', 'payload' => ['target_player_user_id' => $user]], $now->modify('+70 seconds'), true);
$pdo->prepare('UPDATE town_presence SET tile_x=30,tile_y=30,expires_at=? WHERE user_id=?')->execute([auth_sql_time($now->modify('+100 seconds')), $user]);
$missing = town_npc_advance_task($pdo, $npc, $now->modify('+71 seconds'), true);
invite_expect(($missing['task_status'] ?? '') === 'failed' && ($missing['failure_reason'] ?? '') === 'player_target_missing', '玩家离开后交谈任务应失败。');

echo "town_npc_player_invite_test: {$checks} checks ok\n";
