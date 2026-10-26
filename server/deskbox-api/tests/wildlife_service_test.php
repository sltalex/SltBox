<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/wildlife.php';

function wildlife_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
$now = new DateTimeImmutable('2026-04-10T04:00:00Z');
$clock = static function() use (&$now): DateTimeImmutable { return $now; };
$random = static fn(int $minimum, int $maximum): int => $minimum;
$member = auth_register($pdo, 'wildlife_tester', 'secret1', 'test-device', '127.0.0.1', $clock);
$user = $member['user']['id'];
$catalog = wildlife_catalog(null, 2);
wildlife_check(count($catalog) === 184 && count(array_unique(array_column($catalog, 'id'))) === 184,
    'The v2 wildlife catalogue contains 184 unique species.');
foreach (['飞鸟' => 60, '小动物' => 44, '蝴蝶' => 80] as $category => $expected)
    wildlife_check(count(array_filter($catalog, static fn(array $item): bool => $item['category'] === $category)) === $expected,
        "The {$category} category has its reviewed species count.");
wildlife_check(count(wildlife_catalog(null, 1)) === 107 &&
    !in_array('monarch', array_column(wildlife_catalog(null, 1), 'id'), true),
    'The compatibility catalogue only contains IDs understood by the old client.');
foreach ($catalog as $item)
    wildlife_check(in_array($item['placement'], ['tree','grass','sky'], true) && $item['seasons'] !== [] &&
        $item['periods'] !== [] && $item['weathers'] !== [] && $item['preferred_weathers'] !== [] &&
        is_string($item['motion']) && trim($item['motion']) !== '', 'Every species has reviewed ecological metadata.');
wildlife_check(wildlife_eligible('winter', 'night', 'thunderstorm', null, 2) === [],
    'Strict ecology permits conditions with no eligible animal.');
wildlife_check(wildlife_season(new DateTimeImmutable('2026-02-03T15:59:00Z'), 480) === 'winter',
    'The local date before Lichun remains winter.');
wildlife_check(wildlife_season(new DateTimeImmutable('2026-02-03T16:00:00Z'), 480) === 'spring',
    'Lichun starts spring at local midnight.');
wildlife_check(wildlife_season(new DateTimeImmutable('2026-05-05T16:00:00Z'), 480) === 'summer',
    'Lixia starts summer.');
wildlife_check(wildlife_season(new DateTimeImmutable('2026-08-07T16:00:00Z'), 480) === 'autumn',
    'Liqiu starts autumn.');
wildlife_check(wildlife_season(new DateTimeImmutable('2026-11-07T16:00:00Z'), 480) === 'winter',
    'Lidong starts winter.');
wildlife_check(wildlife_period(new DateTimeImmutable('2026-04-10T21:00:00Z'), 480) === 'morning' &&
    wildlife_period(new DateTimeImmutable('2026-04-10T12:00:00Z'), 480) === 'night',
    'Local hours select the morning and night periods.');
wildlife_check(wildlife_season(new DateTimeImmutable('2027-01-01T00:00:00Z'), 480) === 'winter' &&
    wildlife_season(new DateTimeImmutable('2027-03-01T00:00:00Z'), 480) === 'spring',
    'The winter-to-spring boundary works across a calendar year.');
wildlife_check(wildlife_weather(61) === 'rain' && wildlife_weather(71) === 'snow' &&
    wildlife_weather(999) === null, 'Weather codes are classified explicitly.');
$summerRain = wildlife_eligible('summer', 'day', 'rain', null, 2);
$otter = current(array_values(array_filter($summerRain, static fn(array $entry): bool => $entry['item']['id'] === 'otter')));
wildlife_check(is_array($otter) && $otter['weight'] === 4, 'Rain favors an aquatic animal.');

$weather = static fn(DateTimeImmutable $time): array => [
    'catalog_version' => 2,
    'weather_code' => 0,
    'weather_fetched_at_utc' => $time->format(DATE_ATOM),
    'utc_offset_minutes' => 480,
];
$initial = wildlife_offer($pdo, $user, $weather($now), $clock, $random);
wildlife_check($initial['encounter'] === null && $initial['next_due_at_utc'] !== null,
    'The first visit schedules an encounter without making one immediately.');
$anotherMember = auth_register($pdo, 'wildlife_other', 'secret1', 'other-device', '127.0.0.1', $clock);
$otherUser = $anotherMember['user']['id'];
wildlife_check(wildlife_state_read($pdo, $otherUser, $now, false, 2)['inventory'][0]['quantity'] === 0,
    'Another account cannot see this collection.');
