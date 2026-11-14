<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }

/**
 * Finds the non-secret session identifier that was created during a web login.
 * The access token itself is deliberately never stored in the PHP session.
 */
function web_admin_find_session_id(PDO $pdo, string $accessToken): ?string
{
    if ($accessToken === '') { return null; }
    $statement = $pdo->prepare('SELECT id FROM auth_sessions WHERE access_token_hash = :token LIMIT 1');
    $statement->execute([':token' => hash('sha256', $accessToken)]);
    $id = $statement->fetchColumn();
    return is_string($id) && preg_match('/\A[a-f0-9]{32}\z/D', $id) ? $id : null;
}

/**
 * Validates the server-side PHP-session reference on every management request.
 * Revoking the underlying account session immediately invalidates the browser.
 *
 * @return array{user_id:string,username:string}
 */
function web_admin_validate_session(PDO $pdo, string $userId, ?string $sessionId, callable $clock): array
{
    if (!is_string($sessionId) || !preg_match('/\A[a-f0-9]{32}\z/D', $userId)
        || !preg_match('/\A[a-f0-9]{32}\z/D', $sessionId)) {
        throw new ApiError('invalid_session', 'Your management session has expired. Please sign in again.', 401);
    }
    $statement = $pdo->prepare('SELECT s.user_id, u.username, u.status, s.revoked_at, s.refresh_expires_at, '
        . 'b.user_id AS administrator_id FROM auth_sessions s '
        . 'JOIN auth_users u ON u.id = s.user_id '
        . 'CROSS JOIN admin_bootstrap b WHERE s.id = :session AND s.user_id = :user LIMIT 1');
    $statement->execute([':session' => $sessionId, ':user' => $userId]);
    $session = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($session) || $session['status'] !== 'active' || $session['revoked_at'] !== null
        || auth_parse_sql_time((string)$session['refresh_expires_at']) <= $clock()) {
        throw new ApiError('invalid_session', 'Your management session has expired. Please sign in again.', 401);
    }
    if ($session['administrator_id'] !== $session['user_id']) {
        throw new ApiError('admin_forbidden', 'Administrator access is required.', 403);
    }
    return ['user_id' => (string)$session['user_id'], 'username' => (string)$session['username']];
}

function web_admin_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    session_name('sltbox_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => api_is_https($_SERVER),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function web_admin_csrf_token(): string
{
    $token = $_SESSION['csrf'] ?? null;
    if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf'] = $token;
    }
    return $token;
}

function web_admin_require_csrf(array $post): void
{
    $provided = isset($post['csrf']) && is_string($post['csrf']) ? $post['csrf'] : '';
    $expected = $_SESSION['csrf'] ?? '';
    if (!is_string($expected) || $expected === '' || !hash_equals($expected, $provided)) {
        throw new ApiError('invalid_csrf', 'The form has expired. Reload the page and try again.', 400);
    }
}

function web_admin_sign_out(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
}

function admin_page_post_id(array $post, string $key): string
{
    $value = isset($post[$key]) && is_string($post[$key]) ? $post[$key] : '';
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $value)) {
        throw new ApiError('invalid_target', 'The selected account is invalid.', 400);
    }
    return $value;
}

function admin_page_nonnegative(array $post, string $key, string $code): int
{
    $value = isset($post[$key]) && is_string($post[$key]) ? trim($post[$key]) : '';
    if (!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $value) || (int)$value > 2147483647) {
        throw new ApiError($code, 'Please enter a nonnegative whole number.', 400);
    }
    return (int)$value;
}

