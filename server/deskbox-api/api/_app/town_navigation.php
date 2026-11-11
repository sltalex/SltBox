<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__ . '/shared_town.php';

const TOWN_NAVIGATION_TYPES = ['plain', 'blocked', 'stairs', 'water', 'bridge'];
const TOWN_NAVIGATION_CAPABILITIES = ['ladder', 'bridge_kit'];

function town_navigation_version(PDO $pdo): int
{
    try {
        $value = $pdo->query("SELECT navigation_version FROM town_worlds WHERE world_id='main' LIMIT 1")->fetchColumn();
        return max(1, (int)$value);
    } catch (PDOException) { return 1; }
}

function town_navigation_json(mixed $value): array
{
    if (is_string($value) && trim($value) !== '') {
        try { $value = json_decode($value, true, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException) { $value = []; }
    }
    return is_array($value) ? $value : [];
}

function town_navigation_capabilities(mixed $value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : preg_split('/[,\s]+/', trim($value));
    }
    if (!is_array($value)) return [];
    $result = [];
    foreach ($value as $capability) {
        if (is_string($capability) && in_array($capability, TOWN_NAVIGATION_CAPABILITIES, true)) $result[$capability] = true;
    }
    return array_keys($result);
}

function town_navigation_normalize_semantics(mixed $value): array
{
    $raw = town_navigation_json($value);
    $type = is_string($raw['type'] ?? ($raw['kind'] ?? null)) ? strtolower(trim((string)($raw['type'] ?? $raw['kind']))) : 'blocked';
    $type = match ($type) {
        'road', 'plaza', 'decoration', 'workstation' => 'plain',
        'wall', 'cliff', 'door' => 'blocked',
        default => $type,
    };
    if (!in_array($type, TOWN_NAVIGATION_TYPES, true)) $type = 'blocked';
    $required = town_navigation_capabilities($raw['required_capabilities'] ?? $raw['required_capability'] ?? []);
    if ($required === [] && is_array($raw['transitions'] ?? null)) {
        foreach ($raw['transitions'] as $transition) {
            if (is_array($transition)) $required = array_values(array_unique(array_merge($required, town_navigation_capabilities($transition['requires'] ?? []))));
        }
    }
    if ($type === 'blocked' && strtolower((string)($raw['kind'] ?? '')) === 'cliff' && $required === []) $required = ['ladder'];
    if ($type === 'water' && $required === []) $required = ['bridge_kit'];
    $blockedNpc = array_key_exists('blocked_npc', $raw) ? (bool)$raw['blocked_npc'] : (array_key_exists('blocks_walk', $raw) ? (bool)$raw['blocks_walk'] : $type === 'blocked');
    $connections = is_array($raw['connections'] ?? null) ? $raw['connections'] : [];
    if ($connections === [] && is_array($raw['transitions'] ?? null)) {
        foreach ($raw['transitions'] as $transition) {
            if (!is_array($transition)) continue;
            foreach (['from', 'to'] as $direction) if (is_string($transition[$direction] ?? null)) $connections[] = $transition[$direction];
        }
    }
    return [
        'type' => $type,
        'blocked_player' => (bool)($raw['blocked_player'] ?? false),
        'blocked_npc' => $blockedNpc,
        'height' => max(0, min(32, (int)($raw['height'] ?? ($raw['elevation'] ?? 0)))),
        'connections' => $connections,
        'required_capabilities' => $required,
        'interaction_points' => is_array($raw['interaction_points'] ?? null) ? $raw['interaction_points'] : [],
    ];
}

function town_navigation_direction(int $dx, int $dy): string
{
    return $dx === 1 ? 'east' : ($dx === -1 ? 'west' : ($dy === 1 ? 'south' : 'north'));
}

