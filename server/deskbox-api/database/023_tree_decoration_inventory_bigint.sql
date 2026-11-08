-- Shared-town reservations use the same inventory pool and may grow beyond
-- the old INT range.  Keep the existing rows and constraints intact.
ALTER TABLE tree_decoration_inventory
    MODIFY COLUMN quantity BIGINT UNSIGNED NOT NULL DEFAULT 1;
