<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__ . '/aquarium.php';

function admin_require(PDO $pdo, string $token, callable $clock): array
{
    $session = auth_find_access_session($pdo, $token, $clock());
    $owner = $pdo->query('SELECT user_id FROM admin_bootstrap WHERE id = 1')->fetchColumn();
    if ($owner !== $session['user_id']) {
        throw new ApiError('admin_forbidden', 'Administrator access is required.', 403);
    }
    return $session;
}

function admin_read(PDO $pdo, string $actorId, string $view, array $query, callable $clock): array
{
    if ($view === 'fruit_recycle') {
        $row = $pdo->query('SELECT price_per_fruit, updated_by, updated_at FROM fruit_recycle_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        return ['price_per_fruit' => (int)($row['price_per_fruit'] ?? 1), 'updated_by' => $row['updated_by'] ?? null,
            'updated_at' => $row['updated_at'] ?? null];
    }
    if ($view === 'shop') {
        $row = $pdo->query('SELECT card_pack_price, updated_by, updated_at FROM aquarium_catalog_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        return ['card_pack_price' => (int)($row['card_pack_price'] ?? aquarium_card_pack_price()),
            'updated_by' => $row['updated_by'] ?? null, 'updated_at' => $row['updated_at'] ?? null];
    }
    if ($view === 'overview') {
        $metrics = [
            'users' => 'SELECT COUNT(*) FROM auth_users',
            'active_users' => "SELECT COUNT(*) FROM auth_users WHERE status = 'active'",
            'sessions' => 'SELECT COUNT(*) FROM auth_sessions WHERE revoked_at IS NULL AND refresh_expires_at > :now',
            'backups' => 'SELECT COUNT(*) FROM account_backup_snapshots',
            'backup_bytes' => 'SELECT COALESCE(SUM(total_bytes), 0) FROM account_backup_snapshots',
            'tree_participants' => 'SELECT COUNT(*) FROM tree_profiles WHERE participation_enabled = 1',
            'dingtalk_links' => "SELECT COUNT(*) FROM account_identities WHERE provider = 'dingtalk'",
        ];
        $result = [];
        foreach ($metrics as $name => $sql) {
            $statement = $pdo->prepare($sql);
            $statement->execute(str_contains($sql, ':now') ? [':now' => auth_sql_time($clock())] : []);
            $result[$name] = (int)$statement->fetchColumn();
        }
        return ['overview' => $result];
    }
    if ($view === 'users') {
        $search = trim((string)($query['q'] ?? ''));
        $page = max(1, min(100000, (int)($query['page'] ?? 1)));
        $pageSize = max(1, min(100, (int)($query['page_size'] ?? 20)));
        $pattern = '%' . strtolower(addcslashes($search, '%_\\')) . '%';
        $count = $pdo->prepare('SELECT COUNT(*) FROM auth_users WHERE username_normalized LIKE :search');
        $count->execute([':search' => $pattern]);
        $total = (int)$count->fetchColumn();
        $pageCount = max(1, (int)ceil($total / $pageSize));
        $page = min($page, $pageCount);
        $offset = ($page - 1) * $pageSize;
        $statement = $pdo->prepare('SELECT u.id, u.username, u.status, u.created_at, '
            . 'CASE WHEN b.user_id = u.id THEN 1 ELSE 0 END AS is_admin '
            . 'FROM auth_users u CROSS JOIN admin_bootstrap b '
            . 'WHERE u.username_normalized LIKE :search ORDER BY u.created_at, u.id LIMIT ' . $pageSize . ' OFFSET ' . $offset);
        $statement->execute([':search' => $pattern]);
        $users = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as &$user) { $user['is_admin'] = (int)$user['is_admin']; }
        unset($user);
        return ['users' => $users, 'total' => $total, 'page' => $page, 'page_size' => $pageSize, 'page_count' => $pageCount];
    }
    if ($view === 'audit') {
        return ['audit' => $pdo->query('SELECT id, actor_id, target_id, action, reason, result, details_json, created_at '
            . 'FROM admin_audit ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC)];
    }
    if ($view !== 'user') { throw new ApiError('invalid_view', 'Unknown administrator view.', 400); }
    $targetId = admin_id($query, 'id');
    $statement = $pdo->prepare('SELECT u.id, u.username, u.status, u.created_at, '
        . 'CASE WHEN b.user_id = u.id THEN 1 ELSE 0 END AS is_admin '
        . 'FROM auth_users u CROSS JOIN admin_bootstrap b WHERE u.id = :id');
    $statement->execute([':id' => $targetId]);
    $user = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($user)) { throw new ApiError('user_not_found', 'Account not found.', 404); }
    $user['is_admin'] = (int)$user['is_admin'];
    $sessions = $pdo->prepare('SELECT id, device_id, created_at, updated_at, access_expires_at, refresh_expires_at, revoked_at '
        . 'FROM auth_sessions WHERE user_id = :id ORDER BY created_at DESC LIMIT 100');
    $sessions->execute([':id' => $targetId]);
    $backups = $pdo->prepare('SELECT id, device_id, app_version, file_count, total_bytes, sha256, created_at '
        . 'FROM account_backup_snapshots WHERE user_id = :id ORDER BY created_at DESC LIMIT 100');
    $backups->execute([':id' => $targetId]);
    $uploads = $pdo->prepare('SELECT id, device_id, app_version, total_bytes, updated_at '
        . 'FROM account_backup_uploads WHERE user_id = :id ORDER BY updated_at DESC LIMIT 50');
    $uploads->execute([':id' => $targetId]);
    $identity = $pdo->prepare('SELECT corp_id, dingtalk_user_id, display_name, organization_name, department_name, created_at '
        . "FROM account_identities WHERE user_id = :id AND provider = 'dingtalk'");
    $identity->execute([':id' => $targetId]);
    $profile = $pdo->prepare('SELECT participation_enabled, state_version, total_energy, stage, fruit_energy, '
        . 'fruit_total, harvested_total FROM tree_profiles WHERE user_id = :id');
    $profile->execute([':id' => $targetId]);
    $treeProfile = $profile->fetch(PDO::FETCH_ASSOC);
    $day = $clock()->format('Y-m-d');
    $growth = 0;
    if (is_array($treeProfile)) {
        $daily = $pdo->prepare('SELECT growth FROM tree_daily_progress WHERE user_id = :id AND progress_day = :day');
        $daily->execute([':id' => $targetId, ':day' => $day]);
        $growth = (int)($daily->fetchColumn() ?: 0);
    }
    $fruitTotal = (int)($treeProfile['fruit_total'] ?? 0);
    $harvested = (int)($treeProfile['harvested_total'] ?? 0);
    $treeState = ['day' => $day, 'state_version' => (int)($treeProfile['state_version'] ?? 0),
        'fruit_cost' => TREE_FRUIT_COST, 'today_growth' => $growth,
        'total_energy' => (int)($treeProfile['total_energy'] ?? 0), 'stage' => (int)($treeProfile['stage'] ?? 1),
        'fruit_energy' => (int)($treeProfile['fruit_energy'] ?? 0),
        'fruit_inventory' => max(0, $fruitTotal - $harvested),
        'fruit_total' => $fruitTotal, 'harvested_total' => $harvested];
    $aquariumProfile = $pdo->prepare('SELECT initialized, coins FROM aquarium_profiles WHERE user_id = :id');
    $aquariumProfile->execute([':id' => $targetId]);
    $aquarium = $aquariumProfile->fetch(PDO::FETCH_ASSOC);
    return ['user' => $user, 'sessions' => $sessions->fetchAll(PDO::FETCH_ASSOC),
        'backups' => $backups->fetchAll(PDO::FETCH_ASSOC),
        'backup_uploads' => $uploads->fetchAll(PDO::FETCH_ASSOC),
        'identity' => $identity->fetch(PDO::FETCH_ASSOC) ?: null,
        'tree' => ['participating' => is_array($treeProfile) && (bool)$treeProfile['participation_enabled'],
            'state' => $treeState],
        'aquarium' => ['initialized' => is_array($aquarium) && (bool)$aquarium['initialized'],
            'coins' => (int)($aquarium['coins'] ?? 0)]];
}

