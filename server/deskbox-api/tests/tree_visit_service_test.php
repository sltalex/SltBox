<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/account_data.php';
require dirname(__DIR__) . '/api/_app/fruit.php';
require dirname(__DIR__) . '/api/_app/tree_visit.php';

function visit_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function visit_unavailable(PDO $pdo, string $account): void
{
    try { tree_visit_snapshot($pdo, $account); }
    catch (ApiError $error) {
        visit_expect($error->apiCode === 'tree_visit_unavailable' && $error->httpStatus === 404,
            'Unavailable trees must have a single 404 result.');
        return;
    }
    throw new RuntimeException('Unavailable tree was exposed.');
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
$now = '2026-10-27 08:00:00';
foreach (['viewer', 'owner', 'private'] as $id) {
    $name = $id === 'owner' ? 'sltalex' : $id;
    $pdo->prepare('INSERT INTO auth_users (id,username,username_normalized,password_hash,created_at,updated_at) '
        . 'VALUES (:id,:name,:name,:hash,:time,:time)')
        ->execute([':id' => $id, ':name' => $name, ':hash' => 'unused', ':time' => $now]);
}
$insert = $pdo->prepare('INSERT INTO tree_profiles '
    . '(user_id,participation_enabled,total_energy,fruit_total,harvested_total,stage,updated_at) '
    . 'VALUES (:user,:enabled,180000,5,2,5,:time)');
$insert->execute([':user' => 'owner', ':enabled' => 1, ':time' => $now]);
$insert->execute([':user' => 'private', ':enabled' => 0, ':time' => $now]);
$pdo->prepare('INSERT INTO tree_decoration_profiles '
    . '(user_id,scene_json,active_theme_id,created_at,updated_at) '
    . 'VALUES (:user,:scene,:theme,:time,:time)')
    ->execute([':user' => 'owner', ':scene' => '[{"item_id":"red-lantern","x":0.5,"y":0.4,"scale":1,"rotation":0,"layer":1},{"item_id":"spring-garden","x":0.5,"y":0.4,"scale":1,"rotation":0,"layer":1}]',
        ':theme' => 'spring', ':time' => $now]);
fruit_ensure_schema($pdo);
$pdo->prepare('INSERT INTO user_fruit_pending (user_id,fruit_id,created_at) VALUES (:user,:fruit,:time)')
    ->execute([':user' => 'owner', ':fruit' => 'apple', ':time' => $now]);
$dayOne = new DateTimeImmutable('2026-10-27 15:59:59', new DateTimeZone('UTC'));
$dayTwo = new DateTimeImmutable('2026-10-27 16:00:00', new DateTimeZone('UTC'));
visit_expect(tree_visit_like_day($dayOne) === '2026-10-27'
    && tree_visit_like_day($dayTwo) === '2026-10-28', 'Daily likes reset at Shanghai midnight.');
$pdo->exec('CREATE TABLE tree_visit_likes '
    . '(user_id VARCHAR(64) NOT NULL, visitor_id VARCHAR(64) NOT NULL, '
    . 'PRIMARY KEY (user_id, visitor_id))');
$pdo->exec("INSERT INTO tree_visit_likes (user_id,visitor_id) VALUES ('owner','other')");

$snapshot = tree_visit_snapshot($pdo, 'owner', 'other', $dayOne);
visit_expect($snapshot['like_count'] === 1 && $snapshot['liked_by_me'],
    'Legacy likes are preserved and block another like on migration day.');
visit_expect($snapshot['username'] === 'sltalex' && $snapshot['stage'] === 5, 'Public tree identity/stage.');
visit_expect($snapshot['total_energy'] === 180000, 'Growth progress appears in visit.');
visit_expect($snapshot['fruit_inventory'] === 3 && $snapshot['fruit_on_tree_count'] === 1
    && $snapshot['fruits'] === ['apple'], 'Read-only fruit snapshot.');
$insertFruit = $pdo->prepare('INSERT INTO user_fruit_pending (user_id,fruit_id,created_at) VALUES (:user,:fruit,:time)');
for ($index = 0; $index < 12; $index++)
    $insertFruit->execute([':user' => 'owner', ':fruit' => 'apple', ':time' => $now]);
$manyFruits = tree_visit_snapshot($pdo, 'owner', 'other', $dayOne);
visit_expect($manyFruits['fruit_on_tree_count'] === 13 && $manyFruits['fruits'] === array_fill(0, 13, 'apple'),
    'Visit shows every fruit beyond the former twelve-item limit.');
visit_expect($snapshot['active_theme_id'] === null && count($snapshot['placements']) === 1,
    'Saved decorations appear in visits while retired themes are hidden.');
visit_expect($pdo->query("SELECT active_theme_id FROM tree_decoration_profiles WHERE user_id='owner'")->fetchColumn() === 'spring',
    'A read-only visit preserves the historical theme field in storage.');
visit_expect($snapshot['wildlife_species_id'] === null, 'Absent wildlife state is safe.');
$first = tree_visit_record($pdo, 'owner', 'viewer', 'visit-operation-0001');
visit_expect($first['visit_count'] === 1, 'A successful visit increments the total.');
$duplicate = tree_visit_record($pdo, 'owner', 'viewer', 'visit-operation-0001');
visit_expect($duplicate['visit_count'] === 1, 'Retrying the same visit cannot count twice.');
try { tree_visit_record($pdo, 'owner', 'other', 'visit-operation-0001');
    throw new RuntimeException('Reused operation was accepted for a different visitor.'); }
catch (ApiError $error) { visit_expect($error->httpStatus === 409, 'Reused visit id is rejected.'); }
try { tree_visit_record($pdo, 'owner', 'owner', 'visit-operation-0002');
    throw new RuntimeException('A user visited their own tree.'); }
catch (ApiError $error) { visit_expect($error->apiCode === 'self_visit_not_allowed' && $error->httpStatus === 403,
    'A user cannot visit their own tree.'); }
visit_expect(tree_visit_record($pdo, 'owner', 'viewer', 'visit-operation-0003')['visit_count'] === 2,
    'A new visit increments the total.');
visit_expect(tree_visit_counts($pdo)['entries'][0] === ['account' => 'owner', 'visit_count' => 2],
    'The leaderboard can read actual visit totals.');
$liked = tree_visit_set_like($pdo, 'owner', 'viewer', true, $dayOne);
visit_expect($liked === ['like_count' => 2, 'liked_by_me' => true], 'A viewer can like once today.');
visit_expect(tree_visit_set_like($pdo, 'owner', 'viewer', true, $dayOne) === $liked,
    'Repeating a like on the same day does not increase the count.');
visit_expect(tree_visit_set_like($pdo, 'owner', 'other', true, $dayOne)['like_count'] === 2,
    'Migrated likes cannot be repeated on migration day.');
try { tree_visit_set_like($pdo, 'owner', 'viewer', false, $dayOne);
    throw new RuntimeException('A daily like was undone.'); }
catch (ApiError $error) { visit_expect($error->apiCode === 'like_cannot_undo' && $error->httpStatus === 409,
    'Daily likes cannot be undone.'); }
$tomorrow = tree_visit_snapshot($pdo, 'owner', 'viewer', $dayTwo);
visit_expect($tomorrow['like_count'] === 2 && !$tomorrow['liked_by_me'],
    'The next day re-enables the viewer without resetting the total.');
visit_expect(tree_visit_set_like($pdo, 'owner', 'viewer', true, $dayTwo) ===
    ['like_count' => 3, 'liked_by_me' => true], 'The same viewer can like again tomorrow.');
visit_expect(tree_visit_set_like($pdo, 'owner', 'other', true, $dayTwo)['like_count'] === 4,
    'A legacy liker can like again on a later day.');
$pdo->exec('CREATE TABLE wildlife_encounter_state '
    . '(user_id TEXT PRIMARY KEY, encounter_id TEXT, species_id TEXT, expires_at TEXT)');
$pdo->prepare('INSERT INTO wildlife_encounter_state VALUES (:user,:encounter,:species,:expiry)')
    ->execute([':user' => 'owner', ':encounter' => 'encounter', ':species' => 'butterfly',
        ':expiry' => gmdate('Y-m-d H:i:s', time() + 3600)]);
visit_expect(tree_visit_snapshot($pdo, 'owner')['wildlife_species_id'] === 'butterfly',
    'Active wildlife appears in visit.');
visit_expect(!isset($snapshot['wallet']) && !isset($snapshot['inventory']),
    'Visit must not reveal wallet or backpack.');
visit_unavailable($pdo, 'private');
try { tree_visit_record($pdo, 'private', 'viewer', 'visit-operation-0004');
    throw new RuntimeException('Private tree accepted a visit.'); }
catch (ApiError $error) { visit_expect($error->httpStatus === 404, 'Private visit denied.'); }
try { tree_visit_set_like($pdo, 'private', 'viewer', true);
    throw new RuntimeException('Private tree accepted a like.'); }
catch (ApiError $error) { visit_expect($error->httpStatus === 404, 'Private like denied.'); }
visit_unavailable($pdo, 'missing');
visit_unavailable($pdo, '');
$pdo->prepare('UPDATE tree_profiles SET participation_enabled=1 WHERE user_id=:user')->execute([':user'=>'private']);
$pdo->prepare('INSERT INTO tree_decoration_profiles '
    . '(user_id,scene_json,active_theme_id,created_at,updated_at) '
    . 'VALUES (:user,:scene,:theme,:time,:time)')
    ->execute([':user' => 'private', ':scene' => '[{"item_id":"red-lantern","x":0.5,"y":0.4,"scale":1,"rotation":0,"layer":1}]',
        ':theme' => 'spring', ':time' => $now]);
$ordinary = tree_visit_snapshot($pdo, 'private');
visit_expect($ordinary['placements'] === [] && $ordinary['active_theme_id'] === null,
    'Decoration visibility matches the desktop tree.');
echo "tree visit service passed\n";
