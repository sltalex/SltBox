-- Published full-sheet Stardew town assets.  These records are independent
-- from the tree decoration catalog and never consume tree inventory.
CREATE TABLE IF NOT EXISTS town_asset_sheets (
    sheet_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_path VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mime_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    image_width INT UNSIGNED NOT NULL,
    image_height INT UNSIGNED NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
    uploaded_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (sheet_id),
    UNIQUE KEY uq_town_asset_sheet_hash (sha256),
    KEY ix_town_asset_sheet_status (status, updated_at),
    CONSTRAINT fk_town_asset_sheet_user FOREIGN KEY (uploaded_by)
        REFERENCES auth_users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_asset_items (
    asset_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sheet_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(120) NOT NULL,
    category VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subcategory VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    tags_json TEXT NOT NULL,
    x INT UNSIGNED NOT NULL,
    y INT UNSIGNED NOT NULL,
    region_width INT UNSIGNED NOT NULL,
    region_height INT UNSIGNED NOT NULL,
    display_width INT UNSIGNED NOT NULL,
    display_height INT UNSIGNED NOT NULL,
    anchor_json VARCHAR(256) NOT NULL,
    footprint_json VARCHAR(256) NOT NULL,
    collision_json VARCHAR(256) NOT NULL,
    frames_json TEXT NOT NULL,
    manual_locked TINYINT(1) NOT NULL DEFAULT 1,
    updated_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (asset_id),
    KEY ix_town_asset_item_sheet (sheet_id, updated_at),
    CONSTRAINT fk_town_asset_item_sheet FOREIGN KEY (sheet_id)
        REFERENCES town_asset_sheets (sheet_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_asset_item_user FOREIGN KEY (updated_by)
        REFERENCES auth_users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_asset_releases (
    release_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    release_version INT UNSIGNED NOT NULL,
    config_json MEDIUMTEXT NOT NULL,
    config_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    published_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    published_at DATETIME NOT NULL,
    PRIMARY KEY (release_id),
    UNIQUE KEY uq_town_asset_release_version (release_version),
    CONSTRAINT fk_town_asset_release_user FOREIGN KEY (published_by)
        REFERENCES auth_users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_asset_active_release (
    singleton_id TINYINT UNSIGNED NOT NULL,
    release_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (singleton_id),
    CONSTRAINT fk_town_asset_active_release FOREIGN KEY (release_id)
        REFERENCES town_asset_releases (release_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- MySQL does not apply migrations transactionally. Make the compatibility
-- column safe to replay after a partially completed deployment.
SET @town_asset_source_sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'town_objects'
              AND column_name = 'asset_source'
        ),
        'SELECT 1',
        'ALTER TABLE town_objects ADD COLUMN asset_source VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''legacy'''
    )
);
PREPARE town_asset_source_stmt FROM @town_asset_source_sql;
EXECUTE town_asset_source_stmt;
DEALLOCATE PREPARE town_asset_source_stmt;
