<?php
declare(strict_types=1);

define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/town_assets.php';

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$expect(town_asset_sort_mode(null, 'building') === 'y_sort', 'Building assets default to y_sort.');
$expect(town_asset_sort_mode(null, 'terrain') === 'background', 'Terrain assets default to background sorting.');
$expect(town_asset_sort_mode('foreground', 'building') === 'foreground', 'Foreground sorting is accepted.');
$expect(town_asset_sort_mode('split', 'building') === 'split', 'Split sorting is accepted.');
$expect(town_asset_sort_mode('manual', 'object') === 'manual', 'Manual sorting remains available for compatibility.');

try {
    town_asset_sort_mode('invalid', 'object');
    throw new RuntimeException('Invalid sorting mode was accepted.');
} catch (ApiError $error) {
    $expect($error->apiCode === 'invalid_sort_mode' && $error->httpStatus === 400,
        'Invalid sorting mode returns the expected API error.');
}

$row = [
    'asset_id' => 'town-source-house', 'name' => '小屋', 'category' => 'building', 'subcategory' => 'house',
    'sheet_id' => 'sheet', 'x' => 0, 'y' => 0, 'region_width' => 64, 'region_height' => 64,
    'display_width' => 64, 'display_height' => 64, 'anchor_json' => '{"x":0.5,"y":1}',
    'footprint_json' => '{"width":3,"height":3}', 'collision_json' => '{"x":0.1,"y":0.55,"width":0.8,"height":0.4}',
    'frames_json' => '[]', 'manual_locked' => 1,
];
$public = town_asset_item_public($row, ['sheet_id' => 'sheet', 'image_width' => 64, 'image_height' => 64, 'sha256' => str_repeat('a', 64)]);
$expect($public['sort_mode'] === 'y_sort', 'Published rows without the new column remain readable with a safe default.');
$row['sort_mode'] = 'foreground';
$expect(town_asset_item_public($row, ['sheet_id' => 'sheet', 'image_width' => 64, 'image_height' => 64, 'sha256' => str_repeat('a', 64)])['sort_mode'] === 'foreground',
    'The persisted sorting mode is exposed to the client catalog.');
$row['sort_mode'] = 'split';
$row['split_y'] = 32;
$expect(town_asset_item_public($row, ['sheet_id' => 'sheet', 'image_width' => 64, 'image_height' => 64, 'sha256' => str_repeat('a', 64)])['split_y'] === 32,
    'The persisted split line is exposed to the client catalog.');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE town_asset_sheets (sheet_id TEXT PRIMARY KEY, original_name TEXT, storage_path TEXT, mime_type TEXT, file_size INTEGER, image_width INTEGER, image_height INTEGER, sha256 TEXT, status TEXT)');
$pdo->exec('CREATE TABLE town_asset_items (asset_id TEXT PRIMARY KEY, sheet_id TEXT, name TEXT, category TEXT, subcategory TEXT, tags_json TEXT, x INTEGER, y INTEGER, region_width INTEGER, region_height INTEGER, display_width INTEGER, display_height INTEGER, anchor_json TEXT, footprint_json TEXT, collision_json TEXT, frames_json TEXT, sort_mode TEXT, split_y INTEGER, manual_locked INTEGER, updated_by TEXT, created_at TEXT, updated_at TEXT)');
$pdo->prepare('INSERT INTO town_asset_sheets VALUES (?,?,?,?,?,?,?,?,?)')->execute(['sheet', 'town.png', 'missing.png', 'image/png', 1, 64, 64, str_repeat('a', 64), 'draft']);
$saved = town_asset_save_item($pdo, 'user', [
    'asset_id' => 'house', 'sheet_id' => 'sheet', 'name' => '测试房屋', 'category' => 'building', 'subcategory' => 'house',
    'tags' => '[]', 'x' => 0, 'y' => 0, 'width' => 64, 'height' => 64, 'display_width' => 64, 'display_height' => 64,
    'anchor' => '{"x":0.5,"y":1}', 'footprint' => '{"width":3,"height":3}',
    'collision' => '{"x":0.1,"y":0.55,"width":0.8,"height":0.4}', 'frames' => '[]', 'navigation' => '{"type":"blocked"}',
    'sort_mode' => 'foreground',
], new DateTimeImmutable('2026-11-14T00:00:00Z'));
$expect($saved['sort_mode'] === 'foreground', 'The save path persists sort_mode when the migration column exists.');
$savedSplit = town_asset_save_item($pdo, 'user', [
    'asset_id' => 'split-house', 'sheet_id' => 'sheet', 'name' => '拆分房屋', 'category' => 'building', 'subcategory' => 'house',
    'tags' => '[]', 'x' => 0, 'y' => 0, 'width' => 64, 'height' => 64, 'display_width' => 64, 'display_height' => 64,
    'anchor' => '{"x":0.5,"y":1}', 'footprint' => '{"width":3,"height":3}',
    'collision' => '{"x":0.1,"y":0.55,"width":0.8,"height":0.4}', 'frames' => '[]', 'navigation' => '{"type":"blocked"}',
    'sort_mode' => 'split', 'split_y' => 48,
], new DateTimeImmutable('2026-11-14T00:00:00Z'));
$expect($savedSplit['sort_mode'] === 'split' && $savedSplit['split_y'] === 48, 'The split mode and source split line are persisted.');

