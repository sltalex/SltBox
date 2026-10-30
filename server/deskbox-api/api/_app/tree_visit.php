<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__ . '/tree_decorations.php';

function tree_visit_like_day(DateTimeImmutable $now): string
{
    return $now->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
}

function tree_visit_ensure_schema(PDO $pdo, ?DateTimeImmutable $now = null): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS tree_visit_totals ('
        . 'user_id VARCHAR(64) PRIMARY KEY, visit_count BIGINT NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tree_visit_operations ('
        . 'operation_id VARCHAR(64) PRIMARY KEY, user_id VARCHAR(64) NOT NULL, '
        . 'visitor_id VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tree_visit_likes ('
        . 'user_id VARCHAR(64) NOT NULL, visitor_id VARCHAR(64) NOT NULL, '
        . 'PRIMARY KEY (user_id, visitor_id))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tree_visit_daily_likes ('
        . 'user_id VARCHAR(64) NOT NULL, visitor_id VARCHAR(64) NOT NULL, like_day DATE NOT NULL, '
        . 'PRIMARY KEY (user_id, visitor_id, like_day))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tree_visit_like_migrations ('
        . 'migration_name VARCHAR(64) PRIMARY KEY, like_day DATE NOT NULL)');

    // Legacy likes have no date. Preserve each as one like on the first migration day.
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $ignore = $sqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    $pdo->prepare($ignore . ' INTO tree_visit_like_migrations (migration_name,like_day) '
        . 'VALUES (:name,:day)')->execute([
            ':name' => 'legacy_likes', ':day' => tree_visit_like_day($now ?? api_clock()),
        ]);
    $pdo->exec($ignore . ' INTO tree_visit_daily_likes (user_id,visitor_id,like_day) '
        . 'SELECT l.user_id,l.visitor_id,m.like_day FROM tree_visit_likes l '
        . "JOIN tree_visit_like_migrations m ON m.migration_name='legacy_likes'");
}

function tree_visit_assert_public(PDO $pdo, string $account, bool $lock = false): void
{
    if ($account === '' || strlen($account) > 64) {
        throw new ApiError('tree_visit_unavailable', 'This tree is unavailable.', 404);
    }
    $suffix = $lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $query = $pdo->prepare('SELECT user_id FROM tree_profiles '
        . 'WHERE user_id=:user AND participation_enabled=1' . $suffix);
    $query->execute([':user' => $account]);
    if ($query->fetchColumn() === false) {
        throw new ApiError('tree_visit_unavailable', 'This tree is unavailable.', 404);
    }
}

function tree_visit_counts(PDO $pdo): array
{
    tree_visit_ensure_schema($pdo);
    $query = $pdo->query('SELECT p.user_id AS account, COALESCE(v.visit_count,0) AS visit_count '
        . 'FROM tree_profiles p LEFT JOIN tree_visit_totals v ON v.user_id=p.user_id '
        . 'WHERE p.participation_enabled=1 '
        . 'ORDER BY p.total_energy DESC, p.updated_at ASC, p.user_id ASC LIMIT 50');
    return ['entries' => array_map(static fn(array $row): array => [
        'account' => (string)$row['account'], 'visit_count' => (int)$row['visit_count'],
    ], $query->fetchAll(PDO::FETCH_ASSOC))];
}

function tree_visit_like_state(PDO $pdo, string $account, ?string $viewer,
    DateTimeImmutable $now): array
{
    $count = $pdo->prepare('SELECT COUNT(*) FROM tree_visit_daily_likes WHERE user_id=:user');
    $count->execute([':user' => $account]);
    $liked = false;
    if ($viewer !== null) {
        $query = $pdo->prepare('SELECT 1 FROM tree_visit_daily_likes '
            . 'WHERE user_id=:user AND visitor_id=:visitor AND like_day=:day');
        $query->execute([':user' => $account, ':visitor' => $viewer,
            ':day' => tree_visit_like_day($now)]);
        $liked = $query->fetchColumn() !== false;
    }
    return ['like_count' => (int)$count->fetchColumn(), 'liked_by_me' => $liked];
}

