<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/account_data.php';
require dirname(__DIR__) . '/api/_app/town_assets.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys=ON'); $pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
$pdo->exec("CREATE TABLE town_asset_sheets (sheet_id TEXT PRIMARY KEY, original_name TEXT NOT NULL, storage_path TEXT NOT NULL, mime_type TEXT NOT NULL, file_size INTEGER NOT NULL, image_width INTEGER NOT NULL, image_height INTEGER NOT NULL, sha256 TEXT NOT NULL UNIQUE, status TEXT NOT NULL DEFAULT 'draft', uploaded_by TEXT NOT NULL REFERENCES auth_users(id), created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE town_asset_items (asset_id TEXT PRIMARY KEY, sheet_id TEXT NOT NULL REFERENCES town_asset_sheets(sheet_id) ON DELETE CASCADE, name TEXT NOT NULL, category TEXT NOT NULL, subcategory TEXT NOT NULL DEFAULT '', tags_json TEXT NOT NULL, x INTEGER NOT NULL, y INTEGER NOT NULL, region_width INTEGER NOT NULL, region_height INTEGER NOT NULL, display_width INTEGER NOT NULL, display_height INTEGER NOT NULL, anchor_json TEXT NOT NULL, footprint_json TEXT NOT NULL, collision_json TEXT NOT NULL, frames_json TEXT NOT NULL, manual_locked INTEGER NOT NULL DEFAULT 1, updated_by TEXT NOT NULL REFERENCES auth_users(id), created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE town_asset_releases (release_id TEXT PRIMARY KEY, release_version INTEGER NOT NULL UNIQUE, config_json TEXT NOT NULL, config_sha256 TEXT NOT NULL, published_by CHAR(32) NOT NULL REFERENCES auth_users(id), published_at TEXT NOT NULL);
CREATE TABLE town_asset_active_release (singleton_id INTEGER PRIMARY KEY, release_id TEXT NOT NULL REFERENCES town_asset_releases(release_id));");

$now = new DateTimeImmutable('2026-11-15T00:00:00Z');
$user = auth_register($pdo, 'release_admin', 'secret1', 'release-test', '127.0.0.1', static fn() => $now)['user']['id'];
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAFklEQVR4nGP4TyFgGDVg1IBRA4aLAQBdePwur/3haQAAAABJRU5ErkJggg==', true);
$path = tempnam(sys_get_temp_dir(), 'town-release-'); file_put_contents($path, $png);
$sheet = town_asset_upload_sheet($pdo, $user, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'size' => strlen($png), 'name' => 'sheet.png'], $now);

$releases = [];
for ($i = 1; $i <= 9; $i++) {
    town_asset_save_item($pdo, $user, ['asset_id' => 'town-source-release', 'sheet_id' => $sheet['sheet_id'], 'name' => '版本 ' . $i,
        'category' => 'tree', 'x' => '0', 'y' => '0', 'width' => '16', 'height' => '16', 'display_width' => '16', 'display_height' => '16'], $now);
    $releases[$i] = town_asset_publish($pdo, $user, $now);
}

$stored = $pdo->query('SELECT release_version FROM town_asset_releases ORDER BY release_version DESC')->fetchAll(PDO::FETCH_COLUMN);
if (count($stored) !== TOWN_ASSET_RELEASE_KEEP) throw new RuntimeException('发布 9 次后应只剩 ' . TOWN_ASSET_RELEASE_KEEP . ' 个版本，实际 ' . count($stored));
if (array_map('intval', $stored) !== [9, 8, 7, 6, 5, 4]) throw new RuntimeException('保留的应是最新 6 个版本，实际 ' . implode(',', $stored));
if ($releases[4]['pruned'] !== 0 || $releases[6]['pruned'] !== 0) throw new RuntimeException('前 6 次发布没有可清理的版本。');
if ($releases[7]['pruned'] !== 1) throw new RuntimeException('第 7 次发布应清理 1 个旧版本。');
if ($releases[8]['pruned'] !== 1 || $releases[9]['pruned'] !== 1) throw new RuntimeException('后续每次发布都应各清理 1 个旧版本。');

$active = town_asset_active_summary($pdo);
if ((int)$active['release_version'] !== 9) throw new RuntimeException('当前线上版本应保持为最新的 v9。');

try { town_asset_rollback($pdo, $releases[3]['release_id']); throw new RuntimeException('已清理的版本不应还能回滚。'); }
catch (ApiError $error) { if ($error->getMessage() !== 'The requested release was not found.') throw $error; }

town_asset_rollback($pdo, $releases[4]['release_id']);
if ((int)town_asset_active_summary($pdo)['release_version'] !== 4) throw new RuntimeException('保留窗口内最旧的 v4 应仍可回滚。');

$state = town_asset_admin_state($pdo);
if (count($state['releases']) !== TOWN_ASSET_RELEASE_KEEP) throw new RuntimeException('后台回滚列表只应返回 ' . TOWN_ASSET_RELEASE_KEEP . ' 项。');
if ((string)$state['active']['release_id'] !== (string)$releases[4]['release_id']) throw new RuntimeException('后台状态没有反映回滚后的激活版本。');

town_asset_publish($pdo, $user, $now);
$after = $pdo->query('SELECT release_version FROM town_asset_releases ORDER BY release_version DESC')->fetchAll(PDO::FETCH_COLUMN);
if (array_map('intval', $after) !== [10, 9, 8, 7, 6, 5]) throw new RuntimeException('回滚后再次发布应保留最新 6 个版本，实际 ' . implode(',', $after));
if ((int)town_asset_active_summary($pdo)['release_version'] !== 10) throw new RuntimeException('再次发布后应指向新的 v10。');

unlink($path); echo "town_asset_release_retention_test: ok\n";
