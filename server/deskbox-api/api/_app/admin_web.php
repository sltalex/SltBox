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

function admin_page_mutate(PDO $pdo, array $actor, array $post): string
{
    web_admin_require_csrf($post);
    $operation = isset($post['operation']) && is_string($post['operation']) ? $post['operation'] : '';
    if ($operation === 'logout') { web_admin_sign_out(); return 'logged_out'; }

    if ($operation === 'fruit_catalog_item') {
        fruit_admin_update($pdo,$post,api_clock());
        return 'fruit_catalog_item';
    }

    $targetId = $operation === 'shop_price'
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
    } else {
        throw new ApiError('invalid_action', 'Unknown management operation.', 400);
    }
    admin_mutate($pdo, $actor['user_id'], $body, 'api_clock');
    return $operation;
}
