<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/account_data.php';
require dirname(__DIR__) . '/api/_app/town_assets.php';
require dirname(__DIR__) . '/api/_app/admin_web.php';

// 校验：后台素材列表按「添加时间倒序」（created_at 降序，后加的在前）排列，
// 而发布配置仍按原有规则（图集 / Y / X / ID）排列，两者互不影响。
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
$pdo->exec("CREATE TABLE town_asset_sheets (sheet_id TEXT PRIMARY KEY, original_name TEXT NOT NULL, storage_path TEXT NOT NULL, mime_type TEXT NOT NULL, file_size INTEGER NOT NULL, image_width INTEGER NOT NULL, image_height INTEGER NOT NULL, sha256 TEXT NOT NULL UNIQUE, status TEXT NOT NULL DEFAULT 'draft', uploaded_by TEXT NOT NULL REFERENCES auth_users(id), created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE town_asset_items (asset_id TEXT PRIMARY KEY, sheet_id TEXT NOT NULL REFERENCES town_asset_sheets(sheet_id) ON DELETE CASCADE, name TEXT NOT NULL, category TEXT NOT NULL, subcategory TEXT NOT NULL DEFAULT '', tags_json TEXT NOT NULL, x INTEGER NOT NULL, y INTEGER NOT NULL, region_width INTEGER NOT NULL, region_height INTEGER NOT NULL, display_width INTEGER NOT NULL, display_height INTEGER NOT NULL, anchor_json TEXT NOT NULL, footprint_json TEXT NOT NULL, collision_json TEXT NOT NULL, frames_json TEXT NOT NULL, manual_locked INTEGER NOT NULL DEFAULT 1, updated_by TEXT NOT NULL REFERENCES auth_users(id), created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE town_asset_releases (release_id TEXT PRIMARY KEY, release_version INT NOT NULL UNIQUE, config_json TEXT NOT NULL, config_sha256 TEXT NOT NULL, published_by CHAR(32) NOT NULL REFERENCES auth_users(id), published_at TEXT NOT NULL);
CREATE TABLE town_asset_active_release (singleton_id INTEGER PRIMARY KEY, release_id TEXT NOT NULL REFERENCES town_asset_releases(release_id));");

$start = new DateTimeImmutable('2026-11-15T10:00:00Z');
$user = auth_register($pdo, 'order_admin', 'secret1', 'order-test', '127.0.0.1', static fn() => $start)['user']['id'];
// 64x64 的空白图集，留出多个 16 像素格，方便用不同 Y 坐标验证排序依据
$image = imagecreatetruecolor(64, 64);
imagefilledrectangle($image, 0, 0, 63, 63, imagecolorallocate($image, 40, 120, 60));
ob_start();
imagepng($image);
$png = (string)ob_get_clean();
$path = tempnam(sys_get_temp_dir(), 'town-order-');
file_put_contents($path, $png);
$sheet = town_asset_upload_sheet($pdo, $user, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'size' => strlen($png), 'name' => 'order.png'], $start);
@unlink($path);

// 故意让 ID 的字典序与添加顺序相反，Y 坐标也与添加顺序相反，用来确认排序依据是 created_at
$plan = [
    ['id' => 'town-source-z-first', 'y' => 32],
    ['id' => 'town-source-m-second', 'y' => 16],
    ['id' => 'town-source-a-third', 'y' => 0],
];
foreach ($plan as $index => $row) {
    $at = $start->modify('+' . ($index + 1) . ' minutes');
    town_asset_save_item($pdo, $user, [
        'asset_id' => $row['id'], 'sheet_id' => $sheet['sheet_id'], 'name' => '素材' . ($index + 1),
        'category' => 'tree', 'x' => '0', 'y' => (string)$row['y'], 'width' => '16', 'height' => '16',
        'display_width' => '16', 'display_height' => '16',
    ], $at);
}

$adminIds = array_column(town_asset_read_admin_items($pdo), 'id');
$expected = array_reverse(array_column($plan, 'id')); // 后加的在前
if ($adminIds !== $expected) {
    throw new RuntimeException('后台列表未按添加时间倒序排列：' . implode(',', $adminIds));
}

// 编辑最早添加的那条：created_at 保持不变，位置不能跑到后面
town_asset_save_item($pdo, $user, [
    'asset_id' => 'town-source-z-first', 'sheet_id' => $sheet['sheet_id'], 'name' => '素材1改名',
    'category' => 'tree', 'x' => '0', 'y' => '32', 'width' => '16', 'height' => '16',
    'display_width' => '16', 'display_height' => '16',
], $start->modify('+1 hour'));
$adminIds = array_column(town_asset_read_admin_items($pdo), 'id');
if ($adminIds !== $expected) {
    throw new RuntimeException('编辑后位置被改变：' . implode(',', $adminIds));
}

// 发布配置仍按旧规则（sheet_id, y, x, asset_id）排，不受后台展示顺序影响
$configIds = array_column(town_asset_build_config($pdo)['items'], 'id');
$configExpected = ['town-source-a-third', 'town-source-m-second', 'town-source-z-first'];
if ($configIds !== $configExpected) {
    throw new RuntimeException('发布配置顺序被改动：' . implode(',', $configIds));
}

echo "town_asset_admin_order_test: " . count($adminIds) . " checks ok\n";
