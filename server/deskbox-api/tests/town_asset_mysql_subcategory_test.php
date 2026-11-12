<?php
declare(strict_types=1);

// Run against MySQL 8 with DESKBOX_TEST_MYSQL_DSN, DESKBOX_TEST_MYSQL_USER,
// and DESKBOX_TEST_MYSQL_PASSWORD. All writes use connection-local temporary tables.
$dsn = getenv('DESKBOX_TEST_MYSQL_DSN');
if (!is_string($dsn) || $dsn === '') {
    echo "town_asset_mysql_subcategory_test: skipped (MySQL DSN not configured)\n";
    exit;
}
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/account_data.php';
require dirname(__DIR__) . '/api/_app/town_assets.php';
require dirname(__DIR__) . '/api/_app/admin_web.php';

$pdo = new PDO($dsn, getenv('DESKBOX_TEST_MYSQL_USER') ?: '',
    getenv('DESKBOX_TEST_MYSQL_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    throw new RuntimeException('This regression requires real MySQL character-set enforcement.');
}
$schema = file_get_contents(dirname(__DIR__) . '/database/024_town_asset_registry.sql');
$upgrade = file_get_contents(dirname(__DIR__) . '/database/025_town_asset_subcategory_unicode.sql');
$create = static function (string $table, bool $legacy = false) use ($pdo, $schema): void {
    if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . ' \(.*?\) ENGINE=InnoDB[^;]*;/s', $schema, $match)) {
        throw new RuntimeException('Unable to locate the town asset table definition.');
    }
    $sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $match[0]);
    // Temporary InnoDB tables cannot carry foreign keys; retain all column definitions.
    $sql = preg_replace('/,\s*CONSTRAINT \w+ FOREIGN KEY \([^)]+\)\s+REFERENCES \w+ \([^)]+\) ON DELETE \w+/', '', $sql);
    if ($legacy) $sql = str_replace(
        'subcategory VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci',
        'subcategory VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin', $sql);
    $pdo->exec($sql);
};

try {
    $create('town_asset_sheets');
    $create('town_asset_items', true);
    $user = str_repeat('a', 32);
    $sheet = str_repeat('b', 32);
    $pdo->prepare('INSERT INTO town_asset_sheets VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
        $sheet, 'fixture.png', 'mysql-subcategory-fixture-not-on-disk.png', 'image/png', 1,
        16, 16, str_repeat('c', 64), 'draft', $user, '2026-11-10 00:00:00', '2026-11-10 00:00:00',
    ]);
    $_SESSION = ['csrf' => str_repeat('d', 64)];
    $post = ['csrf'=>$_SESSION['csrf'], 'operation'=>'town_asset_item',
        'asset_id'=>'mysql-subcategory-fixture', 'sheet_id'=>$sheet, 'name'=>'字符集测试',
        'category'=>'tool', 'x'=>'0', 'y'=>'0', 'width'=>'16', 'height'=>'16',
        'display_width'=>'16', 'display_height'=>'16', 'footprint'=>'{"width":1,"height":1}'];
    admin_page_mutate($pdo, ['user_id'=>$user], $post + ['subcategory'=>'vehicle']);
    try {
        admin_page_mutate($pdo, ['user_id'=>$user], $post + ['subcategory'=>'车辆']);
        throw new RuntimeException('The legacy ASCII schema did not reproduce the reported failure.');
    } catch (PDOException $error) {
        if (!in_array((int)($error->errorInfo[1] ?? 0), [1366, 3988], true)) throw $error;
    }
    if (town_asset_read_admin_items($pdo)[0]['subcategory'] !== 'vehicle') {
        throw new RuntimeException('A rejected save changed the original asset.');
    }
    $pdo->exec($upgrade);
    $pdo->exec($upgrade);
    foreach (['车辆', str_repeat('树', 64), str_repeat('🐟', 64), ''] as $subcategory) {
        admin_page_mutate($pdo, ['user_id'=>$user], $post + ['subcategory'=>$subcategory]);
        if (town_asset_read_admin_items($pdo)[0]['subcategory'] !== $subcategory) {
            throw new RuntimeException('The upgraded MySQL column did not round-trip Unicode.');
        }
    }
    $pdo->exec('DROP TEMPORARY TABLE town_asset_items');
    $create('town_asset_items');
    admin_page_mutate($pdo, ['user_id'=>$user], $post + ['subcategory'=>'车辆']);
    if (town_asset_read_admin_items($pdo)[0]['subcategory'] !== '车辆') {
        throw new RuntimeException('A fresh installation could not create a Chinese subcategory.');
    }
    echo "town_asset_mysql_subcategory_test: ok (legacy failure, upgrade replay, Unicode round-trip, fresh install)\n";
} finally {
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS town_asset_items');
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS town_asset_sheets');
}