$maximumRandom = static fn(int $minimum, int $maximum): int => $maximum;
$slow = wildlife_offer($pdo, $otherUser, $weather($now), $clock, $maximumRandom);
wildlife_check((new DateTimeImmutable($slow['next_due_at_utc']))->getTimestamp() - $now->getTimestamp() === 1800,
    'The maximum scheduled interval is 30 minutes.');
$stormNow = new DateTimeImmutable('2026-12-10T14:00:00Z');
$stormClock = static function() use (&$stormNow): DateTimeImmutable { return $stormNow; };
$stormUser = auth_register($pdo, 'wildlife_storm', 'secret1', 'storm-device', '127.0.0.1', $stormClock)['user']['id'];
$stormWeather = array_replace($weather($stormNow), ['weather_code' => 95]);
wildlife_offer($pdo, $stormUser, $stormWeather, $stormClock, $random);
$stormNow = $stormNow->modify('+15 minutes');
$stormWeather = array_replace($weather($stormNow), ['weather_code' => 95]);
$stormState = wildlife_offer($pdo, $stormUser, $stormWeather, $stormClock, $random);
wildlife_check($stormState['encounter'] === null && $stormState['next_due_at_utc'] !== null,
    'No eligible animal schedules another check instead of failing the request.');
$now = $now->modify('+14 minutes');
wildlife_check(wildlife_offer($pdo, $user, $weather($now), $clock, $random)['encounter'] === null,
    'An encounter is not offered before the cooldown.');
$now = $now->modify('+1 minute');
$offered = wildlife_offer($pdo, $user, $weather($now), $clock, $random);
$encounter = $offered['encounter'];
wildlife_check(is_array($encounter) && in_array($encounter['species_id'], array_column($catalog, 'id'), true),
    'A spring daytime clear-weather encounter is chosen from eligible species.');
wildlife_check(wildlife_offer($pdo, $user, $weather($now), $clock, $random)['encounter']['id'] === $encounter['id'],
    'Refreshing the encounter does not reroll the animal.');
try {
    wildlife_capture($pdo, $otherUser, ['encounter_id' => $encounter['id'], 'operation_id' => 'foreign-capture-0001'], $clock);
    throw new RuntimeException('Another account captured the animal.');
} catch (ApiError $error) {
    wildlife_check($error->apiCode === 'wildlife_encounter_expired', 'Encounters are account-owned.');
}
$capture = ['encounter_id' => $encounter['id'], 'operation_id' => 'wildlife-capture-test-0001'];
$result = wildlife_capture($pdo, $user, $capture, $clock);
wildlife_check($result['species_id'] === $encounter['species_id'] && $result['quantity'] === 1 && !$result['duplicate'],
    'Capture credits exactly one animal.');
wildlife_check(wildlife_capture($pdo, $user, $capture, $clock)['duplicate'],
    'Retrying the same capture is idempotent.');
try {
    wildlife_capture($pdo, $user, ['encounter_id' => $encounter['id'], 'operation_id' => 'wildlife-capture-test-0002'], $clock);
    throw new RuntimeException('The encounter was captured twice.');
} catch (ApiError $error) {
    wildlife_check($error->apiCode === 'wildlife_encounter_expired', 'Another operation cannot capture the same encounter.');
}
$state = wildlife_state_read($pdo, $user, $now, false, 2);
$capturedItem = array_values(array_filter($state['inventory'], static fn(array $item): bool =>
    $item['species_id'] === $encounter['species_id']))[0];
wildlife_check($capturedItem['quantity'] === 1 && $capturedItem['discovered'],
    'The animal is in the backpack and permanently discovered.');

$now = $now->modify('+1 hour');
$resumed = wildlife_offer($pdo, $user, $weather($now) + ['resume_after_hidden' => true], $clock, $random);
wildlife_check($resumed['encounter'] === null &&
    (new DateTimeImmutable($resumed['next_due_at_utc']))->getTimestamp() - $now->getTimestamp() === 900,
    'Returning from a hidden widget starts a new 15-minute wait without backfilling.');
$now = $now->modify('+15 minutes');
$late = wildlife_offer($pdo, $user, $weather($now), $clock, $random)['encounter'];
wildlife_check(is_array($late) && $late['id'] !== $encounter['id'],
    'Returning after a long absence creates only one current encounter.');
