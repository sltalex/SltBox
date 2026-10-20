-- Fruit recycling price is a server-controlled singleton. Apply after 006_aquarium.sql.
CREATE TABLE fruit_recycle_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    price_per_fruit INT UNSIGNED NOT NULL DEFAULT 1,
    updated_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_fruit_recycle_updated_by FOREIGN KEY (updated_by) REFERENCES auth_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO fruit_recycle_settings (id, price_per_fruit, updated_at)
VALUES (1, 1, CURRENT_TIMESTAMP);
