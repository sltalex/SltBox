<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) {
    http_response_code(404);
    exit;
}

final class ApiError extends RuntimeException
{
    public function __construct(
        public readonly string $apiCode,
        string $message,
        public readonly int $httpStatus
    ) {
        parent::__construct($message);
    }
}

function auth_register(PDO $pdo, string $username, string $password, string $deviceId, string $remoteAddress, callable $clock): array
{
    [$displayName, $normalizedName] = auth_validate_credentials($username, $password);
    auth_rate_limit($pdo, 'register', hash('sha256', $remoteAddress), 5, 3600, $clock);
    $now = $clock();
    $userId = auth_random_id();
    try {
        $pdo->beginTransaction();
        $bootstrap = $pdo->prepare('SELECT user_id FROM admin_bootstrap WHERE id = 1' .
            ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
        $bootstrap->execute();
        $firstUser = $bootstrap->fetchColumn() === null;
        $statement = $pdo->prepare(
            'INSERT INTO auth_users (id, username, username_normalized, password_hash, created_at, updated_at) '
            . 'VALUES (:id, :username, :normalized, :password_hash, :created_at, :updated_at)'
        );
        $statement->execute([
            ':id' => $userId,
            ':username' => $displayName,
            ':normalized' => $normalizedName,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':created_at' => auth_sql_time($now),
            ':updated_at' => auth_sql_time($now),
        ]);
        if ($firstUser) {
            $owner = $pdo->prepare('UPDATE admin_bootstrap SET user_id = :user_id WHERE id = 1 AND user_id IS NULL');
            $owner->execute([':user_id' => $userId]);
        }
        $session = auth_issue_session($pdo, $userId, $displayName, $deviceId, $now);
        $pdo->commit();
        return $session;
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (auth_is_unique_violation($error)) {
            throw new ApiError('username_taken', 'This username is already registered.', 409);
        }
        throw $error;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function auth_login(PDO $pdo, string $username, string $password, string $deviceId, string $remoteAddress, callable $clock): array
{
    $normalizedName = auth_normalize_username($username);
    $bucket = hash('sha256', $remoteAddress . "\0" . $normalizedName);
    auth_rate_limit($pdo, 'login', $bucket, 5, 900, $clock);
    $statement = $pdo->prepare(
        'SELECT id, username, password_hash, status FROM auth_users WHERE username_normalized = :username LIMIT 1'
    );
    $statement->execute([':username' => $normalizedName]);
    $user = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($user) || !password_verify($password, (string)$user['password_hash'])) {
        throw new ApiError('invalid_credentials', 'The username or password is incorrect.', 401);
    }
    if ($user['status'] !== 'active') { throw new ApiError('account_disabled', 'This account is disabled.', 403); }
    if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
        $update = $pdo->prepare('UPDATE auth_users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id');
        $update->execute([
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':updated_at' => auth_sql_time($clock()),
            ':id' => $user['id'],
        ]);
    }
    auth_clear_rate_limit($pdo, 'login', $bucket);
    return auth_issue_session($pdo, (string)$user['id'], (string)$user['username'], $deviceId, $clock());
}

function auth_refresh(PDO $pdo, string $refreshToken, callable $clock): array
{
    if ($refreshToken === '') {
        throw new ApiError('invalid_session', 'The session is invalid or expired.', 401);
    }
    $now = $clock();
    $statement = $pdo->prepare(
        'SELECT s.id, s.user_id, s.device_id, s.refresh_expires_at, s.revoked_at, u.username, u.status '
        . 'FROM auth_sessions s JOIN auth_users u ON u.id = s.user_id '
        . 'WHERE s.refresh_token_hash = :token_hash LIMIT 1'
    );
    $oldRefreshHash = hash('sha256', $refreshToken);
    $statement->execute([':token_hash' => $oldRefreshHash]);
    $session = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($session) || $session['status'] !== 'active' || $session['revoked_at'] !== null || auth_parse_sql_time((string)$session['refresh_expires_at']) <= $now) {
        throw new ApiError('invalid_session', 'The session is invalid or expired.', 401);
    }
    $tokens = auth_new_tokens($now);
    $update = $pdo->prepare(
        'UPDATE auth_sessions SET access_token_hash = :access_hash, refresh_token_hash = :refresh_hash, '
        . 'access_expires_at = :access_expires, refresh_expires_at = :refresh_expires, updated_at = :updated_at '
        . 'WHERE id = :id AND refresh_token_hash = :old_refresh_hash AND revoked_at IS NULL'
    );
    $update->execute([
        ':access_hash' => hash('sha256', $tokens['access_token']),
        ':refresh_hash' => hash('sha256', $tokens['refresh_token']),
        ':access_expires' => auth_sql_time($tokens['access_expires_at_value']),
        ':refresh_expires' => auth_sql_time($tokens['refresh_expires_at_value']),
        ':updated_at' => auth_sql_time($now),
        ':id' => $session['id'],
        ':old_refresh_hash' => $oldRefreshHash,
    ]);
    if ($update->rowCount() !== 1) {
        throw new ApiError('invalid_session', 'The session is invalid or expired.', 401);
    }
    return [
        'user' => auth_public_user($pdo, (string)$session['user_id'], (string)$session['username']),
        'session' => auth_public_tokens($tokens),
    ];
}

