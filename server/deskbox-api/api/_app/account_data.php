<?php
declare(strict_types=1);

const TREE_FRUIT_COST = 3000;
const TREE_STAGE_INTERVAL = 36000;

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }

const ACCOUNT_BACKUP_MAX_FILES = 128;
const ACCOUNT_BACKUP_MAX_FILE_BYTES = 2 * 1024 * 1024;
const ACCOUNT_BACKUP_MAX_TOTAL_BYTES = 16 * 1024 * 1024;
const ACCOUNT_BACKUP_UPLOAD_MAX_BYTES = 64 * 1024 * 1024;
const ACCOUNT_BACKUP_UPLOAD_CHUNK_BYTES = 4 * 1024 * 1024;

function account_authenticated_user(PDO $pdo, callable $clock): array
{
    return auth_find_access_session($pdo, api_bearer_token(), $clock());
}

function backup_create_snapshot(
    PDO $pdo,
    string $userId,
    string $deviceId,
    string $appVersion,
    array $files,
    callable $clock
): array {
    $deviceId = trim($deviceId);
    if ($deviceId === '' || strlen($deviceId) > 128) {
        throw new ApiError('invalid_device', 'Device identifier is invalid.', 400);
    }
    if (strlen($appVersion) > 64) { throw new ApiError('invalid_backup', 'App version is invalid.', 400); }
    $validated = backup_validate_files($files);
    $snapshotId = auth_random_id();
    $now = $clock();
    $manifestHash = backup_manifest_hash($validated);
    $totalBytes = array_sum(array_column($validated, 'bytes'));
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT id FROM auth_users WHERE id = :id LIMIT 1' . ($driver === 'sqlite' ? '' : ' FOR UPDATE'));
        $lock->execute([':id' => $userId]);
        if ($lock->fetchColumn() === false) { throw new ApiError('invalid_session', 'The session is invalid or expired.', 401); }

        $insert = $pdo->prepare(
            'INSERT INTO account_backup_snapshots (id, user_id, device_id, app_version, file_count, total_bytes, sha256, created_at) '
            . 'VALUES (:id, :user_id, :device_id, :app_version, :file_count, :total_bytes, :sha256, :created_at)'
        );
        $insert->execute([
            ':id' => $snapshotId, ':user_id' => $userId, ':device_id' => $deviceId,
            ':app_version' => $appVersion, ':file_count' => count($validated), ':total_bytes' => $totalBytes,
            ':sha256' => $manifestHash, ':created_at' => auth_sql_time($now),
        ]);
        $fileInsert = $pdo->prepare(
            'INSERT INTO account_backup_files (snapshot_id, relative_path, byte_count, sha256, json_content) '
            . 'VALUES (:snapshot_id, :path, :bytes, :sha256, :content)'
        );
        foreach ($validated as $file) {
            $fileInsert->execute([
                ':snapshot_id' => $snapshotId, ':path' => $file['path'], ':bytes' => $file['bytes'],
                ':sha256' => $file['sha256'], ':content' => $file['content'],
            ]);
        }

        $old = $pdo->prepare(
            'SELECT id FROM account_backup_snapshots WHERE user_id = :user_id '
            . 'ORDER BY created_at DESC, id DESC LIMIT 100 OFFSET 5'
        );
        $old->execute([':user_id' => $userId]);
        $delete = $pdo->prepare('DELETE FROM account_backup_snapshots WHERE id = :id AND user_id = :user_id');
        foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $oldId) {
            $delete->execute([':id' => $oldId, ':user_id' => $userId]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }

    return ['snapshot' => [
        'id' => $snapshotId, 'device_id' => $deviceId, 'created_at' => $now->format(DateTimeInterface::ATOM),
        'app_version' => $appVersion, 'file_count' => count($validated), 'total_bytes' => $totalBytes,
        'sha256' => $manifestHash,
    ]];
}

function backup_list_snapshots(PDO $pdo, string $userId): array
{
    $statement = $pdo->prepare(
        'SELECT id, device_id, created_at, app_version, file_count, total_bytes, sha256 '
        . 'FROM account_backup_snapshots WHERE user_id = :user_id ORDER BY created_at DESC, id DESC LIMIT 5'
    );
    $statement->execute([':user_id' => $userId]);
    $items = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = backup_public_snapshot($row);
    }
    return ['snapshots' => $items];
}

