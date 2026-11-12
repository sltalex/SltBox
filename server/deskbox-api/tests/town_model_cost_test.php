<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/shared_town.php';
require dirname(__DIR__) . '/api/_app/town_npcs.php';
require dirname(__DIR__) . '/api/_app/town_agent.php';
require dirname(__DIR__) . '/api/_app/town_model_provider.php';

$checks = 0;
function cost_expect(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = new DateTimeImmutable('2026-11-11T10:00:00Z'); $stamp = auth_sql_time($now);
$provider = str_repeat('a', 32); $npc = str_repeat('b', 32); $run = str_repeat('c', 32);
$pdo->exec("CREATE TABLE town_model_providers (provider_id TEXT PRIMARY KEY,name TEXT,base_url TEXT,model_name TEXT,api_key_ciphertext TEXT,temperature REAL,timeout_seconds INTEGER,monthly_quota INTEGER,enabled INTEGER,updated_at TEXT);
CREATE TABLE town_model_provider_pricing (provider_id TEXT PRIMARY KEY,currency TEXT,input_per_million REAL,output_per_million REAL,updated_at TEXT);
CREATE TABLE town_model_provider_revisions (revision_id INTEGER PRIMARY KEY AUTOINCREMENT,provider_id TEXT,action TEXT,snapshot_json TEXT,created_at TEXT);
CREATE TABLE town_agent_runs (run_id TEXT PRIMARY KEY,npc_id TEXT,model_provider_id TEXT,status TEXT,prompt_tokens INTEGER,completion_tokens INTEGER,total_tokens INTEGER,latency_ms INTEGER,error_code TEXT,error_message TEXT,created_at TEXT,completed_at TEXT);
CREATE TABLE town_agent_run_costs (run_id TEXT PRIMARY KEY,provider_id TEXT,currency TEXT,prompt_tokens INTEGER,completion_tokens INTEGER,input_per_million REAL,output_per_million REAL,amount REAL,created_at TEXT);
CREATE TABLE town_npcs (npc_id TEXT PRIMARY KEY,name TEXT);
CREATE TABLE town_npc_activity_logs (log_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,run_id TEXT,log_type TEXT,summary TEXT,detail_json TEXT,created_at TEXT);
CREATE TABLE town_agent_token_reservations (reservation_id TEXT PRIMARY KEY,provider_id TEXT,worker_id TEXT,reserved_tokens INTEGER,actual_tokens INTEGER,status TEXT,expires_at TEXT,created_at TEXT,settled_at TEXT);");
$pdo->prepare('INSERT INTO town_model_providers VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$provider,'test','https://example.test/v1','test-model','',0.4,20,0,1,$stamp]);
$pdo->prepare('INSERT INTO town_model_provider_pricing VALUES (?,?,?,?,?)')->execute([$provider,'CNY',2.5,10.0,$stamp]);
$pdo->prepare('INSERT INTO town_npcs VALUES (?,?)')->execute([$npc,'测试村民']);
$pdo->prepare('INSERT INTO town_agent_runs VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$run,$npc,$provider,'completed',120,40,160,10,'','',$stamp,$stamp]);
town_model_provider_record_run_cost($pdo, $run, $provider, 120, 40, $now);
$cost = $pdo->query('SELECT currency,prompt_tokens,completion_tokens,amount FROM town_agent_run_costs')->fetch(PDO::FETCH_ASSOC);
cost_expect(is_array($cost) && $cost['currency'] === 'CNY', 'Cost should preserve the configured currency.');
cost_expect((int)$cost['prompt_tokens'] === 120 && (int)$cost['completion_tokens'] === 40, 'Cost should snapshot token counts.');
cost_expect(abs((float)$cost['amount'] - 0.0007) < 0.000001, 'Cost should use input and output per-million rates.');
$report = town_agent_admin_report($pdo, 7, null);
cost_expect(abs((float)$report['summary']['cost_amount'] - 0.0007) < 0.000001, 'Agent report should aggregate run costs.');
cost_expect(($report['summary']['cost_currency'] ?? '') === 'CNY', 'Agent report should expose cost currency.');
cost_expect(count($report['cost_rows'] ?? []) === 1, 'Agent report should expose per-run cost rows.');
town_model_provider_record_revision($pdo, $provider, 'save', ['model_name' => 'test-model', 'currency' => 'CNY'], $now);
cost_expect((int)$pdo->query('SELECT COUNT(*) FROM town_model_provider_revisions')->fetchColumn() === 1, 'Provider revisions should be retained.');
$report = town_agent_admin_report($pdo, 7, null);
cost_expect(count($report['provider_revisions'] ?? []) === 1, 'Agent report should expose provider revisions.');
echo "town_model_cost_test: {$checks} checks ok\n";