$bulk = town_asset_save_items_bulk($pdo, 'user', [
    [
        'asset_id' => 'auto-house-1', 'sheet_id' => 'sheet', 'name' => '自动素材 1', 'category' => 'object', 'subcategory' => 'auto-scan',
        'tags' => '["auto-scan"]', 'x' => 0, 'y' => 0, 'width' => 16, 'height' => 16, 'display_width' => 16, 'display_height' => 16,
        'anchor' => '{"x":0.5,"y":1}', 'footprint' => '{"width":1,"height":1}', 'collision' => '{"x":0.1,"y":0.55,"width":0.8,"height":0.4}',
        'frames' => '[]', 'navigation' => '{"type":"blocked"}', 'sort_mode' => 'y_sort', 'split_y' => '',
    ],
    [
        'asset_id' => 'auto-house-2', 'sheet_id' => 'sheet', 'name' => '自动素材 2', 'category' => 'object', 'subcategory' => 'auto-scan',
        'tags' => '["auto-scan"]', 'x' => 16, 'y' => 0, 'width' => 16, 'height' => 16, 'display_width' => 16, 'display_height' => 16,
        'anchor' => '{"x":0.5,"y":1}', 'footprint' => '{"width":1,"height":1}', 'collision' => '{"x":0.1,"y":0.55,"width":0.8,"height":0.4}',
        'frames' => '[]', 'navigation' => '{"type":"blocked"}', 'sort_mode' => 'y_sort', 'split_y' => '',
    ],
], new DateTimeImmutable('2026-11-14T00:00:00Z'));
$expect($bulk['count'] === 2 && count($bulk['items']) === 2, 'The automatic scan batch saves two draft assets transactionally.');

try {
    town_asset_save_item($pdo, 'user', [
        'asset_id' => 'invalid-split-house', 'sheet_id' => 'sheet', 'name' => '无效拆分房屋', 'category' => 'building', 'subcategory' => 'house',
        'tags' => '[]', 'x' => 0, 'y' => 0, 'width' => 64, 'height' => 64, 'display_width' => 64, 'display_height' => 64,
        'anchor' => '{"x":0.5,"y":1}', 'footprint' => '{"width":3,"height":3}',
        'collision' => '{"x":0.1,"y":0.55,"width":0.8,"height":0.4}', 'frames' => '[]', 'navigation' => '{"type":"blocked"}',
        'sort_mode' => 'split', 'split_y' => 64,
    ], new DateTimeImmutable('2026-11-14T00:00:00Z'));
    throw new RuntimeException('An out-of-range split line was accepted.');
} catch (ApiError $error) {
    $expect($error->apiCode === 'invalid_split_y' && $error->httpStatus === 400,
        'An out-of-range split line is rejected.');
}

echo "town_asset_sort_mode_test: {$checks} checks ok\n";