function auth_me(PDO $pdo, string $accessToken, callable $clock): array
{
    $session = auth_find_access_session($pdo, $accessToken, $clock());
    return ['user' => auth_public_user($pdo, (string)$session['user_id'], (string)$session['username'])];
}

function auth_logout(PDO $pdo, string $accessToken, callable $clock): void
{
    if ($accessToken === '') {
        return;
    }
    $now = auth_sql_time($clock());
    $statement = $pdo->prepare(
        'UPDATE auth_sessions SET revoked_at = :revoked_at, updated_at = :updated_at '
        . 'WHERE access_token_hash = :token_hash AND revoked_at IS NULL'
    );
    $statement->execute([':revoked_at' => $now, ':updated_at' => $now, ':token_hash' => hash('sha256', $accessToken)]);
}

function auth_validate_credentials(string $username, string $password): array
{
    $displayName = trim($username);
    if (!preg_match('/\A[A-Za-z0-9_]{3,32}\z/D', $displayName)) {
        throw new ApiError('invalid_username', 'Username must be 3-32 letters, numbers, or underscores.', 400);
    }
    $passwordLength = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : preg_match_all('/./us', $password, $unused);
    if ($passwordLength === false || $passwordLength < 6 || $passwordLength > 128) {
        throw new ApiError('invalid_password', 'Password must be 6-128 characters.', 400);
    }
    return [$displayName, strtolower($displayName)];
}

function auth_normalize_username(string $username): string
{
    $displayName = trim($username);
    return preg_match('/\A[A-Za-z0-9_]{3,32}\z/D', $displayName) ? strtolower($displayName) : '__invalid__';
}

function auth_issue_session(PDO $pdo, string $userId, string $username, string $deviceId, DateTimeImmutable $now): array
{
    $tokens = auth_new_tokens($now);
    $statement = $pdo->prepare(
        'INSERT INTO auth_sessions (id, user_id, device_id, access_token_hash, refresh_token_hash, access_expires_at, '
        . 'refresh_expires_at, revoked_at, created_at, updated_at) VALUES (:id, :user_id, :device_id, :access_hash, '
        . ':refresh_hash, :access_expires, :refresh_expires, NULL, :created_at, :updated_at)'
    );
    $statement->execute([
        ':id' => auth_random_id(), ':user_id' => $userId, ':device_id' => substr($deviceId, 0, 128),
        ':access_hash' => hash('sha256', $tokens['access_token']), ':refresh_hash' => hash('sha256', $tokens['refresh_token']),
        ':access_expires' => auth_sql_time($tokens['access_expires_at_value']),
        ':refresh_expires' => auth_sql_time($tokens['refresh_expires_at_value']),
        ':created_at' => auth_sql_time($now), ':updated_at' => auth_sql_time($now),
    ]);
    return ['user' => auth_public_user($pdo, $userId, $username), 'session' => auth_public_tokens($tokens)];
}

function auth_public_user(PDO $pdo, string $userId, string $username): array
{
    $owner = $pdo->query('SELECT user_id FROM admin_bootstrap WHERE id = 1')->fetchColumn();
    return ['id' => $userId, 'username' => $username,
        'role' => $owner === $userId ? 'admin' : 'user'];
}