function admin_id(array $values, string $key): string
{
    $id = data_string($values, $key);
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
        throw new ApiError('invalid_target', 'Account identifier is invalid.', 400);
    }
    return $id;
}

function admin_mutate(PDO $pdo, string $actorId, array $body, callable $clock): array
{
    $action = data_string($body, 'action');
    $targetId = admin_id($body, 'target_id');
    $reason = data_string($body, 'reason');
    if (strlen($reason) > 500) {
        throw new ApiError('invalid_reason', 'The operation note must be at most 500 bytes.', 400);
    }
    $reason = $reason === '' ? 'admin_ui' : $reason;
    $allowed = ['disable', 'enable', 'update_user', 'revoke_session', 'revoke_all_sessions', 'delete_user',
        'delete_backup', 'tree_correct', 'tree_reset', 'unlink_dingtalk', 'set_aquarium_coins',
        'set_fruit_recycle_price', 'set_aquarium_card_pack_price'];
    if (!in_array($action, $allowed, true)) { throw new ApiError('invalid_action', 'Unknown action.', 400); }
    if ($action === 'delete_user') {
        auth_rate_limit($pdo, 'admin_delete', hash('sha256', $actorId), 5, 900, $clock);
    }
    $now = $clock();
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    try {
        $pdo->beginTransaction();
        $target = $pdo->prepare('SELECT id, username, status FROM auth_users WHERE id = :id' .
            ($driver === 'mysql' ? ' FOR UPDATE' : ''));
        $target->execute([':id' => $targetId]);
        $user = $target->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) { throw new ApiError('user_not_found', 'Account not found.', 404); }
        $owner = $pdo->query('SELECT user_id FROM admin_bootstrap WHERE id = 1')->fetchColumn();
        if ($targetId === $owner && in_array($action, ['disable', 'delete_user', 'revoke_all_sessions'], true)) {
            throw new ApiError('protected_admin', 'The administrator account is protected.', 403);
        }
        $details = [];
        if ($action === 'update_user') {
            $details = admin_update_user($pdo, $targetId, $body, $now, $driver);
        } elseif ($action === 'disable' || $action === 'enable') {
            $status = $action === 'disable' ? 'disabled' : 'active';
            $update = $pdo->prepare('UPDATE auth_users SET status = :status, updated_at = :now WHERE id = :id');
            $update->execute([':status' => $status, ':now' => auth_sql_time($now), ':id' => $targetId]);
            if ($action === 'disable') { admin_revoke_sessions($pdo, $targetId, null, $now); }
            $details['status'] = $status;
        } elseif ($action === 'revoke_session' || $action === 'revoke_all_sessions') {
            $sessionId = $action === 'revoke_session' ? admin_id($body, 'session_id') : null;
            $details['revoked'] = admin_revoke_sessions($pdo, $targetId, $sessionId, $now);
            if ($sessionId !== null) { $details['session_id'] = $sessionId; }
        } elseif ($action === 'delete_user') {
            if ($targetId === $actorId || data_string($body, 'confirm_username') !== $user['username']) {
                throw new ApiError('delete_confirmation', 'Confirm the exact account name.', 400);
            }
            $password = isset($body['admin_password']) && is_string($body['admin_password'])
                ? $body['admin_password'] : '';
            $check = $pdo->prepare('SELECT password_hash FROM auth_users WHERE id = :id');
            $check->execute([':id' => $actorId]);
            if ($password === '' || !password_verify($password, (string)$check->fetchColumn())) {
                throw new ApiError('invalid_credentials', 'Administrator password is incorrect.', 401);
            }
            $pdo->prepare('DELETE FROM auth_users WHERE id = :id')->execute([':id' => $targetId]);
        } elseif ($action === 'delete_backup') {
            $snapshotId = admin_id($body, 'snapshot_id');
            $delete = $pdo->prepare('DELETE FROM account_backup_snapshots WHERE id = :snapshot AND user_id = :user');
            $delete->execute([':snapshot' => $snapshotId, ':user' => $targetId]);
            if ($delete->rowCount() !== 1) { throw new ApiError('backup_not_found', 'Backup not found.', 404); }
            $details['snapshot_id'] = $snapshotId;
        } elseif ($action === 'unlink_dingtalk') {
            $delete = $pdo->prepare("DELETE FROM account_identities WHERE user_id = :id AND provider = 'dingtalk'");
            $delete->execute([':id' => $targetId]);
            $details['unlinked'] = $delete->rowCount() > 0;
        } elseif ($action === 'set_aquarium_coins') {
            $details = admin_set_aquarium_coins($pdo, $targetId, $body, $now, $driver);
        } elseif ($action === 'set_fruit_recycle_price') {
            if ($targetId !== $actorId) { throw new ApiError('invalid_target', 'Recycle price changes must target the administrator.', 400); }
            $details = admin_set_fruit_recycle_price($pdo, $actorId, $body, $now, $driver);
        } elseif ($action === 'set_aquarium_card_pack_price') {
            if ($targetId !== $actorId) { throw new ApiError('invalid_target', 'Shop price changes must target the administrator.', 400); }
            $details = admin_set_aquarium_card_pack_price($pdo, $actorId, $body, $now, $driver);
        } else {
            $details = admin_change_tree($pdo, $targetId, $action, $body, $now, $driver);
        }
        admin_write_audit($pdo, $actorId, $targetId, $action, $reason, 'success', $details, $now);
        $pdo->commit();
        if ($action === 'delete_user') {
            auth_clear_rate_limit($pdo, 'admin_delete', hash('sha256', $actorId));
        }
        return ['ok' => true, 'details' => $details];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        try {
            admin_write_audit($pdo, $actorId, $targetId, $action, $reason, 'failed',
                ['code' => $error instanceof ApiError ? $error->apiCode : 'server_error'], $now);
        } catch (Throwable $auditError) {
            error_log('[DeskBoxAdmin] Failed to write failure audit: ' . $auditError->getMessage());
        }
        throw $error;
    }
}

