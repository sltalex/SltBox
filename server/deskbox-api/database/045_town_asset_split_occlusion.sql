-- Optional source-pixel split line for a single building image.
-- The upper crop is rendered behind characters; the lower crop is rendered
-- in the foreground band. NULL keeps the existing automatic ordering.
SET @town_asset_split_y_added := (
    SELECT COUNT(*) = 0 FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='town_asset_items' AND column_name='split_y');
SET @town_asset_split_y_sql := IF(
    @town_asset_split_y_added,
    'ALTER TABLE town_asset_items ADD COLUMN split_y SMALLINT NULL DEFAULT NULL',
    'SELECT 1');
PREPARE town_asset_split_y_stmt FROM @town_asset_split_y_sql;
EXECUTE town_asset_split_y_stmt;
DEALLOCATE PREPARE town_asset_split_y_stmt;
