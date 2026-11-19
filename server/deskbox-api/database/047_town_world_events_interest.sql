-- Public town facts, per-account receipts, and server-defined perception points.
-- This migration is additive; existing NPC-private events remain in town_npc_events.

CREATE TABLE IF NOT EXISTS town_world_events (
    event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    world_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    target_npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    public_payload_json JSON NOT NULL,
    visibility VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'public',
    occurred_at DATETIME NOT NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (event_id),
    UNIQUE KEY ux_town_world_events_source (world_id, source_key),
    KEY ix_town_world_events_cursor (world_id, event_id),
    KEY ix_town_world_events_type (world_id, event_type, event_id),
    KEY ix_town_world_events_expiry (world_id, expires_at),
    CONSTRAINT fk_town_world_events_world FOREIGN KEY (world_id)
        REFERENCES town_worlds (world_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_world_events_actor FOREIGN KEY (actor_npc_id)
        REFERENCES town_npcs (npc_id) ON DELETE SET NULL,
    CONSTRAINT fk_town_world_events_target FOREIGN KEY (target_npc_id)
        REFERENCES town_npcs (npc_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_world_event_receipts (
    event_id BIGINT UNSIGNED NOT NULL,
    viewer_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unread',
    read_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (event_id, viewer_id),
    KEY ix_town_world_event_receipts_viewer (viewer_id, state, event_id),
    CONSTRAINT fk_town_world_event_receipts_event FOREIGN KEY (event_id)
        REFERENCES town_world_events (event_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_world_event_receipts_viewer FOREIGN KEY (viewer_id)
        REFERENCES auth_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_interest_points (
    interest_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    world_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    asset_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    object_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    tile_x INT NOT NULL,
    tile_y INT NOT NULL,
    radius SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    perception_channels_json JSON NOT NULL,
    activity_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    public_name VARCHAR(128) NOT NULL DEFAULT '',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (interest_id),
    KEY ix_town_interest_points_area (world_id, enabled, tile_x, tile_y),
    KEY ix_town_interest_points_object (world_id, object_id),
    CONSTRAINT fk_town_interest_points_world FOREIGN KEY (world_id)
        REFERENCES town_worlds (world_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_interest_points_object FOREIGN KEY (object_id)
        REFERENCES town_objects (object_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
