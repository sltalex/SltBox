<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }

const TOWN_ASSET_MAX_BYTES = 64 * 1024 * 1024;
const TOWN_ASSET_MAX_PIXELS = 50_000_000;
const TOWN_ASSET_MAX_ITEMS = 10_000;
const TOWN_ASSET_CATEGORIES = ['character', 'animal', 'monster', 'terrain', 'water', 'farmland',
    'fence', 'building', 'tree', 'path', 'object', 'tool', 'ui', 'portrait', 'effect', 'font', 'other'];

function town_asset_categories(): array { return TOWN_ASSET_CATEGORIES; }

function town_asset_storage_root(): string
{
    $root = dirname(__DIR__, 2) . '/_private_uploads/town-assets';
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('Town asset storage is unavailable.');
    }
    return $root;
}

function town_asset_id(string $value): string
{
    // Keep the resource namespace isolated from tree decorations while allowing
    // common asset-hub/file-name characters in manually entered IDs.
    if (!preg_match('/\Atown-source-[A-Za-z0-9][A-Za-z0-9._-]{0,96}\z/D', $value)) {
        throw new ApiError('invalid_asset_id', 'The town asset ID is invalid.', 400);
    }
    return $value;
}

function town_asset_json_array(mixed $value, string $code): array
{
    if (is_string($value)) {
        try { $value = json_decode($value, true, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new ApiError($code, 'The town asset metadata is invalid.', 400); }
    }
    if (!is_array($value)) { throw new ApiError($code, 'The town asset metadata is invalid.', 400); }
    return $value;
}

function town_asset_has_navigation_column(PDO $pdo): bool
{
    static $cache = [];
    $key = spl_object_id($pdo);
    if (isset($cache[$key])) return $cache[$key];
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $columns = $pdo->query('PRAGMA table_info(town_asset_items)')->fetchAll(PDO::FETCH_ASSOC);
            return $cache[$key] = in_array('navigation_json', array_column($columns, 'name'), true);
        }
        $query = $pdo->query('SHOW COLUMNS FROM town_asset_items LIKE \'navigation_json\'');
        return $cache[$key] = (bool)$query->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException) { return $cache[$key] = false; }
}

function town_asset_item_public(array $row, array $sheet): array
{
    $json = static function (mixed $value, array $fallback): array {
        if (is_array($value)) return $value;
        if (is_string($value)) { $decoded = json_decode($value, true); if (is_array($decoded)) return $decoded; }
        return $fallback;
    };
    return [
        'id' => (string)$row['asset_id'], 'name' => (string)$row['name'],
        'category' => (string)$row['category'], 'subcategory' => (string)$row['subcategory'],
        'tags' => $json($row['tags_json'] ?? null, []), 'sheet_id' => (string)$row['sheet_id'],
        'x' => (int)$row['x'], 'y' => (int)$row['y'], 'width' => (int)$row['region_width'],
        'height' => (int)$row['region_height'], 'display_width' => (int)$row['display_width'],
        'display_height' => (int)$row['display_height'],
        'anchor' => $json($row['anchor_json'] ?? null, ['x' => .5, 'y' => 1]),
        'footprint' => $json($row['footprint_json'] ?? null, ['width' => 1, 'height' => 1]),
        'collision' => $json($row['collision_json'] ?? null, ['x' => .1, 'y' => .55, 'width' => .8, 'height' => .4]),
        'navigation' => $json($row['navigation_json'] ?? null, ['type' => 'blocked', 'blocked_npc' => true, 'required_capabilities' => []]),
        'frames' => $json($row['frames_json'] ?? null, []), 'manual_locked' => (bool)$row['manual_locked'],
        'sheet' => ['sheet_id' => (string)$sheet['sheet_id'], 'width' => (int)($sheet['image_width'] ?? $sheet['width'] ?? 0),
            'height' => (int)($sheet['image_height'] ?? $sheet['height'] ?? 0), 'sha256' => (string)$sheet['sha256']],
    ];
}

function town_asset_sheet_public(array $row): array
{
    return ['sheet_id' => (string)$row['sheet_id'], 'original_name' => (string)$row['original_name'],
        'mime_type' => (string)$row['mime_type'], 'file_size' => (int)$row['file_size'],
        'width' => (int)$row['image_width'], 'height' => (int)$row['image_height'],
        'sha256' => (string)$row['sha256'], 'status' => (string)$row['status'],
        'created_at' => (string)$row['created_at'], 'updated_at' => (string)$row['updated_at']];
}

