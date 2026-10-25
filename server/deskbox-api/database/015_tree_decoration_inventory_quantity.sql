-- Allow placeable tree decorations to be owned and placed more than once.
ALTER TABLE tree_decoration_inventory
    ADD COLUMN quantity INT UNSIGNED NOT NULL DEFAULT 1 AFTER item_id;
