-- Apply after 005_admin.sql. Each account has one wallet and one shared aquarium.
CREATE TABLE aquarium_profiles (
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    initialized TINYINT UNSIGNED NOT NULL DEFAULT 0,
    coins INT UNSIGNED NOT NULL DEFAULT 0,
    scene_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    scene_json TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_aquarium_profile_user FOREIGN KEY (user_id) REFERENCES auth_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE aquarium_inventory (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    item_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    KEY ix_aquarium_inventory_user (user_id, created_at, id),
    CONSTRAINT fk_aquarium_inventory_profile FOREIGN KEY (user_id) REFERENCES aquarium_profiles (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE aquarium_wallet_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    delta INT NOT NULL,
    balance INT UNSIGNED NOT NULL,
    reason VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    item_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY ux_aquarium_wallet_operation (user_id, operation_id),
    KEY ix_aquarium_wallet_user (user_id, id),
    CONSTRAINT fk_aquarium_wallet_profile FOREIGN KEY (user_id) REFERENCES aquarium_profiles (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE aquarium_operations (
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, operation_id),
    CONSTRAINT fk_aquarium_operation_profile FOREIGN KEY (user_id) REFERENCES aquarium_profiles (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