function town_asset_read_sheets(PDO $pdo, bool $includeDraft = true): array
{
    $sql = 'SELECT * FROM town_asset_sheets' . ($includeDraft ? '' : " WHERE status='published'") . ' ORDER BY updated_at DESC, sheet_id';
    try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    catch (PDOException) { return []; }
}

function town_asset_read_draft_items(PDO $pdo): array
{
    try {
        return $pdo->query('SELECT i.*,s.original_name,s.storage_path,s.mime_type,s.file_size,s.image_width,s.image_height,s.sha256,s.status AS sheet_status '
            . 'FROM town_asset_items i JOIN town_asset_sheets s ON s.sheet_id=i.sheet_id ORDER BY i.sheet_id,i.y,i.x,i.asset_id')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException) { return []; }
}

function town_asset_validate_region(array $body, array $sheet): array
{
    $x = filter_var($body['x'] ?? null, FILTER_VALIDATE_INT); $y = filter_var($body['y'] ?? null, FILTER_VALIDATE_INT);
    $width = filter_var($body['width'] ?? null, FILTER_VALIDATE_INT); $height = filter_var($body['height'] ?? null, FILTER_VALIDATE_INT);
    if ($x === false || $y === false || $width === false || $height === false || $x < 0 || $y < 0 || $width < 16 || $height < 16
        || $x % 16 !== 0 || $y % 16 !== 0 || $width % 16 !== 0 || $height % 16 !== 0
        || $x + $width > (int)$sheet['image_width'] || $y + $height > (int)$sheet['image_height']) {
        throw new ApiError('invalid_region', 'Marked regions must use 16-pixel grid coordinates inside the source image.', 400);
    }
    $path = town_asset_storage_root() . '/' . basename((string)($sheet['storage_path'] ?? ''));
    if (is_file($path) && function_exists('imagecreatefromstring')) {
        $source = @imagecreatefromstring((string)file_get_contents($path));
        if ($source !== false) {
            $hasPixel = false;
            for ($py = $y; $py < $y + $height && !$hasPixel; $py++) for ($px = $x; $px < $x + $width; $px++) {
                $rgba = imagecolorat($source, $px, $py); $alpha = ($rgba >> 24) & 0x7F;
                if ($alpha < 127) { $hasPixel = true; break; }
            }
            unset($source);
            if (!$hasPixel) throw new ApiError('empty_region', 'The marked region is empty.', 400);
        }
    }
    $category = isset($body['category']) && is_string($body['category']) ? trim($body['category']) : '';
    if (!in_array($category, TOWN_ASSET_CATEGORIES, true)) throw new ApiError('invalid_category', 'The town asset category is invalid.', 400);
    $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
    if ($name === '' || strlen($name) > 120) throw new ApiError('invalid_name', 'A town asset name is required.', 400);
    $subcategory = isset($body['subcategory']) && is_string($body['subcategory']) ? trim($body['subcategory']) : '';
    if (preg_match('/\A.{0,64}\z/usD', $subcategory) !== 1) {
        throw new ApiError('invalid_subcategory', '子分类最多填写 64 个有效字符。', 400);
    }
    $tags = town_asset_json_array($body['tags'] ?? [], 'invalid_tags');
    $tags = array_values(array_filter($tags, static fn($v): bool => is_string($v) && trim($v) !== ''));
    $anchor = town_asset_json_array($body['anchor'] ?? ['x' => .5, 'y' => 1], 'invalid_anchor');
    $footprint = isset($body['footprint']) && $body['footprint'] !== '' ? town_asset_json_array($body['footprint'], 'invalid_footprint') : null;
    $collision = town_asset_json_array($body['collision'] ?? ['x' => .1, 'y' => .55, 'width' => .8, 'height' => .4], 'invalid_collision');
    $frames = town_asset_json_array($body['frames'] ?? [], 'invalid_frames');
    $navigation = town_asset_json_array($body['navigation'] ?? ['type' => 'blocked'], 'invalid_navigation');
    $navigationType = strtolower(trim((string)($navigation['type'] ?? 'blocked')));
    if (!in_array($navigationType, ['plain', 'blocked', 'stairs', 'water', 'bridge'], true)) throw new ApiError('invalid_navigation', '素材导航类型无效。', 400);
    $navigation['type'] = $navigationType;
    foreach ($frames as $frame) {
        if (!is_array($frame)) throw new ApiError('invalid_frames', 'The animation frame metadata is invalid.', 400);
        $fx = filter_var($frame['x'] ?? null, FILTER_VALIDATE_INT);
        $fy = filter_var($frame['y'] ?? null, FILTER_VALIDATE_INT);
        $fw = filter_var($frame['w'] ?? ($frame['width'] ?? null), FILTER_VALIDATE_INT);
        $fh = filter_var($frame['h'] ?? ($frame['height'] ?? null), FILTER_VALIDATE_INT);
        if ($fx === false || $fy === false || $fw === false || $fh === false ||
            $fx < 0 || $fy < 0 || $fw < 16 || $fh < 16 ||
            $fx % 16 !== 0 || $fy % 16 !== 0 || $fw % 16 !== 0 || $fh % 16 !== 0 ||
            $fx + $fw > (int)$sheet['image_width'] || $fy + $fh > (int)$sheet['image_height']) {
            throw new ApiError('invalid_frames', 'Animation frames must use 16-pixel grid regions inside the source image.', 400);
        }
    }
    $displayWidth = filter_var($body['display_width'] ?? $width, FILTER_VALIDATE_INT);
    $displayHeight = filter_var($body['display_height'] ?? $height, FILTER_VALIDATE_INT);
    if ($displayWidth === false || $displayHeight === false || $displayWidth < 1 || $displayHeight < 1 || $displayWidth > 4096 || $displayHeight > 4096) {
        throw new ApiError('invalid_display_size', 'The display size is invalid.', 400);
    }
    if ($footprint === null) {
        $contact = town_asset_contact(['x'=>$x,'y'=>$y,'width'=>$width,'height'=>$height,'display_width'=>$displayWidth,'display_height'=>$displayHeight,'frames'=>$frames], $path);
        $footprint=$contact['footprint'];$collision=$contact['collision'];
    }
    if ((int)($footprint['width'] ?? 0) < 1 || (int)($footprint['width'] ?? 0) > 16
        || (int)($footprint['height'] ?? 0) < 1 || (int)($footprint['height'] ?? 0) > 16) {
        throw new ApiError('invalid_footprint', 'The footprint is invalid.', 400);
    }
    return ['x' => $x, 'y' => $y, 'region_width' => $width, 'region_height' => $height,
        'name' => $name, 'category' => $category,
        'subcategory' => $subcategory,
        'tags_json' => json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'display_width' => $displayWidth, 'display_height' => $displayHeight,
        'anchor_json' => json_encode($anchor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'footprint_json' => json_encode($footprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'collision_json' => json_encode($collision, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'frames_json' => json_encode($frames, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'navigation_json' => json_encode($navigation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
}

function town_asset_upload_sheet(PDO $pdo, string $userId, array $file, DateTimeImmutable $now): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)) throw new ApiError('invalid_upload', 'Please upload a PNG image.', 400);
    $size = (int)($file['size'] ?? 0); if ($size < 1 || $size > TOWN_ASSET_MAX_BYTES) throw new ApiError('invalid_upload', 'The PNG is too large.', 413);
    $raw = file_get_contents($file['tmp_name']); if (!is_string($raw) || strlen($raw) !== $size) throw new ApiError('invalid_upload', 'The uploaded image could not be read.', 400);
    $info = @getimagesizefromstring($raw); if (!is_array($info) || ($info['mime'] ?? '') !== 'image/png') throw new ApiError('invalid_upload', 'Only PNG images are supported.', 415);
    $width = (int)($info[0] ?? 0); $height = (int)($info[1] ?? 0); if ($width < 1 || $height < 1 || $width * $height > TOWN_ASSET_MAX_PIXELS) throw new ApiError('invalid_upload', 'The image dimensions are too large.', 413);
    $hash = hash('sha256', $raw); $existing = $pdo->prepare('SELECT * FROM town_asset_sheets WHERE sha256=:hash LIMIT 1'); $existing->execute([':hash' => $hash]); $row = $existing->fetch(PDO::FETCH_ASSOC); if (is_array($row)) return town_asset_sheet_public($row);
    $id = auth_random_id(); $name = isset($file['name']) && is_string($file['name']) ? basename($file['name']) : 'sheet.png'; $stored = $id . '.png'; $path = town_asset_storage_root() . '/' . $stored;
    if (file_put_contents($path, $raw, LOCK_EX) !== strlen($raw)) throw new RuntimeException('Town asset storage is unavailable.');
    $statement = $pdo->prepare('INSERT INTO town_asset_sheets (sheet_id,original_name,storage_path,mime_type,file_size,image_width,image_height,sha256,status,uploaded_by,created_at,updated_at) VALUES (:id,:name,:path,:mime,:size,:width,:height,:hash,\'draft\',:user,:created,:updated)');
    $statement->execute([':id' => $id, ':name' => substr($name, 0, 255), ':path' => $stored, ':mime' => 'image/png', ':size' => $size, ':width' => $width, ':height' => $height, ':hash' => $hash, ':user' => $userId, ':created' => auth_sql_time($now), ':updated' => auth_sql_time($now)]);
    return ['sheet_id' => $id, 'original_name' => substr($name, 0, 255), 'mime_type' => 'image/png', 'file_size' => $size, 'width' => $width, 'height' => $height, 'sha256' => $hash, 'status' => 'draft', 'created_at' => auth_sql_time($now), 'updated_at' => auth_sql_time($now)];
}

function town_asset_save_item(PDO $pdo, string $userId, array $body, DateTimeImmutable $now): array
{
    $rawId = isset($body['asset_id']) && is_string($body['asset_id']) ? trim($body['asset_id']) : '';
    if ($rawId !== '' && !str_starts_with($rawId, 'town-source-')) $rawId = 'town-source-' . $rawId;
    $id = town_asset_id($rawId);
    $sheetId = isset($body['sheet_id']) && is_string($body['sheet_id']) ? trim($body['sheet_id']) : '';
    $query = $pdo->prepare('SELECT * FROM town_asset_sheets WHERE sheet_id=:id LIMIT 1'); $query->execute([':id' => $sheetId]); $sheet = $query->fetch(PDO::FETCH_ASSOC); if (!is_array($sheet)) throw new ApiError('sheet_not_found', 'The source image was not found.', 404);
    $values = town_asset_validate_region($body, $sheet);
    $dup = $pdo->prepare('SELECT asset_id FROM town_asset_items WHERE name=:name AND asset_id<>:id LIMIT 1');
    $dup->execute([':name' => $values['name'], ':id' => $id]);
    if ($dup->fetchColumn() !== false) throw new ApiError('duplicate_name', 'The town asset name is already in use.', 409);
    $hasNavigation = town_asset_has_navigation_column($pdo);
    $insertColumns = '(asset_id,sheet_id,name,category,subcategory,tags_json,x,y,region_width,region_height,display_width,display_height,anchor_json,footprint_json,collision_json,frames_json' . ($hasNavigation ? ',navigation_json' : '') . ',manual_locked,updated_by,created_at,updated_at)';
    $insertValues = 'VALUES (:id,:sheet,:name,:category,:subcategory,:tags,:x,:y,:width,:height,:display_width,:display_height,:anchor,:footprint,:collision,:frames' . ($hasNavigation ? ',:navigation' : '') . ',1,:user,:created,:updated)';
    $sql = 'INSERT INTO town_asset_items ' . $insertColumns . ' ' . $insertValues;
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $sql .= ' ON CONFLICT(asset_id) DO UPDATE SET sheet_id=excluded.sheet_id,name=excluded.name,category=excluded.category,subcategory=excluded.subcategory,tags_json=excluded.tags_json,x=excluded.x,y=excluded.y,region_width=excluded.region_width,region_height=excluded.region_height,display_width=excluded.display_width,display_height=excluded.display_height,anchor_json=excluded.anchor_json,footprint_json=excluded.footprint_json,collision_json=excluded.collision_json,frames_json=excluded.frames_json' . ($hasNavigation ? ',navigation_json=excluded.navigation_json' : '') . ',manual_locked=1,updated_by=excluded.updated_by,updated_at=excluded.updated_at';
    } else {
        $sql .= ' ON DUPLICATE KEY UPDATE sheet_id=VALUES(sheet_id),name=VALUES(name),category=VALUES(category),subcategory=VALUES(subcategory),tags_json=VALUES(tags_json),x=VALUES(x),y=VALUES(y),region_width=VALUES(region_width),region_height=VALUES(region_height),display_width=VALUES(display_width),display_height=VALUES(display_height),anchor_json=VALUES(anchor_json),footprint_json=VALUES(footprint_json),collision_json=VALUES(collision_json),frames_json=VALUES(frames_json)' . ($hasNavigation ? ',navigation_json=VALUES(navigation_json)' : '') . ',manual_locked=1,updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)';
    }
    $timestamp = auth_sql_time($now);
    $parameters = [':id' => $id, ':sheet' => $sheetId, ':name' => $values['name'], ':category' => $values['category'], ':subcategory' => $values['subcategory'], ':tags' => $values['tags_json'], ':x' => $values['x'], ':y' => $values['y'], ':width' => $values['region_width'], ':height' => $values['region_height'], ':display_width' => $values['display_width'], ':display_height' => $values['display_height'], ':anchor' => $values['anchor_json'], ':footprint' => $values['footprint_json'], ':collision' => $values['collision_json'], ':frames' => $values['frames_json'], ':user' => $userId, ':created' => $timestamp, ':updated' => $timestamp];
    if ($hasNavigation) $parameters[':navigation'] = $values['navigation_json'];
    $statement = $pdo->prepare($sql); $statement->execute($parameters);
    $read = $pdo->prepare('SELECT i.*,s.image_width,s.image_height,s.sha256 FROM town_asset_items i JOIN town_asset_sheets s ON s.sheet_id=i.sheet_id WHERE i.asset_id=:id'); $read->execute([':id' => $id]); $row = $read->fetch(PDO::FETCH_ASSOC); return is_array($row) ? town_asset_item_public($row, $sheet) : [];
}

function town_asset_delete_item(PDO $pdo, string $assetId): void
{
    $statement = $pdo->prepare('DELETE FROM town_asset_items WHERE asset_id=:id'); $statement->execute([':id' => town_asset_id($assetId)]);
}

function town_asset_build_config(PDO $pdo): array
{
    $items = town_asset_read_draft_items($pdo); $sheets = [];
    foreach ($items as $row) { $sheets[(string)$row['sheet_id']] = ['sheet_id' => (string)$row['sheet_id'], 'width' => (int)$row['image_width'], 'height' => (int)$row['image_height'], 'sha256' => (string)$row['sha256']]; }
    $public = []; foreach ($items as $row) { $sheet = $sheets[(string)$row['sheet_id']]; $public[] = town_asset_item_public($row, $sheet); }
    return ['schema_version' => 1, 'items' => $public, 'sheets' => array_values($sheets)];
}

/**
 * Returns draft items in the same public shape used by the client catalog.
 * The database row uses asset_id and *_json columns, while the admin page
 * renders id and decoded metadata.
 */
function town_asset_read_admin_items(PDO $pdo): array
{
    $items = [];
    foreach (town_asset_read_draft_items($pdo) as $row) {
        $sheet = [
            'sheet_id' => (string)$row['sheet_id'],
            'image_width' => (int)$row['image_width'],
            'image_height' => (int)$row['image_height'],
            'sha256' => (string)$row['sha256'],
        ];
        $items[] = town_asset_item_public($row, $sheet);
    }
    return $items;
}

function town_asset_publish(PDO $pdo, string $userId, DateTimeImmutable $now): array
{
    $config = town_asset_build_config($pdo); if (count($config['items']) > TOWN_ASSET_MAX_ITEMS) throw new ApiError('too_many_assets', 'Too many town assets.', 400);
    $ids = []; $names = []; $regions = [];
    foreach ($config['items'] as $item) {
        town_asset_id((string)$item['id']);
        if (isset($ids[$item['id']])) throw new ApiError('duplicate_id', 'The town asset ID is duplicated.', 409);
        if (isset($names[$item['name']])) throw new ApiError('duplicate_name', 'The town asset name is duplicated.', 409);
        $ids[$item['id']] = true; $names[$item['name']] = true;
        $regionKey = $item['sheet_id'] . ':' . $item['x'] . ':' . $item['y'] . ':' . $item['width'] . ':' . $item['height'];
        if (isset($regions[$regionKey])) throw new ApiError('duplicate_region', 'The same source region is marked more than once.', 409);
        $regions[$regionKey] = true;
    }
    $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); $hash = hash('sha256', $json); $release = auth_random_id();
    $pdo->beginTransaction(); try {
        $versionQuery = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'SELECT COALESCE(MAX(release_version),0) FROM town_asset_releases'
            : 'SELECT release_version FROM town_asset_releases ORDER BY release_version DESC LIMIT 1 FOR UPDATE';
        $version = (int)($pdo->query($versionQuery)->fetchColumn() ?: 0) + 1;
        $pdo->prepare('INSERT INTO town_asset_releases (release_id,release_version,config_json,config_sha256,published_by,published_at) VALUES (:id,:version,:json,:hash,:user,:at)')->execute([':id'=>$release,':version'=>$version,':json'=>$json,':hash'=>$hash,':user'=>$userId,':at'=>auth_sql_time($now)]);
        $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT INTO town_asset_active_release (singleton_id,release_id) VALUES (1,:release) ON CONFLICT(singleton_id) DO UPDATE SET release_id=excluded.release_id' : 'INSERT INTO town_asset_active_release (singleton_id,release_id) VALUES (1,:release) ON DUPLICATE KEY UPDATE release_id=VALUES(release_id)'; $pdo->prepare($sql)->execute([':release'=>$release]);
        $pdo->exec("UPDATE town_asset_sheets SET status='published',updated_at=updated_at WHERE sheet_id IN (SELECT DISTINCT sheet_id FROM town_asset_items)");
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    return ['release_id'=>$release,'release_version'=>$version,'config_sha256'=>$hash,'items'=>count($config['items'])];
}

function town_asset_rollback(PDO $pdo, string $releaseId): array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $releaseId)) throw new ApiError('invalid_release', 'The release identifier is invalid.', 400);
    $query = $pdo->prepare('SELECT release_id,release_version,config_sha256 FROM town_asset_releases WHERE release_id=:id LIMIT 1');
    $query->execute([':id' => $releaseId]); $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new ApiError('release_not_found', 'The requested release was not found.', 404);
    $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? 'INSERT INTO town_asset_active_release (singleton_id,release_id) VALUES (1,:release) ON CONFLICT(singleton_id) DO UPDATE SET release_id=excluded.release_id'
        : 'INSERT INTO town_asset_active_release (singleton_id,release_id) VALUES (1,:release) ON DUPLICATE KEY UPDATE release_id=VALUES(release_id)';
    $pdo->prepare($sql)->execute([':release' => $releaseId]);
    return ['release_id' => (string)$row['release_id'], 'release_version' => (int)$row['release_version'], 'config_sha256' => (string)$row['config_sha256']];
}

