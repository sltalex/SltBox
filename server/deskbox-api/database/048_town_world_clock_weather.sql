-- Authoritative shared-town clock and weather state.  This migration is additive
-- so old clients can continue to read the world while the new state is rolled out.
CREATE TABLE IF NOT EXISTS town_world_state (
    world_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    absolute_minute BIGINT UNSIGNED NOT NULL DEFAULT 0,
    calendar_day INT UNSIGNED NOT NULL DEFAULT 1,
    weather_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'clear',
    weather_started_at DATETIME NOT NULL,
    next_weather_change_at DATETIME NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (world_id),
    CONSTRAINT fk_town_world_state_world FOREIGN KEY (world_id)
        REFERENCES town_worlds (world_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Existing worlds must have an authoritative row before the worker can advance
-- the clock. This is idempotent and also initializes worlds added later.
INSERT INTO town_world_state
    (world_id, absolute_minute, calendar_day, weather_code, weather_started_at,
     next_weather_change_at, revision, updated_at)
SELECT w.world_id, 0, 1, 'clear', UTC_TIMESTAMP(), NULL, 1, UTC_TIMESTAMP()
FROM town_worlds w
LEFT JOIN town_world_state s ON s.world_id = w.world_id
WHERE s.world_id IS NULL;