function admin_update_user(PDO $pdo, string $userId, array $body,
    DateTimeImmutable $now, string $driver): array
{
    $username = trim((string)($body['username'] ?? ''));
    $status = (string)($body['status'] ?? '');
    if (!preg_match('/\A[A-Za-z0-9_]{3,32}\z/D', $username)) {
        throw new ApiError('invalid_username', 'Username must be 3-32 letters, numbers, or underscores.', 400);
    }
    if (!in_array($status, ['active', 'disabled'], true)) {
        throw new ApiError('invalid_status', 'Account status is invalid.', 400);
    }
    $normalized = strtolower($username);
    $duplicate = $pdo->prepare('SELECT id FROM auth_users WHERE username_normalized = :normalized AND id <> :id LIMIT 1');
    $duplicate->execute([':normalized' => $normalized, ':id' => $userId]);
    if ($duplicate->fetchColumn() !== false) {
        throw new ApiError('username_taken', 'This username is already registered.', 409);
    }
    $owner = $pdo->query('SELECT user_id FROM admin_bootstrap WHERE id = 1')->fetchColumn();
    if ($userId === $owner && $status !== 'active') {
        throw new ApiError('protected_admin', 'The administrator account is protected.', 403);
    }
    $update = $pdo->prepare('UPDATE auth_users SET username = :username, username_normalized = :normalized, status = :status, updated_at = :now WHERE id = :id');
    $update->execute([':username' => $username, ':normalized' => $normalized, ':status' => $status,
        ':now' => auth_sql_time($now), ':id' => $userId]);
    if ($status === 'disabled') { admin_revoke_sessions($pdo, $userId, null, $now); }
    return ['username' => $username, 'status' => $status];
}

