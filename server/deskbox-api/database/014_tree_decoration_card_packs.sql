ALTER TABLE aquarium_catalog_settings
    ADD COLUMN tree_card_pack_single_price INT UNSIGNED NOT NULL DEFAULT 200 AFTER card_pack_price,
    ADD COLUMN tree_card_pack_ten_price INT UNSIGNED NOT NULL DEFAULT 1800 AFTER tree_card_pack_single_price;

CREATE TABLE IF NOT EXISTS tree_decoration_card_packs (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    draw_count TINYINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_tree_decoration_card_packs_user (user_id, created_at),
    CONSTRAINT fk_tree_decoration_card_packs_user FOREIGN KEY (user_id)
        REFERENCES auth_users(id) ON DELETE CASCADE,
    CONSTRAINT chk_tree_decoration_card_packs_draw_count CHECK (draw_count IN (1, 10))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
