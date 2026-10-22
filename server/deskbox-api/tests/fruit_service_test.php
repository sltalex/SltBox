<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/account_data.php';
require dirname(__DIR__) . '/api/_app/aquarium.php';
require dirname(__DIR__) . '/api/_app/fruit.php';

function fruit_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
$clock = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-22T10:00:00Z');
$member = auth_register($pdo, 'fruit_tester', 'secret1', 'test-device', '127.0.0.1', $clock);
$user = $member['user']['id'];
fruit_ensure_schema($pdo);
$catalog = fruit_catalog_read($pdo);
fruit_check(count($catalog['items']) === 112, 'The deduplicated fruit manifest seeds all 112 fruits.');
fruit_check(count(array_unique(array_column($catalog['items'], 'id'))) === 112, 'Fruit IDs are unique.');
$adminPost = ['fruit_id'=>'apple','fruit_name'=>'苹果','category'=>'常见水果',
    'probability'=>'2.5000','min_price'=>'15','max_price'=>'20','enabled'=>'1'];
fruit_admin_update($pdo,$adminPost,$clock());
fruit_admin_update($pdo,$adminPost,$clock());
$saved = $pdo->query("SELECT probability,min_price,max_price FROM fruit_catalog WHERE id='apple'")->fetch(PDO::FETCH_ASSOC);
fruit_check((float)$saved['probability'] === 2.5 && (int)$saved['min_price'] === 15 && (int)$saved['max_price'] === 20,
    'Fruit catalog save and unchanged retry both succeed.');
$pdo->exec("UPDATE fruit_catalog SET enabled=0 WHERE id<>'apple'");
try {
    $disabledPost = $adminPost;
    unset($disabledPost['enabled']);
    fruit_admin_update($pdo,$disabledPost,$clock());
    throw new RuntimeException('Disabling the last weighted fruit should fail.');
} catch (ApiError $error) {
    fruit_check($error->apiCode === 'invalid_probability', 'The last positive weight is rejected.');
}
fruit_check((int)$pdo->query("SELECT enabled FROM fruit_catalog WHERE id='apple'")->fetchColumn() === 1,
    'A rejected catalog save rolls back the enabled flag.');
$apple = $catalog['items'][0];
$price = fruit_daily_price($pdo, $apple, '2026-10-22');
fruit_check($price >= 15 && $price <= 20 && $price === fruit_daily_price($pdo, $apple, '2026-10-22'),
    'One stable daily price stays within its configured range.');
$pdo->exec("UPDATE fruit_catalog SET enabled=0 WHERE id<>'apple'");
fruit_record_spawn($pdo, $user, 2, $clock());
fruit_check((int)$pdo->query('SELECT COUNT(*) FROM user_fruit_pending')->fetchColumn() === 2,
    'Mature fruit receives a type but does not enter the backpack.');
fruit_check((int)$pdo->query('SELECT COUNT(*) FROM user_fruit_inventory')->fetchColumn() === 0,
    'Unharvested fruit is absent from inventory.');
fruit_record_harvest($pdo, $user, 1, $clock());
fruit_check((int)$pdo->query('SELECT quantity FROM user_fruit_inventory')->fetchColumn() === 1,
    'Harvest moves one typed fruit into inventory.');
fruit_check((int)$pdo->query('SELECT COUNT(*) FROM user_fruit_discoveries')->fetchColumn() === 1,
    'Harvest permanently discovers the fruit.');
fruit_record_harvest($pdo, $user, 10, $clock());
fruit_check((int)$pdo->query('SELECT quantity FROM user_fruit_inventory')->fetchColumn() === 2,
    'Legacy fruit without a new pending type is not migrated.');
$pdo->prepare("INSERT INTO aquarium_profiles (user_id,initialized,coins,scene_version,scene_json,created_at,updated_at) VALUES (?,1,100,0,'[]',?,?)")
    ->execute([$user, auth_sql_time($clock()), auth_sql_time($clock())]);