function tree_visit_snapshot(PDO $pdo, string $account, ?string $viewer = null,
    ?DateTimeImmutable $now = null): array
{
    if ($account === '' || strlen($account) > 64) {
        throw new ApiError('tree_visit_unavailable', 'This tree is unavailable.', 404);
    }
    $query = $pdo->prepare('SELECT u.username, p.stage, p.total_energy, p.fruit_total, p.harvested_total '
        . 'FROM tree_profiles p JOIN auth_users u ON u.id=p.user_id '
        . 'WHERE p.user_id=:user AND p.participation_enabled=1');
    $query->execute([':user' => $account]);
    $tree = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($tree)) {
        throw new ApiError('tree_visit_unavailable', 'This tree is unavailable.', 404);
    }
    $now ??= api_clock();
    tree_visit_ensure_schema($pdo, $now);
    $visits = $pdo->prepare('SELECT visit_count FROM tree_visit_totals WHERE user_id=:user');
    $visits->execute([':user' => $account]);
    $visitCount = (int)$visits->fetchColumn();
    $likes = tree_visit_like_state($pdo, $account, $viewer, $now);

    $fruitInventory = max(0, (int)$tree['fruit_total'] - (int)$tree['harvested_total']);
    $fruits = [];
    $fruitOnTreeCount = 0;
    if ((int)$tree['stage'] >= 5) {
        try {
            $onTree = fruit_on_tree($pdo, $account);
            $fruitOnTreeCount = count($onTree);
            foreach ($onTree as $fruit) {
                $fruits[] = $fruit['fruit_id'];
            }
        } catch (PDOException $error) {
            if ((string)$error->getCode() !== '42S02' &&
                !str_contains($error->getMessage(), 'no such table: user_fruit_pending')) throw $error;
        }
    }

    $scene = null;
    if (strcasecmp((string)$tree['username'], 'sltalex') === 0) {
        $decoration = $pdo->prepare('SELECT scene_json '
            . 'FROM tree_decoration_profiles WHERE user_id=:user');
        $decoration->execute([':user' => $account]);
        $scene = $decoration->fetch(PDO::FETCH_ASSOC);
    }
    $placements = is_array($scene) ? json_decode((string)$scene['scene_json'], true) : null;
    if (is_array($placements)) {
        $activeItems = tree_decoration_catalog_definition()['by_id'];
        $placements = array_values(array_filter($placements,
            static fn($placement): bool => is_array($placement)
                && isset($activeItems[$placement['item_id'] ?? ''])));
    }
    $speciesId = null;
    try {
        $encounter = $pdo->prepare('SELECT species_id, expires_at FROM wildlife_encounter_state '
            . 'WHERE user_id=:user AND encounter_id IS NOT NULL');
        $encounter->execute([':user' => $account]);
        $visitor = $encounter->fetch(PDO::FETCH_ASSOC);
        if (is_array($visitor) && is_string($visitor['species_id']) &&
            is_string($visitor['expires_at']) &&
            new DateTimeImmutable($visitor['expires_at'], new DateTimeZone('UTC')) >
                new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            $speciesId = $visitor['species_id'];
        }
    } catch (PDOException $error) {
        if ((string)$error->getCode() !== '42S02' &&
            !str_contains($error->getMessage(), 'no such table: wildlife_encounter_state')) throw $error;
    }

    return [
        'username' => (string)$tree['username'],
        'stage' => (int)$tree['stage'],
        'total_energy' => (int)$tree['total_energy'],
        'visit_count' => $visitCount,
        'like_count' => $likes['like_count'],
        'liked_by_me' => $likes['liked_by_me'],
        'fruit_inventory' => $fruitInventory,
        'fruit_on_tree_count' => $fruitOnTreeCount,
        'fruits' => $fruits,
        'placements' => is_array($placements) ? $placements : [],
        'active_theme_id' => null,
        'wildlife_species_id' => $speciesId,
    ];
}

function tree_visit_record(PDO $pdo, string $account, string $viewer, string $operationId): array
{
    if (!preg_match('/\A[A-Za-z0-9_-]{16,64}\z/D', $operationId)) {
        throw new ApiError('invalid_operation', 'A stable visit identifier is required.', 400);
    }
    tree_visit_assert_other($account, $viewer);
    tree_visit_assert_public($pdo, $account);
    tree_visit_ensure_schema($pdo);
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $pdo->beginTransaction();
        try {
            tree_visit_assert_public($pdo, $account, true);
            $insert = $pdo->prepare(($sqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE')
                . ' INTO tree_visit_operations (operation_id,user_id,visitor_id,created_at) '
                . 'VALUES (:operation,:user,:visitor,:created)');
            $insert->execute([':operation' => $operationId, ':user' => $account,
                ':visitor' => $viewer, ':created' => gmdate('Y-m-d H:i:s')]);
            if ($insert->rowCount() === 1) {
                $upsert = $sqlite
                    ? 'INSERT INTO tree_visit_totals (user_id,visit_count) VALUES (:user,1) '
                        . 'ON CONFLICT(user_id) DO UPDATE SET visit_count=visit_count+1'
                    : 'INSERT INTO tree_visit_totals (user_id,visit_count) VALUES (:user,1) '
                        . 'ON DUPLICATE KEY UPDATE visit_count=visit_count+1';
                $pdo->prepare($upsert)->execute([':user' => $account]);
            } else {
                $prior = $pdo->prepare('SELECT user_id,visitor_id FROM tree_visit_operations WHERE operation_id=:operation');
                $prior->execute([':operation' => $operationId]);
                $row = $prior->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row) || $row['user_id'] !== $account || $row['visitor_id'] !== $viewer) {
                    throw new ApiError('operation_conflict', 'Visit identifier was reused.', 409);
                }
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    return tree_visit_snapshot($pdo, $account, $viewer);
}

function tree_visit_assert_other(string $account, string $viewer): void
{
    if ($account === $viewer) {
        throw new ApiError('self_visit_not_allowed', 'You cannot visit your own tree.', 403);
    }
}

function tree_visit_set_like(PDO $pdo, string $account, string $viewer, bool $liked,
    ?DateTimeImmutable $now = null): array
{
    tree_visit_assert_public($pdo, $account);
    if (!$liked) {
        throw new ApiError('like_cannot_undo', 'A daily like cannot be undone.', 409);
    }
    $now ??= api_clock();
    tree_visit_ensure_schema($pdo, $now);
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pdo->beginTransaction();
    try {
        tree_visit_assert_public($pdo, $account, true);
        $query = $pdo->prepare(($sqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE')
            . ' INTO tree_visit_daily_likes (user_id,visitor_id,like_day) '
            . 'VALUES (:user,:visitor,:day)');
        $query->execute([':user' => $account, ':visitor' => $viewer,
            ':day' => tree_visit_like_day($now)]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return tree_visit_like_state($pdo, $account, $viewer, $now);
}
