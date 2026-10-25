-- Account-scoped mature-tree decoration collection and versioned scene.
CREATE TABLE tree_decoration_profiles (
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    shards BIGINT UNSIGNED NOT NULL DEFAULT 0,
    scene_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    scene_json MEDIUMTEXT NOT NULL,
    active_theme_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_tree_decoration_profile_user FOREIGN KEY (user_id) REFERENCES auth_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE tree_decoration_inventory (
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    item_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    acquired_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, item_id),
    CONSTRAINT fk_tree_decoration_inventory_user FOREIGN KEY (user_id) REFERENCES auth_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE tree_decoration_operations (
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    response_json MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, operation_id),
    KEY ix_tree_decoration_operations_created (created_at),
    CONSTRAINT fk_tree_decoration_operation_user FOREIGN KEY (user_id) REFERENCES auth_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
