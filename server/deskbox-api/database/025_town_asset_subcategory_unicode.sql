-- Existing installations used ASCII for a free-text field in the web editor.
-- Widen its character set without changing existing values or asset IDs.
-- Reapplying this migration keeps the same column definition.
ALTER TABLE town_asset_items
    MODIFY COLUMN subcategory VARCHAR(64)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '';
