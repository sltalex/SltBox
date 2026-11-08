<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/account_data.php';
require dirname(__DIR__) . '/api/_app/tree_town_prototype.php';

$townChecks = 0;
function town_expect(bool $condition, string $message): void
{
    global $townChecks;
    $townChecks++;
    if (!$condition) { throw new RuntimeException($message); }
}
function town_error(string $code, int $status, callable $callback): void
{
    try { $callback(); }
    catch (ApiError $error) {
        town_expect($error->apiCode === $code && $error->httpStatus === $status,
            "Expected {$code}/{$status}; received {$error->apiCode}/{$error->httpStatus}.");
        return;
    }
    throw new RuntimeException('Expected ' . $code);
}
function town_request(array $layout, int $version, string $operation): array
{
    return ['action' => 'save_scene', 'base_scene_version' => $version,
        'operation_id' => $operation, 'layout' => $layout];
}
function town_snapshot_other_tables(PDO $pdo): array
{
    $result = [];
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name") as $table) {
        $name = $table['name'];
        if ($name === 'tree_town_prototype_profiles') { continue; }
        $result[$name] = $pdo->query('SELECT * FROM "' . str_replace('"', '""', $name) . '" ORDER BY rowid')
            ->fetchAll(PDO::FETCH_ASSOC);
    }
    return $result;
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
$migration = file_get_contents(dirname(__DIR__) . '/database/021_tree_town_prototype.sql');
$sqliteMigration = str_replace(['CHARACTER SET ascii COLLATE ascii_bin', ' UNSIGNED'], '', $migration);
$sqliteMigration = preg_replace('/ENGINE=InnoDB[^;]+;/s', ';', $sqliteMigration);
$pdo->exec($sqliteMigration);
$pdo->exec($sqliteMigration);
town_expect((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='tree_town_prototype_profiles'")->fetchColumn() === 1,
    'The new migration creates an independent table and can be applied twice.');

$clock = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-11-08T10:00:00Z');
$firstAccount = auth_register($pdo, 'town_owner_a', 'secret1', 'town-device-a', '127.0.0.1', $clock);
$secondAccount = auth_register($pdo, 'town_owner_b', 'secret1', 'town-device-b', '127.0.0.2', $clock);
$firstId = $firstAccount['user']['id'];
$secondId = $secondAccount['user']['id'];
unset($_SERVER['HTTP_AUTHORIZATION']);
town_error('invalid_session', 401, static fn() => account_authenticated_user($pdo, $clock));
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $firstAccount['session']['access_token'];
town_expect(account_authenticated_user($pdo, $clock)['user_id'] === $firstId,
    'The existing authenticated session determines the first account.');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $secondAccount['session']['access_token'];
town_expect(account_authenticated_user($pdo, $clock)['user_id'] === $secondId,
    'The existing authenticated session determines the second account.');
unset($_SERVER['HTTP_AUTHORIZATION']);

$before = town_snapshot_other_tables($pdo);
$catalog = town_prototype_catalog();
town_expect(count($catalog['items']) === 16 && count($catalog['by_id']) === 16,
    'The isolated demo catalog contains sixteen distinct materials.');
// The public v1 endpoints are retired after the shared-town migration. Keep
// these service checks for rollback compatibility, but do not compare their
// old 48x48 demo assets with the active shared-town client catalog.
town_expect(is_array(json_decode(file_get_contents(dirname(__DIR__) . '/config/town-prototype-catalog.json'), true)),
    'The retired service catalog remains valid for rollback tooling.');
town_expect(is_array(json_decode(file_get_contents(dirname(__DIR__) . '/config/town-prototype-default.json'), true)),
    'The retired service default layout remains valid for rollback tooling.');
$initial = town_prototype_read_state($pdo, $firstId);
town_expect($initial['scene_version'] === 0 && $initial['updated_at'] === null
    && count($initial['layout']['placements']) === 16, 'An unsaved account gets the complete example layout.');
town_expect((int)$pdo->query('SELECT COUNT(*) FROM tree_town_prototype_profiles')->fetchColumn() === 0,
    'Reading an absent town never inserts a profile.');

$layout = $initial['layout'];
$layout['demo_stage'] = 1;
$request = town_request($layout, 0, 'save-first-a');
$saved = town_prototype_save_scene($pdo, $firstId, $request, $clock());
town_expect($saved['scene_version'] === 1 && !$saved['duplicate'] && $saved['layout'] === $layout,
    'First save stores the exact layout and advances the account version.');
town_expect(town_prototype_read_state($pdo, $firstId)['layout'] === $layout,
    'A newly constructed reader retrieves the cloud layout.');
town_expect(town_prototype_read_state($pdo, $secondId)['scene_version'] === 0,
    'Another account cannot read the first account town.');
$replayed = town_prototype_save_scene($pdo, $firstId, $request, $clock()->modify('+5 minutes'));
town_expect($replayed['duplicate'] && $replayed['scene_version'] === 1
    && $replayed['updated_at'] === $saved['updated_at'], 'Lost-response retry does not save twice or alter timestamps.');

$changed = $layout;
$changed['demo_stage'] = 2;
town_error('operation_conflict', 409, static fn() => town_prototype_save_scene($pdo, $firstId,
    town_request($changed, 0, 'save-first-a'), $clock()));
town_error('scene_conflict', 409, static fn() => town_prototype_save_scene($pdo, $firstId,
    town_request($changed, 0, 'save-stale-device'), $clock()));
town_expect(town_prototype_read_state($pdo, $firstId)['layout'] === $layout,
    'Conflicting saves never overwrite the newer cloud map.');
$secondSaved = town_prototype_save_scene($pdo, $secondId, town_request($changed, 0, 'save-first-a'), $clock());
town_expect($secondSaved['scene_version'] === 1 && $secondSaved['layout']['demo_stage'] === 2,
    'Identical operation identifiers are independent between accounts.');
town_expect(town_prototype_read_state($pdo, $firstId)['layout']['demo_stage'] === 1,
    'Saving another account leaves the first account unchanged.');
$updated = town_prototype_save_scene($pdo, $firstId, town_request($changed, 1, 'save-second-a'), $clock());
town_expect($updated['scene_version'] === 2, 'A save based on the current cloud version succeeds.');
town_error('scene_conflict', 409, static fn() => town_prototype_save_scene($pdo, $firstId, $request, $clock()));
town_expect(town_prototype_read_state($pdo, $firstId)['scene_version'] === 2,
    'A delayed request from before a newer save cannot revert the layout.');

$empty = $layout;
$empty['placements'] = [];
town_expect(town_prototype_validate_layout($empty)['placements'] === [], 'Removing all demonstration objects is valid.');
$edges = $empty;
$edges['placements'] = [
    ['instance_id' => 'edge-one', 'asset_id' => 'prop_lamp', 'tile_x' => 47, 'tile_y' => 47],
    ['instance_id' => 'edge-two', 'asset_id' => 'prop_lamp', 'tile_x' => 46, 'tile_y' => 47],
    ['instance_id' => 'pond-adjacent', 'asset_id' => 'prop_lamp', 'tile_x' => 33, 'tile_y' => 8],
];
town_expect(count(town_prototype_validate_layout($edges)['placements']) === 3,
    'Exact map edges, touching footprints and adjacency to reserved regions are valid.');
$full = $empty;
$shelfX = 1; $shelfY = 1; $shelfHeight = 0;
foreach ($catalog['items'] as $item) {
    for ($number = 0; $number < 3; $number++) {
        $width = $item['footprint']['width']; $height = $item['footprint']['height'];
        if ($shelfX + $width > 32) { $shelfX = 1; $shelfY += $shelfHeight + 1; $shelfHeight = 0; }
        $full['placements'][] = ['instance_id' => $item['id'] . '-' . $number, 'asset_id' => $item['id'],
            'tile_x' => $shelfX, 'tile_y' => $shelfY];
        $shelfX += $width + 1; $shelfHeight = max($shelfHeight, $height);
    }
}
town_expect(count(town_prototype_validate_layout($full)['placements']) === 48,
    'All sixteen item types can place their complete three-item demonstration inventory.');
foreach (range(1, 5) as $stage) {
    $candidate = $empty;
    $candidate['demo_stage'] = $stage;
    town_expect(town_prototype_validate_layout($candidate)['demo_stage'] === $stage, 'All five demonstration stages are valid.');
}
$badLayouts = [];
foreach (['template_version' => 2, 'catalog_version' => 0, 'demo_stage' => 0] as $field => $value) {
    $bad = $empty; $bad[$field] = $value; $badLayouts[] = $bad;
}
$bad = $empty; $bad['demo_stage'] = 6; $badLayouts[] = $bad;
$bad = $empty; $bad['demo_stage'] = 1.0; $badLayouts[] = $bad;
$bad = $empty; $bad['user_id'] = $secondId; $badLayouts[] = $bad;
$object = ['instance_id' => 'one', 'asset_id' => 'prop_lamp', 'tile_x' => 2, 'tile_y' => 2];
foreach ([['asset_id', 'unknown'], ['tile_x', -1], ['tile_y', 48], ['tile_x', 2.5],
    ['tile_x', '2'], ['instance_id', 'has spaces'], ['instance_id', str_repeat('a', 129)]] as [$field, $value]) {
    $badObject = $object; $badObject[$field] = $value;
    $bad = $empty; $bad['placements'] = [$badObject]; $badLayouts[] = $bad;
}
$bad = $empty; $bad['placements'] = [$object, $object]; $badLayouts[] = $bad;
$bad = $empty; $bad['placements'] = [$object, $object + ['extra' => true]]; $badLayouts[] = $bad;
$overlap = $object; $overlap['instance_id'] = 'overlap';
$bad = $empty; $bad['placements'] = [$object, $overlap]; $badLayouts[] = $bad;
foreach (['pond', 'tree', 'spawn'] as $reserved) {
    $badObject = $object;
    $badObject['tile_x'] = $catalog['world'][$reserved]['x'];
    $badObject['tile_y'] = $catalog['world'][$reserved]['y'];
    $bad = $empty; $bad['placements'] = [$badObject]; $badLayouts[] = $bad;
}
$bad = $empty;
foreach (range(0, 3) as $index) {
    $bad['placements'][] = ['instance_id' => 'quantity-' . $index, 'asset_id' => 'prop_lamp',
        'tile_x' => 2 + $index * 2, 'tile_y' => 2];
}
$badLayouts[] = $bad;
$bad = $empty; $bad['placements'] = array_fill(0, 49, $object); $badLayouts[] = $bad;
foreach ($badLayouts as $bad) {
    town_error('invalid_scene', 400, static fn() => town_prototype_validate_layout($bad));
}
foreach ([['operation_id', ''], ['operation_id', 'bad operation'], ['base_scene_version', -1],
    ['base_scene_version', '2'], ['action', 'pick_fruit']] as [$field, $value]) {
    $bad = town_request($empty, 2, 'valid-operation'); $bad[$field] = $value;
    town_error('invalid_operation', 400, static fn() => town_prototype_save_scene($pdo, $firstId, $bad, $clock()));
}
$forged = town_request($empty, 2, 'forged-owner'); $forged['user_id'] = $secondId;
town_error('invalid_operation', 400, static fn() => town_prototype_save_scene($pdo, $firstId, $forged, $clock()));
town_error('invalid_session', 401, static fn() => town_prototype_save_scene($pdo, 'missing-owner',
    town_request($empty, 0, 'missing-owner-op'), $clock()));
town_expect(!$pdo->inTransaction(), 'Failed operations always release their transaction.');
town_expect(town_snapshot_other_tables($pdo) === $before,
    'All authentication, tree, real decoration, fruit, wildlife and wallet tables remain untouched by town operations.');
town_expect((int)$pdo->query('SELECT COUNT(*) FROM tree_town_prototype_profiles')->fetchColumn() === 2,
    'Only the two explicitly saved accounts have prototype rows.');
echo "town prototype service tests passed ({$townChecks} checks)\n";
