<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__, 3) . '/_app/bootstrap.php';
require dirname(__DIR__, 3) . '/_app/account_data.php';
require_once dirname(__DIR__, 3) . '/_app/tree_town_prototype.php';

api_run('POST', static function (PDO $pdo): array {
    $actor = account_authenticated_user($pdo, 'api_clock');
    $userId = (string)$actor['user_id'];
    try {
        return town_prototype_save_scene($pdo, $userId, api_body(65536, false, 65536));
    } catch (ApiError $error) {
        if ($error->apiCode !== 'scene_conflict') { throw $error; }
        api_json(['code' => $error->apiCode, 'message' => $error->getMessage(),
            'state' => town_prototype_read_state($pdo, $userId)], $error->httpStatus);
    }
});
