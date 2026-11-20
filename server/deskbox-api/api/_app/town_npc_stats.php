<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }

const TOWN_NPC_STAT_KEYS = ['mood', 'health', 'stamina', 'labor', 'academics', 'culture', 'social', 'wealth', 'stress', 'reputation'];

function town_npc_stat_defaults(): array
{
    return ['mood' => 60, 'health' => 60, 'stamina' => 60, 'labor' => 50, 'academics' => 50, 'culture' => 50, 'social' => 50, 'wealth' => 50, 'stress' => 40, 'reputation' => 50];
}

function town_npc_stat_level(int $value, string $key = ''): string
{
    $value = max(1, min(100, $value));
    if ($key === 'stress') {
        if ($value <= 20) return '轻松';
        if ($value <= 40) return '较低';
        if ($value <= 60) return '一般';
        if ($value <= 75) return '偏高';
        if ($value <= 90) return '很高';
        return '极高';
    }
    if ($value <= 20) return '糟糕';
    if ($value <= 40) return '较差';
    if ($value <= 60) return '一般';
    if ($value <= 75) return '良好';
    if ($value <= 90) return '很好';
    return '极佳';
}

function town_npc_stat_normalize(array $values, ?array $fallback = null): array
{
    $result = $fallback ?? town_npc_stat_defaults();
    foreach (TOWN_NPC_STAT_KEYS as $key) {
        if (array_key_exists($key, $values)) $result[$key] = max(1, min(100, (int)$values[$key]));
    }
    return $result;
}

function town_npc_stat_public(array $values, int $revision = 1, string $updatedAt = ''): array
{
    $values = town_npc_stat_normalize($values);
    $levels = [];
    foreach ($values as $key => $value) $levels[$key] = town_npc_stat_level((int)$value, (string)$key);
    return ['values' => $values, 'levels' => $levels, 'revision' => max(1, $revision), 'updated_at' => $updatedAt];
}

function town_npc_stats_table_available(PDO $pdo): bool
{
    if (function_exists('town_npc_life_table_exists')) return town_npc_life_table_exists($pdo, 'town_npc_stats');
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') return (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='town_npc_stats'")->fetchColumn() > 0;
        return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='town_npc_stats'")->fetchColumn() > 0;
    } catch (Throwable $error) { return false; }
}

function town_npc_stats_for(PDO $pdo, string $npcId): array
{
    $defaults = town_npc_stat_public(town_npc_stat_defaults());
    if (!town_npc_stats_table_available($pdo)) return $defaults;
    try {
        $query = $pdo->prepare('SELECT mood,health,stamina,labor,academics,culture,social,wealth,stress,reputation,revision,updated_at FROM town_npc_stats WHERE npc_id=:npc LIMIT 1');
        $query->execute([':npc' => $npcId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return $defaults;
        $values = []; foreach (TOWN_NPC_STAT_KEYS as $key) $values[$key] = (int)$row[$key];
        return town_npc_stat_public($values, (int)$row['revision'], (string)$row['updated_at']);
    } catch (Throwable $error) { return $defaults; }
}

function town_npc_attach_stats(PDO $pdo, array $rows): array
{
    if ($rows === [] || !town_npc_stats_table_available($pdo)) {
        foreach ($rows as $index => $row) $rows[$index]['stats'] = town_npc_stat_public(town_npc_stat_defaults());
        return $rows;
    }
    $ids = [];
    foreach ($rows as $row) if (is_string($row['npc_id'] ?? null)) $ids[] = (string)$row['npc_id'];
    $ids = array_values(array_unique($ids));
    $found = [];
    if ($ids !== []) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $query = $pdo->prepare('SELECT npc_id,mood,health,stamina,labor,academics,culture,social,wealth,stress,reputation,revision,updated_at FROM town_npc_stats WHERE npc_id IN (' . $placeholders . ')');
            $query->execute($ids);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $values = []; foreach (TOWN_NPC_STAT_KEYS as $key) $values[$key] = (int)$row[$key];
                $found[(string)$row['npc_id']] = town_npc_stat_public($values, (int)$row['revision'], (string)$row['updated_at']);
            }
        } catch (Throwable $error) { $found = []; }
    }
    foreach ($rows as $index => $row) $rows[$index]['stats'] = $found[(string)($row['npc_id'] ?? '')] ?? town_npc_stat_public(town_npc_stat_defaults());
    return $rows;
}