function backup_download_snapshot(PDO $pdo, string $userId, string $snapshotId): array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $snapshotId)) {
        throw new ApiError('snapshot_not_found', 'Backup snapshot was not found.', 404);
    }
    $statement = $pdo->prepare(
        'SELECT id, device_id, created_at, app_version, file_count, total_bytes, sha256 '
        . 'FROM account_backup_snapshots WHERE id = :id AND user_id = :user_id LIMIT 1'
    );
    $statement->execute([':id' => $snapshotId, ':user_id' => $userId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) { throw new ApiError('snapshot_not_found', 'Backup snapshot was not found.', 404); }
    $filesStatement = $pdo->prepare(
        'SELECT relative_path, byte_count, sha256, json_content FROM account_backup_files '
        . 'WHERE snapshot_id = :snapshot_id ORDER BY relative_path ASC'
    );
    $filesStatement->execute([':snapshot_id' => $snapshotId]);
    $files = [];
    foreach ($filesStatement->fetchAll(PDO::FETCH_ASSOC) as $file) {
        $files[] = [
            'path' => (string)$file['relative_path'], 'bytes' => (int)$file['byte_count'],
            'sha256' => (string)$file['sha256'], 'content' => (string)$file['json_content'],
        ];
    }
    $snapshot = backup_public_snapshot($row);
    $snapshot['files'] = $files;
    return ['snapshot' => $snapshot];
}

function backup_validate_files(array $files): array
{
    if (count($files) < 1 || count($files) > ACCOUNT_BACKUP_MAX_FILES) {
        throw new ApiError('invalid_backup', 'Backup file count is invalid.', 400);
    }
    $seen = [];
    $total = 0;
    $validated = [];
    foreach ($files as $candidate) {
        if (!is_array($candidate)) { throw new ApiError('invalid_backup', 'Backup file entry is invalid.', 400); }
        $path = isset($candidate['path']) && is_string($candidate['path']) ? str_replace('\\', '/', $candidate['path']) : '';
        $content = isset($candidate['content']) && is_string($candidate['content']) ? $candidate['content'] : '';
        $bytes = isset($candidate['bytes']) && is_int($candidate['bytes']) ? $candidate['bytes'] : -1;
        $sha256 = isset($candidate['sha256']) && is_string($candidate['sha256']) ? strtolower($candidate['sha256']) : '';
        $segments = explode('/', $path);
        if ($path === '' || strlen($path) > 512 || str_starts_with($path, '/') || !str_ends_with(strtolower($path), '.json') ||
            in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true) ||
            preg_match('/[\x00-\x1F<>:"|?*]/', $path)) {
            throw new ApiError('invalid_backup_path', 'Backup contains an invalid path.', 400);
        }
        $key = strtolower($path);
        if (isset($seen[$key])) { throw new ApiError('invalid_backup_path', 'Backup contains a duplicate path.', 400); }
        $seen[$key] = true;
        $actualBytes = strlen($content);
        if ($bytes !== $actualBytes || $bytes > ACCOUNT_BACKUP_MAX_FILE_BYTES) {
            throw new ApiError('backup_size_mismatch', 'Backup file size does not match.', 400);
        }
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $sha256) || !hash_equals($sha256, hash('sha256', $content))) {
            throw new ApiError('backup_hash_mismatch', 'Backup file hash does not match.', 400);
        }
        try { json_decode($content, false, 128, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new ApiError('invalid_backup_json', 'Backup file is not valid JSON.', 400); }
        $total += $actualBytes;
        if ($total > ACCOUNT_BACKUP_MAX_TOTAL_BYTES) { throw new ApiError('backup_too_large', 'Backup is too large.', 413); }
        $validated[] = ['path' => $path, 'content' => $content, 'bytes' => $actualBytes, 'sha256' => $sha256];
    }
    usort($validated, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
    return $validated;
}

function backup_manifest_hash(array $files): string
{
    $context = hash_init('sha256');
    foreach ($files as $file) {
        hash_update($context, $file['path'] . "\0" . $file['sha256'] . "\0" . (string)$file['bytes'] . "\n");
    }
    return hash_final($context);
}