function town_asset_active_config(PDO $pdo): array
{
    try { $query=$pdo->query('SELECT r.* FROM town_asset_active_release a JOIN town_asset_releases r ON r.release_id=a.release_id WHERE a.singleton_id=1 LIMIT 1'); $row=$query->fetch(PDO::FETCH_ASSOC); if (!is_array($row)) return ['release_id'=>null,'release_version'=>0,'config_sha256'=>null,'items'=>[],'sheets'=>[]]; $config=json_decode((string)$row['config_json'],true,32,JSON_THROW_ON_ERROR); $config['release_id']=(string)$row['release_id']; $config['release_version']=(int)$row['release_version']; $config['config_sha256']=(string)$row['config_sha256']; return $config; } catch (Throwable) { return ['release_id'=>null,'release_version'=>0,'config_sha256'=>null,'items'=>[],'sheets'=>[]]; }
}

function town_asset_published_item(PDO $pdo, string $assetId): ?array
{
    $config = town_asset_active_config($pdo); foreach (($config['items'] ?? []) as $item) if (is_array($item) && ($item['id'] ?? '') === $assetId) return $item; return null;
}

function town_asset_active_summary(PDO $pdo): array
{
    $config=town_asset_active_config($pdo); return ['release_id'=>$config['release_id'] ?? null,'release_version'=>(int)($config['release_version'] ?? 0),'config_sha256'=>$config['config_sha256'] ?? null,'item_count'=>count($config['items'] ?? [])];
}

function town_asset_admin_state(PDO $pdo): array
{
    $releases=[]; try { $releases=$pdo->query('SELECT release_id,release_version,config_sha256,published_at FROM town_asset_releases ORDER BY release_version DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException) {} return ['sheets'=>array_map('town_asset_sheet_public',town_asset_read_sheets($pdo)),'items'=>town_asset_read_admin_items($pdo),'releases'=>$releases,'active'=>town_asset_active_summary($pdo),'categories'=>TOWN_ASSET_CATEGORIES];
}

function town_asset_file(PDO $pdo, string $sheetId): array
{
    $query=$pdo->prepare('SELECT storage_path,mime_type FROM town_asset_sheets WHERE sheet_id=:id LIMIT 1'); $query->execute([':id'=>$sheetId]); $row=$query->fetch(PDO::FETCH_ASSOC); if (!is_array($row)) throw new ApiError('sheet_not_found','The source image was not found.',404); $path=town_asset_storage_root().'/'.basename((string)$row['storage_path']); if (!is_file($path)) throw new ApiError('sheet_not_found','The source image is unavailable.',404); return [$path,(string)$row['mime_type']];
}

require_once __DIR__ . '/town_asset_footprints.php';