function admin_page_mutate(PDO $pdo, array $actor, array $post, array $files = []): string
{
    web_admin_require_csrf($post);
    $operation = isset($post['operation']) && is_string($post['operation']) ? $post['operation'] : '';
    if ($operation === 'logout') { web_admin_sign_out(); return 'logged_out'; }

    if ($operation === 'fruit_catalog_item') {
        fruit_admin_update($pdo,$post,api_clock());
        return 'fruit_catalog_item';
    }
    if ($operation === 'wildlife_catalog_item') {
        wildlife_admin_update($pdo, $post, api_clock());
        return 'wildlife_catalog_item';
    }
    if ($operation === 'town_asset_reflow') {
        require_once __DIR__ . '/shared_town.php';
        town_asset_apply_reflow($pdo,$actor['user_id'],(string)($post['plan_sha256']??''),api_clock());
        return 'town_asset_reflow';
    }
    if ($operation === 'town_navigation_rebuild') {
        require_once __DIR__ . '/town_navigation.php';
        town_navigation_rebuild($pdo, api_clock());
        return 'town_navigation_rebuild';
    }
    if ($operation === 'town_asset_upload') {
        $file = is_array($files['sheet'] ?? null) ? $files['sheet'] : [];
        town_asset_upload_sheet($pdo, $actor['user_id'], $file, api_clock());
        return 'town_asset_upload';
    }
    if ($operation === 'town_asset_item') {
        town_asset_save_item($pdo, $actor['user_id'], [
            'asset_id' => $post['asset_id'] ?? '', 'sheet_id' => $post['sheet_id'] ?? '',
            'name' => $post['name'] ?? '', 'category' => $post['category'] ?? '', 'subcategory' => $post['subcategory'] ?? '',
            'tags' => $post['tags'] ?? [], 'x' => $post['x'] ?? null, 'y' => $post['y'] ?? null,
            'width' => $post['width'] ?? null, 'height' => $post['height'] ?? null,
            'display_width' => $post['display_width'] ?? null, 'display_height' => $post['display_height'] ?? null,
            'anchor' => $post['anchor'] ?? ['x' => .5, 'y' => 1], 'footprint' => $post['footprint'] ?? '',
            'collision' => $post['collision'] ?? ['x' => .1, 'y' => .55, 'width' => .8, 'height' => .4], 'frames' => $post['frames'] ?? [],
            'navigation' => $post['navigation'] ?? ['type' => 'blocked'], 'sort_mode' => $post['sort_mode'] ?? null,
            'split_y' => $post['split_y'] ?? null,
        ], api_clock());
        return 'town_asset_item';
    }
    if ($operation === 'town_asset_bulk_item') {
        $rawItems = $post['items'] ?? '';
        if (is_string($rawItems)) {
            try { $rawItems = json_decode($rawItems, true, 32, JSON_THROW_ON_ERROR); }
            catch (JsonException) { throw new ApiError('invalid_asset_batch', '批量素材数据不是有效 JSON。', 400); }
        }
        if (!is_array($rawItems)) throw new ApiError('invalid_asset_batch', '批量素材数据格式无效。', 400);
        town_asset_save_items_bulk($pdo, $actor['user_id'], $rawItems, api_clock());
        return 'town_asset_bulk_item';
    }
    if ($operation === 'town_asset_delete') {
        town_asset_delete_item($pdo, isset($post['asset_id']) && is_string($post['asset_id']) ? $post['asset_id'] : '');
        return 'town_asset_delete';
    }
    if ($operation === 'town_asset_publish') {
        town_asset_publish($pdo, $actor['user_id'], api_clock());
        return 'town_asset_publish';
    }
    if ($operation === 'town_asset_rollback') {
        town_asset_rollback($pdo, isset($post['release_id']) && is_string($post['release_id']) ? $post['release_id'] : '');
        return 'town_asset_rollback';
    }
    if ($operation === 'town_model_provider_save') {
        require_once __DIR__ . '/town_model_provider.php';
        town_model_provider_save($pdo, $post, api_clock());
        return 'town_model_provider_save';
    }
    if ($operation === 'town_model_provider_test') {
        require_once __DIR__ . '/town_model_provider.php';
        town_model_provider_test_connection($pdo);
        return 'town_model_provider_test';
    }
    if (str_starts_with($operation, 'town_npc_')) {
        require_once __DIR__ . '/town_npcs.php';
        if ($operation === 'town_npc_delete') {
            $id = is_string($post['npc_id'] ?? null) ? trim((string)$post['npc_id']) : '';
            if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) throw new ApiError('invalid_npc', 'NPC identifier is invalid.', 400);
            $statement = $pdo->prepare('DELETE FROM town_npcs WHERE npc_id=:id'); $statement->execute([':id' => $id]);
            return 'town_npc_delete';
        }
        if ($operation === 'town_npc_toggle') {
            $id = is_string($post['npc_id'] ?? null) ? trim((string)$post['npc_id']) : '';
            $field = ($post['field'] ?? '') === 'agent_paused' ? 'agent_paused' : 'enabled';
            $value = (int)(($post['value'] ?? '0') === '1');
            $statement = $pdo->prepare("UPDATE town_npcs SET {$field}=:value,updated_at=:updated WHERE npc_id=:id"); $statement->execute([':value' => $value, ':updated' => auth_sql_time(api_clock()), ':id' => $id]);
            return 'town_npc_toggle';
        }
        if ($operation === 'town_npc_bulk_toggle') {
            $rawIds = $post['npc_ids'] ?? [];
            if (!is_array($rawIds)) $rawIds = [$rawIds];
            $field = is_string($post['field'] ?? null) ? trim((string)$post['field']) : '';
            $value = ($post['value'] ?? '') === '1';
            town_npc_bulk_toggle($pdo, array_values($rawIds), $field, $value, api_clock(), $actor['user_id']);
            return 'town_npc_bulk_toggle';
        }
        if ($operation === 'town_npc_clear_memory') {
            $id = is_string($post['npc_id'] ?? null) ? trim((string)$post['npc_id']) : '';
            town_npc_clear_memories($pdo, $id, api_clock());
            return 'town_npc_clear_memory';
        }
        if ($operation === 'town_npc_relationship_save') {
            $id = is_string($post['npc_id'] ?? null) ? trim((string)$post['npc_id']) : '';
            $otherId = is_string($post['other_npc_id'] ?? null) ? trim((string)$post['other_npc_id']) : '';
            town_npc_save_relationship($pdo, $id, $otherId, (int)($post['affinity'] ?? 0), api_clock());
            return 'town_npc_relationship_save';
        }
        if ($operation === 'town_npc_inventory_save') {
            $id = is_string($post['npc_id'] ?? null) ? trim((string)$post['npc_id']) : '';
            town_npc_replace_inventory($pdo, $id, ['ladder' => (int)($post['ladder_quantity'] ?? 0), 'bridge_kit' => (int)($post['bridge_kit_quantity'] ?? 0)], api_clock());
            return 'town_npc_inventory_save';
        }
        if ($operation === 'town_npc_home_save') {
            $id = is_string($post['npc_id'] ?? null) ? trim((string)$post['npc_id']) : '';
            $decode = static function (mixed $value): array { if (is_array($value)) return $value; if (!is_string($value) || trim($value) === '') return []; try { $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR); return is_array($decoded) ? $decoded : []; } catch (JsonException) { throw new ApiError('invalid_npc_home', '家的交互配置必须是有效 JSON。', 400); } };
            town_npc_save_home($pdo, $id, ['home_asset_id' => $post['home_asset_id'] ?? '', 'home_asset_name' => $post['home_asset_name'] ?? '', 'tile_x' => (int)($post['home_tile_x'] ?? 0), 'tile_y' => (int)($post['home_tile_y'] ?? 0), 'interaction' => $decode($post['home_interaction'] ?? '')], api_clock());
            return 'town_npc_home_save';
        }
        if ($operation === 'town_npc_policy_save') {
            $id = is_string($post['npc_id'] ?? null) ? trim((string)$post['npc_id']) : '';
            town_npc_save_decision_policy($pdo, $id, ['decision_interval_seconds' => (int)($post['decision_interval_seconds'] ?? 60), 'observation_interval_seconds' => (int)($post['observation_interval_seconds'] ?? 300), 'dialogue_cooldown_seconds' => (int)($post['dialogue_cooldown_seconds'] ?? 60), 'max_decisions_per_hour' => (int)($post['max_decisions_per_hour'] ?? 60)], api_clock());
            return 'town_npc_policy_save';
        }
        if ($operation === 'town_npc_global_policy_save') {
            town_npc_save_global_policy($pdo, ['enabled' => ($post['global_policy_enabled'] ?? '') === '1', 'decision_interval_seconds' => (int)($post['global_decision_interval_seconds'] ?? 60), 'observation_interval_seconds' => (int)($post['global_observation_interval_seconds'] ?? 300), 'dialogue_cooldown_seconds' => (int)($post['global_dialogue_cooldown_seconds'] ?? 60), 'max_decisions_per_hour' => (int)($post['global_max_decisions_per_hour'] ?? 60)], api_clock());
            return 'town_npc_global_policy_save';
        }
        if ($operation === 'town_npc_economy_save') {
            $id = is_string($post['npc_id'] ?? null) ? trim((string)$post['npc_id']) : '';
            $items = [];
            if (is_string($post['economy_inventory'] ?? null) && trim((string)$post['economy_inventory']) !== '') {
                try { $decoded = json_decode((string)$post['economy_inventory'], true, 32, JSON_THROW_ON_ERROR); if (!is_array($decoded)) throw new JsonException('not object'); $items = $decoded; }
                catch (JsonException) { throw new ApiError('invalid_npc_economy', 'NPC 库存必须是 JSON 对象，例如 {"seed":10}。', 400); }
            }
            town_npc_set_wallet($pdo, $id, max(0, (int)($post['wallet_balance'] ?? 0)), $actor['user_id'], api_clock());
            town_npc_set_economy_inventory($pdo, $id, $items, api_clock());
            return 'town_npc_economy_save';
        }
        if ($operation === 'town_npc_schedule_save') {
            $id = is_string($post['npc_id'] ?? null) ? trim((string)$post['npc_id']) : '';
            $rows = $post['schedule_rows'] ?? [];
            if (!is_array($rows)) throw new ApiError('invalid_schedule', '日程表格式无效。', 400);
            town_npc_replace_schedule($pdo, $id, array_values($rows), api_clock());
            return 'town_npc_schedule_save';
        }
        if ($operation === 'town_work_point_save') {
            $decode = static function (mixed $value): array { if (is_array($value)) return $value; if (!is_string($value) || trim($value) === '') return []; try { $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR); return is_array($decoded) ? $decoded : []; } catch (JsonException) { throw new ApiError('invalid_work_point', '工作点交互和结果必须是有效 JSON。', 400); } };
            $id = is_string($post['point_id'] ?? null) && trim((string)$post['point_id']) !== '' ? trim((string)$post['point_id']) : null;
            town_npc_save_work_point($pdo, ['point_key' => $post['point_key'] ?? '', 'name' => $post['point_name'] ?? '', 'kind' => $post['point_kind'] ?? 'workshop', 'tile_x' => (int)($post['point_tile_x'] ?? 0), 'tile_y' => (int)($post['point_tile_y'] ?? 0), 'interaction' => $decode($post['point_interaction'] ?? ''), 'result' => $decode($post['point_result'] ?? '')], api_clock(), $id);
            return 'town_work_point_save';
        }
        if ($operation === 'town_work_point_toggle') {
            $id = is_string($post['point_id'] ?? null) ? trim((string)$post['point_id']) : '';
            if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) throw new ApiError('invalid_work_point', '工作点 ID 无效。', 400);
            $value = (int)(($post['value'] ?? '0') === '1');
            $statement = $pdo->prepare('UPDATE town_work_points SET enabled=:enabled,updated_at=:updated WHERE point_id=:id'); $statement->execute([':enabled' => $value, ':updated' => auth_sql_time(api_clock()), ':id' => $id]);
            if ($statement->rowCount() === 0) throw new ApiError('work_point_not_found', '工作点不存在。', 404);
            return 'town_work_point_toggle';
        }
        if ($operation === 'town_npc_force_task') {
            $id = is_string($post['npc_id'] ?? null) ? trim((string)$post['npc_id']) : '';
            $action = is_string($post['force_action'] ?? null) ? strtolower(trim((string)$post['force_action'])) : '';
            if (!in_array($action, TOWN_NPC_ACTIONS, true)) throw new ApiError('invalid_npc_action', '管理员任务类型无效。', 400);
            $targetId = is_string($post['force_target_id'] ?? null) ? trim((string)$post['force_target_id']) : '';
            $payload = [
                'target_id' => $targetId,
                'target' => [
                    'tile_x' => (int)($post['force_tile_x'] ?? 0),
                    'tile_y' => (int)($post['force_tile_y'] ?? 0),
                ],
                'job' => is_string($post['force_job'] ?? null) ? trim((string)$post['force_job']) : '',
                'dialogue' => is_string($post['force_dialogue'] ?? null) ? trim((string)$post['force_dialogue']) : '',
                'duration_minutes' => max(0, min(1440, (int)($post['force_duration_minutes'] ?? 20))),
            ];
            town_npc_admin_force_action($pdo, ['npc_id' => $id, 'action' => $action, 'payload' => $payload], api_clock());
            return 'town_npc_force_task';
        }
        if ($operation === 'town_npc_save') {
            $decode = static function(mixed $value): array { if (is_array($value)) return $value; if (!is_string($value) || trim($value) === '') return []; try { $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR); return is_array($decoded) ? $decoded : []; } catch (JsonException) { throw new ApiError('invalid_npc', '人格、外观或日程必须是有效 JSON。', 400); } };
            $id = is_string($post['npc_id'] ?? null) && trim((string)$post['npc_id']) !== '' ? trim((string)$post['npc_id']) : null;
            $enabled = true; $agentPaused = false;
            if ($id !== null) {
                $state = $pdo->prepare('SELECT enabled,agent_paused FROM town_npcs WHERE npc_id=:id LIMIT 1');
                $state->execute([':id' => $id]); $current = $state->fetch(PDO::FETCH_ASSOC);
                if (is_array($current)) { $enabled = (bool)$current['enabled']; $agentPaused = (bool)$current['agent_paused']; }
            }
            $body = ['name' => $post['name'] ?? '', 'occupation' => $post['occupation'] ?? 'farmer', 'avatar_asset_id' => $post['avatar_asset_id'] ?? '', 'tile_x' => (int)($post['tile_x'] ?? 0), 'tile_y' => (int)($post['tile_y'] ?? 0), 'set_position' => ($post['set_position'] ?? '') === '1', 'appearance' => $decode($post['appearance'] ?? ''), 'profile' => ['personality' => $decode($post['personality'] ?? ''), 'family_background' => $post['family_background'] ?? '', 'short_term_goal' => $post['short_term_goal'] ?? '', 'long_term_goal' => $post['long_term_goal'] ?? '', 'speech_style' => $post['speech_style'] ?? '', 'values' => $decode($post['values'] ?? ''), 'fears' => $decode($post['fears'] ?? '')], 'schedule' => $decode($post['schedule'] ?? '[]'), 'enabled' => $enabled, 'agent_paused' => $agentPaused];
            town_npc_upsert($pdo, $body, api_clock(), $id);
            return 'town_npc_save';
        }
    }

    $targetId = in_array($operation, ['shop_price', 'tree_pack_prices', 'tree_fruit_settings'], true)
        ? $actor['user_id'] : admin_page_post_id($post, 'target_id');
    $body = ['target_id' => $targetId, 'reason' => 'web_admin'];
    if ($operation === 'update_user') {
        $body += [
            'action' => 'update_user',
            'username' => isset($post['username']) && is_string($post['username']) ? $post['username'] : '',
            'status' => isset($post['status']) && is_string($post['status']) ? $post['status'] : '',
        ];
    } elseif ($operation === 'enable' || $operation === 'disable') {
        $body['action'] = $operation;
    } elseif ($operation === 'revoke_all') {
        $body['action'] = 'revoke_all_sessions';
    } elseif ($operation === 'revoke_session') {
        $body += ['action' => 'revoke_session', 'session_id' => admin_page_post_id($post, 'session_id')];
    } elseif ($operation === 'set_coins') {
        $body += ['action' => 'set_aquarium_coins', 'coins' => admin_page_nonnegative($post, 'coins', 'invalid_coins')];
    } elseif ($operation === 'delete_user') {
        $body += [
            'action' => 'delete_user',
            'confirm_username' => isset($post['confirm_username']) && is_string($post['confirm_username']) ? $post['confirm_username'] : '',
            'admin_password' => isset($post['admin_password']) && is_string($post['admin_password']) ? $post['admin_password'] : '',
        ];
    } elseif ($operation === 'shop_price') {
        $body += ['action' => 'set_aquarium_card_pack_price', 'card_pack_price' => admin_page_nonnegative($post, 'card_pack_price', 'invalid_price')];
    } elseif ($operation === 'tree_pack_prices') {
        $body += [
            'action' => 'set_tree_decoration_card_pack_prices',
            'tree_card_pack_single_price' => admin_page_nonnegative($post, 'tree_card_pack_single_price', 'invalid_price'),
            'tree_card_pack_ten_price' => admin_page_nonnegative($post, 'tree_card_pack_ten_price', 'invalid_price'),
        ];
    } elseif ($operation === 'tree_fruit_settings') {
        $body += ['action' => 'set_tree_fruit_settings',
            'energy_cost' => admin_page_nonnegative($post, 'energy_cost', 'invalid_tree_fruit_settings'),
            'min_batch' => admin_page_nonnegative($post, 'min_batch', 'invalid_tree_fruit_settings'),
            'max_batch' => admin_page_nonnegative($post, 'max_batch', 'invalid_tree_fruit_settings')];
    } else {
        throw new ApiError('invalid_action', 'Unknown management operation.', 400);
    }
    admin_mutate($pdo, $actor['user_id'], $body, 'api_clock');
    return $operation;
}
