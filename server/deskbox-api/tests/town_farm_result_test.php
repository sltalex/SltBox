<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/shared_town.php';
require dirname(__DIR__) . '/api/_app/town_navigation.php';
require dirname(__DIR__) . '/api/_app/town_npcs.php';

$checks = 0;
function farm_expect(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$stamp = auth_sql_time(new DateTimeImmutable('2026-11-11T10:00:00Z'));
$pdo->exec("CREATE TABLE town_worlds (world_id TEXT PRIMARY KEY,navigation_version INTEGER);
CREATE TABLE town_work_points (point_id TEXT PRIMARY KEY,world_id TEXT,point_key TEXT,name TEXT,kind TEXT,tile_x INTEGER,tile_y INTEGER,interaction_json TEXT,result_json TEXT,enabled INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE town_farm_plots (plot_id TEXT PRIMARY KEY,world_id TEXT,point_id TEXT,plot_key TEXT,name TEXT,crop_id TEXT,growth_stage INTEGER,state TEXT,watered_at TEXT,watering_count INTEGER,enabled INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE town_work_runs (run_id TEXT PRIMARY KEY,point_id TEXT,npc_id TEXT,task_id TEXT,job TEXT,status TEXT,result_json TEXT,started_at TEXT,completed_at TEXT);
CREATE TABLE town_npc_activity_logs (log_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,run_id TEXT,log_type TEXT,summary TEXT,detail_json TEXT,created_at TEXT);");
$pdo->prepare('INSERT INTO town_worlds VALUES (?,?)')->execute(['main', 1]);
$point = ['10000000000000000000000000000001','main','farm-zone-3','三号农田','farm',128,120,'{"radius":1}','{"effect":"water_crop"}',1,$stamp,$stamp];
$pdo->prepare('INSERT INTO town_work_points VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute($point);
$pdo->prepare('INSERT INTO town_farm_plots VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(['20000000000000000000000000000001','main',$point[0],'farm-plot-3-1','三号农田一号地块','wheat',1,'growing',null,0,1,$stamp,$stamp]);
$npc = str_repeat('c', 32); $task = str_repeat('d', 32);
$first = town_npc_record_work_result($pdo, $npc, $task, ['target_id' => 'farm-zone-3', 'job' => 'water_crop'], new DateTimeImmutable('2026-11-11T10:00:00Z'));
farm_expect(($first['work_result']['world_effect']['applied'] ?? false) === true, 'Farm work must apply a durable watering effect.');
$row = $pdo->query('SELECT state,watering_count,watered_at FROM town_farm_plots')->fetch();
farm_expect($row['state'] === 'watered' && (int)$row['watering_count'] === 1 && $row['watered_at'] === '2026-11-11 10:00:00', 'Watering must update plot state and timestamp.');
$second = town_npc_record_work_result($pdo, $npc, str_repeat('e', 32), ['target_id' => 'farm-zone-3', 'job' => 'water_crop'], new DateTimeImmutable('2026-11-11T12:00:00Z'));
farm_expect(($second['work_result']['world_effect']['reason'] ?? '') === 'already_watered', 'Repeated same-day watering must be idempotent.');
farm_expect((int)$pdo->query('SELECT watering_count FROM town_farm_plots')->fetchColumn() === 1, 'Idempotent watering must not increment twice.');
$snapshot = town_farm_snapshot($pdo);
farm_expect(count($snapshot) === 1 && $snapshot[0]['plot_key'] === 'farm-plot-3-1' && $snapshot[0]['state'] === 'watered', 'Farm snapshot must expose durable plot state.');
farm_expect((int)$pdo->query('SELECT COUNT(*) FROM town_work_runs')->fetchColumn() === 2, 'Every completed work task remains auditable even when its effect is idempotent.');

echo "town_farm_result_test: {$checks} checks ok\n";
