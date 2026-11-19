-- Allow a split building to declare more than one horizontal occlusion line.
-- Existing split_y values remain valid and are mirrored into split_lines_json.
SET @town_asset_split_lines_added := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'town_asset_items' AND column_name = 'split_lines_json'
);
SET @town_asset_split_lines_sql := IF(
    @town_asset_split_lines_added,
    'SELECT 1',
    'ALTER TABLE town_asset_items ADD COLUMN split_lines_json JSON NULL DEFAULT NULL'
);
PREPARE town_asset_split_lines_stmt FROM @town_asset_split_lines_sql;
EXECUTE town_asset_split_lines_stmt;
DEALLOCATE PREPARE town_asset_split_lines_stmt;

UPDATE town_asset_items
SET split_lines_json = JSON_ARRAY(split_y)
WHERE split_lines_json IS NULL AND split_y IS NOT NULL;
