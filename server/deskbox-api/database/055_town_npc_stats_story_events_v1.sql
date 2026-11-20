-- NPC individual values (1-100) and story events.
-- Safe to run repeatedly during rolling upgrades.
CREATE TABLE IF NOT EXISTS town_npc_stats (
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mood TINYINT UNSIGNED NOT NULL DEFAULT 60,
    health TINYINT UNSIGNED NOT NULL DEFAULT 60,
    stamina TINYINT UNSIGNED NOT NULL DEFAULT 60,
    labor TINYINT UNSIGNED NOT NULL DEFAULT 50,
    academics TINYINT UNSIGNED NOT NULL DEFAULT 50,
    culture TINYINT UNSIGNED NOT NULL DEFAULT 50,
    social TINYINT UNSIGNED NOT NULL DEFAULT 50,
    wealth TINYINT UNSIGNED NOT NULL DEFAULT 50,
    stress TINYINT UNSIGNED NOT NULL DEFAULT 40,
    reputation TINYINT UNSIGNED NOT NULL DEFAULT 50,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (npc_id),
    CONSTRAINT ck_town_npc_stats_range CHECK (mood BETWEEN 1 AND 100 AND health BETWEEN 1 AND 100 AND stamina BETWEEN 1 AND 100 AND labor BETWEEN 1 AND 100 AND academics BETWEEN 1 AND 100 AND culture BETWEEN 1 AND 100 AND social BETWEEN 1 AND 100 AND wealth BETWEEN 1 AND 100 AND stress BETWEEN 1 AND 100 AND reputation BETWEEN 1 AND 100),
    CONSTRAINT fk_town_npc_stats_npc FOREIGN KEY (npc_id) REFERENCES town_npcs(npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_npc_stat_changes (
    change_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    before_json JSON NOT NULL,
    delta_json JSON NOT NULL,
    after_json JSON NOT NULL,
    reason VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (change_id),
    KEY ix_town_npc_stat_changes_npc (npc_id, change_id),
    CONSTRAINT fk_town_npc_stat_changes_npc FOREIGN KEY (npc_id) REFERENCES town_npcs(npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_story_events (
    event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    world_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    template_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'scheduled',
    title VARCHAR(160) NOT NULL,
    summary VARCHAR(1000) NOT NULL,
    story_json JSON NOT NULL,
    random_seed BIGINT NOT NULL,
    generation_source VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'rule',
    model_run_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    starts_at DATETIME NOT NULL,
    expires_at DATETIME NULL,
    occurred_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (event_id),
    KEY ix_town_story_events_world (world_id, status, occurred_at),
    CONSTRAINT fk_town_story_events_world FOREIGN KEY (world_id) REFERENCES town_worlds(world_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_story_event_participants (
    event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    role VARCHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'invited',
    eligibility_json JSON NOT NULL,
    task_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    joined_at DATETIME NULL,
    completed_at DATETIME NULL,
    outcome_json JSON NOT NULL,
    PRIMARY KEY (event_id, npc_id),
    KEY ix_town_story_participants_npc (npc_id, status),
    CONSTRAINT fk_town_story_participants_event FOREIGN KEY (event_id) REFERENCES town_story_events(event_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_story_participants_npc FOREIGN KEY (npc_id) REFERENCES town_npcs(npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_story_event_effects (
    effect_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    stat_delta_json JSON NOT NULL,
    relationship_delta_json JSON NOT NULL,
    memory_json JSON NOT NULL,
    task_payload_json JSON NOT NULL,
    applied_at DATETIME NULL,
    PRIMARY KEY (effect_id),
    UNIQUE KEY ux_town_story_event_effect (event_id, npc_id),
    CONSTRAINT fk_town_story_effects_event FOREIGN KEY (event_id) REFERENCES town_story_events(event_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_story_effects_npc FOREIGN KEY (npc_id) REFERENCES town_npcs(npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_story_event_policies (
    world_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    daily_min_events TINYINT UNSIGNED NOT NULL DEFAULT 0,
    daily_max_events TINYINT UNSIGNED NOT NULL DEFAULT 2,
    condition_enabled TINYINT(1) NOT NULL DEFAULT 1,
    cooldown_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 240,
    max_participants TINYINT UNSIGNED NOT NULL DEFAULT 5,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (world_id),
    CONSTRAINT fk_town_story_event_policies_world FOREIGN KEY (world_id) REFERENCES town_worlds(world_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
