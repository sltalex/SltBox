-- Bind every social commitment to the authoritative NPC task that executes it.
-- The metadata guards make this migration safe to replay during rolling deploys.
SET @town_social_task_id_added := (
    SELECT COUNT(*) = 0
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'town_social_commitments'
      AND column_name = 'task_id'
);
SET @town_social_task_id_sql := IF(
    @town_social_task_id_added,
    'ALTER TABLE town_social_commitments ADD COLUMN task_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER action_id',
    'SELECT 1'
);
PREPARE town_social_task_id_stmt FROM @town_social_task_id_sql;
EXECUTE town_social_task_id_stmt;
DEALLOCATE PREPARE town_social_task_id_stmt;

SET @town_social_task_index_added := (
    SELECT COUNT(*) = 0
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'town_social_commitments'
      AND index_name = 'ix_town_social_commitments_task'
);
SET @town_social_task_index_sql := IF(
    @town_social_task_index_added,
    'ALTER TABLE town_social_commitments ADD KEY ix_town_social_commitments_task (task_id)',
    'SELECT 1'
);
PREPARE town_social_task_index_stmt FROM @town_social_task_index_sql;
EXECUTE town_social_task_index_stmt;
DEALLOCATE PREPARE town_social_task_index_stmt;
