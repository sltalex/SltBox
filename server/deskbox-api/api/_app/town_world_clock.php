<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__ . '/town_world_events.php';

const TOWN_WORLD_WEATHER_CODES = ['clear', 'cloudy', 'rain', 'storm', 'snow', 'fog'];
const TOWN_WORLD_TIME_ZONE = 'Asia/Shanghai';

function town_world_clock_default(DateTimeImmutable $now): array
{
    return [
        'world_id' => defined('SHARED_TOWN_WORLD_ID') ? SHARED_TOWN_WORLD_ID : 'main',
        'time_zone' => TOWN_WORLD_TIME_ZONE,
        'absolute_minute' => 0, 'calendar_day' => 1, 'weather_code' => 'clear',
        'weather_started_at' => $now->format('Y-m-d H:i:s'), 'next_weather_change_at' => null,
        'revision' => 0, 'updated_at' => $now->format('Y-m-d H:i:s'),
    ];
}

function town_world_clock_public(array $row): array
{
    $minute = max(0, (int)($row['absolute_minute'] ?? 0));
    $minuteOfDay = $minute % 1440;
    return [
        'world_id' => (string)($row['world_id'] ?? 'main'),
        'time_zone' => TOWN_WORLD_TIME_ZONE,
        'absolute_minute' => $minute,
        'calendar_day' => max(1, (int)($row['calendar_day'] ?? 1)),
        'minute_of_day' => $minuteOfDay,
        'time_label' => sprintf('%02d:%02d', intdiv($minuteOfDay, 60), $minuteOfDay % 60),
        'weather_code' => (string)($row['weather_code'] ?? 'clear'),
        'weather_started_at' => $row['weather_started_at'] !== null ? (string)$row['weather_started_at'] : null,
        'next_weather_change_at' => $row['next_weather_change_at'] !== null ? (string)$row['next_weather_change_at'] : null,
        'revision' => max(0, (int)($row['revision'] ?? 0)),
        'updated_at' => (string)($row['updated_at'] ?? ''),
    ];
}

function town_world_clock_read(PDO $pdo, ?DateTimeImmutable $now = null): array
{
    $now ??= api_clock();
    try {
        $query = $pdo->prepare('SELECT world_id,absolute_minute,calendar_day,weather_code,weather_started_at,next_weather_change_at,revision,updated_at FROM town_world_state WHERE world_id=:world LIMIT 1');
        $query->execute([':world' => defined('SHARED_TOWN_WORLD_ID') ? SHARED_TOWN_WORLD_ID : 'main']);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? town_world_clock_public($row) : town_world_clock_default($now);
    } catch (PDOException) {
        // Rolling deployments may expose world.php before migration 048 is run.
        return town_world_clock_default($now);
    }
}

function town_world_clock_require_weather(string $weather): string
{
    $weather = strtolower(trim($weather));
    if (!in_array($weather, TOWN_WORLD_WEATHER_CODES, true)) {
        throw new ApiError('invalid_weather', 'The town weather is invalid.', 400);
    }
    return $weather;
}