function backup_upload_init(PDO $pdo, string $userId, array $body, callable $clock): array
{
    $deviceId = api_string($body, 'device_id');
    $appVersion = api_string($body, 'app_version');
    $total = isset($body['total_bytes']) && is_int($body['total_bytes']) ? $body['total_bytes'] : 0;
    $sha = strtolower(api_string($body, 'sha256'));
    if ($deviceId === '' || strlen($deviceId) > 128 || strlen($appVersion) > 64 || $total < 1 || $total > ACCOUNT_BACKUP_UPLOAD_MAX_BYTES || !preg_match('/\A[a-f0-9]{64}\z/D', $sha)) {
        throw new ApiError('invalid_upload', 'Backup upload metadata is invalid.', 400);
    }
    $existing = $pdo->prepare('SELECT id FROM account_backup_uploads WHERE user_id = :user_id AND device_id = :device AND total_bytes = :total AND sha256 = :sha ORDER BY updated_at DESC LIMIT 1');
    $existing->execute([':user_id' => $userId, ':device' => $deviceId, ':total' => $total, ':sha' => $sha]);
    $existingId = $existing->fetchColumn();
    if (is_string($existingId) && $existingId !== '') {
        return ['upload_id' => $existingId, 'chunk_size' => ACCOUNT_BACKUP_UPLOAD_CHUNK_BYTES, 'received' => backup_upload_received($pdo, $existingId)];
    }
    $id = auth_random_id(); $now = $clock();
    $insert = $pdo->prepare('INSERT INTO account_backup_uploads (id, user_id, device_id, app_version, total_bytes, sha256, created_at, updated_at) VALUES (:id, :user_id, :device, :version, :total, :sha, :created, :updated)');
    $insert->execute([':id' => $id, ':user_id' => $userId, ':device' => $deviceId, ':version' => $appVersion, ':total' => $total, ':sha' => $sha, ':created' => auth_sql_time($now), ':updated' => auth_sql_time($now)]);
    return ['upload_id' => $id, 'chunk_size' => ACCOUNT_BACKUP_UPLOAD_CHUNK_BYTES, 'received' => []];
}

function backup_upload_root(): string
{
    $root = dirname(__DIR__, 2) . '/_private_uploads';
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) { throw new RuntimeException('Upload storage is unavailable.'); }
    return $root;
}

function backup_upload_read(PDO $pdo, string $userId, string $uploadId): array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $uploadId)) { throw new ApiError('upload_not_found', 'Backup upload was not found.', 404); }
    $statement = $pdo->prepare('SELECT * FROM account_backup_uploads WHERE id = :id AND user_id = :user_id LIMIT 1');
    $statement->execute([':id' => $uploadId, ':user_id' => $userId]);
    $upload = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($upload)) { throw new ApiError('upload_not_found', 'Backup upload was not found.', 404); }
    return $upload;
}

function backup_upload_received(PDO $pdo, string $uploadId): array
{
    $statement = $pdo->prepare('SELECT part_start, part_end FROM account_backup_upload_parts WHERE upload_id = :id ORDER BY part_start');
    $statement->execute([':id' => $uploadId]);
    return array_map(static fn(array $row): array => [(int)$row['part_start'], (int)$row['part_end']], $statement->fetchAll(PDO::FETCH_ASSOC));
}

