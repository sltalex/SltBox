<?php
declare(strict_types=1);
define('DESKBOX_API_ENTRY', true);
require dirname(__DIR__) . '/api/_app/bootstrap.php';
require dirname(__DIR__) . '/api/_app/town_world_clock.php';

$checks = 0;
function clock_expect(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}

$clockRoute = file_get_contents(dirname(__DIR__) . '/api/v2/town/clock.php');
clock_expect(is_string($clockRoute) && str_contains($clockRoute, 'town_agent_require_service()'),
    'The clock read route must authenticate the Worker service token.');
clock_expect(is_string($clockRoute) && str_contains($clockRoute, "HTTP_X_DESKBOX_AGENT_TOKEN") && str_contains($clockRoute, '} else {'),
    'The clock read route must keep the Worker and browser authentication paths separate.');
$clockAdvanceRoute = file_get_contents(dirname(__DIR__) . '/api/v2/town/clock-advance.php');
clock_expect(is_string($clockAdvanceRoute) && substr_count($clockAdvanceRoute, 'dirname(__DIR__, 2)') >= 3
    && !str_contains($clockAdvanceRoute, 'dirname(__DIR__, 3)'),
    'The clock advance route must resolve the API _app directory from its direct town route.');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE town_world_state (world_id TEXT PRIMARY KEY,absolute_minute INTEGER,calendar_day INTEGER,weather_code TEXT,weather_started_at TEXT,next_weather_change_at TEXT,revision INTEGER,updated_at TEXT)');
$pdo->exec('CREATE TABLE town_world_events (event_id INTEGER PRIMARY KEY AUTOINCREMENT,world_id TEXT,source_key TEXT,event_type TEXT,actor_npc_id TEXT,target_npc_id TEXT,public_payload_json TEXT,visibility TEXT,occurred_at TEXT,expires_at TEXT,created_at TEXT,UNIQUE(world_id,source_key))');
$pdo->exec("INSERT INTO town_world_state VALUES ('main',1430,1,'clear','2026-11-15 12:00:00',NULL,1,'2026-11-15 12:00:00')");
$now = new DateTimeImmutable('2026-11-15 12:00:00', new DateTimeZone('UTC'));
$initial = town_world_clock_read($pdo, $now);
clock_expect($initial['time_label'] === '23:50' && $initial['revision'] === 1, 'The clock should expose the authoritative time and revision.');
clock_expect(($initial['time_zone'] ?? '') === 'Asia/Shanghai', 'The shared town clock must declare Beijing time.');
$advanced = town_world_clock_advance($pdo, 1, 15, $now);
clock_expect($advanced['calendar_day'] === 2 && $advanced['minute_of_day'] === 5 && $advanced['revision'] === 2, 'Advancing across midnight should roll the calendar day.');
clock_expect($advanced['event']['event_type'] === 'time_advanced', 'Clock advancement should publish a public event.');
try { town_world_clock_advance($pdo, 1, 15, $now); clock_expect(false, 'A stale clock revision must be rejected.'); }
catch (ApiError $error) { clock_expect($error->apiCode === 'world_revision_conflict' && $error->httpStatus === 409, 'A stale clock revision should return a conflict.'); }
$weather = town_world_clock_set_weather($pdo, 'rain', 60, 2, $now);
clock_expect($weather['weather_code'] === 'rain' && $weather['next_weather_change_at'] === '2026-11-15 13:00:00' && $weather['revision'] === 3, 'Weather changes should be persisted with a duration and revision.');
clock_expect($weather['event']['event_type'] === 'weather_changed', 'Weather changes should publish a public event.');
$expired = town_world_clock_advance($pdo, 3, 1, new DateTimeImmutable('2026-11-15 13:01:00', new DateTimeZone('UTC')));
clock_expect($expired['weather_code'] === 'clear' && $expired['next_weather_change_at'] === null && $expired['revision'] === 4, 'Temporary weather should return to clear after its expiry.');
clock_expect(($expired['weather_event']['event_type'] ?? '') === 'weather_changed' && ($expired['weather_event']['payload']['reason'] ?? '') === 'duration_expired', 'Weather expiry should publish an explicit public event.');
try { town_world_clock_set_weather($pdo, 'cloudy', 10, 3, $now); clock_expect(false, 'A stale weather revision must be rejected.'); }
catch (ApiError $error) { clock_expect($error->apiCode === 'world_revision_conflict', 'A stale weather revision should be rejected after clock expiry.'); }
try { town_world_clock_set_weather($pdo, 'volcanic', 10, 4, $now); clock_expect(false, 'Unknown weather must be rejected.'); }
catch (ApiError $error) { clock_expect($error->apiCode === 'invalid_weather', 'Unknown weather should use the stable error code.'); }
echo "town_world_clock_test: {$checks} checks ok\n";