function admin_set_fruit_recycle_price(PDO $pdo, string $actorId, array $body,
    DateTimeImmutable $now, string $driver): array
{
    $price = $body['price_per_fruit'] ?? null;
    if (!is_int($price) || $price < 0 || $price > 2147483647) {
        throw new ApiError('invalid_price', 'Fruit recycle price is invalid.', 400);
    }
    $insert = $driver === 'sqlite'
        ? 'INSERT INTO fruit_recycle_settings (id, price_per_fruit, updated_by, updated_at) VALUES (1, :price, :actor, :now) ON CONFLICT(id) DO UPDATE SET price_per_fruit = excluded.price_per_fruit, updated_by = excluded.updated_by, updated_at = excluded.updated_at'
        : 'INSERT INTO fruit_recycle_settings (id, price_per_fruit, updated_by, updated_at) VALUES (1, :price, :actor, :now) ON DUPLICATE KEY UPDATE price_per_fruit = VALUES(price_per_fruit), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)';
    $pdo->prepare($insert)->execute([':price' => $price, ':actor' => $actorId, ':now' => auth_sql_time($now)]);
    return ['price_per_fruit' => $price];
}

function admin_set_aquarium_card_pack_price(PDO $pdo, string $actorId, array $body,
    DateTimeImmutable $now, string $driver): array
{
    $price = $body['card_pack_price'] ?? null;
    if (!is_int($price) || $price < 0 || $price > 2147483647) {
        throw new ApiError('invalid_price', 'Aquarium card pack price is invalid.', 400);
    }
    $insert = $driver === 'sqlite'
        ? 'INSERT INTO aquarium_catalog_settings (id, card_pack_price, updated_by, updated_at) VALUES (1, :price, :actor, :now) ON CONFLICT(id) DO UPDATE SET card_pack_price = excluded.card_pack_price, updated_by = excluded.updated_by, updated_at = excluded.updated_at'
        : 'INSERT INTO aquarium_catalog_settings (id, card_pack_price, updated_by, updated_at) VALUES (1, :price, :actor, :now) ON DUPLICATE KEY UPDATE card_pack_price = VALUES(card_pack_price), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)';
    $pdo->prepare($insert)->execute([':price' => $price, ':actor' => $actorId, ':now' => auth_sql_time($now)]);
    return ['card_pack_price' => $price];
}

