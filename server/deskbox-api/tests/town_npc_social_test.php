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
CREATE TABLE town_npc_runtime_states (npc_id TEXT PRIMARY KEY,tile_x INTEGER,tile_y INTEGER,state TEXT,current_task_id TEXT,dialogue TEXT,mood_json TEXT,capabilities_json TEXT,state_version INTEGER,updated_at TEXT,facing TEXT);
CREATE TABLE town_npc_tasks (task_id TEXT PRIMARY KEY,npc_id TEXT,action TEXT,target_id TEXT,payload_json TEXT,status TEXT,attempts INTEGER,scheduled_at TEXT,started_at TEXT,completed_at TEXT,failure_reason TEXT);
CREATE TABLE town_npc_events (event_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,event_type TEXT,payload_json TEXT,created_at TEXT);
CREATE TABLE town_npc_activity_logs (log_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,run_id TEXT,log_type TEXT,summary TEXT,detail_json TEXT,created_at TEXT);
CREATE TABLE town_npc_relationships (npc_id TEXT,other_npc_id TEXT,affinity INTEGER,relationship_json TEXT,updated_at TEXT,PRIMARY KEY(npc_id,other_npc_id));
CREATE TABLE town_npc_memories (memory_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,subject_npc_id TEXT,asset_id TEXT,asset_name TEXT,tile_x INTEGER,tile_y INTEGER,navigation_version INTEGER,memory_type TEXT,content TEXT,importance INTEGER,created_at TEXT,expires_at TEXT);
CREATE TABLE town_npc_schedules (schedule_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,weekday INTEGER,start_minute INTEGER,end_minute INTEGER,action TEXT,target_id TEXT,job TEXT,priority INTEGER);
CREATE TABLE town_npc_inventory (npc_id TEXT,item_id TEXT,quantity INTEGER,updated_at TEXT,PRIMARY KEY(npc_id,item_id));
CREATE TABLE town_npc_wallets (npc_id TEXT PRIMARY KEY,balance INTEGER,initialized_by TEXT,updated_at TEXT);
CREATE TABLE town_npc_transactions (transaction_id TEXT PRIMARY KEY,seller_npc_id TEXT,buyer_npc_id TEXT,item_id TEXT,quantity INTEGER,amount INTEGER,reason TEXT,created_at TEXT);
CREATE TABLE town_npc_wallet_entries (entry_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,entry_type TEXT,delta INTEGER,balance_after INTEGER,transaction_id TEXT,reason TEXT,created_at TEXT);
CREATE TABLE town_objects (object_id TEXT PRIMARY KEY,world_id TEXT,asset_id TEXT,owner_user_id TEXT,chunk_x INTEGER,chunk_y INTEGER,tile_x INTEGER,tile_y INTEGER,footprint_json TEXT,layer INTEGER,object_version INTEGER,created_at TEXT,updated_at TEXT,deleted_at TEXT);
CREATE TABLE town_asset_items (asset_id TEXT PRIMARY KEY,name TEXT,category TEXT,navigation_json TEXT);");
$pdo->prepare('INSERT INTO town_worlds VALUES (?,?,?,?)')->execute(['main', 1, 1, $stamp]);
$insertNpc = $pdo->prepare('INSERT INTO town_npcs VALUES (?,?,?,?,?,?,?,?,?,?)');
$insertNpc->execute([$alice, '甲', 'main', '', '{}', 'farmer', 1, 0, $stamp, $stamp]);
$insertNpc->execute([$bob, '乙', 'main', '', '{}', 'merchant', 1, 0, $stamp, $stamp]);
$runtime = $pdo->prepare('INSERT INTO town_npc_runtime_states VALUES (?,?,?,?,?,?,?,?,?,?,?)');
$runtime->execute([$alice, 10, 10, 'rest', null, '', '{}', '[]', 1, $stamp, null]);
$runtime->execute([$bob, 12, 10, 'rest', null, '', '{}', '[]', 1, $stamp, null]);