$now = $now->modify('+10 minutes');
try {
    wildlife_capture($pdo, $user, ['encounter_id' => $late['id'], 'operation_id' => 'wildlife-capture-expired'], $clock);
    throw new RuntimeException('An expired animal was captured.');
} catch (ApiError $error) {
    wildlife_check($error->apiCode === 'wildlife_encounter_expired', 'The animal leaves after ten minutes.');
}
try {
    wildlife_offer($pdo, $user, $weather($now->modify('-5 hours')), $clock, $random);
    throw new RuntimeException('Stale weather generated an encounter.');
} catch (ApiError $error) {
    wildlife_check($error->apiCode === 'stale_wildlife_weather', 'Stale weather pauses encounters.');
}

$httpRestoreBody = api_decode_body(json_encode(['owner_user_id' => $user, 'inventory' => [
    ['species_id' => 'blue-morpho', 'quantity' => 3, 'discovered' => true],
], 'catalog_version' => 2
], JSON_THROW_ON_ERROR));
$httpRestored = wildlife_restore($pdo, $user, $httpRestoreBody, $clock);
wildlife_check(array_column($httpRestored['inventory'], 'quantity', 'species_id')['blue-morpho'] === 3,
    'An HTTP-decoded wildlife backup restores its inventory.');

$restored = wildlife_restore($pdo, $user, ['catalog_version' => 2, 'owner_user_id' => $user, 'inventory' => [
    ['species_id' => 'blue-morpho', 'quantity' => 3, 'discovered' => true],
    ['species_id' => 'rabbit', 'quantity' => 0, 'discovered' => true],
]], $clock);
$quantities = array_column($restored['inventory'], 'quantity', 'species_id');
wildlife_check($quantities['blue-morpho'] === 3, 'Restore takes the larger backed-up count.');
wildlife_restore($pdo, $user, ['catalog_version' => 2, 'owner_user_id' => $user, 'inventory' => [
    ['species_id' => 'blue-morpho', 'quantity' => 1, 'discovered' => true],
]], $clock);
$state = wildlife_state_read($pdo, $user, $now, false, 2);
$quantities = array_column($state['inventory'], 'quantity', 'species_id');
$discoveries = array_column($state['inventory'], 'discovered', 'species_id');
wildlife_check($quantities['blue-morpho'] === 3 && $discoveries['rabbit'],
    'A repeated or older backup cannot reduce counts or erase discoveries.');
$legacyRestored = wildlife_restore($pdo, $user, ['catalog_version' => 1, 'owner_user_id' => $user, 'inventory' => [
    ['species_id' => 'blue-morpho', 'quantity' => 99, 'discovered' => true],
]], $clock);
$legacyQuantities = array_column($legacyRestored['inventory'], 'quantity', 'species_id');
$currentQuantities = array_column(wildlife_state_read($pdo, $user, $now, false, 2)['inventory'], 'quantity', 'species_id');
wildlife_check(count($legacyRestored['inventory']) === 107 && $legacyQuantities['blue-morpho'] === 3 &&
    $currentQuantities['blue-morpho'] === 3,
    'A v1 restore returns compatibility state without writing its historical quantity.');
$completeInventory = array_map(static fn(array $item): array => [
    'species_id' => $item['id'], 'quantity' => 0, 'discovered' => false,
], $catalog);
$completeRestoreJson = json_encode([
    'catalog_version' => 2, 'owner_user_id' => $user, 'inventory' => $completeInventory,
], JSON_THROW_ON_ERROR);
wildlife_check(strlen($completeRestoreJson) > 8192 && strlen($completeRestoreJson) < 32768,
    'A complete wildlife backup exceeds the generic request size limit.');
$completeRestoreBody = api_decode_body($completeRestoreJson, WILDLIFE_RESTORE_MAX_BYTES);
wildlife_check(count(wildlife_restore($pdo, $user, $completeRestoreBody, $clock)['inventory']) === 184,
    'A complete HTTP-decoded 184-species backup can be restored.');
try {
    wildlife_restore($pdo, $user, ['owner_user_id' => 'another-account', 'inventory' => []], $clock);
    throw new RuntimeException('Another account backup was accepted.');
} catch (ApiError $error) {
    wildlife_check($error->apiCode === 'invalid_wildlife_restore', 'Restore rejects another account owner.');
}
echo "wildlife_service_test: OK\n";