function admin_set_aquarium_coins(PDO $pdo, string $userId, array $body,
    DateTimeImmutable $now, string $driver): array
{
    $coins = $body['coins'] ?? null;
    if (!is_int($coins) || $coins < 0 || $coins > 2147483647) {
        throw new ApiError('invalid_coins', 'Aquarium coin balance is invalid.', 400);
    }
    $insert = $driver === 'sqlite'
        ? 'INSERT INTO aquarium_profiles (user_id, initialized, coins, scene_version, scene_json, created_at, updated_at) '
            . "VALUES (:user, 0, 0, 0, '[]', :now, :now) ON CONFLICT(user_id) DO NOTHING"
        : 'INSERT INTO aquarium_profiles (user_id, initialized, coins, scene_version, scene_json, created_at, updated_at) '
            . "VALUES (:user, 0, 0, 0, '[]', :now, :now) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)";
    $pdo->prepare($insert)->execute([':user' => $userId, ':now' => auth_sql_time($now)]);
    $profile = $pdo->prepare('SELECT initialized, coins FROM aquarium_profiles WHERE user_id = :user'
        . ($driver === 'mysql' ? ' FOR UPDATE' : ''));
    $profile->execute([':user' => $userId]);
    $current = $profile->fetch(PDO::FETCH_ASSOC);
    if (!is_array($current)) { throw new ApiError('server_error', 'Aquarium profile could not be created.', 500); }
    $before = (int)$current['coins'];
    $delta = $coins - $before;
    $pdo->prepare('UPDATE aquarium_profiles SET initialized = 1, coins = :coins, updated_at = :now WHERE user_id = :user')
        ->execute([':coins' => $coins, ':now' => auth_sql_time($now), ':user' => $userId]);
    if ($delta !== 0) {
        aquarium_record_wallet($pdo, $userId, 'admin-' . auth_random_id(), $delta, $coins,
            'admin_adjustment', null, $now);
    }
    return ['initialized_before' => (bool)$current['initialized'], 'initialized' => true,
        'before' => $before, 'after' => $coins, 'delta' => $delta];
}

function admin_write_audit(PDO $pdo, string $actorId, string $targetId, string $action,
    string $reason, string $result, array $details, DateTimeImmutable $now): void
{
    $audit = $pdo->prepare('INSERT INTO admin_audit '
        . '(actor_id, target_id, action, reason, result, details_json, created_at) '
        . 'VALUES (:actor, :target, :action, :reason, :result, :details, :now)');
    $audit->execute([':actor' => $actorId, ':target' => $targetId, ':action' => $action,
        ':reason' => $reason, ':result' => $result,
        ':details' => json_encode($details, JSON_THROW_ON_ERROR), ':now' => auth_sql_time($now)]);
}

