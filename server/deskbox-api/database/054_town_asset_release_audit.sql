-- Record the navigation context used when an asset release was published.
-- Safe to apply more than once on rolling deployments.
CREATE TABLE IF NOT EXISTS town_asset_release_audits (
    audit_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    release_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    release_version INT UNSIGNED NOT NULL,
    published_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    navigation_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    navigation_preview_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (audit_id),
    UNIQUE KEY ux_town_asset_release_audit_release (release_id),
    KEY ix_town_asset_release_audit_created (created_at),
    CONSTRAINT fk_town_asset_release_audit_release FOREIGN KEY (release_id)
        REFERENCES town_asset_releases (release_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
