-- Shared town world v2.  This migration intentionally leaves the old demo
-- profile table in place until the operator has backed it up and cleared it.
CREATE TABLE IF NOT EXISTS town_worlds (
    world_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    world_version INT UNSIGNED NOT NULL DEFAULT 2,
    terrain_version INT UNSIGNED NOT NULL DEFAULT 2,
    map_width SMALLINT UNSIGNED NOT NULL DEFAULT 192,
    map_height SMALLINT UNSIGNED NOT NULL DEFAULT 192,
    tile_size SMALLINT UNSIGNED NOT NULL DEFAULT 16,
    chunk_size SMALLINT UNSIGNED NOT NULL DEFAULT 24,
    change_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (world_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_graylist (
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    enabled_at DATETIME NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_town_graylist_user FOREIGN KEY (user_id)
        REFERENCES auth_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_objects (
    object_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    world_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    asset_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    chunk_x SMALLINT NOT NULL,
    chunk_y SMALLINT NOT NULL,
    tile_x SMALLINT NOT NULL,
    tile_y SMALLINT NOT NULL,
    footprint_json VARCHAR(256) NOT NULL,
    layer TINYINT NOT NULL DEFAULT 0,
    object_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    PRIMARY KEY (object_id),
    KEY ix_town_objects_chunk (world_id, chunk_x, chunk_y, deleted_at),
    KEY ix_town_objects_owner (owner_user_id, deleted_at),
    CONSTRAINT fk_town_object_world FOREIGN KEY (world_id)
        REFERENCES town_worlds (world_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_object_user FOREIGN KEY (owner_user_id)
        REFERENCES auth_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_operations (
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    response_json MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, operation_id),
    CONSTRAINT fk_town_operation_user FOREIGN KEY (user_id)
        REFERENCES auth_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_change_log (
    cursor BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    world_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    chunk_x SMALLINT NOT NULL,
    chunk_y SMALLINT NOT NULL,
    action VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    object_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_json MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (cursor),
    KEY ix_town_changes_world (world_id, cursor),
    CONSTRAINT fk_town_change_world FOREIGN KEY (world_id)
        REFERENCES town_worlds (world_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_presence (
    world_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_name VARCHAR(64) NOT NULL,
    chunk_x SMALLINT NOT NULL,
    chunk_y SMALLINT NOT NULL,
    tile_x SMALLINT NOT NULL,
    tile_y SMALLINT NOT NULL,
    last_seen_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (world_id, user_id),
    KEY ix_town_presence_expiry (world_id, expires_at),
    CONSTRAINT fk_town_presence_world FOREIGN KEY (world_id)
        REFERENCES town_worlds (world_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_presence_user FOREIGN KEY (user_id)
        REFERENCES auth_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_decoration_reservations (
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    item_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reserved_quantity BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, item_id),
    CONSTRAINT fk_town_reservation_user FOREIGN KEY (user_id)
        REFERENCES auth_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO town_worlds
    (world_id, world_version, terrain_version, map_width, map_height, tile_size, chunk_size, created_at, updated_at)
VALUES ('main', 2, 2, 192, 192, 16, 24, UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE world_id = VALUES(world_id);

-- The old account-scoped demo layout is not migrated into the global town.
-- Keep the table for a rollback window, but remove all demo data explicitly.
DELETE FROM tree_town_prototype_profiles;

-- SQLite test/dev databases use the same migration file after replacing the
-- MySQL-only clauses in the test harness; keep the data model explicit here.
