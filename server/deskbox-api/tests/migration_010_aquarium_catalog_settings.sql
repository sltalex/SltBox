CREATE TABLE aquarium_catalog_settings (
    id INTEGER PRIMARY KEY,
    card_pack_price INTEGER NOT NULL DEFAULT 200 CHECK (card_pack_price >= 0),
    updated_by TEXT NULL REFERENCES auth_users(id) ON DELETE SET NULL,
    updated_at TEXT NOT NULL
);
INSERT INTO aquarium_catalog_settings (id, card_pack_price, updated_at) VALUES (1, 200, '2026-01-01 00:00:00');
