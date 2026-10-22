-- Server-controlled aquarium catalogue settings. Apply after 009_dingtalk_favorites.sql.
-- The catalogue remains code-defined; this table stores only values that the
-- administrator is allowed to change without a client release.
CREATE TABLE aquarium_catalog_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    card_pack_price INT UNSIGNED NOT NULL DEFAULT 200,
    updated_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_aquarium_catalog_updated_by FOREIGN KEY (updated_by) REFERENCES auth_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO aquarium_catalog_settings (id, card_pack_price, updated_at)
VALUES (1, 200, CURRENT_TIMESTAMP);
