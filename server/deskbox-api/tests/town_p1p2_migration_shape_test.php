<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$events = file_get_contents($root . '/database/047_town_world_events_interest.sql');
$clock = file_get_contents($root . '/database/048_town_world_clock_weather.sql');
$social = file_get_contents($root . '/database/050_town_social_matters_v1.sql');
$snapshots = file_get_contents($root . '/database/052_town_world_snapshots_v1.sql');
if ($events === false || $clock === false || $social === false || $snapshots === false) throw new RuntimeException('migration_files_missing');

if (preg_match('/CREATE TABLE IF NOT EXISTS town_world_events.*?world_id VARCHAR\\(64\\)/is', $events)) {
    throw new RuntimeException('town_world_events_world_id_must_match_town_worlds');
}
if (preg_match('/CREATE TABLE IF NOT EXISTS town_interest_points.*?world_id VARCHAR\\(64\\)/is', $events)) {
    throw new RuntimeException('town_interest_points_world_id_must_match_town_worlds');
}
if (!preg_match('/CREATE TABLE IF NOT EXISTS town_world_events.*?world_id VARCHAR\\(32\\)/is', $events)
    || !preg_match('/CREATE TABLE IF NOT EXISTS town_interest_points.*?world_id VARCHAR\\(32\\)/is', $events)
    || !preg_match('/CREATE TABLE IF NOT EXISTS town_world_state.*?world_id VARCHAR\\(32\\)/is', $clock)) {
    throw new RuntimeException('p1p2_world_id_shape_missing');
}
if (!preg_match('/CREATE TABLE IF NOT EXISTS town_social_matters.*?world_id VARCHAR\\(32\\)/is', $social)
    || !preg_match('/CREATE TABLE IF NOT EXISTS town_social_responses.*?matter_id CHAR\\(32\\)/is', $social)
    || !preg_match('/CREATE TABLE IF NOT EXISTS town_social_commitments.*?owner_npc_id CHAR\\(32\\)/is', $social)) {
    throw new RuntimeException('p2_social_migration_shape_missing');
}
if (!preg_match('/CREATE TABLE IF NOT EXISTS town_world_snapshots.*?world_id VARCHAR\\(32\\)/is', $snapshots)
    || !preg_match('/CREATE TABLE IF NOT EXISTS town_world_snapshots.*?state_sha256 CHAR\\(64\\)/is', $snapshots)
    || !preg_match('/CREATE TABLE IF NOT EXISTS town_world_snapshots.*?event_cursor BIGINT/is', $snapshots)) {
    throw new RuntimeException('p2_snapshot_migration_shape_missing');
}
echo "town_p1p2_migration_shape_test: 9 checks ok\n";
