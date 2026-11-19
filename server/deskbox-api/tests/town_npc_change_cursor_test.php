<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/town_npcs.php';

$checks = 0;
function cursor_expect(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE town_npc_events (event_id INTEGER PRIMARY KEY AUTOINCREMENT,npc_id TEXT,event_type TEXT,payload_json TEXT,created_at TEXT)');
$insert = $pdo->prepare('INSERT INTO town_npc_events (npc_id,event_type,payload_json,created_at) VALUES (?,?,?,?)');
for ($index = 1; $index <= 525; $index++) $insert->execute([str_repeat('a', 32), 'state_changed', json_encode(['state_version' => $index]), '2026-11-13 12:00:00']);
$first = town_npc_changes($pdo, 0, 200);
cursor_expect(count($first['events']) === 200 && $first['cursor'] === 200, 'A limited response cursor must stop at the last delivered event, not the global maximum.');
cursor_expect($first['has_more'] === true, 'A full page with undispatched rows must advertise another page.');
$second = town_npc_changes($pdo, $first['cursor'], 200);
$third = town_npc_changes($pdo, $second['cursor'], 200);
$ids = array_merge(array_column($first['events'], 'event_id'), array_column($second['events'], 'event_id'), array_column($third['events'], 'event_id'));
cursor_expect($ids === range(1, 525), 'Repeated incremental reads must deliver every event once and in order.');
cursor_expect($second['cursor'] === 400 && $third['cursor'] === 525 && $third['has_more'] === false, 'The final page must keep the exact delivered cursor and finish pagination.');
$empty = town_npc_changes($pdo, 525, 200);
cursor_expect($empty['events'] === [] && $empty['cursor'] === 525 && $empty['has_more'] === false, 'An empty incremental page must preserve the requested cursor.');
$insert->execute([str_repeat('a', 32), 'conversation', '{"dialogue":"新对话"}', '2026-11-13 12:00:01']);
$tail = town_npc_changes($pdo, $empty['cursor'], 200);
cursor_expect(count($tail['events']) === 1 && $tail['events'][0]['event_id'] === 526 && $tail['cursor'] === 526, 'An event appended after an empty read must still be delivered.');
$bounded = town_npc_changes($pdo, 0, 10000);
cursor_expect(count($bounded['events']) === 500 && $bounded['cursor'] === 500 && $bounded['has_more'] === true, 'The server must cap page size without skipping the remaining tail.');
try {
    town_npc_changes($pdo, -1);
    throw new RuntimeException('Expected invalid cursor rejection.');
} catch (ApiError $error) {
    cursor_expect($error->apiCode === 'invalid_cursor', 'Negative cursors must be rejected.');
}
echo "town_npc_change_cursor_test: {$checks} checks ok\n";