function admin_revoke_sessions(PDO $pdo, string $userId, ?string $sessionId, DateTimeImmutable $now): int
{
    $sql = 'UPDATE auth_sessions SET revoked_at = :now, updated_at = :updated '
        . 'WHERE user_id = :user AND revoked_at IS NULL';
    if ($sessionId !== null) { $sql .= ' AND id = :session'; }
    $statement = $pdo->prepare($sql);
    $params = [':now' => auth_sql_time($now), ':updated' => auth_sql_time($now), ':user' => $userId];
    if ($sessionId !== null) { $params[':session'] = $sessionId; }
    $statement->execute($params);
    return $statement->rowCount();
}

function admin_change_tree(PDO $pdo, string $userId, string $action, array $body,
    DateTimeImmutable $now, string $driver): array
{
    tree_ensure_profile($pdo, $userId, $now, $driver);
    $lock = $pdo->prepare('SELECT state_version FROM tree_profiles WHERE user_id = :id'
        . ($driver === 'mysql' ? ' FOR UPDATE' : ''));
    $lock->execute([':id' => $userId]);
    $current = tree_read_state($pdo, $userId, $now->format('Y-m-d'));
    if (data_string($body, 'day') !== $now->format('Y-m-d')) {
        throw new ApiError('state_conflict', 'The state day is stale.', 409);
    }
    $version = data_nonnegative_int($body, 'base_version');
    if ($version !== $current['state_version']) {
        throw new ApiError('state_conflict', 'Tree state changed on another device.', 409);
    }
    $energy = $action === 'tree_reset' ? 0 : data_nonnegative_int($body, 'total_energy');
    $growth = $action === 'tree_reset' ? 0 : data_nonnegative_int($body, 'today_growth');
    $fruitEnergy = $action === 'tree_reset' ? 0 : data_nonnegative_int($body, 'fruit_energy');
    $fruitTotal = $action === 'tree_reset' ? 0 : data_nonnegative_int($body, 'fruit_total');
    $harvested = $action === 'tree_reset' ? 0 : data_nonnegative_int($body, 'harvested_total');
    if ($growth > $energy || $fruitEnergy >= TREE_FRUIT_COST || $harvested > $fruitTotal) {
        throw new ApiError('invalid_state', 'Tree state is inconsistent.', 400);
    }
    $fruitBudget = max(0, $energy - 144000);
    if ($fruitTotal > intdiv($fruitBudget, TREE_FRUIT_COST)
        || ($fruitTotal === intdiv($fruitBudget, TREE_FRUIT_COST)
            && $fruitEnergy > $fruitBudget % TREE_FRUIT_COST)) {
        throw new ApiError('invalid_state', 'Fruit exceeds post-maturity energy.', 400);
    }
    $update = $pdo->prepare('UPDATE tree_profiles SET total_energy = :energy, stage = :stage, '
        . 'fruit_energy = :fruit_energy, fruit_total = :fruit_total, harvested_total = :harvested, '
        . 'state_version = state_version + 1, updated_at = :now WHERE user_id = :id');
    $update->execute([':energy' => $energy, ':stage' => tree_stage($energy), ':fruit_energy' => $fruitEnergy,
        ':fruit_total' => $fruitTotal, ':harvested' => $harvested, ':now' => auth_sql_time($now), ':id' => $userId]);
    $daily = $driver === 'sqlite'
        ? 'INSERT INTO tree_daily_progress (user_id, progress_day, growth) VALUES (:id, :day, :growth) '
            . 'ON CONFLICT(user_id, progress_day) DO UPDATE SET growth = excluded.growth'
        : 'INSERT INTO tree_daily_progress (user_id, progress_day, growth) VALUES (:id, :day, :growth) '
            . 'ON DUPLICATE KEY UPDATE growth = VALUES(growth)';
    $statement = $pdo->prepare($daily);
    $statement->execute([':id' => $userId, ':day' => $now->format('Y-m-d'), ':growth' => $growth]);
    return ['before' => $current, 'after' => tree_read_state($pdo, $userId, $now->format('Y-m-d'))];
}