function town_npc_stats_save(PDO $pdo, string $npcId, array $values, string $sourceType = 'admin', string $sourceId = '', string $reason = '', ?DateTimeImmutable $now = null): array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $npcId)) throw new ApiError('invalid_npc', '村民 ID 无效。', 400);
    if (!town_npc_stats_table_available($pdo)) throw new ApiError('stats_migration_required', 'NPC 个体数值迁移尚未执行。', 503);
    $now ??= api_clock();
    $exists = $pdo->prepare('SELECT 1 FROM town_npcs WHERE npc_id=:npc LIMIT 1'); $exists->execute([':npc' => $npcId]);
    if (!$exists->fetchColumn()) throw new ApiError('npc_not_found', '村民不存在。', 404);
    $next = town_npc_stat_normalize($values);
    $stamp = auth_sql_time($now);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $json = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        // Read the previous values only after acquiring the row lock. This
        // keeps before/delta history truthful when an admin edit and a story
        // effect arrive at the same time.
        $lock = $pdo->prepare('SELECT mood,health,stamina,labor,academics,culture,social,wealth,stress,reputation,revision,updated_at FROM town_npc_stats WHERE npc_id=:npc' . ($driver === 'mysql' ? ' FOR UPDATE' : ''));
        $lock->execute([':npc' => $npcId]);
        $locked = $lock->fetch(PDO::FETCH_ASSOC);
        $revision = is_array($locked) ? (int)$locked['revision'] : 0;
        $old = is_array($locked)
            ? town_npc_stat_normalize(array_intersect_key($locked, array_flip(TOWN_NPC_STAT_KEYS)))
            : town_npc_stat_defaults();
        $delta = [];
        foreach (TOWN_NPC_STAT_KEYS as $key) {
            if ($next[$key] !== $old[$key]) $delta[$key] = $next[$key] - $old[$key];
        }
        if ($revision < 1) {
            $insert = $pdo->prepare('INSERT INTO town_npc_stats (npc_id,mood,health,stamina,labor,academics,culture,social,wealth,stress,reputation,revision,updated_at) VALUES (:npc,:mood,:health,:stamina,:labor,:academics,:culture,:social,:wealth,:stress,:reputation,1,:updated)');
            $insert->execute([':npc' => $npcId, ':mood' => $next['mood'], ':health' => $next['health'], ':stamina' => $next['stamina'], ':labor' => $next['labor'], ':academics' => $next['academics'], ':culture' => $next['culture'], ':social' => $next['social'], ':wealth' => $next['wealth'], ':stress' => $next['stress'], ':reputation' => $next['reputation'], ':updated' => $stamp]);
            $revision = 1;
        } else {
            $update = $pdo->prepare('UPDATE town_npc_stats SET mood=:mood,health=:health,stamina=:stamina,labor=:labor,academics=:academics,culture=:culture,social=:social,wealth=:wealth,stress=:stress,reputation=:reputation,revision=revision+1,updated_at=:updated WHERE npc_id=:npc');
            $update->execute([':npc' => $npcId, ':mood' => $next['mood'], ':health' => $next['health'], ':stamina' => $next['stamina'], ':labor' => $next['labor'], ':academics' => $next['academics'], ':culture' => $next['culture'], ':social' => $next['social'], ':wealth' => $next['wealth'], ':stress' => $next['stress'], ':reputation' => $next['reputation'], ':updated' => $stamp]);
            $revision++;
        }
        if ($delta !== []) {
            $history = $pdo->prepare('INSERT INTO town_npc_stat_changes (npc_id,source_type,source_id,before_json,delta_json,after_json,reason,created_at) VALUES (:npc,:source,:source_id,:before,:delta,:after,:reason,:created)');
            $history->execute([':npc' => $npcId, ':source' => substr($sourceType, 0, 32), ':source_id' => substr($sourceId, 0, 128), ':before' => json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':delta' => json_encode($delta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ':after' => $json, ':reason' => substr($reason, 0, 255), ':created' => $stamp]);
            // Push manual and story-driven changes through the existing NPC
            // change feed so open clients update their value cards without a
            // full page refresh. Older rolling deployments may not have the
            // event table yet, so the persistence path remains fail-safe.
            if (function_exists('town_npc_emit_event')) {
                try { town_npc_emit_event($pdo, $npcId, 'stats_changed', ['stats' => town_npc_stat_public($next, $revision, $stamp), 'delta' => $delta, 'reason' => substr($reason, 0, 255)], $now); }
                catch (Throwable $eventError) { /* optional realtime table during rolling migration */ }
            }
        }
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) { if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    return town_npc_stat_public($next, $revision, $stamp);
}

