-- Isolated demo-town layouts; no tree progress, inventory or wallet fields are changed.
CREATE TABLE IF NOT EXISTS tree_town_prototype_profiles (
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    scene_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    layout_json MEDIUMTEXT NOT NULL,
    last_operation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    last_request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_town_prototype_profile_user FOREIGN KEY (user_id)
        REFERENCES auth_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
