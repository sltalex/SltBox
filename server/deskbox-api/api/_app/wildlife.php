<?php
declare(strict_types=1);

const WILDLIFE_MANIFEST_PATH = __DIR__ . '/../../config/wildlife.json';
const WILDLIFE_RESTORE_MAX_BYTES = 32768;

function wildlife_catalog(?PDO $pdo = null): array
{
    static $items = null;
    if ($items === null) {
        $manifest = json_decode((string)file_get_contents(WILDLIFE_MANIFEST_PATH), true, 512, JSON_THROW_ON_ERROR);
        $items = $manifest['items'] ?? [];
        if (!is_array($items) || count($items) < 16 || count($items) > 256)
            throw new RuntimeException('Wildlife catalogue is invalid.');
        $ids = [];
        foreach ($items as $item) {
            if (!is_array($item) || !preg_match('/\A[a-z-]{1,80}\z/D', (string)($item['id'] ?? '')) ||
                !in_array($item['category'] ?? null, ['昆虫', '飞鸟', '小动物'], true) ||
                !is_array($item['seasons'] ?? null) || !is_array($item['periods'] ?? null) ||
                !is_array($item['weathers'] ?? null) || !is_array($item['preferred_weathers'] ?? null) ||
                isset($ids[$item['id']])) throw new RuntimeException('Wildlife catalogue item is invalid.');
            $ids[$item['id']] = true;
        }
    }
    if ($pdo === null) return $items;
    if (!$pdo->inTransaction()) wildlife_ensure_catalog_schema($pdo);
    $settings = [];
    foreach ($pdo->query('SELECT species_id,name,enabled,encounter_weight FROM wildlife_catalog_settings')->fetchAll(PDO::FETCH_ASSOC) as $row)
        $settings[$row['species_id']] = $row;
    return array_map(static function (array $item) use ($settings): array {
        $setting = $settings[$item['id']] ?? null;
        $item['name'] = $setting['name'] ?? $item['name'];
        $item['enabled'] = $setting === null ? true : (bool)$setting['enabled'];
        $item['encounter_weight'] = $setting === null ? 1 : (int)$setting['encounter_weight'];
        return $item;
    }, $items);
}

function wildlife_ensure_catalog_schema(PDO $pdo): void
{
    $date = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'TEXT' : 'DATETIME';
    $pdo->exec("CREATE TABLE IF NOT EXISTS wildlife_catalog_settings (
        species_id VARCHAR(80) PRIMARY KEY, name VARCHAR(120) NOT NULL,
        enabled INT NOT NULL DEFAULT 1, encounter_weight INT NOT NULL DEFAULT 1,
        updated_at {$date} NOT NULL)");
}