function auth_find_access_session(PDO $pdo, string $accessToken, DateTimeImmutable $now): array
{
    if ($accessToken === '') {
        throw new ApiError('invalid_session', 'The session is invalid or expired.', 401);
    }
    $statement = $pdo->prepare(
        'SELECT s.id, s.user_id, s.access_expires_at, s.revoked_at, u.username, u.status FROM auth_sessions s '
        . 'JOIN auth_users u ON u.id = s.user_id WHERE s.access_token_hash = :token_hash LIMIT 1'
    );
    $statement->execute([':token_hash' => hash('sha256', $accessToken)]);
    $session = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($session) || $session['status'] !== 'active' || $session['revoked_at'] !== null || auth_parse_sql_time((string)$session['access_expires_at']) <= $now) {
        throw new ApiError('invalid_session', 'The session is invalid or expired.', 401);
    }
    return $session;
}

function auth_rate_limit(PDO $pdo, string $action, string $bucket, int $maximum, int $windowSeconds, callable $clock): void
{
    $now = $clock();
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    try {
        $pdo->beginTransaction();
        $insertSql = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO auth_rate_limits (bucket_key, action, window_started_at, attempts) VALUES (:bucket, :action, :started_at, 0)'
            : 'INSERT IGNORE INTO auth_rate_limits (bucket_key, action, window_started_at, attempts) VALUES (:bucket, :action, :started_at, 0)';
        $insert = $pdo->prepare($insertSql);
        $insert->execute([':bucket' => $bucket, ':action' => $action, ':started_at' => auth_sql_time($now)]);

        $lockSuffix = $driver === 'sqlite' ? '' : ' FOR UPDATE';
        $statement = $pdo->prepare(
            'SELECT id, attempts, window_started_at FROM auth_rate_limits '
            . 'WHERE bucket_key = :bucket AND action = :action LIMIT 1' . $lockSuffix
        );
        $statement->execute([':bucket' => $bucket, ':action' => $action]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Rate-limit bucket could not be created.');
        }

        $windowStart = auth_parse_sql_time((string)$row['window_started_at']);
        if ($windowStart->modify("+{$windowSeconds} seconds") <= $now) {
            $reset = $pdo->prepare('UPDATE auth_rate_limits SET attempts = 1, window_started_at = :started_at WHERE id = :id');
            $reset->execute([':started_at' => auth_sql_time($now), ':id' => $row['id']]);
        } elseif ((int)$row['attempts'] >= $maximum) {
            throw new ApiError('rate_limited', 'Too many attempts. Please try again later.', 429);
        } else {
            $update = $pdo->prepare('UPDATE auth_rate_limits SET attempts = attempts + 1 WHERE id = :id');
            $update->execute([':id' => $row['id']]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function auth_clear_rate_limit(PDO $pdo, string $action, string $bucket): void
{
    $statement = $pdo->prepare('DELETE FROM auth_rate_limits WHERE bucket_key = :bucket AND action = :action');
    $statement->execute([':bucket' => $bucket, ':action' => $action]);
}

function auth_new_tokens(DateTimeImmutable $now): array
{
    return [
        'access_token' => auth_random_token(), 'refresh_token' => auth_random_token(),
        'access_expires_at_value' => $now->modify('+15 minutes'),
        'refresh_expires_at_value' => $now->modify('+30 days'),
    ];
}

function auth_public_tokens(array $tokens): array
{
    return [
        'access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'],
        'access_expires_at' => $tokens['access_expires_at_value']->format(DateTimeInterface::ATOM),
        'refresh_expires_at' => $tokens['refresh_expires_at_value']->format(DateTimeInterface::ATOM),
    ];
}

function auth_random_token(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
function auth_random_id(): string { return bin2hex(random_bytes(16)); }
function auth_sql_time(DateTimeImmutable $time): string { return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
function auth_parse_sql_time(string $value): DateTimeImmutable { return new DateTimeImmutable($value, new DateTimeZone('UTC')); }
function auth_is_unique_violation(PDOException $error): bool { return in_array((string)$error->getCode(), ['23000', '19'], true); }
