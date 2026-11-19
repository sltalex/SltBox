<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/town_npcs.php';

$checks = 0;
function context_expect(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE town_npc_relationships (npc_id TEXT,other_npc_id TEXT,affinity INTEGER,relationship_json TEXT,updated_at TEXT,PRIMARY KEY(npc_id,other_npc_id));
CREATE TABLE town_npc_memories (memory_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,memory_type TEXT,content TEXT,importance INTEGER,created_at TEXT,expires_at TEXT);
CREATE TABLE town_worlds (world_id TEXT PRIMARY KEY,navigation_version INTEGER DEFAULT 1);
CREATE TABLE town_interest_points (interest_id TEXT PRIMARY KEY,world_id TEXT,kind TEXT,asset_id TEXT,object_id TEXT,tile_x INTEGER,tile_y INTEGER,radius INTEGER,perception_channels_json TEXT,activity_type TEXT,public_name TEXT,enabled INTEGER,updated_at TEXT);
CREATE TABLE town_objects (object_id TEXT PRIMARY KEY,world_id TEXT,asset_id TEXT,tile_x INTEGER,tile_y INTEGER,footprint_json TEXT,layer INTEGER,deleted_at TEXT);
CREATE TABLE town_asset_items (asset_id TEXT PRIMARY KEY,name TEXT,category TEXT,navigation_json TEXT);
CREATE TABLE town_npc_runtime_states (npc_id TEXT PRIMARY KEY,tile_x INTEGER,tile_y INTEGER,height INTEGER);');
$pdo->exec("INSERT INTO town_worlds VALUES ('main',3)");
$npc = str_repeat('a', 32); $now = new DateTimeImmutable('2026-11-11T12:00:00Z'); $stamp = auth_sql_time($now);
$pdo->exec("INSERT INTO town_npc_runtime_states VALUES ('$npc',11,10,0)");
$pdo->exec("INSERT INTO town_interest_points VALUES ('market-1','main','market','market-1',NULL,12,10,8,'[\"visible\"]','trade','集市',1,'2026-11-11 12:00:00')");
$insertRelationship = $pdo->prepare('INSERT INTO town_npc_relationships VALUES (?,?,?,?,?)');
$insertRelationship->execute([$npc, str_repeat('b', 32), 5, '{"trust":2}', $stamp]);
$insertMemory = $pdo->prepare('INSERT INTO town_npc_memories (npc_id,memory_type,content,importance,created_at,expires_at) VALUES (?,?,?,?,?,?)');
$insertMemory->execute([$npc, 'conversation', '保留的记忆', 3, $stamp, null]);
$insertMemory->execute([$npc, 'conversation', '已过期记忆', 9, $stamp, auth_sql_time($now->modify('-1 minute'))]);
$context = town_npc_agent_context($pdo, $npc, $now);
context_expect(count($context['relationships']) === 1 && $context['relationships'][0]['affinity'] === 5, 'Agent context should include directed relationships.');
context_expect(count($context['memories']) === 1 && $context['memories'][0]['content'] === '保留的记忆', 'Expired memories must not enter model context.');
context_expect(count($context['observations']) === 1 && $context['observations'][0]['public_name'] === '集市' && $context['navigation_version'] === 3, 'Agent context should include bounded nearby observations and navigation version.');
$insertMemory->execute([$npc, 'conversation', '农田浇水计划', 1, $stamp, null]);
$insertMemory->execute([$npc, 'conversation', '集市采购计划', 1, $stamp, null]);
$relevant = town_npc_agent_context($pdo, $npc, $now, '农田');
context_expect(($relevant['memories'][0]['content'] ?? '') === '农田浇水计划', 'Agent memory retrieval should rank query-relevant memories first.');
echo "town_npc_agent_context_test: {$checks} checks ok\n";