function wildlife_admin_update(PDO $pdo, array $post, DateTimeImmutable $now): void
{
    $id = is_string($post['species_id'] ?? null) ? $post['species_id'] : '';
    $name = is_string($post['species_name'] ?? null) ? trim($post['species_name']) : '';
    $weight = is_string($post['encounter_weight'] ?? null) ? trim($post['encounter_weight']) : '';
    if (!preg_match('/\A[a-z-]{1,80}\z/D', $id) || !in_array($id, array_column(wildlife_catalog(), 'id'), true)
        || $name === '' || strlen($name) > 120 || !preg_match('/\A(?:[1-9]|[1-9][0-9]|100)\z/D', $weight))
        throw new ApiError('invalid_wildlife', '动物图鉴参数无效。', 400);
    wildlife_ensure_catalog_schema($pdo);
    $enabled = ($post['enabled'] ?? null) === '1' ? 1 : 0;
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pdo->beginTransaction();
    try {
        $sql = 'INSERT INTO wildlife_catalog_settings (species_id,name,enabled,encounter_weight,updated_at) '
            . 'VALUES (:id,:name,:enabled,:weight,:updated) ' . ($sqlite
                ? 'ON CONFLICT(species_id) DO UPDATE SET name=excluded.name,enabled=excluded.enabled,encounter_weight=excluded.encounter_weight,updated_at=excluded.updated_at'
                : 'ON DUPLICATE KEY UPDATE name=VALUES(name),enabled=VALUES(enabled),encounter_weight=VALUES(encounter_weight),updated_at=VALUES(updated_at)');
        $pdo->prepare($sql)->execute([':id' => $id, ':name' => $name, ':enabled' => $enabled,
            ':weight' => (int)$weight, ':updated' => auth_sql_time($now)]);
        $catalog = wildlife_catalog($pdo);
        foreach (['spring','summer','autumn','winter'] as $season)
            foreach (['morning','day','dusk','night'] as $period)
                foreach (['clear','cloudy','fog','drizzle','rain','snow','thunderstorm'] as $weather) {
                    $available = false;
                    foreach ($catalog as $species) {
                        if ($species['enabled'] && in_array($season, $species['seasons'], true) &&
                            in_array($period, $species['periods'], true) && in_array($weather, $species['weathers'], true)) {
                            $available = true;
                            break;
                        }
                    }
                    if (!$available)
                        throw new ApiError('invalid_wildlife', '每种季节、时段和天气须至少保留一种可遇见动物。', 400);
                }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function wildlife_ensure_schema(PDO $pdo): void
{
    wildlife_ensure_catalog_schema($pdo);
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $date = $sqlite ? 'TEXT' : 'DATETIME';
    $pdo->exec("CREATE TABLE IF NOT EXISTS wildlife_encounter_state (
        user_id VARCHAR(64) PRIMARY KEY, encounter_id VARCHAR(64) NULL, species_id VARCHAR(80) NULL,
        expires_at {$date} NULL, next_due_at {$date} NOT NULL, updated_at {$date} NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS wildlife_inventory (
        user_id VARCHAR(64) NOT NULL, species_id VARCHAR(80) NOT NULL, quantity BIGINT NOT NULL DEFAULT 0,
        updated_at {$date} NOT NULL, PRIMARY KEY(user_id,species_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS wildlife_discoveries (
        user_id VARCHAR(64) NOT NULL, species_id VARCHAR(80) NOT NULL, discovered_at {$date} NOT NULL,
        PRIMARY KEY(user_id,species_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS wildlife_capture_operations (
        user_id VARCHAR(64) NOT NULL, operation_id VARCHAR(128) NOT NULL, encounter_id VARCHAR(64) NOT NULL,
        species_id VARCHAR(80) NOT NULL, created_at {$date} NOT NULL,
        PRIMARY KEY(user_id,operation_id))");
}

function wildlife_weather(int $code): ?string
{
    return match (true) {
        $code === 0 || $code === 1 => 'clear',
        $code === 2 || $code === 3 => 'cloudy',
        $code === 45 || $code === 48 => 'fog',
        $code >= 51 && $code <= 57 => 'drizzle',
        ($code >= 61 && $code <= 67) || ($code >= 80 && $code <= 82) => 'rain',
        ($code >= 71 && $code <= 77) || ($code >= 85 && $code <= 86) => 'snow',
        $code >= 95 && $code <= 99 => 'thunderstorm',
        default => null,
    };
}

function wildlife_solar_longitude(float $unixSeconds): float
{
    $t = ($unixSeconds / 86400.0 + 2440587.5 - 2451545.0) / 36525.0;
    $mean = 280.46646 + $t * (36000.76983 + $t * 0.0003032);
    $anomaly = deg2rad(357.52911 + $t * (35999.05029 - 0.0001537 * $t));
    $center = (1.914602 - $t * (0.004817 + 0.000014 * $t)) * sin($anomaly)
        + (0.019993 - 0.000101 * $t) * sin(2 * $anomaly) + 0.000289 * sin(3 * $anomaly);
    $longitude = $mean + $center - 0.00569 - 0.00478 *
        sin(deg2rad(125.04 - 1934.136 * $t));
    return fmod(fmod($longitude, 360.0) + 360.0, 360.0);
}

function wildlife_term_instant(int $year, int $month, int $approximateDay, float $longitude): int
{
    $middle = (new DateTimeImmutable(sprintf('%04d-%02d-%02dT12:00:00Z', $year, $month, $approximateDay)))->getTimestamp();
    $low = (float)($middle - 5 * 86400);
    $high = (float)($middle + 5 * 86400);
    for ($i = 0; $i < 48; $i++) {
        $mid = ($low + $high) / 2.0;
        $difference = fmod(wildlife_solar_longitude($mid) - $longitude + 540.0, 360.0) - 180.0;
        if ($difference < 0) $low = $mid;
        else $high = $mid;
    }
    return (int)round(($low + $high) / 2.0);
}

function wildlife_season(DateTimeImmutable $now, int $utcOffsetMinutes): string
{
    $localTime = $now->getTimestamp() + $utcOffsetMinutes * 60;
    $year = (int)gmdate('Y', $localTime);
    $today = gmdate('Y-m-d', $localTime);
    $terms = [
        'spring' => [2, 4, 315.0],
        'summer' => [5, 5, 45.0],
        'autumn' => [8, 7, 135.0],
        'winter' => [11, 7, 225.0],
    ];
    $season = 'winter';
    foreach ($terms as $name => [$month, $day, $longitude]) {
        $term = wildlife_term_instant($year, $month, $day, $longitude);
        if ($today >= gmdate('Y-m-d', $term + $utcOffsetMinutes * 60)) $season = $name;
    }
    return $season;
}

function wildlife_period(DateTimeImmutable $now, int $utcOffsetMinutes): string
{
    $hour = (int)gmdate('G', $now->getTimestamp() + $utcOffsetMinutes * 60);
    return $hour >= 5 && $hour < 9 ? 'morning' :
        ($hour >= 9 && $hour < 17 ? 'day' : ($hour >= 17 && $hour < 20 ? 'dusk' : 'night'));
}

function wildlife_eligible(string $season, string $period, string $weather, ?PDO $pdo = null): array
{
    $eligible = [];
    foreach (wildlife_catalog($pdo) as $item) {
        if (isset($item['enabled']) && !$item['enabled']) continue;
        if (!in_array($season, $item['seasons'], true) || !in_array($period, $item['periods'], true) ||
            !in_array($weather, $item['weathers'], true)) continue;
        $eligible[] = ['item' => $item,
            'weight' => (in_array($weather, $item['preferred_weathers'], true) ? 4 : 1) * ($item['encounter_weight'] ?? 1)];
    }
    return $eligible;
}

function wildlife_pick_species(array $eligible, callable $randomInt): string
{
    $total = array_sum(array_column($eligible, 'weight'));
    if ($total < 1) throw new RuntimeException('No wildlife is eligible.');
    $roll = $randomInt(1, $total);
    foreach ($eligible as $entry) {
        $roll -= $entry['weight'];
        if ($roll <= 0) return $entry['item']['id'];
    }
    throw new RuntimeException('Wildlife selection failed.');
}

function wildlife_datetime(?string $value): ?DateTimeImmutable
{
    return $value === null ? null : new DateTimeImmutable($value . ' UTC');
}

function wildlife_state_read(PDO $pdo, string $userId, DateTimeImmutable $now, bool $schemaReady = false): array
{
    // MySQL DDL implicitly commits: callers already inside a capture/offer
    // transaction must not run CREATE TABLE IF NOT EXISTS a second time.
    if (!$schemaReady) wildlife_ensure_schema($pdo);
    $inventory = [];
    $query = $pdo->prepare('SELECT species_id,quantity FROM wildlife_inventory WHERE user_id=:user');
    $query->execute([':user' => $userId]);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $inventory[$row['species_id']] = (int)$row['quantity'];
    $query = $pdo->prepare('SELECT species_id FROM wildlife_discoveries WHERE user_id=:user');
    $query->execute([':user' => $userId]);
    $discovered = array_fill_keys($query->fetchAll(PDO::FETCH_COLUMN), true);
    $query = $pdo->prepare('SELECT encounter_id,species_id,expires_at,next_due_at FROM wildlife_encounter_state WHERE user_id=:user');
    $query->execute([':user' => $userId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    $expiry = $row ? wildlife_datetime($row['expires_at']) : null;
    $encounter = $row && $row['encounter_id'] !== null && $expiry !== null && $expiry > $now
        ? ['id' => $row['encounter_id'], 'species_id' => $row['species_id'],
            'expires_at_utc' => $expiry->format(DATE_ATOM)] : null;
    return ['inventory' => array_map(static fn(array $item): array => [
        'species_id' => $item['id'], 'quantity' => $inventory[$item['id']] ?? 0,
        'discovered' => isset($discovered[$item['id']]) || ($inventory[$item['id']] ?? 0) > 0,
    ], wildlife_catalog()), 'encounter' => $encounter,
        'next_due_at_utc' => $row ? wildlife_datetime($row['next_due_at'])->format(DATE_ATOM) : null];
}

function wildlife_offer(PDO $pdo, string $userId, array $body, callable $clock,
    ?callable $randomInt = null): array
{
    $now = $clock();
    $weatherCode = $body['weather_code'] ?? null;
    $offset = $body['utc_offset_minutes'] ?? null;
    $fetchedText = $body['weather_fetched_at_utc'] ?? null;
    $resume = $body['resume_after_hidden'] ?? false;
    if (!is_int($weatherCode) || ($weather = wildlife_weather($weatherCode)) === null ||
        !is_int($offset) || $offset < -720 || $offset > 840 ||
        !is_bool($resume) ||
        !is_string($fetchedText) || !preg_match('/\A\d{4}-\d\d-\d\dT.*(?:Z|[+-]\d\d:\d\d)\z/D', $fetchedText))
        throw new ApiError('invalid_wildlife_weather', 'A fresh weather observation is required.', 400);
    try { $fetched = new DateTimeImmutable($fetchedText); }
    catch (Throwable) { throw new ApiError('invalid_wildlife_weather', 'Weather timestamp is invalid.', 400); }
    $age = $now->getTimestamp() - $fetched->getTimestamp();
    if ($age < -300 || $age > 14400)
        throw new ApiError('stale_wildlife_weather', 'Weather observation is stale.', 409);
    $random = $randomInt ?? static fn(int $min, int $max): int => random_int($min, $max);
    wildlife_ensure_schema($pdo);
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $pdo->beginTransaction();
    try {
        $due = $now->modify('+' . $random(15, 30) . ' minutes');
        $pdo->prepare(($mysql ? 'INSERT IGNORE' : 'INSERT OR IGNORE') .
            ' INTO wildlife_encounter_state (user_id,next_due_at,updated_at) VALUES (:user,:due,:now)')
            ->execute([':user' => $userId, ':due' => auth_sql_time($due), ':now' => auth_sql_time($now)]);
        $query = $pdo->prepare('SELECT encounter_id,species_id,expires_at,next_due_at FROM wildlife_encounter_state WHERE user_id=:user' . ($mysql ? ' FOR UPDATE' : ''));
        $query->execute([':user' => $userId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($resume) {
            $pdo->prepare('UPDATE wildlife_encounter_state SET encounter_id=NULL,species_id=NULL,expires_at=NULL,next_due_at=:due,updated_at=:now WHERE user_id=:user')
                ->execute([':due' => auth_sql_time($due), ':now' => auth_sql_time($now), ':user' => $userId]);
            $row['encounter_id'] = null;
            $row['expires_at'] = null;
            $row['next_due_at'] = auth_sql_time($due);
        }
        $expiry = wildlife_datetime($row['expires_at']);
        if (!($row['encounter_id'] !== null && $expiry !== null && $expiry > $now) &&
            wildlife_datetime($row['next_due_at']) <= $now) {
            $season = wildlife_season($now, $offset);
            $period = wildlife_period($now, $offset);
            $species = wildlife_pick_species(wildlife_eligible($season, $period, $weather, $pdo), $random);
            $pdo->prepare('UPDATE wildlife_encounter_state SET encounter_id=:id,species_id=:species,expires_at=:expiry,next_due_at=:due,updated_at=:now WHERE user_id=:user')
                ->execute([':id' => bin2hex(random_bytes(16)), ':species' => $species,
                    ':expiry' => auth_sql_time($now->modify('+10 minutes')),
                    ':due' => auth_sql_time($now->modify('+' . $random(15, 30) . ' minutes')),
                    ':now' => auth_sql_time($now), ':user' => $userId]);
        }
        $state = wildlife_state_read($pdo, $userId, $now, true);
        $pdo->commit();
        return $state;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function wildlife_capture(PDO $pdo, string $userId, array $body, callable $clock): array
{
    $id = $body['encounter_id'] ?? null;
    $operation = $body['operation_id'] ?? null;
    if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/D', $id) || !is_string($operation) ||
        !preg_match('/\A[A-Za-z0-9_-]{16,128}\z/D', $operation))
        throw new ApiError('invalid_wildlife_capture', 'Encounter and operation ids are required.', 400);
    wildlife_ensure_schema($pdo);
    $now = $clock();
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT encounter_id,species_id,expires_at FROM wildlife_encounter_state WHERE user_id=:user' . ($mysql ? ' FOR UPDATE' : ''));
        $query->execute([':user' => $userId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        $query = $pdo->prepare('SELECT encounter_id,species_id FROM wildlife_capture_operations WHERE user_id=:user AND operation_id=:operation' . ($mysql ? ' FOR UPDATE' : ''));
        $query->execute([':user' => $userId, ':operation' => $operation]);
        $prior = $query->fetch(PDO::FETCH_ASSOC);
        if ($prior) {
            if ($prior['encounter_id'] !== $id) throw new ApiError('operation_conflict', 'Operation id was used for another encounter.', 409);
            $pdo->commit();
            return ['species_id' => $prior['species_id'], 'quantity' => 1, 'duplicate' => true];
        }
        if (!$row || $row['encounter_id'] !== $id || wildlife_datetime($row['expires_at']) <= $now)
            throw new ApiError('wildlife_encounter_expired', 'The animal has left.', 409);
        $species = (string)$row['species_id'];
        $sqlite = !$mysql;
        $pdo->prepare('INSERT INTO wildlife_inventory (user_id,species_id,quantity,updated_at) VALUES (:user,:species,1,:now) ' .
            ($sqlite ? 'ON CONFLICT(user_id,species_id) DO UPDATE SET quantity=quantity+1,updated_at=excluded.updated_at'
                : 'ON DUPLICATE KEY UPDATE quantity=quantity+1,updated_at=VALUES(updated_at)'))
            ->execute([':user' => $userId, ':species' => $species, ':now' => auth_sql_time($now)]);
        $pdo->prepare(($sqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE') . ' INTO wildlife_discoveries (user_id,species_id,discovered_at) VALUES (:user,:species,:now)')
            ->execute([':user' => $userId, ':species' => $species, ':now' => auth_sql_time($now)]);
        $pdo->prepare('INSERT INTO wildlife_capture_operations (user_id,operation_id,encounter_id,species_id,created_at) VALUES (:user,:operation,:id,:species,:now)')
            ->execute([':user' => $userId, ':operation' => $operation, ':id' => $id,
                ':species' => $species, ':now' => auth_sql_time($now)]);
        $pdo->prepare('UPDATE wildlife_encounter_state SET encounter_id=NULL,species_id=NULL,expires_at=NULL,updated_at=:now WHERE user_id=:user')
            ->execute([':now' => auth_sql_time($now), ':user' => $userId]);
        $pdo->commit();
        return ['species_id' => $species, 'quantity' => 1, 'duplicate' => false];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function wildlife_restore(PDO $pdo, string $userId, array $body, callable $clock): array
{
    if (($body['owner_user_id'] ?? null) !== $userId || !is_array($body['inventory'] ?? null) ||
        count($body['inventory']) > count(wildlife_catalog()))
        throw new ApiError('invalid_wildlife_restore', 'Wildlife backup owner or inventory is invalid.', 400);
    $known = array_column(wildlife_catalog(), null, 'id');
    $seen = [];
    $inventory = [];
    foreach ($body['inventory'] as $item) {
        if ($item instanceof stdClass) $item = get_object_vars($item);
        if (!is_array($item) || !is_string($item['species_id'] ?? null) ||
            !isset($known[$item['species_id']]) || isset($seen[$item['species_id']]) ||
            !is_int($item['quantity'] ?? null) || $item['quantity'] < 0 || $item['quantity'] > 1000000000 ||
            !is_bool($item['discovered'] ?? null))
            throw new ApiError('invalid_wildlife_restore', 'Wildlife backup entry is invalid.', 400);
        $seen[$item['species_id']] = true;
        $inventory[] = $item;
    }
    wildlife_ensure_schema($pdo);
    $now = $clock();
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pdo->beginTransaction();
    try {
        foreach ($inventory as $item) {
            $args = [':user' => $userId, ':species' => $item['species_id'],
                ':quantity' => $item['quantity'], ':now' => auth_sql_time($now)];
            $pdo->prepare('INSERT INTO wildlife_inventory (user_id,species_id,quantity,updated_at) VALUES (:user,:species,:quantity,:now) ' .
                ($sqlite ? 'ON CONFLICT(user_id,species_id) DO UPDATE SET quantity=MAX(quantity,excluded.quantity),updated_at=excluded.updated_at'
                    : 'ON DUPLICATE KEY UPDATE quantity=GREATEST(quantity,VALUES(quantity)),updated_at=VALUES(updated_at)'))
                ->execute($args);
            if ($item['discovered'] || $item['quantity'] > 0)
                $pdo->prepare(($sqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE') . ' INTO wildlife_discoveries (user_id,species_id,discovered_at) VALUES (:user,:species,:now)')
                    ->execute([':user' => $userId, ':species' => $item['species_id'], ':now' => auth_sql_time($now)]);
        }
        $state = wildlife_state_read($pdo, $userId, $now, true);
        $pdo->commit();
        return $state;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