function backup_upload_chunk(PDO $pdo, string $userId, string $uploadId, callable $clock): array
{
    $upload = backup_upload_read($pdo, $userId, $uploadId);
    $range = (string)($_SERVER['HTTP_CONTENT_RANGE'] ?? '');
    if (!preg_match('/\Abytes (\d+)-(\d+)\/(\d+)\z/D', $range, $matches)) { throw new ApiError('invalid_range', 'Content-Range is required.', 400); }
    $start = (int)$matches[1]; $end = (int)$matches[2]; $total = (int)$matches[3];
    if ($total !== (int)$upload['total_bytes'] || $start < 0 || $end < $start || $end >= $total || $end - $start + 1 > ACCOUNT_BACKUP_UPLOAD_CHUNK_BYTES) { throw new ApiError('invalid_range', 'Backup upload range is invalid.', 400); }
    $raw = file_get_contents('php://input', false, null, 0, $end - $start + 2);
    if ($raw === false || strlen($raw) !== $end - $start + 1) { throw new ApiError('invalid_chunk', 'Backup upload chunk size is invalid.', 400); }
    $hash = strtolower((string)($_SERVER['HTTP_X_CHUNK_SHA256'] ?? ''));
    if (!preg_match('/\A[a-f0-9]{64}\z/D', $hash) || !hash_equals($hash, hash('sha256', $raw))) { throw new ApiError('chunk_hash_mismatch', 'Backup upload chunk hash does not match.', 400); }
    $path = backup_upload_root() . '/' . $uploadId . '.bin';
    $handle = fopen($path, 'c+b');
    if ($handle === false) { throw new RuntimeException('Upload storage is unavailable.'); }
    try {
        if (!flock($handle, LOCK_EX)) { throw new RuntimeException('Upload storage is busy.'); }
        fseek($handle, $start); fwrite($handle, $raw); fflush($handle); flock($handle, LOCK_UN);
    } finally { fclose($handle); }
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sql = $driver === 'sqlite'
        ? 'INSERT INTO account_backup_upload_parts (upload_id, part_start, part_end, sha256) VALUES (:id, :start, :end, :sha) ON CONFLICT(upload_id, part_start) DO UPDATE SET part_end = excluded.part_end, sha256 = excluded.sha256'
        : 'INSERT INTO account_backup_upload_parts (upload_id, part_start, part_end, sha256) VALUES (:id, :start, :end, :sha) ON DUPLICATE KEY UPDATE part_end = VALUES(part_end), sha256 = VALUES(sha256)';
    $statement = $pdo->prepare($sql); $statement->execute([':id' => $uploadId, ':start' => $start, ':end' => $end, ':sha' => $hash]);
    $touch = $pdo->prepare('UPDATE account_backup_uploads SET updated_at = :updated WHERE id = :id AND user_id = :user_id');
    $touch->execute([':updated' => auth_sql_time($clock()), ':id' => $uploadId, ':user_id' => $userId]);
    return ['upload_id' => $uploadId, 'received' => backup_upload_received($pdo, $uploadId)];
}

