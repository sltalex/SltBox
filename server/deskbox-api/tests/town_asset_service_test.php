<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/account_data.php';
require dirname(__DIR__) . '/api/_app/town_assets.php';
require dirname(__DIR__) . '/api/_app/admin_web.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys=ON'); $pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
$pdo->exec("CREATE TABLE town_asset_sheets (sheet_id TEXT PRIMARY KEY, original_name TEXT NOT NULL, storage_path TEXT NOT NULL, mime_type TEXT NOT NULL, file_size INTEGER NOT NULL, image_width INTEGER NOT NULL, image_height INTEGER NOT NULL, sha256 TEXT NOT NULL UNIQUE, status TEXT NOT NULL DEFAULT 'draft', uploaded_by TEXT NOT NULL REFERENCES auth_users(id), created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE town_asset_items (asset_id TEXT PRIMARY KEY, sheet_id TEXT NOT NULL REFERENCES town_asset_sheets(sheet_id) ON DELETE CASCADE, name TEXT NOT NULL, category TEXT NOT NULL, subcategory TEXT NOT NULL DEFAULT '', tags_json TEXT NOT NULL, x INTEGER NOT NULL, y INTEGER NOT NULL, region_width INTEGER NOT NULL, region_height INTEGER NOT NULL, display_width INTEGER NOT NULL, display_height INTEGER NOT NULL, anchor_json TEXT NOT NULL, footprint_json TEXT NOT NULL, collision_json TEXT NOT NULL, frames_json TEXT NOT NULL, manual_locked INTEGER NOT NULL DEFAULT 1, updated_by TEXT NOT NULL REFERENCES auth_users(id), created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE town_asset_releases (release_id TEXT PRIMARY KEY, release_version INTEGER NOT NULL UNIQUE, config_json TEXT NOT NULL, config_sha256 TEXT NOT NULL, published_by CHAR(32) NOT NULL REFERENCES auth_users(id), published_at TEXT NOT NULL);
CREATE TABLE town_asset_active_release (singleton_id INTEGER PRIMARY KEY, release_id TEXT NOT NULL REFERENCES town_asset_releases(release_id));");
$now = new DateTimeImmutable('2026-11-09T00:00:00Z'); $user = auth_register($pdo, 'asset_admin', 'secret1', 'asset-test', '127.0.0.1', static fn() => $now)['user']['id'];
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAFklEQVR4nGP4TyFgGDVg1IBRA4aLAQBdePwur/3haQAAAABJRU5ErkJggg==', true); $path = tempnam(sys_get_temp_dir(), 'town-asset-'); file_put_contents($path, $png);
$sheet = town_asset_upload_sheet($pdo, $user, ['error'=>UPLOAD_ERR_OK,'tmp_name'=>$path,'size'=>strlen($png),'name'=>'sheet.png'], $now);
if ($sheet['width'] !== 16 || $sheet['height'] !== 16) throw new RuntimeException('PNG dimensions were not detected.');
try {
    town_asset_save_item($pdo, $user, ['asset_id'=>'town-source-out-of-bounds','sheet_id'=>$sheet['sheet_id'],'name'=>'越界','category'=>'tree','x'=>'1','y'=>'0','width'=>'1','height'=>'1'], $now);
    throw new RuntimeException('Out-of-bounds region was accepted.');
} catch (ApiError $error) {
    if ($error->apiCode !== 'invalid_region') throw $error;
}
$item = town_asset_save_item($pdo, $user, ['asset_id'=>'town-source-test-tree','sheet_id'=>$sheet['sheet_id'],'name'=>'测试树','category'=>'tree','subcategory'=>'forest','x'=>'0','y'=>'0','width'=>'16','height'=>'16','display_width'=>'16','display_height'=>'16','footprint'=>'{"width":1,"height":1}'], $now);
if ($item['id'] !== 'town-source-test-tree') throw new RuntimeException('Draft item was not saved.');
$adminItems = town_asset_read_admin_items($pdo);
if (($adminItems[0]['id'] ?? '') !== 'town-source-test-tree' || !array_key_exists('frames', $adminItems[0])) {
    throw new RuntimeException('Admin draft items were not normalized to the public shape.');
}
if (town_asset_active_config($pdo)['items'] !== []) throw new RuntimeException('Draft item became visible before publish.');
$shortId = town_asset_save_item($pdo, $user, ['asset_id'=>'town-source-a_1','sheet_id'=>$sheet['sheet_id'],'name'=>'短 ID','category'=>'object','x'=>'0','y'=>'0','width'=>'16','height'=>'16'], $now);
if ($shortId['id'] !== 'town-source-a_1') throw new RuntimeException('Asset-hub style town asset ID was rejected.');
town_asset_delete_item($pdo, 'town-source-a_1');
$fileNameId = town_asset_save_item($pdo, $user, ['asset_id'=>'fall_town.zh-CN..png','sheet_id'=>$sheet['sheet_id'],'name'=>'文件名 ID','category'=>'object','x'=>'0','y'=>'0','width'=>'16','height'=>'16'], $now);
if ($fileNameId['id'] !== 'town-source-fall_town.zh-CN..png') throw new RuntimeException('File-name style town asset ID was not normalized.');
town_asset_delete_item($pdo, 'town-source-fall_town.zh-CN..png');
$_SESSION = ['csrf' => str_repeat('a', 64)];
$subcategoryPost = ['csrf'=>$_SESSION['csrf'],'operation'=>'town_asset_item',
    'asset_id'=>'town-source-test-tree','sheet_id'=>$sheet['sheet_id'],
    'name'=>'测试树','category'=>'tree','x'=>'0','y'=>'0','width'=>'16','height'=>'16',
    'display_width'=>'16','display_height'=>'16','footprint'=>'{"width":1,"height":1}'];
foreach (['车辆', str_repeat('树', 64), str_repeat('a', 64), ''] as $subcategory) {
    admin_page_mutate($pdo, ['user_id'=>$user], $subcategoryPost + ['subcategory'=>$subcategory]);
    if (town_asset_read_admin_items($pdo)[0]['subcategory'] !== $subcategory) {
        throw new RuntimeException('Admin save did not preserve the subcategory.');
    }
}
foreach ([str_repeat('树', 65), str_repeat('a', 65)] as $subcategory) {
    try {
        admin_page_mutate($pdo, ['user_id'=>$user], $subcategoryPost + ['subcategory'=>$subcategory]);
        throw new RuntimeException('An overlong subcategory reached storage.');
    } catch (ApiError $error) {
        if ($error->apiCode !== 'invalid_subcategory') throw $error;
    }
    if (town_asset_read_admin_items($pdo)[0]['subcategory'] !== '') {
        throw new RuntimeException('A rejected subcategory changed the draft.');
    }
}
if (admin_page_mutate($pdo, ['user_id'=>$user], ['csrf'=>$_SESSION['csrf'],'operation'=>'town_asset_delete','asset_id'=>'town-source-test-tree']) !== 'town_asset_delete') {
    throw new RuntimeException('Admin delete dispatch did not complete.');
}
if (town_asset_read_draft_items($pdo) !== []) throw new RuntimeException('Admin delete did not remove the draft item.');
$item = town_asset_save_item($pdo, $user, ['asset_id'=>'town-source-test-tree','sheet_id'=>$sheet['sheet_id'],'name'=>'测试树','category'=>'tree','subcategory'=>'forest','x'=>'0','y'=>'0','width'=>'16','height'=>'16','display_width'=>'16','display_height'=>'16','footprint'=>'{"width":1,"height":1}'], $now);
admin_page_mutate($pdo, ['user_id'=>$user], ['csrf'=>$_SESSION['csrf'],'operation'=>'town_asset_item','asset_id'=>'town-source-test-tree','sheet_id'=>$sheet['sheet_id'],'name'=>'测试树已编辑','category'=>'tree','subcategory'=>'forest','x'=>'0','y'=>'0','width'=>'16','height'=>'16','display_width'=>'16','display_height'=>'16']);
if (town_asset_read_draft_items($pdo)[0]['name'] !== '测试树已编辑') throw new RuntimeException('Admin edit dispatch did not update the draft item.');
$release = town_asset_publish($pdo, $user, $now); if ($release['items'] !== 1) throw new RuntimeException('Release did not contain draft item.');
if (town_asset_published_item($pdo, 'town-source-test-tree')['name'] !== '测试树已编辑') throw new RuntimeException('Published item was not readable.');
$secondRelease = town_asset_publish($pdo, $user, $now);
if ($secondRelease['release_version'] <= $release['release_version']) throw new RuntimeException('Release versions did not increase.');
town_asset_rollback($pdo, $release['release_id']);
if (town_asset_active_summary($pdo)['release_id'] !== $release['release_id']) throw new RuntimeException('Rollback did not select the requested release.');
unlink($path); echo "town_asset_service_test: ok\n";
