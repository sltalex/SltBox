<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/shared_town.php';
require dirname(__DIR__) . '/api/_app/town_navigation.php';
require dirname(__DIR__) . '/api/_app/town_npcs.php';

function social_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = api_clock();
$stamp = auth_sql_time($now);
$alice = str_repeat('a', 32);
$bob = str_repeat('b', 32);
$pdo->exec("CREATE TABLE town_worlds (world_id TEXT PRIMARY KEY,navigation_version INTEGER,world_version INTEGER DEFAULT 1,updated_at TEXT);
CREATE TABLE town_npcs (npc_id TEXT PRIMARY KEY,name TEXT,world_id TEXT,avatar_asset_id TEXT,appearance_json TEXT,occupation TEXT,enabled INTEGER,agent_paused INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE town_npc_runtime_states (npc_id TEXT PRIMARY KEY,tile_x INTEGER,tile_y INTEGER,state TEXT,current_task_id TEXT,dialogue TEXT,mood_json TEXT,capabilities_json TEXT,state_version INTEGER,updated_at TEXT);
CREATE TABLE town_npc_tasks (task_id TEXT PRIMARY KEY,npc_id TEXT,action TEXT,target_id TEXT,payload_json TEXT,status TEXT,attempts INTEGER,scheduled_at TEXT,started_at TEXT,completed_at TEXT,failure_reason TEXT);
CREATE TABLE town_npc_events (event_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,event_type TEXT,payload_json TEXT,created_at TEXT);
CREATE TABLE town_npc_activity_logs (log_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,run_id TEXT,log_type TEXT,summary TEXT,detail_json TEXT,created_at TEXT);
CREATE TABLE town_npc_relationships (npc_id TEXT,other_npc_id TEXT,affinity INTEGER,relationship_json TEXT,updated_at TEXT,PRIMARY KEY(npc_id,other_npc_id));
CREATE TABLE town_npc_memories (memory_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,memory_type TEXT,content TEXT,importance INTEGER,created_at TEXT,expires_at TEXT);
CREATE TABLE town_npc_schedules (schedule_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,weekday INTEGER,start_minute INTEGER,end_minute INTEGER,action TEXT,target_id TEXT,job TEXT,priority INTEGER);
CREATE TABLE town_npc_inventory (npc_id TEXT,item_id TEXT,quantity INTEGER,updated_at TEXT,PRIMARY KEY(npc_id,item_id));
CREATE TABLE town_objects (object_id TEXT PRIMARY KEY,world_id TEXT,asset_id TEXT,owner_user_id TEXT,chunk_x INTEGER,chunk_y INTEGER,tile_x INTEGER,tile_y INTEGER,footprint_json TEXT,layer INTEGER,object_version INTEGER,created_at TEXT,updated_at TEXT,deleted_at TEXT);
CREATE TABLE town_asset_items (asset_id TEXT PRIMARY KEY,navigation_json TEXT);");
$pdo->prepare('INSERT INTO town_worlds VALUES (?,?,?,?)')->execute(['main', 1, 1, $stamp]);
$insertNpc = $pdo->prepare('INSERT INTO town_npcs VALUES (?,?,?,?,?,?,?,?,?,?)');
$insertNpc->execute([$alice, '甲', 'main', '', '{}', 'farmer', 1, 0, $stamp, $stamp]);
$insertNpc->execute([$bob, '乙', 'main', '', '{}', 'merchant', 1, 0, $stamp, $stamp]);
$runtime = $pdo->prepare('INSERT INTO town_npc_runtime_states VALUES (?,?,?,?,?,?,?,?,?,?)');
$runtime->execute([$alice, 10, 10, 'rest', null, '', '{}', '[]', 1, $stamp]);
$runtime->execute([$bob, 12, 10, 'rest', null, '', '{}', '[]', 1, $stamp]);

$started = town_npc_apply_action($pdo, [
    'npc_id' => $alice, 'action' => 'talk',
    'payload' => ['target_id' => $bob, 'dialogue' => '今天一起整理集市吧。', 'duration_minutes' => 0],
], $now, true);
social_expect(count($started['route'] ?? []) === 3, 'Talk should route to the target NPC position.');
social_expect(($started['to']['tile_x'] ?? null) === 12 && ($started['to']['tile_y'] ?? null) === 10, 'Talk target position should come from the target NPC runtime state.');
$first = town_npc_advance_task($pdo, $alice, $now->modify('+1 second'), true);
social_expect(($first['task_status'] ?? '') === 'running', 'Talk should enter its interaction state before completion.');
$second = town_npc_advance_task($pdo, $alice, $now->modify('+2 seconds'), true);
social_expect(($second['task_status'] ?? '') === 'running', 'Talk should arrive at the target before completing.');
$done = town_npc_advance_task($pdo, $alice, $now->modify('+3 seconds'), true);
social_expect(($done['task_status'] ?? '') === 'completed', 'Talk should complete after its configured duration.');
$relationship = $pdo->query('SELECT affinity,relationship_json FROM town_npc_relationships WHERE npc_id=' . $pdo->quote($alice) . ' AND other_npc_id=' . $pdo->quote($bob))->fetch(PDO::FETCH_ASSOC);
$reverse = $pdo->query('SELECT affinity FROM town_npc_relationships WHERE npc_id=' . $pdo->quote($bob) . ' AND other_npc_id=' . $pdo->quote($alice))->fetch(PDO::FETCH_ASSOC);
$memories = (int)$pdo->query('SELECT COUNT(*) FROM town_npc_memories')->fetchColumn();
$events = (int)$pdo->query("SELECT COUNT(*) FROM town_npc_events WHERE event_type='conversation'")->fetchColumn();
social_expect(is_array($relationship) && (int)$relationship['affinity'] === 1, 'Talk should increase the source relationship affinity.');
social_expect(is_array($reverse) && (int)$reverse['affinity'] === 1, 'Talk should increase the reciprocal relationship affinity.');
social_expect($memories === 2 && $events === 1, 'Talk should create one durable memory per NPC and one conversation event.');
$relationshipRows = town_npc_relationship_rows($pdo, $alice);
$memoryRows = town_npc_memory_rows($pdo, $bob);
social_expect(count($relationshipRows) === 2 && (int)$relationshipRows[0]['affinity'] === 1, 'Relationship inspection should return both directed rows.');
social_expect(count($memoryRows) === 1 && ($memoryRows[0]['memory_type'] ?? '') === 'conversation', 'Memory inspection should return durable conversation details.');
$filteredRelationships = town_npc_relationship_rows($pdo, $alice, 1, 1);
$filteredMemories = town_npc_memory_rows($pdo, $bob, 1, 0);
social_expect(count($filteredRelationships) === 1 && count($filteredMemories) === 1, 'Relationship and memory inspection should support bounded filtering and pagination.');
try {
    town_npc_apply_action($pdo, [
        'npc_id' => $alice, 'action' => 'talk',
        'payload' => ['target_id' => $bob, 'dialogue' => '重复打扰。', 'duration_minutes' => 0],
    ], $now->modify('+10 seconds'), true);
    throw new RuntimeException('Expected talk cooldown.');
} catch (ApiError $error) { social_expect($error->apiCode === 'talk_cooldown', 'Repeated talk should be rate limited server-side.'); }
town_npc_save_relationship($pdo, $alice, $bob, 5, $now->modify('+4 seconds'));
$edited = town_npc_relationship_rows($pdo, $alice);
social_expect(count($edited) === 2 && (int)$edited[0]['affinity'] === 5 && (int)$edited[1]['affinity'] === 5, 'Admin relationship save should update both directions.');
$memoryInsert = $pdo->prepare('INSERT INTO town_npc_memories (npc_id,memory_type,content,importance,created_at) VALUES (?,?,?,?,?)');
for ($index = 0; $index < 25; $index++) $memoryInsert->execute([$alice, 'conversation', '历史交谈 #' . $index, 2, auth_sql_time($now->modify('+' . ($index + 10) . ' seconds'))]);
town_npc_compact_memories($pdo, $alice, $now->modify('+40 seconds'));
$rawCount = (int)$pdo->query('SELECT COUNT(*) FROM town_npc_memories WHERE npc_id=' . $pdo->quote($alice) . ' AND memory_type=\'conversation\'')->fetchColumn();
$summaryCount = (int)$pdo->query('SELECT COUNT(*) FROM town_npc_memories WHERE npc_id=' . $pdo->quote($alice) . ' AND memory_type=\'conversation_summary\'')->fetchColumn();
$agentContext = town_npc_agent_context($pdo, $alice, $now->modify('+40 seconds'));
social_expect($rawCount === 12 && $summaryCount === 1, 'Conversation compaction should keep the newest raw memories and one summary.');
social_expect(count(array_filter($agentContext['memories'] ?? [], static fn(array $memory): bool => ($memory['memory_type'] ?? '') === 'conversation_summary')) === 1, 'Agent context should include the deterministic conversation summary.');
$moderated = town_npc_moderate_dialogue("你好\x00，<script>alert(1)</script>");
social_expect(($moderated['allowed'] ?? true) === false && ($moderated['reason'] ?? '') === 'unsafe_markup', 'Dialogue moderation should reject executable markup.');

echo "town_npc_social_test: checks ok\n";