function town_world_clock_advance(PDO $pdo, int $expectedRevision, int $minutes, ?DateTimeImmutable $now = null): array
{
    $now ??= api_clock();
    if ($expectedRevision < 0 || $minutes < 1 || $minutes > 120) {
        throw new ApiError('invalid_clock_advance', 'The clock advance is invalid.', 400);
    }
    $world = defined('SHARED_TOWN_WORLD_ID') ? SHARED_TOWN_WORLD_ID : 'main';
    $pdo->beginTransaction();
    try {
        $sql = 'SELECT world_id,absolute_minute,calendar_day,weather_code,weather_started_at,next_weather_change_at,revision,updated_at FROM town_world_state WHERE world_id=:world LIMIT 1';
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') $sql .= ' FOR UPDATE';
        $query = $pdo->prepare($sql); $query->execute([':world' => $world]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new ApiError('town_clock_unavailable', 'The town clock is not initialized.', 503);
        $revision = (int)$row['revision'];
        if ($revision !== $expectedRevision) throw new ApiError('world_revision_conflict', 'The town clock has changed. Reload it before advancing.', 409);
        $absolute = (int)$row['absolute_minute'] + $minutes;
        $day = max(1, (int)$row['calendar_day'] + intdiv($absolute, 1440) - intdiv((int)$row['absolute_minute'], 1440));
        $nextRevision = $revision + 1;
        $weather = (string)($row['weather_code'] ?? 'clear');
        $weatherChanged = false;
        $nextWeatherChangeAt = $row['next_weather_change_at'] !== null ? (string)$row['next_weather_change_at'] : null;
        if ($nextWeatherChangeAt !== null && trim($nextWeatherChangeAt) !== '') {
            try { $weatherExpired = auth_parse_sql_time($nextWeatherChangeAt) <= $now; }
            catch (Throwable) { $weatherExpired = true; }
            if ($weatherExpired) {
                // A temporary weather override always returns to the stable
                // clear state. The change is committed with the clock revision
                // so every worker and client observes one ordered transition.
                $weather = 'clear';
                $weatherChanged = (string)($row['weather_code'] ?? 'clear') !== $weather;
                $nextWeatherChangeAt = null;
            }
        }
        $update = $pdo->prepare('UPDATE town_world_state SET absolute_minute=:absolute,calendar_day=:day,weather_code=:weather,next_weather_change_at=:next_weather,weather_started_at=:weather_started,revision=:revision,updated_at=:updated WHERE world_id=:world');
        $update->execute([
            ':absolute' => $absolute, ':day' => $day, ':weather' => $weather,
            ':next_weather' => $nextWeatherChangeAt,
            ':weather_started' => $weatherChanged ? $now->format('Y-m-d H:i:s') : $row['weather_started_at'],
            ':revision' => $nextRevision, ':updated' => $now->format('Y-m-d H:i:s'), ':world' => $world,
        ]);
        $event = town_world_event_append($pdo, ['source_type' => 'world_clock', 'source_id' => $world, 'source_revision' => (string)$nextRevision, 'event_type' => 'time_advanced', 'public_payload' => ['calendar_day' => $day, 'minute_of_day' => $absolute % 1440, 'minutes' => $minutes], 'occurred_at' => $now], $now);
        $weatherEvent = null;
        if ($weatherChanged) {
            $weatherEvent = town_world_event_append($pdo, [
                'source_type' => 'weather', 'source_id' => $world, 'source_revision' => (string)$nextRevision,
                'event_type' => 'weather_changed',
                'public_payload' => ['weather_code' => $weather, 'reason' => 'duration_expired', 'duration_minutes' => 0],
                'occurred_at' => $now,
            ], $now);
        }
        $pdo->commit();
        $result = town_world_clock_read($pdo, $now);
        $result['event'] = $event;
        if ($weatherEvent !== null) $result['weather_event'] = $weatherEvent;
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function town_world_clock_set_weather(PDO $pdo, string $weather, int $durationMinutes, int $expectedRevision, ?DateTimeImmutable $now = null): array
{
    $now ??= api_clock();
    $weather = town_world_clock_require_weather($weather);
    if ($durationMinutes < 0 || $durationMinutes > 7 * 1440) throw new ApiError('invalid_weather_duration', 'The weather duration is invalid.', 400);
    $world = defined('SHARED_TOWN_WORLD_ID') ? SHARED_TOWN_WORLD_ID : 'main';
    $pdo->beginTransaction();
    try {
        $sql = 'SELECT weather_code,revision FROM town_world_state WHERE world_id=:world LIMIT 1';
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') $sql .= ' FOR UPDATE';
        $query = $pdo->prepare($sql); $query->execute([':world' => $world]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new ApiError('town_clock_unavailable', 'The town clock is not initialized.', 503);
        if ((int)$row['revision'] !== $expectedRevision) throw new ApiError('world_revision_conflict', 'The town clock has changed. Reload it before changing weather.', 409);
        $revision = $expectedRevision + 1;
        $next = $durationMinutes > 0 ? $now->modify('+' . $durationMinutes . ' minutes')->format('Y-m-d H:i:s') : null;
        $update = $pdo->prepare('UPDATE town_world_state SET weather_code=:weather,weather_started_at=:started,next_weather_change_at=:next,revision=:revision,updated_at=:updated WHERE world_id=:world');
        $update->execute([':weather' => $weather, ':started' => $now->format('Y-m-d H:i:s'), ':next' => $next, ':revision' => $revision, ':updated' => $now->format('Y-m-d H:i:s'), ':world' => $world]);
        $event = town_world_event_append($pdo, ['source_type' => 'weather', 'source_id' => $world, 'source_revision' => (string)$revision, 'event_type' => 'weather_changed', 'public_payload' => ['weather_code' => $weather, 'duration_minutes' => $durationMinutes], 'occurred_at' => $now], $now);
        $pdo->commit();
        $result = town_world_clock_read($pdo, $now); $result['event'] = $event; return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
