-- Persist the authoritative navigation height alongside an NPC position.
-- The metadata guard keeps this additive migration replayable on MySQL 8.

SET @town_npc_runtime_height_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema=DATABASE()
          AND table_name='town_npc_runtime_states'
          AND column_name='height') = 0,
    'ALTER TABLE town_npc_runtime_states ADD COLUMN height SMALLINT NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE town_npc_runtime_height_stmt FROM @town_npc_runtime_height_sql;
EXECUTE town_npc_runtime_height_stmt;
DEALLOCATE PREPARE town_npc_runtime_height_stmt;
