-- Face-to-face NPC conversation state. Safe to replay during rolling deploys.
SET @town_npc_facing_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_runtime_states' AND column_name='facing') = 0,
    'ALTER TABLE town_npc_runtime_states ADD COLUMN facing VARCHAR(8) NOT NULL DEFAULT ''down''',
    'SELECT 1');
PREPARE town_npc_facing_stmt FROM @town_npc_facing_sql;
EXECUTE town_npc_facing_stmt;
DEALLOCATE PREPARE town_npc_facing_stmt;
