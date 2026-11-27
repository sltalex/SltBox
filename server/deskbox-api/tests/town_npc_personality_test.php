<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
function api_clock(): DateTimeImmutable { return new DateTimeImmutable('2026-11-20T00:00:00Z'); }
function auth_sql_time(DateTimeInterface $value): string { return $value->format('Y-m-d H:i:s'); }
function town_npc_life_table_exists(PDO $pdo, string $table): bool { $q=$pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name"); $q->execute([':name'=>$table]); return (int)$q->fetchColumn()>0; }
function town_npc_life_column_exists(PDO $pdo, string $table, string $column): bool { $q=$pdo->prepare("SELECT COUNT(*) FROM pragma_table_info(:table) WHERE name=:column"); $q->execute([':table'=>$table,':column'=>$column]); return (int)$q->fetchColumn()>0; }
class ApiError extends RuntimeException { public string $apiCode; public function __construct(string $code,string $message,int $status=400){$this->apiCode=$code;parent::__construct($message);} }
require dirname(__DIR__) . '/api/_app/town_npc_stats.php';
require dirname(__DIR__) . '/api/_app/town_npc_personality.php';
$pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$npc=str_repeat('a',32); $pdo->exec("CREATE TABLE town_npcs (npc_id TEXT PRIMARY KEY); INSERT INTO town_npcs VALUES ('$npc'); CREATE TABLE town_npc_stats (npc_id TEXT PRIMARY KEY,mood INTEGER,health INTEGER,stamina INTEGER,labor INTEGER,academics INTEGER,culture INTEGER,social INTEGER,wealth INTEGER,stress INTEGER,reputation INTEGER,revision INTEGER,updated_at TEXT); CREATE TABLE town_npc_stat_changes (change_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,source_type TEXT,source_id TEXT,before_json TEXT,delta_json TEXT,after_json TEXT,reason TEXT,created_at TEXT); CREATE TABLE town_npc_personality (npc_id TEXT PRIMARY KEY,courage INTEGER,kindness INTEGER,curiosity INTEGER,sociability INTEGER,diligence INTEGER,caution INTEGER,creativity INTEGER,independence INTEGER,revision INTEGER,updated_at TEXT); CREATE TABLE town_npc_personality_changes (change_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,before_json TEXT,delta_json TEXT,after_json TEXT,reason TEXT,created_at TEXT); CREATE TABLE town_npc_profiles (npc_id TEXT PRIMARY KEY,short_term_goal TEXT,long_term_goal TEXT); CREATE TABLE town_npc_runtime_states (npc_id TEXT PRIMARY KEY,current_goal TEXT,desire_json TEXT,plan_json TEXT,needs_json TEXT,last_decision_context_json TEXT,last_reflection_at TEXT,updated_at TEXT);");
$checks=0; $expect=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$saved=town_npc_personality_save($pdo,$npc,['courage'=>101,'kindness'=>2,'curiosity'=>80],'初始化',api_clock()); $expect($saved['values']['courage']===100&&$saved['levels']['kindness']==='很低','Personality values should clamp and expose levels.');
$pdo->prepare('INSERT INTO town_npc_profiles VALUES (?,?,?)')->execute([$npc,'修复水渠','守护农田']); $pdo->prepare('INSERT INTO town_npc_runtime_states VALUES (?,?,?,?,?,?,?,?)')->execute([$npc,'修复水渠','{"rest":20}','{"next":"clear"}','{"rest":40}',null,null,auth_sql_time(api_clock())]);
$context=town_npc_decision_context($pdo,$npc,api_clock()); $expect(($context['current_goal']??'')==='修复水渠'&&($context['needs']['rest']??0)===40,'Decision context should read current goals and needs from the server.');
$expect(($context['personality']['values']['courage']??0)===100,'Decision context must include stable personality values.');
town_npc_tick_life($pdo,$npc,api_clock(),null,1);
$needs=json_decode((string)$pdo->query("SELECT needs_json FROM town_npc_runtime_states WHERE npc_id='$npc'")->fetchColumn(),true);
$expect(($needs['hunger']??0)===1&&($needs['thirst']??0)===1,'Life ticks should persist gradually increasing hunger and thirst instead of resetting needs.');
$low=$context['stats']['values']; $low['stamina']=15; $low['health']=15; town_npc_stats_save($pdo,$npc,$low,'test','low','低状态测试',api_clock());
$blocked=false; try { town_npc_action_allowed_by_values($pdo,$npc,'work'); } catch (ApiError $error) { $blocked=$error->apiCode==='npc_too_tired'; }
$expect($blocked,'Low stamina or health should force the NPC to rest before other actions.');
echo "town_npc_personality_test: {$checks} checks ok\n";