$sale = ['fruit_id'=>'apple', 'quantity'=>1, 'operation_id'=>'fruit-sale-test-0001'];
$result = fruit_sell($pdo, $user, $sale, $clock);
fruit_check($result['total'] === $price && !$result['duplicate'], 'Fruit sale uses the saved daily price.');
fruit_check(fruit_sell($pdo, $user, $sale, $clock)['duplicate'], 'Fruit sale retry is idempotent.');
fruit_check((int)$pdo->query('SELECT quantity FROM user_fruit_inventory')->fetchColumn() === 1,
    'A duplicate sale does not debit inventory twice.');
fruit_check((int)$pdo->query('SELECT COUNT(*) FROM user_fruit_discoveries')->fetchColumn() === 1,
    'Selling fruit does not darken its encyclopedia entry.');
fruit_check((int)$pdo->query("SELECT COUNT(*) FROM aquarium_wallet_entries WHERE reason='fruit_atlas_sale'")->fetchColumn() === 1,
    'Typed fruit sale records a wallet entry.');
$other = auth_register($pdo, 'legacy_fruit', 'secret2', 'other-device', '127.0.0.2', $clock)['user']['id'];
fruit_prepare_legacy($pdo,$other,2,$clock());
fruit_record_spawn($pdo,$other,1,$clock());
fruit_record_harvest($pdo,$other,2,$clock());
fruit_check((int)$pdo->query("SELECT remaining FROM user_fruit_legacy WHERE user_id='$other'")->fetchColumn() === 0,
    'The first two harvested fruits remain legacy fruit.');
fruit_check((int)$pdo->query("SELECT COUNT(*) FROM user_fruit_inventory WHERE user_id='$other'")->fetchColumn() === 0,
    'Existing pre-atlas fruit does not light up the new encyclopedia.');
fruit_record_harvest($pdo,$other,1,$clock());
fruit_check((int)$pdo->query("SELECT quantity FROM user_fruit_inventory WHERE user_id='$other'")->fetchColumn() === 1,
    'A later new fruit is discovered only after old fruit is consumed.');
$typed = auth_register($pdo, 'typed_fruit', 'secret3', 'typed-device', '127.0.0.3', $clock)['user']['id'];
$pdo->exec("UPDATE fruit_catalog SET enabled=0 WHERE id<>'apple'");
$newGrowth = tree_apply_progress($pdo,$typed,[
    'operation_id'=>'typed-growth-000001','device_id'=>'typed-device','day'=>'2026-10-22',
    'energy_delta'=>147000,'day_growth_delta'=>147000,'fruit_delta'=>1,'harvested_delta'=>0,'typed_fruit'=>true,
],$clock);
$onTree=fruit_on_tree($pdo,$typed);
fruit_check($newGrowth['state']['fruit_inventory']===1 && count($onTree)===1 && $onTree[0]['fruit_id']==='apple',
    'New mature energy assigns the enabled fruit to the tree.');
$pickRequest=['operation_id'=>'typed-pick-0000001','pending_id'=>$onTree[0]['id']];
$picked=fruit_pick($pdo,$typed,$pickRequest,$clock);
fruit_check($picked['fruit_id']==='apple' && $picked['quantity']>=1 && $picked['quantity']<=5,
    'Clicking the visible apple credits one to five of that apple.');
fruit_check(fruit_pick($pdo,$typed,$pickRequest,$clock)['duplicate'],
    'Retrying the same pick does not grant more fruit.');
try {
    fruit_pick($pdo,$typed,['operation_id'=>'typed-pick-0000001','pending_id'=>$onTree[0]['id']+1],$clock);
    throw new RuntimeException('Reused operation id should reject another tree fruit.');
} catch (ApiError $error) {
    fruit_check($error->apiCode==='operation_conflict','A retry cannot target another fruit.');
}
fruit_check((int)$pdo->query("SELECT quantity FROM user_fruit_inventory WHERE user_id='$typed'")->fetchColumn()===$picked['quantity'],
    'The typed backpack contains only the picked quantity.');
echo "fruit_service_test: ok\n";
