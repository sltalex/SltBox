<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/shared_town.php';
require dirname(__DIR__) . '/api/_app/town_navigation.php';
require dirname(__DIR__) . '/api/_app/town_npcs.php';

$checks = 0;
function economy_expect(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$stamp = auth_sql_time(new DateTimeImmutable('2026-11-11T10:00:00Z'));
$pdo->exec("CREATE TABLE town_worlds (world_id TEXT PRIMARY KEY,navigation_version INTEGER);
CREATE TABLE town_work_points (point_id TEXT PRIMARY KEY,world_id TEXT,point_key TEXT,name TEXT,kind TEXT,tile_x INTEGER,tile_y INTEGER,interaction_json TEXT,result_json TEXT,enabled INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE town_work_runs (run_id TEXT PRIMARY KEY,point_id TEXT,npc_id TEXT,task_id TEXT,job TEXT,status TEXT,result_json TEXT,started_at TEXT,completed_at TEXT);
CREATE TABLE town_npc_activity_logs (log_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,run_id TEXT,log_type TEXT,summary TEXT,detail_json TEXT,created_at TEXT);
CREATE TABLE town_market_stocks (stock_id TEXT PRIMARY KEY,world_id TEXT,point_id TEXT,stock_key TEXT,item_id TEXT,quantity INTEGER,updated_at TEXT);
CREATE TABLE town_workshop_outputs (output_id TEXT PRIMARY KEY,world_id TEXT,point_id TEXT,npc_id TEXT,task_id TEXT,item_id TEXT,quantity INTEGER,produced_at TEXT);");
$pdo->prepare('INSERT INTO town_worlds VALUES (?,?)')->execute(['main',1]);
$market = ['10000000000000000000000000000002','main','market-zone-1','一号集市','market',96,96,'{}','{"effect":"restock_market","resource":"market_stock"}',1,$stamp,$stamp];
$workshop = ['10000000000000000000000000000003','main','workshop-zone-2','二号工坊','workshop',112,96,'{}','{"effect":"craft_item","resource":"workshop_output"}',1,$stamp,$stamp];
$insertPoint = $pdo->prepare('INSERT INTO town_work_points VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'); $insertPoint->execute($market); $insertPoint->execute($workshop);
$pdo->prepare('INSERT INTO town_market_stocks VALUES (?,?,?,?,?,?,?)')->execute(['30000000000000000000000000000001','main',$market[0],'market-stock-1','market_goods',0,$stamp]);
$npc = str_repeat('f', 32);
$marketRun = town_npc_record_work_result($pdo, $npc, str_repeat('1', 32), ['target_id' => 'market-zone-1', 'job' => 'restock_market'], new DateTimeImmutable('2026-11-11T10:00:00Z'));
economy_expect(($marketRun['work_result']['world_effect']['applied'] ?? false) === true, 'Market work must apply a stock effect.');
economy_expect((int)$pdo->query('SELECT quantity FROM town_market_stocks')->fetchColumn() === 1, 'Market restock must increase stock quantity.');
$workshopRun = town_npc_record_work_result($pdo, $npc, str_repeat('2', 32), ['target_id' => 'workshop-zone-2', 'job' => 'craft_item'], new DateTimeImmutable('2026-11-11T10:01:00Z'));
economy_expect(($workshopRun['work_result']['world_effect']['applied'] ?? false) === true, 'Workshop work must create a durable output.');
economy_expect((int)$pdo->query('SELECT COUNT(*) FROM town_workshop_outputs')->fetchColumn() === 1, 'Workshop output must be persisted.');
$snapshot = town_economy_snapshot($pdo);
economy_expect(count($snapshot['market_stocks']) === 1 && $snapshot['market_stocks'][0]['quantity'] === 1, 'Economy snapshot must expose market stock.');
economy_expect(count($snapshot['workshop_outputs']) === 1 && $snapshot['workshop_outputs'][0]['item_id'] === 'crafted_item', 'Economy snapshot must expose workshop output.');

echo "town_npc_economy_result_test: {$checks} checks ok\n";
