<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 2) . '/_app/bootstrap.php';
require dirname(__DIR__, 2) . '/_app/account_data.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    api_run('GET', static function (PDO $pdo): array {
        $user = account_authenticated_user($pdo, 'api_clock');
        $statement = $pdo->prepare(
            'SELECT document_urls_json, documents_json FROM dingtalk_group_document_favorites WHERE user_id = :user_id LIMIT 1'
        );
        $statement->execute([':user_id' => (string)$user['user_id']]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['document_urls' => [], 'documents' => []];
        }
        return dingtalk_favorites_decode_row($row);
    });
}

api_run('PUT', static function (PDO $pdo): array {
    $user = account_authenticated_user($pdo, 'api_clock');
    $body = api_body();
    $documentUrls = dingtalk_favorites_validate_urls($body['document_urls'] ?? null);
    $documents = dingtalk_favorites_validate_documents($body['documents'] ?? null);
    $urlsJson = json_encode($documentUrls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $documentsJson = json_encode($documents, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sql = $driver === 'sqlite'
        ? 'INSERT INTO dingtalk_group_document_favorites (user_id, document_urls_json, documents_json, updated_at) VALUES (:user_id, :urls, :documents, :updated_at) ON CONFLICT(user_id) DO UPDATE SET document_urls_json = excluded.document_urls_json, documents_json = excluded.documents_json, updated_at = excluded.updated_at'
        : 'INSERT INTO dingtalk_group_document_favorites (user_id, document_urls_json, documents_json, updated_at) VALUES (:user_id, :urls, :documents, :updated_at) ON DUPLICATE KEY UPDATE document_urls_json = VALUES(document_urls_json), documents_json = VALUES(documents_json), updated_at = VALUES(updated_at)';
    $statement = $pdo->prepare($sql);
    $statement->execute([
        ':user_id' => (string)$user['user_id'], ':urls' => $urlsJson,
        ':documents' => $documentsJson, ':updated_at' => auth_sql_time(api_clock()),
    ]);
    return ['document_urls' => $documentUrls, 'documents' => $documents];
});

function dingtalk_favorites_decode_row(array $row): array
{
    try {
        $urls = json_decode((string)$row['document_urls_json'], true, 64, JSON_THROW_ON_ERROR);
        $documents = json_decode((string)$row['documents_json'], true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new ApiError('favorites_corrupt', 'Favorite data is corrupt.', 500);
    }
    return [
        'document_urls' => dingtalk_favorites_validate_urls($urls),
        'documents' => dingtalk_favorites_validate_documents($documents),
    ];
}

function dingtalk_favorites_validate_urls(mixed $value): array
{
    if ($value === null) { return []; }
    if (!is_array($value) || count($value) > 4096) {
        throw new ApiError('invalid_favorites', 'Favorite URL data is invalid.', 400);
    }
    $result = [];
    foreach ($value as $url) {
        if (!is_string($url) || strlen($url) > 4096 || !preg_match('/\Ahttps?:\/\/[^\s]+\z/i', $url)) {
            throw new ApiError('invalid_favorites', 'Favorite URL data is invalid.', 400);
        }
        $result[$url] = true;
    }
    return array_keys($result);
}

function dingtalk_favorites_validate_documents(mixed $value): array
{
    if ($value === null) { return []; }
    if (!is_array($value) || count($value) > 4096) {
        throw new ApiError('invalid_favorites', 'Favorite document data is invalid.', 400);
    }
    $result = [];
    foreach ($value as $document) {
        if (!is_array($document)) {
            throw new ApiError('invalid_favorites', 'Favorite document data is invalid.', 400);
        }
        $groupName = $document['group_name'] ?? $document['groupName'] ?? null;
        $lastSeenAt = $document['last_seen_at'] ?? $document['lastSeenAt'] ?? null;
        $title = $document['title'] ?? null;
        $url = $document['url'] ?? null;
        foreach ([$groupName, $title, $url, $lastSeenAt] as $field) {
            if (!is_string($field) || strlen($field) > 4096) {
                throw new ApiError('invalid_favorites', 'Favorite document data is invalid.', 400);
            }
        }
        if (!preg_match('/\Ahttps?:\/\/[^\s]+\z/i', $url)) {
            throw new ApiError('invalid_favorites', 'Favorite document URL is invalid.', 400);
        }
        $conversationId = $document['conversation_id'] ?? $document['conversationId'] ?? '';
        $conversationId = is_string($conversationId) ? substr($conversationId, 0, 512) : '';
        $result[] = [
            'group_name' => $groupName, 'title' => $title,
            'url' => $url, 'last_seen_at' => $lastSeenAt,
            'conversation_id' => $conversationId,
        ];
    }
    return $result;
}
