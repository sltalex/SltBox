<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/shared_town.php';
require dirname(__DIR__) . '/api/_app/town_navigation.php';
require dirname(__DIR__) . '/api/_app/town_npcs.php';
require dirname(__DIR__) . '/api/_app/admin_web.php';
function bulk_expect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$stamp = auth_sql_time(api_clock());
$ids = [str_repeat('a', 32), str_repeat('b', 32), str_repeat('c', 32)];
$pdo->exec("CREATE TABLE town_npcs (npc_id TEXT PRIMARY KEY,name TEXT,world_id TEXT,avatar_asset_id TEXT,appearance_json TEXT,occupation TEXT,enabled INTEGER,agent_paused INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE town_npc_activity_logs (log_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,run_id TEXT,log_type TEXT,summary TEXT,detail_json TEXT,created_at TEXT);");
$insert = $pdo->prepare('INSERT INTO town_npcs VALUES (?,?,?,?,?,?,?,?,?,?)');
foreach ($ids as $index => $id) $insert->execute([$id, '村民' . $index, 'main', '', '{}', 'farmer', 1, 0, $stamp, $stamp]);
$_SESSION['csrf'] = str_repeat('f', 64);
$result = admin_page_mutate($pdo, ['user_id' => str_repeat('d', 32)], ['csrf' => $_SESSION['csrf'], 'operation' => 'town_npc_bulk_toggle', 'npc_ids' => [$ids[0], $ids[1]], 'field' => 'agent_paused', 'value' => '1']);
bulk_expect($result === 'town_npc_bulk_toggle', 'Bulk pause should return its operation name.');
bulk_expect((int)$pdo->query('SELECT COUNT(*) FROM town_npcs WHERE agent_paused=1')->fetchColumn() === 2, 'Bulk pause should update every selected NPC.');
bulk_expect((int)$pdo->query("SELECT COUNT(*) FROM town_npc_activity_logs WHERE summary='管理员批量暂停了 Agent'")->fetchColumn() === 2, 'Bulk pause should leave one audit log per NPC.');
try {
    admin_page_mutate($pdo, ['user_id' => str_repeat('d', 32)], ['csrf' => $_SESSION['csrf'], 'operation' => 'town_npc_bulk_toggle', 'npc_ids' => [$ids[0], str_repeat('e', 32)], 'field' => 'enabled', 'value' => '0']);
    throw new RuntimeException('Unknown NPC should be rejected.');
} catch (ApiError $error) { bulk_expect($error->apiCode === 'npc_not_found', 'Unknown NPC should return npc_not_found.'); }
try {
    town_npc_bulk_toggle($pdo, $ids, 'unsupported', true, api_clock());
    throw new RuntimeException('Unsupported field should be rejected.');
} catch (ApiError $error) { bulk_expect($error->apiCode === 'invalid_npc_bulk_field', 'Unsupported field should return invalid_npc_bulk_field.'); }
echo "town_npc_bulk_admin_test: checks ok\n";
