<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/admin.php';
require dirname(__DIR__) . '/api/_app/admin_web.php';
require dirname(__DIR__) . '/api/_app/fruit.php';
require dirname(__DIR__) . '/api/_app/wildlife.php';
require dirname(__DIR__) . '/api/_app/catalog_report.php';

function report_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function report_item(array $items, string $id): array
{
    foreach ($items as $item) if ($item['id'] === $id) return $item;
    throw new RuntimeException('Missing report item: ' . $id);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
$clock = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-24T10:00:00Z');
$first = auth_register($pdo, 'report_first', 'secret1', 'first-device', '127.0.0.1', $clock)['user']['id'];
$second = auth_register($pdo, 'report_second', 'secret1', 'second-device', '127.0.0.2', $clock)['user']['id'];
fruit_ensure_schema($pdo);
wildlife_ensure_schema($pdo);

$pdo->prepare("INSERT INTO aquarium_profiles (user_id,initialized,coins,scene_version,scene_json,created_at,updated_at) VALUES (?,1,0,0,'{}','2026-10-24 10:00:00','2026-10-24 10:00:00')")
    ->execute([$first]);
$pdo->prepare("INSERT INTO aquarium_profiles (user_id,initialized,coins,scene_version,scene_json,created_at,updated_at) VALUES (?,1,0,0,'{}','2026-10-24 10:00:00','2026-10-24 10:00:00')")
    ->execute([$second]);
$pdo->prepare("INSERT INTO user_fruit_inventory (user_id,fruit_id,quantity,discovered_at,updated_at) VALUES (?,'apple',3,'2026-10-24 10:00:00','2026-10-24 10:00:00')")
    ->execute([$first]);
$pdo->prepare("INSERT INTO user_fruit_discoveries (user_id,fruit_id,discovered_at) VALUES (?,'apple','2026-10-24 10:00:00'),(?,'apple','2026-10-24 10:00:00')")
    ->execute([$first, $second]);
$pdo->prepare("INSERT INTO aquarium_inventory (id,user_id,item_id,created_at) VALUES ('00000000000000000000000000000001',?,'clownfish','2026-10-24 10:00:00'),('00000000000000000000000000000002',?,'clownfish','2026-10-24 10:00:00')")
    ->execute([$first, $first]);
$pdo->prepare("INSERT INTO aquarium_wallet_entries (user_id,operation_id,delta,balance,reason,item_id,created_at) VALUES (?,'report-purchase',-100,0,'purchase','clownfish','2026-10-24 10:00:00')")
    ->execute([$second]);
$pdo->prepare("INSERT INTO wildlife_inventory (user_id,species_id,quantity,updated_at) VALUES (?,'blue-morpho',4,'2026-10-24 10:00:00')")
    ->execute([$first]);
$pdo->prepare("INSERT INTO wildlife_discoveries (user_id,species_id,discovered_at) VALUES (?,'blue-morpho','2026-10-24 10:00:00'),(?,'blue-morpho','2026-10-24 10:00:00')")
    ->execute([$first, $second]);

$report = catalog_report($pdo);
foreach (['fruit' => ['apple',3], 'aquarium' => ['clownfish',2], 'wildlife' => ['blue-morpho',4]] as $type => [$id,$quantity]) {
    $item = report_item($report[$type]['items'], $id);
    report_check($item['quantity'] === $quantity && $item['holders'] === 1 && $item['obtained_users'] === 2,
        $type . ' counts current copies, current owners and historical owners separately.');
    report_check($report[$type]['quantity'] === $quantity && $report[$type]['obtained_species'] === 1,
        $type . ' overview sums its per-species rows.');
}
report_check(report_item($report['fruit']['items'], 'banana')['quantity'] === 0 &&
    report_item($report['wildlife']['items'], 'monarch')['obtained_users'] === 0,
    'Unobtained species remain visible with zero counts.');
report_check(!in_array('aquarium-card-pack', array_column($report['aquarium']['items'], 'id'), true),
    'The purchasable card pack is not an encyclopedia entry.');

$_SESSION = ['csrf' => str_repeat('a', 64)];
$post = ['csrf' => $_SESSION['csrf'], 'operation' => 'wildlife_catalog_item', 'species_id' => 'blue-morpho',
    'species_name' => '彩蝶', 'encounter_weight' => '7'];
report_check(admin_page_mutate($pdo, ['user_id' => $first], $post) === 'wildlife_catalog_item',
    'The web form routes to the wildlife catalogue update.');
$butterfly = report_item(wildlife_catalog($pdo), 'blue-morpho');
report_check($butterfly['name'] === '彩蝶' && $butterfly['enabled'] === false && $butterfly['encounter_weight'] === 7,
    'The catalogue persists name, availability and weight.');
report_check(report_item(catalog_report($pdo)['wildlife']['items'], 'blue-morpho')['quantity'] === 4 &&
    !in_array('blue-morpho', array_column(array_column(wildlife_eligible('spring','day','clear',$pdo), 'item'), 'id'), true),
    'Disabling an animal stops encounters but retains collected copies in the report.');
try {
    wildlife_admin_update($pdo, ['species_id' => 'unknown', 'species_name' => '未知', 'encounter_weight' => '1'], $clock());
    throw new RuntimeException('An unknown species was editable.');
} catch (ApiError $error) {
    report_check($error->apiCode === 'invalid_wildlife', 'Unknown species IDs are rejected.');
}
try {
    admin_page_mutate($pdo, ['user_id' => $first], array_replace($post, ['csrf' => 'bad']));
    throw new RuntimeException('Invalid CSRF was accepted.');
} catch (ApiError $error) {
    report_check($error->apiCode === 'invalid_csrf', 'Wildlife updates require a valid CSRF token.');
}

echo "catalog_report_test: ok\n";
