-- Optional, administrator-managed affordances for town assets.
-- Unknown or empty affordances remain a read-only observation and never
-- become an executable NPC action by themselves.
SET @town_asset_affordance_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema=DATABASE()
          AND table_name='town_asset_items'
          AND column_name='affordance_json') = 0,
    'ALTER TABLE town_asset_items ADD COLUMN affordance_json JSON NULL',
    'SELECT 1'
);
PREPARE town_asset_affordance_stmt FROM @town_asset_affordance_sql;
EXECUTE town_asset_affordance_stmt;
DEALLOCATE PREPARE town_asset_affordance_stmt;
