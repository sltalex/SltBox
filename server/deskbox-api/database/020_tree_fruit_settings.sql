-- Configurable global fruit-generation threshold and per-trigger batch range.
CREATE TABLE tree_fruit_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    energy_cost INT UNSIGNED NOT NULL DEFAULT 3000,
    min_batch SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    max_batch SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    updated_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_tree_fruit_settings_updated_by FOREIGN KEY (updated_by)
        REFERENCES auth_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO tree_fruit_settings (id, energy_cost, min_batch, max_batch, updated_at)
VALUES (1, 3000, 1, 5, CURRENT_TIMESTAMP);
