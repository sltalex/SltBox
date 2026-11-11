<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/account_data.php';
require dirname(__DIR__) . '/api/_app/shared_town.php';
require dirname(__DIR__) . '/api/_app/town_navigation.php';
require dirname(__DIR__) . '/api/_app/town_npcs.php';
require dirname(__DIR__) . '/api/_app/town_agent.php';

$checks = 0; function npc_expect(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = new DateTimeImmutable('2026-11-10T10:00:00Z'); $stamp = auth_sql_time($now);
$pdo->exec("CREATE TABLE town_worlds (world_id TEXT PRIMARY KEY,world_version INTEGER,terrain_version INTEGER,map_width INTEGER,map_height INTEGER,tile_size INTEGER,chunk_size INTEGER,change_cursor INTEGER,navigation_version INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE town_objects (object_id TEXT PRIMARY KEY,world_id TEXT,asset_id TEXT,owner_user_id TEXT,chunk_x INTEGER,chunk_y INTEGER,tile_x INTEGER,tile_y INTEGER,footprint_json TEXT,layer INTEGER,object_version INTEGER,deleted_at TEXT);
CREATE TABLE town_asset_items (asset_id TEXT PRIMARY KEY,navigation_json TEXT);
CREATE TABLE town_npcs (npc_id TEXT PRIMARY KEY,world_id TEXT,name TEXT,avatar_asset_id TEXT,appearance_json TEXT,occupation TEXT,enabled INTEGER,agent_paused INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE town_npc_profiles (npc_id TEXT PRIMARY KEY,personality_json TEXT,family_background TEXT,short_term_goal TEXT,long_term_goal TEXT,speech_style TEXT,values_json TEXT,fears_json TEXT);
CREATE TABLE town_npc_runtime_states (npc_id TEXT PRIMARY KEY,tile_x INTEGER,tile_y INTEGER,state TEXT,current_task_id TEXT,dialogue TEXT,mood_json TEXT,capabilities_json TEXT,state_version INTEGER,updated_at TEXT);
CREATE TABLE town_npc_events (event_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,event_type TEXT,payload_json TEXT,created_at TEXT);
CREATE TABLE town_npc_tasks (task_id TEXT PRIMARY KEY,npc_id TEXT,action TEXT,target_id TEXT,payload_json TEXT,status TEXT,attempts INTEGER,scheduled_at TEXT,started_at TEXT,completed_at TEXT,failure_reason TEXT);
CREATE TABLE town_agent_leases (lease_name TEXT PRIMARY KEY,holder_id TEXT,lease_token TEXT,expires_at TEXT,updated_at TEXT);");
$pdo->prepare('INSERT INTO town_worlds VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute(['main',1,1,768,768,16,24,0,1,$stamp,$stamp]);
$npc = str_repeat('a', 32); $pdo->prepare('INSERT INTO town_npcs VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$npc,'main','测试村民','', '{}','farmer',1,0,$stamp,$stamp]);
$pdo->prepare('INSERT INTO town_npc_runtime_states VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$npc,90,90,'rest',null,'','{}','["ladder"]',1,$stamp]);
$pdo->prepare('INSERT INTO town_npc_events (npc_id,event_type,payload_json,created_at) VALUES (?,?,?,?)')->execute([$npc,'hello','{}',$stamp]);
npc_expect(town_navigation_can_traverse($pdo, 90, 90)['allowed'] === true, 'Plain ground should be traversable.');
npc_expect(town_navigation_can_traverse($pdo, 64, 20)['allowed'] === false, 'River should be blocked without bridge kit.');
npc_expect(town_navigation_can_traverse($pdo, 64, 20, ['bridge_kit'])['allowed'] === true, 'River should accept bridge kit.');
$route = town_navigation_find_route($pdo, 63, 92, 76, 92, ['bridge_kit']); npc_expect($route['found'] === true, 'Bridge route should be found.');
$blocked = town_navigation_find_route($pdo, 63, 20, 76, 20); npc_expect($blocked['found'] === false && $blocked['reason'] !== 'ok', 'River route must fail without capability.');
$edge = town_navigation_find_route($pdo, 23, 23, 25, 25); npc_expect($edge['found'] === true && count($edge['path']) === 5, 'A route crossing the 24x24 chunk boundary should remain connected.');
$edgeCell = town_navigation_can_traverse($pdo, 767, 767); npc_expect($edgeCell['allowed'] === true, 'The final in-bounds map cell should be traversable.');
$outside = town_navigation_can_traverse($pdo, 768, 767); npc_expect($outside['allowed'] === false && $outside['reason'] === 'blocked', 'A cell beyond the 768x768 map edge must be rejected.');
$outsideRoute = town_navigation_find_route($pdo, 767, 767, 768, 767); npc_expect($outsideRoute['found'] === false, 'A route whose goal is outside the map must be rejected.');
$pdo->prepare('INSERT INTO town_asset_items VALUES (?,?)')->execute(['wall-cache-test', '{"type":"blocked","blocked_npc":true}']);
$pdo->prepare('INSERT INTO town_objects VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute(['wall-cache-object','main','wall-cache-test','owner',3,3,90,90,'{"width":1,"height":1}',1,1,null]);
npc_expect(town_navigation_can_traverse($pdo, 90, 90)['allowed'] === true, 'A changed object must remain cached until navigation rebuild.');
town_navigation_rebuild($pdo, $now->modify('+1 second'));
npc_expect(town_navigation_can_traverse($pdo, 90, 90)['allowed'] === false, 'Navigation rebuild must invalidate the process cache.');
$stairs = town_navigation_normalize_semantics(['type' => 'stairs', 'connections' => ['north', 'south']]);
npc_expect(town_navigation_connections_allow($stairs, 0, -1) && town_navigation_connections_allow($stairs, 0, 1), 'Configured stairs should allow both declared vertical directions.');
npc_expect(!town_navigation_connections_allow($stairs, 1, 0), 'Configured stairs should reject undeclared horizontal directions.');
$snapshot = town_npc_snapshot($pdo); npc_expect(count($snapshot['npcs']) === 1 && $snapshot['navigation_version'] === 2, 'NPC snapshot should expose the rebuilt navigation version.');
$plainNpc = town_npc_upsert($pdo, ['name' => '可通行初始位置', 'occupation' => 'farmer', 'tile_x' => 91, 'tile_y' => 91], $now);
npc_expect(($plainNpc['runtime']['tile_x'] ?? null) === 91 && ($plainNpc['runtime']['tile_y'] ?? null) === 91, 'A new NPC should accept an in-bounds traversable initial position.');
try {
    town_npc_upsert($pdo, ['name' => '越界初始位置', 'occupation' => 'farmer', 'tile_x' => 768, 'tile_y' => 767], $now);
    throw new RuntimeException('Expected an out-of-bounds initial position rejection.');
} catch (ApiError $error) { npc_expect($error->apiCode === 'invalid_npc_position', 'A new NPC outside the map must be rejected.'); }
try {
    town_npc_upsert($pdo, ['name' => '阻挡初始位置', 'occupation' => 'farmer', 'tile_x' => 90, 'tile_y' => 90], $now);
    throw new RuntimeException('Expected a blocked initial position rejection.');
} catch (ApiError $error) { npc_expect($error->apiCode === 'invalid_npc_position', 'A new NPC on a blocked tile must be rejected.'); }
$npcCount = (int)$pdo->query('SELECT COUNT(*) FROM town_npcs')->fetchColumn();
npc_expect($npcCount === 2, 'Rejected NPC positions must not leave partially inserted NPC rows.');
$lease = town_agent_lease($pdo, 'worker-a', false, null, $now); npc_expect($lease['ttl_seconds'] === 30, 'Lease should have a 30 second TTL.');
$sameWorkerLease = town_agent_lease($pdo, 'worker-a', false, null, $now->modify('+5 seconds')); npc_expect($sameWorkerLease['lease_token'] === $lease['lease_token'], 'Re-acquiring a live lease for the same worker must not rotate its token.');
try { town_agent_lease($pdo, 'worker-b', false, null, $now); throw new RuntimeException('Expected lease conflict.'); } catch (ApiError $error) { npc_expect($error->apiCode === 'lease_busy', 'Second worker must not acquire a live lease.'); }
$changes = town_npc_changes($pdo, 0); npc_expect(count($changes['events']) === 1, 'NPC change feed should return events.');
town_npc_validate_plan_envelope([
    'required_capabilities' => ['bridge_kit'],
    'steps' => [['action' => 'walk', 'target' => 'river-crossing'], ['action' => 'work', 'job' => 'repair_bridge']],
], 'work', ['bridge_kit']);
npc_expect(true, 'A bounded walk-then-work plan should be accepted.');
try {
    town_npc_validate_plan_envelope(['steps' => [['action' => 'work'], ['action' => 'walk', 'target' => 'late']]], 'walk', []);
    throw new RuntimeException('Expected terminal-step ordering rejection.');
} catch (ApiError $error) { npc_expect($error->apiCode === 'invalid_npc_plan', 'A walk after a terminal work step must be rejected.'); }
try {
    town_npc_validate_plan_envelope(['required_capabilities' => ['teleport'], 'steps' => [['action' => 'rest']]], 'rest', []);
    throw new RuntimeException('Expected unsupported capability rejection.');
} catch (ApiError $error) { npc_expect($error->apiCode === 'invalid_npc_plan', 'Unsupported capabilities must be rejected.'); }
echo "town_npc_navigation_test: {$checks} checks ok\n";
