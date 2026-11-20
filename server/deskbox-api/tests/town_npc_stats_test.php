<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
function api_clock(): DateTimeImmutable { return new DateTimeImmutable('2026-11-20T00:00:00Z'); }
function auth_sql_time(DateTimeInterface $value): string { return $value->format('Y-m-d H:i:s'); }
function town_npc_life_table_exists(PDO $pdo, string $table): bool { $q = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name"); $q->execute([':name' => $table]); return (int)$q->fetchColumn() > 0; }
class ApiError extends RuntimeException { public function __construct(string $code, string $message, int $status = 400) { parent::__construct($message); } }
require dirname(__DIR__) . '/api/_app/town_npc_stats.php';
$pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE town_npcs (npc_id TEXT PRIMARY KEY); INSERT INTO town_npcs VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');");
$pdo->exec("CREATE TABLE town_npc_stats (npc_id TEXT PRIMARY KEY,mood INTEGER,health INTEGER,stamina INTEGER,labor INTEGER,academics INTEGER,culture INTEGER,social INTEGER,wealth INTEGER,stress INTEGER,reputation INTEGER,revision INTEGER,updated_at TEXT);");
$pdo->exec("CREATE TABLE town_npc_stat_changes (change_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,source_type TEXT,source_id TEXT,before_json TEXT,delta_json TEXT,after_json TEXT,reason TEXT,created_at TEXT);");
$checks = 0; $expect = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; };
$saved = town_npc_stats_save($pdo, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', ['mood' => 90, 'stress' => 10], 'admin', 'admin', '初始化', api_clock());
$expect($saved['values']['mood'] === 90 && $saved['values']['stress'] === 10, 'Values should be clamped and saved.');
$expect($saved['levels']['mood'] === '很好' && $saved['levels']['stress'] === '轻松', '100-point bands should be mapped.');
$saved = town_npc_stats_save($pdo, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', ['mood' => 101, 'health' => 0], 'story_event', 'event-1', '事件影响', api_clock());
$expect($saved['values']['mood'] === 100 && $saved['values']['health'] === 1, 'Values must clamp to 1..100.');
$history = town_npc_stat_history($pdo, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
$expect(count($history['changes']) === 2, 'Both saves with changes should be recorded.');
$expect($history['changes'][1]['delta']['mood'] === 10, 'History should contain the delta.');
echo "town_npc_stats_test: {$checks} checks ok\n";