/** Empty connections preserve legacy stairs; configured stairs are directional. */
function town_navigation_connections_allow(array $cell, int $dx, int $dy): bool
{
    if ((string)($cell['type'] ?? '') !== 'stairs') return true;
    $raw = $cell['connections'] ?? [];
    if (!is_array($raw) || $raw === []) return true;
    $direction = town_navigation_direction($dx, $dy);
    $aliases = [$direction];
    if ($direction === 'north') $aliases = ['north', 'up', 'n'];
    elseif ($direction === 'south') $aliases = ['south', 'down', 's'];
    elseif ($direction === 'east') $aliases = ['east', 'right', 'e'];
    elseif ($direction === 'west') $aliases = ['west', 'left', 'w'];
    foreach ($raw as $key => $value) {
        $name = is_string($key) && !is_int($key) ? strtolower(trim($key)) : strtolower(trim((string)$value));
        if (in_array($name, $aliases, true) && ($value === true || $value === 1 || $value === '1' || is_int($key))) return true;
    }
    return false;
}

/** A height change is only legal through a configured stairs cell. */
function town_navigation_transition_allow(array $from, array $to, int $dx, int $dy): bool
{
    $fromHeight = (int)($from['height'] ?? 0);
    $toHeight = (int)($to['height'] ?? 0);
    if ($fromHeight === $toHeight) return true;
    if (abs($fromHeight - $toHeight) !== 1) return false;
    if ((string)($from['type'] ?? '') !== 'stairs' && (string)($to['type'] ?? '') !== 'stairs') return false;
    return town_navigation_connections_allow($from, $dx, $dy)
        && town_navigation_connections_allow($to, -$dx, -$dy);
}

function town_navigation_fixed_semantics(int $x, int $y): array
{
    $terrain = shared_town_tile_type($x, $y);
    return match ($terrain) {
        'plain', 'road', 'plaza' => town_navigation_normalize_semantics(['type' => 'plain']),
        'bridge' => town_navigation_normalize_semantics(['type' => 'bridge', 'blocked_npc' => false]),
        'river' => town_navigation_normalize_semantics(['type' => 'water', 'blocked_npc' => true, 'required_capabilities' => ['bridge_kit']]),
        'riverbank', 'forest', 'void' => town_navigation_normalize_semantics(['type' => 'blocked']),
        default => town_navigation_normalize_semantics(['type' => 'blocked']),
    };
}

/** Return the highest-priority object navigation semantic covering a tile. */
function town_navigation_object_semantics(PDO $pdo, int $x, int $y): ?array
{
    try {
        $query = $pdo->prepare('SELECT o.asset_id, i.navigation_json FROM town_objects o '
            . 'LEFT JOIN town_asset_items i ON i.asset_id=o.asset_id '
            . 'WHERE o.world_id=:world AND o.deleted_at IS NULL '
            . 'AND o.tile_x<=:x AND o.tile_y<=:y '
            . 'AND o.tile_x+JSON_EXTRACT(o.footprint_json,\'$.width\')>:x2 '
            . 'AND o.tile_y+JSON_EXTRACT(o.footprint_json,\'$.height\')>:y2 '
            . 'ORDER BY o.layer DESC, o.object_id LIMIT 1');
        $query->execute([':world' => SHARED_TOWN_WORLD_ID, ':x' => $x, ':y' => $y, ':x2' => $x, ':y2' => $y]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) return town_navigation_normalize_semantics($row['navigation_json'] ?? null);
        // SQLite builds may have JSON_EXTRACT but differ in numeric coercion;
        // let the explicit fallback below check the exact placed tile.
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') return null;
        throw new PDOException('sqlite navigation fallback');
    } catch (PDOException) {
        // SQLite test schemas and older installations may not have the JSON
        // helper. Unknown placed material remains blocked by default.
        try {
            $query = $pdo->prepare('SELECT o.asset_id,o.tile_x,o.tile_y,o.footprint_json,i.navigation_json FROM town_objects o '
                . 'LEFT JOIN town_asset_items i ON i.asset_id=o.asset_id '
                . 'WHERE o.world_id=:world AND o.deleted_at IS NULL ORDER BY o.layer DESC, o.object_id');
            $query->execute([':world' => SHARED_TOWN_WORLD_ID]);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $footprint = town_navigation_json($row['footprint_json'] ?? null);
                // Older SQLite schemas may not support JSON_EXTRACT; only
                // explicitly configured items open a route in this fallback,
                // using their placed origin as a safe one-cell semantic.
                if ((int)($row['tile_x'] ?? -1) === $x && (int)($row['tile_y'] ?? -1) === $y) {
                    return town_navigation_normalize_semantics($row['navigation_json'] ?? null);
                }
            }
        } catch (PDOException) { }
        return null;
    }
}

