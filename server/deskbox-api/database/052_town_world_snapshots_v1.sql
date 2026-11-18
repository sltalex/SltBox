-- Immutable recovery points for the shared town event stream and authoritative state.
CREATE TABLE IF NOT EXISTS town_world_snapshots (
    snapshot_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    world_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    world_version BIGINT UNSIGNED NOT NULL,
    change_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    npc_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    event_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    navigation_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    state_json MEDIUMTEXT NOT NULL,
    state_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (snapshot_id),
    UNIQUE KEY ux_town_world_snapshots_revision (world_id, world_version, change_cursor, npc_cursor, event_cursor),
    KEY ix_town_world_snapshots_latest (world_id, snapshot_id),
    CONSTRAINT fk_town_world_snapshots_world FOREIGN KEY (world_id)
        REFERENCES town_worlds (world_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
