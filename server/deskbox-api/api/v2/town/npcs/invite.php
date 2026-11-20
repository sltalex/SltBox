<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/account_data.php';
require dirname(__DIR__, 3) . '/_app/town_npcs.php';

api_run('POST', static function (PDO $pdo): array {
    $actor = account_authenticated_user($pdo, 'api_clock');
    $userId = (string)$actor['user_id'];
    shared_town_require_access($pdo, $userId);
    $body = api_body(8192, false, 8192);
    $npcId = is_string($body['npc_id'] ?? null) ? strtolower(trim($body['npc_id'])) : '';
    $intent = is_string($body['intent'] ?? null) ? strtolower(trim($body['intent'])) : '';
    $dialogue = is_string($body['dialogue'] ?? null) ? trim($body['dialogue']) : '';
    if (mb_strlen($dialogue, 'UTF-8') > 240) throw new ApiError('dialogue_too_long', '交谈内容不能超过 240 个字符。', 400);
    if ($intent === 'cancel') {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $npcId)) throw new ApiError('invalid_npc_invitation', 'NPC invitation is invalid.', 400);
        return ['ok' => true, 'invitation' => town_npc_cancel_player_invitation($pdo, $npcId, $userId, api_clock())];
    }
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $npcId) || !in_array($intent, ['talk', 'follow'], true)) {
        throw new ApiError('invalid_npc_invitation', 'NPC invitation is invalid.', 400);
    }
    if ($intent === 'talk') {
        $input = town_npc_moderate_dialogue($dialogue);
        if (!$input['allowed'] || $input['text'] === '') throw new ApiError('dialogue_rejected', '交谈内容无效。', 400);
        try {
            return ['ok' => true, 'invitation' => town_npc_continue_player_talk($pdo, $npcId, $userId, (string)$input['text'], api_clock())];
        } catch (ApiError $error) {
            if ($error->apiCode !== 'npc_invitation_not_found') throw $error;
        }
        // Generate the first reply before creating the route. The runtime
        // dialogue is always the NPC reply, never the player's text.
        $model = town_agent_player_talk($npcId, (string)$input['text'], []);
        $dialogue = (string)$model['reply'];
    }
    $action = $intent === 'talk' ? 'player_talk' : 'follow';
    return ['ok' => true, 'invitation' => town_npc_apply_action($pdo, [
        'npc_id' => $npcId,
        'action' => $action,
        'payload' => ['target_player_user_id' => $userId, 'dialogue' => $dialogue, 'player_dialogue' => $intent === 'talk' ? (string)($input['text'] ?? '') : '', 'reply_source' => $model['source'] ?? 'fallback', 'mood' => $model['mood'] ?? [], 'next_action' => $model['next_action'] ?? [], 'model_run_id' => $model['run_id'] ?? null],
    ], api_clock(), true, false, true)];
});