/**
 * Keep a bounded process-local cell cache.  Every entry is namespaced by the
 * world's navigation version, so the rebuild endpoint invalidates old cells
 * without needing to coordinate PHP-FPM workers.
 */
function &town_navigation_process_cache(): array
{
    static $cache = ['version' => 0, 'cells' => []];
    return $cache;
}

function town_navigation_cell(PDO $pdo, int $x, int $y, ?array &$cache = null, ?int $navigationVersion = null): array
{
    $key = $x . ':' . $y;
    if ($cache !== null && isset($cache[$key])) return $cache[$key];
    $version = $navigationVersion ?? town_navigation_version($pdo);
    $process = &town_navigation_process_cache();
    if ((int)$process['version'] !== $version) { $process['version'] = $version; $process['cells'] = []; }
    $processKey = $x . ':' . $y;
    if (isset($process['cells'][$processKey])) {
        $cell = $process['cells'][$processKey];
        if ($cache !== null) $cache[$key] = $cell;
        return $cell;
    }
    $cell = town_navigation_fixed_semantics($x, $y);
    $object = town_navigation_object_semantics($pdo, $x, $y);
    if ($object !== null) $cell = $object;
    if (count($process['cells']) >= 20000) $process['cells'] = [];
    $process['cells'][$processKey] = $cell;
    if ($cache !== null) $cache[$key] = $cell;
    return $cell;
}

function town_navigation_route_path(PDO $pdo, array $path, ?array &$cache = null, ?int $navigationVersion = null): array
{
    $result = [];
    foreach ($path as $point) {
        $x = (int)($point['x'] ?? 0); $y = (int)($point['y'] ?? 0);
        $cell = town_navigation_cell($pdo, $x, $y, $cache, $navigationVersion);
        $result[] = ['x' => $x, 'y' => $y, 'height' => (int)($cell['height'] ?? 0)];
    }
    return $result;
}

function town_navigation_can_traverse(PDO $pdo, int $x, int $y, array $capabilities = []): array
{
    $allowedCapabilities = array_fill_keys(town_navigation_capabilities($capabilities), true);
    $version = town_navigation_version($pdo);
    $cellCache = null;
    $cell = town_navigation_cell($pdo, $x, $y, $cellCache, $version);
    $required = $cell['required_capabilities'];
    $missing = array_values(array_filter($required, static fn(string $value): bool => !isset($allowedCapabilities[$value])));
    $allowed = $x >= 0 && $y >= 0 && $x < SHARED_TOWN_MAP_SIZE && $y < SHARED_TOWN_MAP_SIZE
        && $missing === [] && (!$cell['blocked_npc'] || $required !== []);
    return ['allowed' => $allowed, 'x' => $x, 'y' => $y, 'type' => $cell['type'],
        'height' => (int)($cell['height'] ?? 0),
        'required_capabilities' => $required, 'missing_capabilities' => $missing,
        'reason' => $allowed ? 'ok' : ($missing !== [] ? 'required_capability' : 'blocked'),
        'navigation_version' => $version];
}

