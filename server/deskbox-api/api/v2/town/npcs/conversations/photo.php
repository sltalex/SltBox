<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 4) . '/_app/bootstrap.php';
require dirname(__DIR__, 4) . '/_app/account_data.php';
require dirname(__DIR__, 4) . '/_app/town_npcs.php';
require dirname(__DIR__, 4) . '/_app/town_npc_photo.php';

/**
 * Convert one authenticated face-to-face photo turn into bounded text facts.
 * The upload is request-scoped: town_npc_photo_extract() only forwards the
 * temporary PHP upload to the internal Worker and this endpoint never stores
 * the original bytes.
 */
api_run('POST', static function (PDO $pdo): array {
    $actor = account_authenticated_user($pdo, 'api_clock');
    $userId = (string)$actor['user_id'];
    shared_town_require_access($pdo, $userId);
    $npcId = is_string($_POST['npc_id'] ?? null) ? strtolower(trim((string)$_POST['npc_id'])) : '';
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $npcId)) throw new ApiError('invalid_npc', '村民 ID 无效。', 400);
    $conversationId = is_string($_POST['conversation_id'] ?? null) ? trim((string)$_POST['conversation_id']) : '';
    if ($conversationId !== '' && !preg_match('/\A[a-f0-9]{32}\z/D', $conversationId)) {
        throw new ApiError('invalid_conversation', '交谈会话无效。', 400);
    }
    $file = town_npc_photo_file($_FILES['image'] ?? []);
    $facts = town_npc_photo_extract($file);
    $source = [
        'type' => 'photo',
        'mode' => (string)($facts['mode'] ?? 'model'),
        'fact_summary' => (string)($facts['fact_summary'] ?? ''),
        'conversation_id' => $conversationId,
    ];
    $invitation = town_npc_continue_player_talk(
        $pdo,
        $npcId,
        $userId,
        (string)$facts['extracted_text'],
        api_clock(),
        $source
    );
    return [
        'ok' => true,
        'npc_id' => $npcId,
        'conversation_id' => $conversationId,
        'extracted_text' => (string)$facts['extracted_text'],
        'fact_summary' => (string)$facts['fact_summary'],
        'mode' => (string)($facts['mode'] ?? 'model'),
        'model_name' => (string)($facts['model_name'] ?? ''),
        'invitation' => $invitation,
        'message_id' => $invitation['message_id'] ?? null,
    ];
});
