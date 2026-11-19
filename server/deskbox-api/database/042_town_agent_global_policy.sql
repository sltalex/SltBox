-- Shared-town global Agent frequency policy.
-- Global intervals are lower bounds (no NPC may run more frequently); the
-- global hourly limit is an upper bound across every NPC. Replaying this file
-- is safe on an existing installation.

CREATE TABLE IF NOT EXISTS town_agent_global_policies (
    world_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    decision_interval_seconds INT UNSIGNED NOT NULL DEFAULT 60,
    observation_interval_seconds INT UNSIGNED NOT NULL DEFAULT 300,
    dialogue_cooldown_seconds INT UNSIGNED NOT NULL DEFAULT 60,
    max_decisions_per_hour INT UNSIGNED NOT NULL DEFAULT 60,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (world_id),
    CONSTRAINT fk_town_agent_global_policy_world FOREIGN KEY (world_id)
        REFERENCES town_worlds (world_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

SET @global_enabled_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_agent_global_policies' AND column_name='enabled') = 0,
    'ALTER TABLE town_agent_global_policies ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER world_id',
    'SELECT 1');
PREPARE global_enabled_stmt FROM @global_enabled_sql; EXECUTE global_enabled_stmt; DEALLOCATE PREPARE global_enabled_stmt;

INSERT IGNORE INTO town_agent_global_policies
    (world_id, enabled, decision_interval_seconds, observation_interval_seconds,
     dialogue_cooldown_seconds, max_decisions_per_hour, updated_at)
VALUES ('main', 0, 60, 300, 60, 60, UTC_TIMESTAMP());
