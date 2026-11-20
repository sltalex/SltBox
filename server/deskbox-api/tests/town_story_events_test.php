<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/town_story_events.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE town_worlds (world_id TEXT PRIMARY KEY);
CREATE TABLE town_npcs (npc_id TEXT PRIMARY KEY,name TEXT,world_id TEXT,occupation TEXT,enabled INTEGER,agent_paused INTEGER);
CREATE TABLE town_npc_relationships (npc_id TEXT,other_npc_id TEXT,affinity INTEGER,relationship_json TEXT,updated_at TEXT,PRIMARY KEY(npc_id,other_npc_id));
CREATE TABLE town_npc_stats (npc_id TEXT PRIMARY KEY,mood INTEGER,health INTEGER,stamina INTEGER,labor INTEGER,academics INTEGER,culture INTEGER,social INTEGER,wealth INTEGER,stress INTEGER,reputation INTEGER,revision INTEGER,updated_at TEXT);
CREATE TABLE town_npc_stat_changes (change_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,source_type TEXT,source_id TEXT,before_json TEXT,delta_json TEXT,after_json TEXT,reason TEXT,created_at TEXT);
CREATE TABLE town_npc_events (event_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,event_type TEXT,payload_json TEXT,created_at TEXT);
CREATE TABLE town_npc_memories (memory_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,memory_type TEXT,content TEXT,importance INTEGER,created_at TEXT,expires_at TEXT);");
$a = str_repeat('a', 32); $b = str_repeat('b', 32);
$pdo->exec("INSERT INTO town_worlds VALUES ('main')");
$insert = $pdo->prepare('INSERT INTO town_npcs VALUES (?,?,?,?,?,?)');
$insert->execute([$a, '甲', 'main', 'farmer', 1, 0]); $insert->execute([$b, '乙', 'main', 'merchant', 1, 0]);
$draft = ['title' => '雨后的集市', 'summary' => '雨停后大家一起整理被打湿的货架。', 'story' => ['opening' => '雨水顺着屋檐滴下。', 'beats' => ['商人清点货物']], 'participants' => [['npc_id' => $a, 'role' => '组织者'], ['npc_id' => $b, 'role' => '帮手']], 'effects' => [['npc_id' => $a, 'stat_delta' => ['mood' => 99, 'labor' => -99], 'relationship_delta' => ['target_npc_id' => $b, 'affinity' => 8], 'memory' => ['content' => '一起整理集市', 'importance' => 4], 'task' => ['action' => 'work', 'target_id' => 'market', 'job' => 'sort_goods']]]];
$policy = ['max_participants' => 5];
$clean = town_story_event_sanitize_draft($pdo, $draft, $policy);
if (!is_array($clean) || count($clean['participants']) !== 2) throw new RuntimeException('valid story draft should be accepted');
if (($clean['effects'][0]['stat_delta']['mood'] ?? 0) !== 15 || ($clean['effects'][0]['stat_delta']['labor'] ?? 0) !== -15) throw new RuntimeException('stat deltas must be bounded to 15');
if (($clean['effects'][0]['task']['action'] ?? '') !== 'work') throw new RuntimeException('supported participant task should be retained');
$bad = town_story_event_sanitize_draft($pdo, ['title' => '坏草案', 'summary' => '缺少参与者', 'participants' => [['npc_id' => $a]]], $policy);
if ($bad !== null) throw new RuntimeException('story draft with fewer than two valid participants must be rejected');
echo "town_story_events_test: 5 checks ok\n";
