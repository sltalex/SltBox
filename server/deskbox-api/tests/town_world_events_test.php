<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/town_world_events.php';

$checks = 0;
function event_expect(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE town_world_events (event_id INTEGER PRIMARY KEY AUTOINCREMENT,world_id TEXT,source_key TEXT,event_type TEXT,actor_npc_id TEXT,target_npc_id TEXT,public_payload_json TEXT,visibility TEXT,occurred_at TEXT,expires_at TEXT,created_at TEXT,UNIQUE(world_id,source_key))');
$pdo->exec('CREATE TABLE town_world_event_receipts (event_id INTEGER,viewer_id TEXT,state TEXT,read_at TEXT,updated_at TEXT,PRIMARY KEY(event_id,viewer_id))');
$pdo->exec('CREATE TABLE town_interest_points (interest_id TEXT PRIMARY KEY,world_id TEXT,kind TEXT,asset_id TEXT,object_id TEXT,tile_x INTEGER,tile_y INTEGER,radius INTEGER,perception_channels_json TEXT,activity_type TEXT,public_name TEXT,enabled INTEGER,updated_at TEXT)');
$pdo->exec('CREATE TABLE town_worlds (world_id TEXT PRIMARY KEY,navigation_version INTEGER DEFAULT 1)');
$pdo->exec("INSERT INTO town_worlds VALUES ('main',1)");
$pdo->exec('CREATE TABLE town_objects (object_id TEXT PRIMARY KEY,world_id TEXT,asset_id TEXT,tile_x INTEGER,tile_y INTEGER,footprint_json TEXT,layer INTEGER,deleted_at TEXT)');
$pdo->exec('CREATE TABLE town_asset_items (asset_id TEXT PRIMARY KEY,name TEXT,category TEXT,navigation_json TEXT)');
$now = new DateTimeImmutable('2026-11-15 12:00:00', new DateTimeZone('UTC'));
$first = town_world_event_append($pdo, ['source_type' => 'npc_task', 'source_id' => str_repeat('a', 32), 'source_revision' => '1', 'event_type' => 'work_completed', 'actor_npc_id' => str_repeat('b', 32), 'payload' => ['summary' => '完成浇水']], $now);
event_expect($first['created'] === true && $first['event_id'] === 1, 'The first public event should be inserted.');
$duplicate = town_world_event_append($pdo, ['source_type' => 'npc_task', 'source_id' => str_repeat('a', 32), 'source_revision' => '1', 'event_type' => 'work_completed', 'payload' => ['summary' => '重复']], $now);
event_expect($duplicate['created'] === false && $duplicate['event_id'] === 1, 'The same source revision must be idempotent.');
$second = town_world_event_append($pdo, ['source_type' => 'weather', 'source_id' => 'main', 'source_revision' => '2', 'event_type' => 'weather_changed', 'payload' => ['weather' => '小雨']], $now);
event_expect($second['event_id'] === 2, 'A new source revision should append a second event.');
$rows = town_world_events_query($pdo, str_repeat('c', 32), 0, 1);
event_expect(count($rows['events']) === 1 && $rows['has_more'] === true && $rows['cursor'] === 1, 'Event pagination must preserve the delivered cursor.');
$updated = town_world_event_acknowledge($pdo, str_repeat('c', 32), [1], 'read', $now);
event_expect($updated === 1, 'A valid receipt must be written.');
$read = town_world_events_query($pdo, str_repeat('c', 32), 0, 10);
event_expect($read['events'][0]['receipt_state'] === 'read' && $read['unread_count'] === 1, 'Read receipts must affect the projected state and unread count.');
$pdo->exec("CREATE TABLE town_npc_events (event_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,event_type TEXT,payload_json TEXT,created_at TEXT)");
town_world_event_project_npc($pdo, 7, str_repeat('b', 32), 'task_completed', ['task_id' => str_repeat('d', 32), 'action' => 'work', 'dialogue' => 'private detail'], $now);
$projected = town_world_events_query($pdo, str_repeat('c', 32), 2, 10);
event_expect(count($projected['events']) === 1 && $projected['events'][0]['event_type'] === 'npc_task_completed' && !isset($projected['events'][0]['payload']['dialogue']), 'NPC projection must publish bounded public facts only.');
town_world_event_project_npc($pdo, 8, str_repeat('b', 32), 'task_replay_delta', ['task_id' => str_repeat('d', 32), 'action' => 'work', 'job' => 'water_crop', 'work_run_id' => str_repeat('e', 32), 'work_point' => ['point_id' => str_repeat('f', 32), 'kind' => 'farm'], 'work_result' => ['income' => 10, 'world_effect' => ['effect' => 'water_crop']]], $now);
$delta = town_world_events_query($pdo, str_repeat('c', 32), 3, 10);
event_expect(count($delta['events']) === 1 && $delta['events'][0]['event_type'] === 'npc_task_replay_delta' && $delta['events'][0]['payload']['work_run_id'] === str_repeat('e', 32) && $delta['events'][0]['payload']['work_result']['income'] === 10, 'Work replay projection must retain its idempotency key and bounded result delta.');
$pdo->exec("INSERT INTO town_interest_points VALUES ('interest-1','main','market','market-1',NULL,10,10,5,'[\"visible\",\"nearby\"]','trade','集市',1,'2026-11-15 12:00:00')");
$pdo->exec("INSERT INTO town_interest_points VALUES ('interest-2','main','weather','',NULL,20,20,5,'[\"audible\"]','rain','雨声',1,'2026-11-15 12:00:00')");
$observations = town_interest_points_nearby($pdo, 11, 10, 8, ['visible']);
event_expect(count($observations) === 1 && $observations[0]['interest_id'] === 'interest-1' && $observations[0]['distance'] === 1, 'Perception must filter by distance and channel.');
$pdo->exec("INSERT INTO town_interest_points VALUES ('interest-3','main','building','house-3',NULL,13,10,5,'[\"visible\",\"audible\"]','home','被墙挡住的房屋',1,'2026-11-15 12:00:00')");
$pdo->exec("INSERT INTO town_asset_items VALUES ('wall-1','Stone wall','building','{\"type\":\"blocked\"}')");
$pdo->exec("INSERT INTO town_objects VALUES ('object-wall','main','wall-1',12,10,'{\"width\":1,\"height\":1}',1,NULL)");
$dynamic = town_interest_points_nearby($pdo, 11, 10, 8, ['visible']);
event_expect(count(array_filter($dynamic, static fn(array $row): bool => $row['interest_id'] === 'object-object-wall' && $row['public_name'] === 'Stone wall')) === 1, 'Placed materials must be projected as named interest points.');
$blockedVisible = town_interest_points_nearby($pdo, 11, 10, 8, ['visible']);
event_expect(count(array_filter($blockedVisible, static fn(array $row): bool => $row['interest_id'] === 'interest-3')) === 0, 'A blocking material must hide a visual interest point.');
$blockedAudible = town_interest_points_nearby($pdo, 11, 10, 8, ['visible', 'audible']);
$audible = array_values(array_filter($blockedAudible, static fn(array $row): bool => $row['interest_id'] === 'interest-3'));
event_expect(count($audible) === 1 && $audible[0]['channels'] === ['audible'], 'Audible perception must survive visual occlusion.');
$pdo->exec("INSERT INTO town_interest_points VALUES ('interest-4','main','tower','tower-4',NULL,15,15,8,'[\"visible\"]','lookout','高处目标',1,'2026-11-15 12:00:00')");
$pdo->exec("INSERT INTO town_asset_items VALUES ('tower-4','High tower','building','{\"type\":\"plain\",\"height\":3}')");
$pdo->exec("INSERT INTO town_objects VALUES ('object-tower','main','tower-4',15,15,'{\"width\":1,\"height\":1}',1,NULL)");
$heightBlocked = town_interest_points_nearby($pdo, 11, 10, 8, ['visible'], 0);
event_expect(count(array_filter($heightBlocked, static fn(array $row): bool => $row['interest_id'] === 'interest-4')) === 0, 'A target at an unreachable height must not be reported as visible.');
echo "town_world_events_test: {$checks} checks ok\n";
