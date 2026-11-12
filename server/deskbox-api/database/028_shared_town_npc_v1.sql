-- Shared-town NPC Agent and dynamic navigation v1.
-- The migration is intentionally additive so existing town objects and
-- players remain valid while the worker is introduced.
-- MySQL 8.0 does not accept IF NOT EXISTS on ADD COLUMN. Use metadata
-- guarded prepared statements so the migration can be safely replayed.
SET @town_nav_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_worlds' AND column_name='navigation_version') = 0,
    'ALTER TABLE town_worlds ADD COLUMN navigation_version INT UNSIGNED NOT NULL DEFAULT 1',
    'SELECT 1');
PREPARE town_nav_stmt FROM @town_nav_sql; EXECUTE town_nav_stmt; DEALLOCATE PREPARE town_nav_stmt;

SET @town_asset_nav_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_asset_items' AND column_name='navigation_json') = 0,
    'ALTER TABLE town_asset_items ADD COLUMN navigation_json JSON NULL',
    'SELECT 1');
PREPARE town_asset_nav_stmt FROM @town_asset_nav_sql; EXECUTE town_asset_nav_stmt; DEALLOCATE PREPARE town_asset_nav_stmt;

SET @town_asset_nav_version_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_asset_items' AND column_name='navigation_version') = 0,
    'ALTER TABLE town_asset_items ADD COLUMN navigation_version INT UNSIGNED NOT NULL DEFAULT 1',
    'SELECT 1');
PREPARE town_asset_nav_version_stmt FROM @town_asset_nav_version_sql; EXECUTE town_asset_nav_version_stmt; DEALLOCATE PREPARE town_asset_nav_version_stmt;

CREATE TABLE IF NOT EXISTS town_npcs (
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    world_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(80) NOT NULL,
    avatar_asset_id VARCHAR(128) NOT NULL DEFAULT '',
    appearance_json JSON NOT NULL,
    occupation VARCHAR(32) NOT NULL DEFAULT 'farmer',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    agent_paused TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (npc_id), KEY ix_town_npcs_world (world_id, enabled),
    CONSTRAINT fk_town_npcs_world FOREIGN KEY (world_id) REFERENCES town_worlds(world_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_npc_profiles (
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    personality_json JSON NOT NULL,
    family_background TEXT NOT NULL,
    short_term_goal VARCHAR(255) NOT NULL DEFAULT '',
    long_term_goal VARCHAR(255) NOT NULL DEFAULT '',
    speech_style VARCHAR(255) NOT NULL DEFAULT '',
    values_json JSON NOT NULL,
    fears_json JSON NOT NULL,
    PRIMARY KEY (npc_id),
    CONSTRAINT fk_town_npc_profiles_npc FOREIGN KEY (npc_id) REFERENCES town_npcs(npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_npc_schedules (
    schedule_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL DEFAULT 0,
    start_minute SMALLINT UNSIGNED NOT NULL,
    end_minute SMALLINT UNSIGNED NOT NULL,
    action VARCHAR(16) NOT NULL,
    target_id VARCHAR(128) NOT NULL DEFAULT '',
    job VARCHAR(64) NOT NULL DEFAULT '',
    priority TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (schedule_id), KEY ix_town_npc_schedules_npc (npc_id, weekday, start_minute),
    CONSTRAINT fk_town_npc_schedules_npc FOREIGN KEY (npc_id) REFERENCES town_npcs(npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_npc_relationships (
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    other_npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    affinity SMALLINT NOT NULL DEFAULT 0,
    relationship_json JSON NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (npc_id, other_npc_id),
    CONSTRAINT fk_town_npc_relationships_npc FOREIGN KEY (npc_id) REFERENCES town_npcs(npc_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_npc_relationships_other FOREIGN KEY (other_npc_id) REFERENCES town_npcs(npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_npc_memories (
    memory_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    memory_type VARCHAR(32) NOT NULL,
    content TEXT NOT NULL,
    importance TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NULL,
    PRIMARY KEY (memory_id), KEY ix_town_npc_memories_npc (npc_id, importance, created_at),
    CONSTRAINT fk_town_npc_memories_npc FOREIGN KEY (npc_id) REFERENCES town_npcs(npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_npc_runtime_states (
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tile_x INT NOT NULL DEFAULT 0,
    tile_y INT NOT NULL DEFAULT 0,
    state VARCHAR(16) NOT NULL DEFAULT 'rest',
    current_task_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    dialogue VARCHAR(500) NOT NULL DEFAULT '',
    capabilities_json JSON NOT NULL,
    state_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (npc_id),
    CONSTRAINT fk_town_npc_runtime_npc FOREIGN KEY (npc_id) REFERENCES town_npcs(npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_npc_tasks (
    task_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(16) NOT NULL,
    target_id VARCHAR(128) NOT NULL DEFAULT '',
    payload_json JSON NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'queued',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    scheduled_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    failure_reason VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (task_id), KEY ix_town_npc_tasks_npc (npc_id, status, scheduled_at),
    CONSTRAINT fk_town_npc_tasks_npc FOREIGN KEY (npc_id) REFERENCES town_npcs(npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_npc_events (
    event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    payload_json JSON NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (event_id), KEY ix_town_npc_events_cursor (event_id, npc_id),
    CONSTRAINT fk_town_npc_events_npc FOREIGN KEY (npc_id) REFERENCES town_npcs(npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_agent_runs (
    run_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    model_provider_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    status VARCHAR(16) NOT NULL,
    prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    error_code VARCHAR(64) NOT NULL DEFAULT '',
    error_message VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    PRIMARY KEY (run_id), KEY ix_town_agent_runs_created (created_at),
    CONSTRAINT fk_town_agent_runs_npc FOREIGN KEY (npc_id) REFERENCES town_npcs(npc_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_model_providers (
    provider_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(80) NOT NULL,
    base_url VARCHAR(255) NOT NULL,
    model_name VARCHAR(128) NOT NULL,
    api_key_ciphertext TEXT NOT NULL,
    temperature DECIMAL(3,2) NOT NULL DEFAULT 0.40,
    timeout_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    monthly_quota INT UNSIGNED NOT NULL DEFAULT 0,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (provider_id), UNIQUE KEY ux_town_model_providers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_agent_leases (
    lease_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    holder_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    lease_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (lease_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
