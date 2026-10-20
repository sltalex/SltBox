CREATE TABLE fruit_recycle_settings (
    id INTEGER PRIMARY KEY,
    price_per_fruit INTEGER NOT NULL DEFAULT 1 CHECK (price_per_fruit >= 0),
    updated_by TEXT NULL REFERENCES auth_users(id) ON DELETE SET NULL,
    updated_at TEXT NOT NULL
);
INSERT INTO fruit_recycle_settings (id, price_per_fruit, updated_at) VALUES (1, 1, '2026-01-01 00:00:00');
