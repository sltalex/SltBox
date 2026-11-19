-- Preserve action-specific deterministic schedule payloads.
-- The column is nullable so this migration is safe for existing rows and
-- rolling deployments; an empty object is used when no extra payload exists.

SET @schedule_payload_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema=DATABASE()
          AND table_name='town_npc_schedules'
          AND column_name='payload_json') = 0,
    'ALTER TABLE town_npc_schedules ADD COLUMN payload_json JSON NULL AFTER priority',
    'SELECT 1');
PREPARE schedule_payload_stmt FROM @schedule_payload_sql;
EXECUTE schedule_payload_stmt;
DEALLOCATE PREPARE schedule_payload_stmt;