/** @return array{found:bool,path:list<array{x:int,y:int}>,cost:int,reason:string,navigation_version:int} */
function town_navigation_find_route(PDO $pdo, int $fromX, int $fromY, int $toX, int $toY, array $capabilities = []): array
{
    $version = town_navigation_version($pdo);
    if ($fromX === $toX && $fromY === $toY) {
        $cache = [];
        return ['found' => true, 'path' => town_navigation_route_path($pdo, [['x' => $fromX, 'y' => $fromY]], $cache, $version), 'cost' => 0, 'reason' => 'ok', 'navigation_version' => $version];
    }
    $start = town_navigation_can_traverse($pdo, $fromX, $fromY, $capabilities);
    $goal = town_navigation_can_traverse($pdo, $toX, $toY, $capabilities);
    if (!$start['allowed'] || !$goal['allowed']) {
        return ['found' => false, 'path' => [], 'cost' => 0,
            'reason' => !$goal['allowed'] ? (string)$goal['reason'] : (string)$start['reason'], 'navigation_version' => $version];
    }
    $cache = [];
    $open = ["{$fromX}:{$fromY}" => ['x' => $fromX, 'y' => $fromY, 'f' => abs($toX - $fromX) + abs($toY - $fromY)]];
    $came = []; $cost = ["{$fromX}:{$fromY}" => 0]; $visited = 0;
    while ($open !== [] && $visited++ < 10000) {
        uasort($open, static fn(array $left, array $right): int => $left['f'] <=> $right['f']);
        $current = array_shift($open); $key = $current['x'] . ':' . $current['y'];
        if ($current['x'] === $toX && $current['y'] === $toY) {
            $path = []; $cursor = $key;
            while (isset($cursor)) {
                [$cx, $cy] = array_map('intval', explode(':', $cursor)); $path[] = ['x' => $cx, 'y' => $cy];
                $cursor = $came[$cursor] ?? null;
            }
            $path = town_navigation_route_path($pdo, array_reverse($path), $cache, $version);
            return ['found' => true, 'path' => $path, 'cost' => count($path) - 1, 'reason' => 'ok', 'navigation_version' => $version];
        }
        $currentCell = town_navigation_cell($pdo, $current['x'], $current['y'], $cache, $version);
        foreach ([[1,0],[-1,0],[0,1],[0,-1]] as [$dx, $dy]) {
            $nx = $current['x'] + $dx; $ny = $current['y'] + $dy;
            $candidate = town_navigation_cell($pdo, $nx, $ny, $cache, $version);
            if (!town_navigation_transition_allow($currentCell, $candidate, $dx, $dy)) continue;
            $required = $candidate['required_capabilities']; $caps = array_fill_keys(town_navigation_capabilities($capabilities), true);
            $missingCandidate = array_filter($required, static fn(string $capability): bool => !isset($caps[$capability]));
            if ($nx < 0 || $ny < 0 || $nx >= SHARED_TOWN_MAP_SIZE || $ny >= SHARED_TOWN_MAP_SIZE || $missingCandidate !== []
                || ($candidate['blocked_npc'] && $required === [])) continue;
            $nextKey = $nx . ':' . $ny; $newCost = $cost[$key] + 1;
            if (!isset($cost[$nextKey]) || $newCost < $cost[$nextKey]) {
                $cost[$nextKey] = $newCost; $came[$nextKey] = $key;
                $open[$nextKey] = ['x' => $nx, 'y' => $ny, 'f' => $newCost + abs($toX - $nx) + abs($toY - $ny)];
            }
        }
    }
    return ['found' => false, 'path' => [], 'cost' => 0, 'reason' => 'no_route', 'navigation_version' => $version];
}

function town_navigation_rebuild(PDO $pdo, ?DateTimeImmutable $now = null): array
{
    $now ??= api_clock(); $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $query = $pdo->query("SELECT navigation_version FROM town_worlds WHERE world_id='main'{$lock}");
        $current = max(1, (int)$query->fetchColumn()); $next = $current + 1;
        $update = $pdo->prepare("UPDATE town_worlds SET navigation_version=:version, world_version=world_version+1, updated_at=:updated WHERE world_id='main'");
        $update->execute([':version' => $next, ':updated' => auth_sql_time($now)]); $pdo->commit();
        return ['navigation_version' => $next, 'world_version' => $next, 'rebuilt_at' => $now->format(DateTimeInterface::ATOM)];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
