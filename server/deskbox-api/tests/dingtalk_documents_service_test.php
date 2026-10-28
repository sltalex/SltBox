<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/auth.php';
require dirname(__DIR__) . '/api/_app/dingtalk_documents.php';

function expect_documents(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
$clock = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-28T01:00:00Z');
$alice = auth_register($pdo, 'DocAlice', 'secret1', 'device-a', '127.0.0.10', $clock)['user']['id'];
$bob = auth_register($pdo, 'DocBob', 'secret1', 'device-b', '127.0.0.11', $clock)['user']['id'];
$first = ['GroupName' => '项目群', 'Title' => '群文档',
    'Url' => 'https://alidocs.dingtalk.com/i/nodes/shared?from=im',
    'LastSeenAt' => '2026-10-27T00:00:00+00:00', 'ConversationId' => 'group-a'];
$named = $first;
$named['Title'] = '预算.xlsx';
$named['Url'] = 'https://alidocs.dingtalk.com/i/nodes/shared?from=portal';
$named['LastSeenAt'] = '2026-10-28T00:00:00+00:00';
$otherGroup = $named;
$otherGroup['ConversationId'] = 'group-b';
$one = dingtalk_documents_apply($pdo, $alice, ['documents' => [$first],
    'sync_interval_hours' => 12, 'last_sync_completed_at' => '2026-10-27T01:00:00+00:00'], $clock());
expect_documents(count($one['documents']) === 1, 'First device upload failed.');
$two = dingtalk_documents_apply($pdo, $alice, ['documents' => [$named, $otherGroup],
    'last_sync_completed_at' => '2026-10-28T01:00:00+00:00'], $clock());
expect_documents(count($two['documents']) === 2, 'Cross-device merge or group separation failed.');
expect_documents($two['documents'][0]['Title'] === '预算.xlsx', 'Known title was lost.');
expect_documents($two['sync_interval_hours'] === 12, 'An index upload overwrote account settings.');
$old = dingtalk_documents_apply($pdo, $alice, ['documents' => [$first],
    'last_sync_completed_at' => '2026-10-26T01:00:00+00:00'], $clock());
expect_documents(count($old['documents']) === 2 &&
    $old['last_sync_completed_at'] === '2026-10-28T01:00:00+00:00',
    'An older device replaced newer documents or scan time.');
expect_documents(dingtalk_documents_read($pdo, $bob)['documents'] === [],
    'Documents crossed SltBox account boundaries.');
dingtalk_documents_apply($pdo, $alice, ['sync_interval_hours' => 24], $clock());
expect_documents(count(dingtalk_documents_read($pdo, $alice)['documents']) === 2 &&
    dingtalk_documents_read($pdo, $alice)['sync_interval_hours'] === 24,
    'Settings-only update erased documents.');
echo "dingtalk_documents_service_test: ok\n";