function backup_upload_complete(PDO $pdo, string $userId, string $uploadId, callable $clock): array
{
    $upload = backup_upload_read($pdo, $userId, $uploadId);
    $parts = backup_upload_received($pdo, $uploadId); $cursor = 0;
    foreach ($parts as $part) { if ($part[0] !== $cursor) { throw new ApiError('upload_incomplete', 'Backup upload is incomplete.', 409); } $cursor = $part[1] + 1; }
    if ($cursor !== (int)$upload['total_bytes']) { throw new ApiError('upload_incomplete', 'Backup upload is incomplete.', 409); }
    $path = backup_upload_root() . '/' . $uploadId . '.bin';
    $actual = hash_file('sha256', $path);
    if (!is_string($actual) || !hash_equals((string)$upload['sha256'], $actual)) { throw new ApiError('upload_hash_mismatch', 'Backup upload hash does not match.', 400); }
    $compressed = file_get_contents($path); $expanded = $compressed === false ? false : gzdecode($compressed, 16 * 1024 * 1024);
    if ($expanded === false) { throw new ApiError('invalid_backup', 'Backup archive is invalid.', 400); }
    try { $body = json_decode($expanded, true, 16, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new ApiError('invalid_backup_json', 'Backup archive is not valid JSON.', 400); }
    if (!is_array($body) || !is_array($body['files'] ?? null)) { throw new ApiError('invalid_backup', 'Backup archive is invalid.', 400); }
    $result = backup_create_snapshot($pdo, $userId, (string)$upload['device_id'], (string)$upload['app_version'], $body['files'], $clock);
    $pdo->prepare('DELETE FROM account_backup_uploads WHERE id = :id AND user_id = :user_id')->execute([':id' => $uploadId, ':user_id' => $userId]);
    @unlink($path);
    return $result;
}

function backup_public_snapshot(array $row): array
{
    $created = auth_parse_sql_time((string)$row['created_at']);
    return [
        'id' => (string)$row['id'], 'device_id' => (string)$row['device_id'],
        'created_at' => $created->format(DateTimeInterface::ATOM), 'app_version' => (string)$row['app_version'],
        'file_count' => (int)$row['file_count'], 'total_bytes' => (int)$row['total_bytes'], 'sha256' => (string)$row['sha256'],
    ];
}

function tree_apply_progress(PDO $pdo, string $userId, array $body, callable $clock): array
{
    $operationId = data_string($body, 'operation_id');
    $deviceId = data_string($body, 'device_id');
    $day = data_string($body, 'day');
    if (!preg_match('/\A[A-Za-z0-9_-]{16,64}\z/D', $operationId)) { throw new ApiError('invalid_operation', 'Operation identifier is invalid.', 400); }
    if ($deviceId === '' || strlen($deviceId) > 128) { throw new ApiError('invalid_device', 'Device identifier is invalid.', 400); }
    if (!tree_valid_day($day)) { throw new ApiError('invalid_day', 'Progress day is invalid.', 400); }
    $energy = data_delta($body, 'energy_delta');
    $growth = data_delta($body, 'day_growth_delta');
    $fruit = data_delta($body, 'fruit_delta');
    $harvested = data_delta($body, 'harvested_delta');
    $typedFruit = ($body['typed_fruit'] ?? false) === true;
    if ($typedFruit) fruit_ensure_schema($pdo);
    if ($energy === 0 && $growth === 0 && $fruit === 0 && $harvested === 0) {
        throw new ApiError('empty_operation', 'Progress operation contains no changes.', 400);
    }
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $now = $clock();
    try {
        $pdo->beginTransaction();
        tree_ensure_profile($pdo, $userId, $now, $driver);
        $lock = $pdo->prepare('SELECT total_energy, fruit_energy FROM tree_profiles WHERE user_id = :user_id' . ($driver === 'sqlite' ? '' : ' FOR UPDATE'));
        $lock->execute([':user_id' => $userId]);
        $lockedProfile = $lock->fetch(PDO::FETCH_ASSOC);
        if (!is_array($lockedProfile)) { throw new ApiError('invalid_session', 'The session is invalid or expired.', 401); }
        $operationSql = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO tree_progress_operations (user_id, operation_id, device_id, progress_day, energy_delta, day_growth_delta, fruit_delta, harvested_delta, created_at) VALUES (:user_id, :operation_id, :device_id, :day, :energy, :growth, :fruit, :harvested, :created_at)'
            : 'INSERT IGNORE INTO tree_progress_operations (user_id, operation_id, device_id, progress_day, energy_delta, day_growth_delta, fruit_delta, harvested_delta, created_at) VALUES (:user_id, :operation_id, :device_id, :day, :energy, :growth, :fruit, :harvested, :created_at)';
        $operation = $pdo->prepare($operationSql);
        $operation->execute([
            ':user_id' => $userId, ':operation_id' => $operationId, ':device_id' => $deviceId, ':day' => $day,
            ':energy' => $energy, ':growth' => $growth, ':fruit' => $fruit, ':harvested' => $harvested,
            ':created_at' => auth_sql_time($now),
        ]);
        $duplicate = $operation->rowCount() === 0;
        if (!$duplicate) {
            $beforeEnergy = (int)$lockedProfile['total_energy'];
            $afterEnergy = $beforeEnergy + $energy;
            $fruitEnergy = (int)$lockedProfile['fruit_energy'];
            $maturity = 4 * TREE_STAGE_INTERVAL;
            $newFruitEnergy = $fruitEnergy + max(0, $afterEnergy - max($beforeEnergy, $maturity));
            $spawnCount = intdiv($newFruitEnergy,TREE_FRUIT_COST);
            if ($typedFruit && ($harvested !== 0 || $fruit !== $spawnCount))
                throw new ApiError('invalid_fruit_progress','New fruits must match the mature energy gain.',409);
            $newFruitEnergy %= TREE_FRUIT_COST;
            $update = $pdo->prepare(
                'UPDATE tree_profiles SET total_energy = total_energy + :energy, fruit_total = fruit_total + :fruit, '
                . 'harvested_total = harvested_total + :harvested, fruit_energy = :fruit_energy, '
                . 'state_version = state_version + 1, updated_at = :updated_at WHERE user_id = :user_id'
            );
            $update->execute([':energy' => $energy, ':fruit' => $fruit, ':harvested' => $harvested,
                ':fruit_energy' => $newFruitEnergy, ':updated_at' => auth_sql_time($now), ':user_id' => $userId]);
            if ($typedFruit && $spawnCount>0) fruit_record_spawn($pdo,$userId,$spawnCount,$now);
            if ($growth > 0) {
                $dailySql = $driver === 'sqlite'
                    ? 'INSERT INTO tree_daily_progress (user_id, progress_day, growth) VALUES (:user_id, :day, :growth) ON CONFLICT(user_id, progress_day) DO UPDATE SET growth = growth + excluded.growth'
                    : 'INSERT INTO tree_daily_progress (user_id, progress_day, growth) VALUES (:user_id, :day, :growth) ON DUPLICATE KEY UPDATE growth = growth + VALUES(growth)';
                $daily = $pdo->prepare($dailySql);
                $daily->execute([':user_id' => $userId, ':day' => $day, ':growth' => $growth]);
            }
            $stageUpdate = $pdo->prepare('UPDATE tree_profiles SET stage = :stage WHERE user_id = :user_id');
            $stageUpdate->execute([':stage' => tree_stage(tree_profile_energy($pdo, $userId)), ':user_id' => $userId]);
        }
        $profile = tree_read_profile($pdo, $userId);
        $pdo->commit();
        return ['duplicate' => $duplicate, 'profile' => $profile,
            'state' => tree_read_state($pdo, $userId, $now->format('Y-m-d'))];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}

function tree_read_state(PDO $pdo, string $userId, string $day): array
{
    if (!tree_valid_day($day)) { throw new ApiError('invalid_day', 'State day is invalid.', 400); }
    tree_ensure_profile($pdo, $userId, new DateTimeImmutable($day . 'T00:00:00Z'), (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $profile = $pdo->prepare('SELECT state_version, total_energy, fruit_total, harvested_total, stage, fruit_energy FROM tree_profiles WHERE user_id = :user_id');
    $profile->execute([':user_id' => $userId]);
    $row = $profile->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) { throw new RuntimeException('Tree profile is missing.'); }
    $daily = $pdo->prepare('SELECT growth FROM tree_daily_progress WHERE user_id = :user_id AND progress_day = :day');
    $daily->execute([':user_id' => $userId, ':day' => $day]);
    $fruitTotal = (int)$row['fruit_total'];
    $harvested = (int)$row['harvested_total'];
    return [
        'day' => $day,
        'state_version' => (int)$row['state_version'],
        'fruit_cost' => TREE_FRUIT_COST,
        'today_growth' => (int)($daily->fetchColumn() ?: 0),
        'total_energy' => (int)$row['total_energy'],
        'stage' => (int)$row['stage'],
        'fruit_energy' => (int)$row['fruit_energy'],
        'fruit_inventory' => max(0, $fruitTotal - $harvested),
        'fruit_total' => $fruitTotal,
        'harvested_total' => $harvested,
    ];
}

function tree_apply_state(PDO $pdo, string $userId, array $body, callable $clock): array
{
    $operationId = data_string($body, 'operation_id');
    $deviceId = data_string($body, 'device_id');
    $day = data_string($body, 'day');
    $baseVersion = data_nonnegative_int($body, 'base_version');
    $todayGrowth = data_nonnegative_int($body, 'today_growth');
    $totalEnergy = data_nonnegative_int($body, 'total_energy');
    $stage = data_nonnegative_int($body, 'stage');
    $fruitEnergy = data_nonnegative_int($body, 'fruit_energy');
    $fruitTotal = data_nonnegative_int($body, 'fruit_total');
    $harvested = data_nonnegative_int($body, 'harvested_total');
    if (!preg_match('/\A[A-Za-z0-9_-]{16,64}\z/D', $operationId)) { throw new ApiError('invalid_operation', 'Operation identifier is invalid.', 400); }
    if ($deviceId === '' || strlen($deviceId) > 128) { throw new ApiError('invalid_device', 'Device identifier is invalid.', 400); }
    $now = $clock();
    $serverDay = $now->format('Y-m-d');
    if ($day !== $serverDay) { throw new ApiError('state_conflict', 'The state day is stale.', 409); }
    if ($stage < 1 || $stage > 5 || $stage !== tree_stage($totalEnergy)) { throw new ApiError('invalid_state', 'Tree stage does not match total energy.', 400); }
    if ($todayGrowth > $totalEnergy || $fruitEnergy >= TREE_FRUIT_COST || $harvested > $fruitTotal) { throw new ApiError('invalid_state', 'Tree fruit or growth state is invalid.', 400); }
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    try {
        $pdo->beginTransaction();
        tree_ensure_profile($pdo, $userId, $now, $driver);
        $lock = $pdo->prepare('SELECT state_version FROM tree_profiles WHERE user_id = :user_id' . ($driver === 'sqlite' ? '' : ' FOR UPDATE'));
        $lock->execute([':user_id' => $userId]);
        $currentVersion = (int)$lock->fetchColumn();
        $existing = $pdo->prepare('SELECT 1 FROM tree_progress_operations WHERE user_id = :user_id AND operation_id = :operation_id');
        $existing->execute([':user_id' => $userId, ':operation_id' => $operationId]);
        if ($existing->fetchColumn() !== false) {
            $state = tree_read_state($pdo, $userId, $serverDay);
            $pdo->commit();
            return ['duplicate' => true, 'state' => $state];
        }
        if ($baseVersion !== $currentVersion) { throw new ApiError('state_conflict', 'The tree state has changed on another device.', 409); }
        $insert = $pdo->prepare($driver === 'sqlite'
            ? 'INSERT INTO tree_progress_operations (user_id, operation_id, device_id, progress_day, energy_delta, day_growth_delta, fruit_delta, harvested_delta, created_at) VALUES (:user_id, :operation_id, :device_id, :day, 0, 0, 0, 0, :created_at)'
            : 'INSERT INTO tree_progress_operations (user_id, operation_id, device_id, progress_day, energy_delta, day_growth_delta, fruit_delta, harvested_delta, created_at) VALUES (:user_id, :operation_id, :device_id, :day, 0, 0, 0, 0, :created_at)');
        $insert->execute([':user_id' => $userId, ':operation_id' => $operationId, ':device_id' => $deviceId, ':day' => $serverDay, ':created_at' => auth_sql_time($now)]);
        $update = $pdo->prepare('UPDATE tree_profiles SET total_energy = :energy, fruit_total = :fruit, harvested_total = :harvested, stage = :stage, fruit_energy = :fruit_energy, state_version = state_version + 1, updated_at = :updated_at WHERE user_id = :user_id');
        $update->execute([
            ':energy' => $totalEnergy, ':fruit' => $fruitTotal, ':harvested' => $harvested,
            ':stage' => $stage, ':fruit_energy' => $fruitEnergy, ':updated_at' => auth_sql_time($now), ':user_id' => $userId,
        ]);
        $daily = $driver === 'sqlite'
            ? 'INSERT INTO tree_daily_progress (user_id, progress_day, growth) VALUES (:user_id, :day, :growth) ON CONFLICT(user_id, progress_day) DO UPDATE SET growth = excluded.growth'
            : 'INSERT INTO tree_daily_progress (user_id, progress_day, growth) VALUES (:user_id, :day, :growth) ON DUPLICATE KEY UPDATE growth = VALUES(growth)';
        $dailyStatement = $pdo->prepare($daily);
        $dailyStatement->execute([':user_id' => $userId, ':day' => $serverDay, ':growth' => $todayGrowth]);
        $state = tree_read_state($pdo, $userId, $serverDay);
        $pdo->commit();
        return ['duplicate' => false, 'state' => $state];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}

function tree_update_profile(PDO $pdo, string $userId, bool $participates, callable $clock): array
{
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    tree_ensure_profile($pdo, $userId, $clock(), $driver);
    $statement = $pdo->prepare('UPDATE tree_profiles SET participation_enabled = :enabled, updated_at = :updated_at WHERE user_id = :user_id');
    $statement->execute([':enabled' => $participates ? 1 : 0, ':updated_at' => auth_sql_time($clock()), ':user_id' => $userId]);
    return ['profile' => tree_read_profile($pdo, $userId)];
}

function tree_leaderboard(PDO $pdo, string $currentUserId, string $day): array
{
    if (!tree_valid_day($day)) { throw new ApiError('invalid_day', 'Leaderboard day is invalid.', 400); }
    $statement = $pdo->prepare(
        'SELECT p.user_id, u.username, p.stage, COALESCE(d.growth, 0) AS today_growth, p.total_energy, p.fruit_total, p.harvested_total '
        . 'FROM tree_profiles p JOIN auth_users u ON u.id = p.user_id '
        . 'LEFT JOIN tree_daily_progress d ON d.user_id = p.user_id AND d.progress_day = :day '
        . 'WHERE p.participation_enabled = 1 ORDER BY COALESCE(d.growth, 0) DESC, p.updated_at ASC, p.user_id ASC'
    );
    $statement->execute([':day' => $day]);
    $entries = [];
    $rank = 0;
    $current = ['rank' => null, 'participating' => false];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rank++;
        $entry = [
            'rank' => $rank, 'account' => (string)$row['user_id'], 'username' => (string)$row['username'],
            'stage' => (int)$row['stage'], 'today_growth' => (int)$row['today_growth'],
            'total_energy' => (int)$row['total_energy'], 'fruit_total' => (int)$row['fruit_total'],
            'fruit_inventory' => max(0, (int)$row['fruit_total'] - (int)$row['harvested_total']),
            'is_current_user' => (string)$row['user_id'] === $currentUserId,
        ];
        if ($entry['is_current_user']) { $current = ['rank' => $rank, 'participating' => true] + $entry; }
        if ($rank <= 50) { $entries[] = $entry; }
    }
    if (!$current['participating']) {
        $profile = $pdo->prepare('SELECT participation_enabled FROM tree_profiles WHERE user_id = :user_id');
        $profile->execute([':user_id' => $currentUserId]);
        $value = $profile->fetchColumn();
        $current['participating'] = $value !== false && (bool)$value;
    }
    return ['day' => $day, 'entries' => $entries, 'current_user' => $current];
}

function tree_ensure_profile(PDO $pdo, string $userId, DateTimeImmutable $now, string $driver): void
{
    $sql = $driver === 'sqlite'
        ? 'INSERT OR IGNORE INTO tree_profiles (user_id, participation_enabled, total_energy, fruit_total, harvested_total, stage, updated_at) VALUES (:user_id, 1, 0, 0, 0, 1, :updated_at)'
        : 'INSERT IGNORE INTO tree_profiles (user_id, participation_enabled, total_energy, fruit_total, harvested_total, stage, updated_at) VALUES (:user_id, 1, 0, 0, 0, 1, :updated_at)';
    $statement = $pdo->prepare($sql);
    $statement->execute([':user_id' => $userId, ':updated_at' => auth_sql_time($now)]);
}

function tree_read_profile(PDO $pdo, string $userId): array
{
    $statement = $pdo->prepare('SELECT participation_enabled, total_energy, fruit_total, harvested_total, stage FROM tree_profiles WHERE user_id = :user_id');
    $statement->execute([':user_id' => $userId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) { throw new RuntimeException('Tree profile is missing.'); }
    return [
        'participating' => (bool)$row['participation_enabled'], 'total_energy' => (int)$row['total_energy'],
        'fruit_total' => (int)$row['fruit_total'], 'harvested_total' => (int)$row['harvested_total'], 'stage' => (int)$row['stage'],
    ];
}

function tree_profile_energy(PDO $pdo, string $userId): int
{
    $statement = $pdo->prepare('SELECT total_energy FROM tree_profiles WHERE user_id = :user_id');
    $statement->execute([':user_id' => $userId]);
    return (int)$statement->fetchColumn();
}

function tree_stage(int $energy): int
{
    return $energy >= 144000 ? 5 : ($energy >= 108000 ? 4 : ($energy >= 72000 ? 3 : ($energy >= 36000 ? 2 : 1)));
}

function tree_valid_day(string $day): bool
{
    if (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $day)) { return false; }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
    return $parsed !== false && $parsed->format('Y-m-d') === $day;
}

function data_string(array $body, string $key): string
{
    return isset($body[$key]) && is_string($body[$key]) ? trim($body[$key]) : '';
}

function data_delta(array $body, string $key): int
{
    $value = $body[$key] ?? null;
    if (!is_int($value) || $value < 0 || $value > 1000000000) {
        throw new ApiError('invalid_progress', 'Progress delta is invalid.', 400);
    }
    return $value;
}

function data_nonnegative_int(array $body, string $key): int
{
    $value = $body[$key] ?? null;
    if (!is_int($value) || $value < 0) { throw new ApiError('invalid_state', 'Tree state value is invalid.', 400); }
    return $value;
}
