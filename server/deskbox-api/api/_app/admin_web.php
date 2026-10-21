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
