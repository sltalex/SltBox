<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }

function dingtalk_documents_validate(mixed $value): array
{
    if (!is_array($value) || count($value) > 50000) {
        throw new ApiError('invalid_documents', 'Document index is invalid.', 400);
    }
    $documents = [];
    foreach ($value as $item) {
        if (is_object($item)) $item = get_object_vars($item);
        if (!is_array($item)) throw new ApiError('invalid_documents', 'Document item is invalid.', 400);
        $group = $item['GroupName'] ?? $item['group_name'] ?? $item['groupName'] ?? null;
        $title = $item['Title'] ?? $item['title'] ?? null;
        $url = $item['Url'] ?? $item['url'] ?? null;
        $seen = $item['LastSeenAt'] ?? $item['last_seen_at'] ?? $item['lastSeenAt'] ?? null;
        $conversation = $item['ConversationId'] ?? $item['conversation_id'] ?? $item['conversationId'] ?? '';
        if (!is_string($group) || strlen($group) > 512 || !is_string($title) || strlen($title) > 255 ||
            !is_string($url) || strlen($url) > 4096 || !is_string($seen) || strlen($seen) > 64 ||
            !is_string($conversation) || strlen($conversation) > 512 ||
            trim($group) === '' || trim($title) === '') {
            throw new ApiError('invalid_documents', 'Document item is invalid.', 400);
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' ||
            !in_array(strtolower((string)($parts['host'] ?? '')), ['alidocs.dingtalk.com', 'dlink.dingtalk.com'], true) ||
            isset($parts['user']) || isset($parts['pass']) || !isset($parts['path'])) {
            throw new ApiError('invalid_documents', 'Document URL is invalid.', 400);
        }
        try { new DateTimeImmutable($seen); }
        catch (Throwable) { throw new ApiError('invalid_documents', 'Document date is invalid.', 400); }
        $documents[] = ['GroupName' => trim($group), 'Title' => trim($title), 'Url' => $url,
            'LastSeenAt' => $seen, 'ConversationId' => trim($conversation)];
    }
    return $documents;
}

function dingtalk_documents_merge(array $old, array $incoming): array
{
    $merged = [];
    foreach (array_merge($old, $incoming) as $document) {
        $parts = parse_url($document['Url']);
        $group = $document['ConversationId'] !== ''
            ? 'id:' . $document['ConversationId'] : 'name:' . strtoupper($document['GroupName']);
        $key = $group . "\x1f" . strtolower((string)$parts['host']) . (string)$parts['path'];
        if (!isset($merged[$key])) { $merged[$key] = $document; continue; }
        $previous = $merged[$key];
        $newer = strtotime($document['LastSeenAt']) >= strtotime($previous['LastSeenAt']);
        $winner = $newer ? $document : $previous;
        $other = $newer ? $previous : $document;
        if ($winner['Title'] === '群文档' && $other['Title'] !== '群文档')
            $winner['Title'] = $other['Title'];
        $merged[$key] = $winner;
    }
    if (count($merged) > 50000) throw new ApiError('too_many_documents', 'Document index is too large.', 413);
    return array_values($merged);
}

function dingtalk_documents_read(PDO $pdo, string $userId): array
{
    $statement = $pdo->prepare('SELECT documents_json, sync_interval_hours, last_sync_completed_at FROM dingtalk_group_document_indexes WHERE user_id = :user_id');
    $statement->execute([':user_id' => $userId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return ['documents' => [], 'sync_interval_hours' => 6, 'last_sync_completed_at' => null];
    try { $decoded = json_decode((string)$row['documents_json'], true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new ApiError('documents_corrupt', 'Document index is corrupt.', 500); }
    return ['documents' => dingtalk_documents_validate($decoded),
        'sync_interval_hours' => (int)$row['sync_interval_hours'],
        'last_sync_completed_at' => $row['last_sync_completed_at'] === '' ? null : $row['last_sync_completed_at']];
}

function dingtalk_documents_apply(PDO $pdo, string $userId, array $body,
    DateTimeImmutable $now): array
{
    $documents = array_key_exists('documents', $body) ? dingtalk_documents_validate($body['documents']) : null;
    $interval = $body['sync_interval_hours'] ?? null;
    if ($interval !== null && (!is_int($interval) || $interval < 1 || $interval > 168))
        throw new ApiError('invalid_interval', 'Sync interval is invalid.', 400);
    $lastSync = $body['last_sync_completed_at'] ?? null;
    if ($lastSync !== null && (!is_string($lastSync) || strlen($lastSync) > 40 || strtotime($lastSync) === false))
        throw new ApiError('invalid_sync_time', 'Sync time is invalid.', 400);
    if ($documents === null && $interval === null && $lastSync === null)
        throw new ApiError('empty_operation', 'No document index changes were supplied.', 400);
    try {
        $pdo->beginTransaction();
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $insert = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO dingtalk_group_document_indexes (user_id, documents_json, updated_at) VALUES (:user_id, :documents, :updated_at)'
            : 'INSERT IGNORE INTO dingtalk_group_document_indexes (user_id, documents_json, updated_at) VALUES (:user_id, :documents, :updated_at)';
        $pdo->prepare($insert)->execute([':user_id' => $userId, ':documents' => '[]', ':updated_at' => auth_sql_time($now)]);
        $lock = $pdo->prepare('SELECT documents_json, sync_interval_hours, last_sync_completed_at FROM dingtalk_group_document_indexes WHERE user_id = :user_id' . ($driver === 'sqlite' ? '' : ' FOR UPDATE'));
        $lock->execute([':user_id' => $userId]);
        $row = $lock->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('Document index row is missing.');
        $existing = dingtalk_documents_validate(json_decode((string)$row['documents_json'], true, 32, JSON_THROW_ON_ERROR));
        $combined = $documents === null ? $existing : dingtalk_documents_merge($existing, $documents);
        $savedInterval = $interval ?? (int)$row['sync_interval_hours'];
        $savedTime = (string)$row['last_sync_completed_at'];
        if ($lastSync !== null && ($savedTime === '' || strtotime($lastSync) > strtotime($savedTime)))
            $savedTime = $lastSync;
        $update = $pdo->prepare('UPDATE dingtalk_group_document_indexes SET documents_json = :documents, sync_interval_hours = :interval, last_sync_completed_at = :last_sync, updated_at = :updated_at WHERE user_id = :user_id');
        $update->execute([':documents' => json_encode($combined, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':interval' => $savedInterval, ':last_sync' => $savedTime,
            ':updated_at' => auth_sql_time($now), ':user_id' => $userId]);
        $pdo->commit();
        return ['documents' => $combined, 'sync_interval_hours' => $savedInterval,
            'last_sync_completed_at' => $savedTime === '' ? null : $savedTime];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