$started = town_npc_apply_action($pdo, [
    'npc_id' => $alice, 'action' => 'talk',
    'payload' => ['target_id' => $bob, 'dialogue' => '今天一起整理集市吧。', 'duration_minutes' => 0],
], $now, true);
social_expect(count($started['route'] ?? []) === 2, 'Talk should route to an adjacent interaction tile.');
social_expect(($started['to']['tile_x'] ?? null) === 11 && ($started['to']['tile_y'] ?? null) === 10, 'Talk route should stop next to the target NPC.');
social_expect(($started['target_tile']['tile_x'] ?? null) === 12 && ($started['target_tile']['tile_y'] ?? null) === 10, 'Talk should retain the target NPC tile separately from the approach tile.');
social_expect(($started['facing'] ?? '') === 'right', 'Talk should record the initiator facing the target.');
$first = town_npc_advance_task($pdo, $alice, $now->modify('+1 second'), true);
social_expect(($first['task_status'] ?? '') === 'running', 'Talk should enter its interaction state before completion.');
$second = town_npc_advance_task($pdo, $alice, $now->modify('+2 seconds'), true);
social_expect(($second['task_status'] ?? '') === 'completed', 'Talk should complete when the initiator reaches the adjacent interaction tile.');
$done = town_npc_advance_task($pdo, $alice, $now->modify('+3 seconds'), true);
social_expect(($done['task_status'] ?? '') === 'none', 'Completed talk should leave the NPC idle.');
$relationship = $pdo->query('SELECT affinity,relationship_json FROM town_npc_relationships WHERE npc_id=' . $pdo->quote($alice) . ' AND other_npc_id=' . $pdo->quote($bob))->fetch(PDO::FETCH_ASSOC);
$reverse = $pdo->query('SELECT affinity FROM town_npc_relationships WHERE npc_id=' . $pdo->quote($bob) . ' AND other_npc_id=' . $pdo->quote($alice))->fetch(PDO::FETCH_ASSOC);
$memories = (int)$pdo->query('SELECT COUNT(*) FROM town_npc_memories')->fetchColumn();
$events = (int)$pdo->query("SELECT COUNT(*) FROM town_npc_events WHERE event_type='conversation'")->fetchColumn();
social_expect(is_array($relationship) && (int)$relationship['affinity'] === 1, 'Talk should increase the source relationship affinity.');
social_expect(is_array($reverse) && (int)$reverse['affinity'] === 1, 'Talk should increase the reciprocal relationship affinity.');
social_expect($memories === 2 && $events === 1, 'Talk should create one durable memory per NPC and one conversation event.');
$spatialMemory = $pdo->query('SELECT subject_npc_id,tile_x,tile_y,navigation_version FROM town_npc_memories WHERE npc_id=' . $pdo->quote($alice) . ' AND memory_type=\'conversation\' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
social_expect(is_array($spatialMemory) && ($spatialMemory['subject_npc_id'] ?? '') === $bob && (int)($spatialMemory['tile_x'] ?? -1) === 11 && (int)($spatialMemory['tile_y'] ?? -1) === 10 && (int)($spatialMemory['navigation_version'] ?? -1) === 1, 'Conversation memory should retain counterpart, location and navigation version.');
$missingStart = town_npc_apply_action($pdo, ['npc_id' => $alice, 'action' => 'talk', 'payload' => ['target_id' => $bob, 'dialogue' => '我来找你。', 'duration_minutes' => 0]], $now->modify('+100 seconds'), true);
$pdo->prepare('UPDATE town_npc_runtime_states SET tile_x=15,tile_y=10,state=\'rest\',current_task_id=NULL WHERE npc_id=?')->execute([$bob]);
$missing = town_npc_advance_task($pdo, $alice, $now->modify('+101 seconds'), true);
$missingMemory = $pdo->query('SELECT subject_npc_id,tile_x,tile_y FROM town_npc_memories WHERE npc_id=' . $pdo->quote($alice) . ' AND memory_type=\'conversation_target_missing\' ORDER BY memory_id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
social_expect(($missing['task_status'] ?? '') === 'failed' && ($missing['failure_reason'] ?? '') === 'conversation_target_missing' && is_array($missingMemory) && ($missingMemory['subject_npc_id'] ?? '') === $bob && (int)($missingMemory['tile_x'] ?? -1) === 12, 'A moved-away target should produce a missing-target memory and fail the conversation task.');
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

// Trade is a routed NPC action: the server moves the initiator to an adjacent
// interaction tile, rechecks the target, then settles inventory and wallets in
// the same transaction as task completion.
$pdo->prepare('INSERT INTO town_npc_wallets VALUES (?,?,?,?)')->execute([$alice, 100, null, $stamp]);
$pdo->prepare('INSERT INTO town_npc_wallets VALUES (?,?,?,?)')->execute([$bob, 50, null, $stamp]);
$pdo->prepare('INSERT INTO town_npc_inventory VALUES (?,?,?,?)')->execute([$alice, 'seed', 2, $stamp]);
$pdo->prepare('INSERT INTO town_npc_inventory VALUES (?,?,?,?)')->execute([$bob, 'seed', 0, $stamp]);
$pdo->prepare('UPDATE town_npc_runtime_states SET tile_x=12,tile_y=10,state=\'rest\',current_task_id=NULL WHERE npc_id=?')->execute([$bob]);
$tradeStart = town_npc_apply_action($pdo, ['npc_id' => $alice, 'action' => 'trade', 'payload' => [
    'target_id' => $bob, 'target_npc_id' => $bob, 'duration_minutes' => 0,
    'trade' => ['seller_npc_id' => $alice, 'buyer_npc_id' => $bob, 'item_id' => 'seed', 'quantity' => 1, 'amount' => 10, 'reason' => '测试交易'],
]], $now->modify('+200 seconds'), true);
social_expect(($tradeStart['action'] ?? '') === 'trade' && count($tradeStart['route'] ?? []) >= 1 && ($tradeStart['target_tile']['tile_x'] ?? null) === 12, 'Trade should route to an adjacent interaction tile.');
$tradeResult = null;
for ($step = 1; $step <= 5; $step++) {
    $tradeResult = town_npc_advance_task($pdo, $alice, $now->modify('+' . (200 + $step) . ' seconds'), true);
    if (($tradeResult['task_status'] ?? '') === 'completed') break;
}
social_expect(($tradeResult['task_status'] ?? '') === 'completed' && is_array($tradeResult['trade'] ?? null), 'Trade should settle after reaching the interaction tile.');
$aliceBalance = (int)$pdo->query('SELECT balance FROM town_npc_wallets WHERE npc_id=' . $pdo->quote($alice))->fetchColumn();
$bobBalance = (int)$pdo->query('SELECT balance FROM town_npc_wallets WHERE npc_id=' . $pdo->quote($bob))->fetchColumn();
$aliceSeed = (int)$pdo->query('SELECT quantity FROM town_npc_inventory WHERE npc_id=' . $pdo->quote($alice) . " AND item_id='seed'")->fetchColumn();
$bobSeed = (int)$pdo->query('SELECT quantity FROM town_npc_inventory WHERE npc_id=' . $pdo->quote($bob) . " AND item_id='seed'")->fetchColumn();
$tradeEvents = (int)$pdo->query("SELECT COUNT(*) FROM town_npc_events WHERE event_type='trade'")->fetchColumn();
social_expect($aliceBalance === 110 && $bobBalance === 40 && $aliceSeed === 1 && $bobSeed === 1 && $tradeEvents === 2, 'Trade should atomically transfer the item, wallet amount and counterpart event.');

// A ledger failure after inventory and wallet statements must roll back the
// entire routed action, while still allowing the task itself to be marked
// failed. This exercises the SAVEPOINT in town_npc_advance_task().
$pdo->exec("CREATE TRIGGER fail_trade_ledger BEFORE INSERT ON town_npc_wallet_entries WHEN NEW.entry_type='trade_expense' BEGIN SELECT RAISE(ABORT, 'forced ledger failure'); END");
$failureStart = town_npc_apply_action($pdo, ['npc_id' => $alice, 'action' => 'trade', 'payload' => [
    'target_id' => $bob, 'target_npc_id' => $bob, 'duration_minutes' => 0,
    'trade' => ['seller_npc_id' => $alice, 'buyer_npc_id' => $bob, 'item_id' => 'seed', 'quantity' => 1, 'amount' => 10, 'reason' => '回滚测试'],
]], $now->modify('+300 seconds'), true);
$failureResult = town_npc_advance_task($pdo, $alice, $now->modify('+301 seconds'), true);
if (($failureResult['task_status'] ?? '') !== 'failed') $failureResult = town_npc_advance_task($pdo, $alice, $now->modify('+302 seconds'), true);
social_expect(($failureResult['task_status'] ?? '') === 'failed' && ($failureResult['failure_reason'] ?? '') === 'trade_failed', 'A ledger exception should fail the trade task.');
$aliceBalanceAfterFailure = (int)$pdo->query('SELECT balance FROM town_npc_wallets WHERE npc_id=' . $pdo->quote($alice))->fetchColumn();
$bobBalanceAfterFailure = (int)$pdo->query('SELECT balance FROM town_npc_wallets WHERE npc_id=' . $pdo->quote($bob))->fetchColumn();
$aliceSeedAfterFailure = (int)$pdo->query('SELECT quantity FROM town_npc_inventory WHERE npc_id=' . $pdo->quote($alice) . " AND item_id='seed'")->fetchColumn();
$bobSeedAfterFailure = (int)$pdo->query('SELECT quantity FROM town_npc_inventory WHERE npc_id=' . $pdo->quote($bob) . " AND item_id='seed'")->fetchColumn();
$failedTransactions = (int)$pdo->query("SELECT COUNT(*) FROM town_npc_transactions WHERE reason='回滚测试'")->fetchColumn();
social_expect($aliceBalanceAfterFailure === 110 && $bobBalanceAfterFailure === 40 && $aliceSeedAfterFailure === 1 && $bobSeedAfterFailure === 1 && $failedTransactions === 0, 'Failed trade must not leave partial balances, inventory, or transaction rows.');
$pdo->exec('DROP TRIGGER fail_trade_ledger');

// Plans without the optional steps array still validate trade fields.
town_npc_validate_plan_envelope(['trade' => ['item_id' => 'seed', 'quantity' => 1, 'amount' => 10]], 'trade', []);
try {
    town_npc_validate_plan_envelope(['trade' => ['item_id' => 'seed', 'quantity' => 0, 'amount' => 10]], 'trade', []);
    throw new RuntimeException('Expected invalid trade envelope.');
} catch (ApiError $error) { social_expect($error->apiCode === 'invalid_npc_trade', 'Trade envelope without steps should reject invalid quantities.'); }

$originalTimezone = date_default_timezone_get();
try {
    foreach (['Asia/Shanghai', 'America/New_York', 'UTC'] as $index => $timezone) {
        date_default_timezone_set($timezone);
        $startTime = $now->modify('+' . (400 + $index * 200) . ' seconds');
        town_npc_apply_action($pdo, ['npc_id' => $alice, 'action' => 'talk', 'payload' => [
            'target_id' => $bob, 'dialogue' => '我们聊一分钟。', 'duration_minutes' => 1,
        ]], $startTime, true);
        $arrived = town_npc_advance_task($pdo, $alice, $startTime->modify('+1 second'), true);
        social_expect($arrived['task_status'] === 'running' && $arrived['state'] === 'talk', 'Timed conversation should enter talk on arrival.');
        $waiting = town_npc_advance_task($pdo, $alice, $startTime->modify('+60 seconds'), true);
        $dialogue = $pdo->query('SELECT dialogue FROM town_npc_runtime_states WHERE npc_id=' . $pdo->quote($alice))->fetchColumn();
        social_expect($waiting['task_status'] === 'running' && $waiting['remaining_seconds'] === 1 && $dialogue === '我们聊一分钟。', 'Conversation text must remain available for its full duration in ' . $timezone . '.');
        $completed = town_npc_advance_task($pdo, $alice, $startTime->modify('+61 seconds'), true);
        $dialogue = $pdo->query('SELECT dialogue FROM town_npc_runtime_states WHERE npc_id=' . $pdo->quote($alice))->fetchColumn();
        social_expect($completed['task_status'] === 'completed' && $dialogue === '', 'Conversation must finish exactly at its UTC deadline and clear the text in ' . $timezone . '.');
    }
} finally {
    date_default_timezone_set($originalTimezone);
}

echo "town_npc_social_test: checks ok\n";
