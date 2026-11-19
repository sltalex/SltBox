-- Automatic visual ordering for shared-town assets.
-- Existing records default to y_sort; the client still accepts manual as a
-- compatibility override for old placements that rely on the six-layer menu.
SET @town_asset_sort_mode_added := (
    SELECT COUNT(*) = 0 FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='town_asset_items' AND column_name='sort_mode');
SET @town_asset_sort_mode_sql := IF(
    @town_asset_sort_mode_added,
    'ALTER TABLE town_asset_items ADD COLUMN sort_mode VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''y_sort''',
    'SELECT 1');
PREPARE town_asset_sort_mode_stmt FROM @town_asset_sort_mode_sql;
EXECUTE town_asset_sort_mode_stmt;
DEALLOCATE PREPARE town_asset_sort_mode_stmt;

-- The new column defaults to y_sort for compatibility.  For the first run,
-- restore the semantic defaults for assets that are explicitly terrain-like;
-- a replay must not overwrite an administrator's later manual choice.
SET @town_asset_sort_mode_backfill_sql := IF(
    @town_asset_sort_mode_added,
    'UPDATE town_asset_items SET sort_mode=CASE WHEN category IN (''terrain'',''water'',''path'') THEN ''background'' ELSE ''y_sort'' END',
    'SELECT 1');
PREPARE town_asset_sort_mode_backfill_stmt FROM @town_asset_sort_mode_backfill_sql;
EXECUTE town_asset_sort_mode_backfill_stmt;
DEALLOCATE PREPARE town_asset_sort_mode_backfill_stmt;