function town_npc_stat_history(PDO $pdo, string $npcId, int $limit = 50, int $cursor = 0): array
{
    if (!town_npc_stats_table_available($pdo)) return ['changes' => [], 'cursor' => $cursor, 'has_more' => false];
    $limit = max(1, min(200, $limit));
    $query = $pdo->prepare('SELECT change_id,source_type,source_id,before_json,delta_json,after_json,reason,created_at FROM town_npc_stat_changes WHERE npc_id=:npc AND change_id>:cursor ORDER BY change_id LIMIT ' . ($limit + 1));
    $query->execute([':npc' => $npcId, ':cursor' => max(0, $cursor)]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC); $hasMore = count($rows) > $limit; if ($hasMore) array_pop($rows);
    $changes = [];
    foreach ($rows as $row) $changes[] = ['change_id' => (int)$row['change_id'], 'source_type' => (string)$row['source_type'], 'source_id' => (string)$row['source_id'], 'before' => json_decode((string)$row['before_json'], true) ?: [], 'delta' => json_decode((string)$row['delta_json'], true) ?: [], 'after' => json_decode((string)$row['after_json'], true) ?: [], 'reason' => (string)$row['reason'], 'created_at' => (string)$row['created_at']];
    return ['changes' => $changes, 'cursor' => $changes === [] ? max(0, $cursor) : (int)$changes[array_key_last($changes)]['change_id'], 'has_more' => $hasMore];
}

/** Admin cards need the latest changes first; public pagination remains oldest-first. */
function town_npc_stat_history_recent(PDO $pdo, string $npcId, int $limit = 12): array
{
    if (!town_npc_stats_table_available($pdo)) return [];
    $limit = max(1, min(200, $limit));
    $query = $pdo->prepare('SELECT change_id,source_type,source_id,before_json,delta_json,after_json,reason,created_at FROM town_npc_stat_changes WHERE npc_id=:npc ORDER BY change_id DESC LIMIT ' . $limit);
    $query->execute([':npc' => $npcId]); $rows = array_reverse($query->fetchAll(PDO::FETCH_ASSOC)); $changes = [];
    foreach ($rows as $row) $changes[] = ['change_id' => (int)$row['change_id'], 'source_type' => (string)$row['source_type'], 'source_id' => (string)$row['source_id'], 'before' => json_decode((string)$row['before_json'], true) ?: [], 'delta' => json_decode((string)$row['delta_json'], true) ?: [], 'after' => json_decode((string)$row['after_json'], true) ?: [], 'reason' => (string)$row['reason'], 'created_at' => (string)$row['created_at']];
    return $changes;
}
