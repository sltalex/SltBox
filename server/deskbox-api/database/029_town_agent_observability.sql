-- Shared-town Agent observability: detailed NPC activity and token reports.
-- The metadata guards keep this additive migration replayable on MySQL 8.

SET @town_runtime_mood_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_runtime_states' AND column_name='mood_json') = 0,
    'ALTER TABLE town_npc_runtime_states ADD COLUMN mood_json JSON NOT NULL DEFAULT (\'{}\')',
    'SELECT 1');
PREPARE town_runtime_mood_stmt FROM @town_runtime_mood_sql; EXECUTE town_runtime_mood_stmt; DEALLOCATE PREPARE town_runtime_mood_stmt;

SET @agent_model_name_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_agent_runs' AND column_name='model_name') = 0,
    'ALTER TABLE town_agent_runs ADD COLUMN model_name VARCHAR(128) NOT NULL DEFAULT \'\'',
    'SELECT 1');
PREPARE agent_model_name_stmt FROM @agent_model_name_sql; EXECUTE agent_model_name_stmt; DEALLOCATE PREPARE agent_model_name_stmt;

SET @agent_total_tokens_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_agent_runs' AND column_name='total_tokens') = 0,
    'ALTER TABLE town_agent_runs ADD COLUMN total_tokens INT UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE agent_total_tokens_stmt FROM @agent_total_tokens_sql; EXECUTE agent_total_tokens_stmt; DEALLOCATE PREPARE agent_total_tokens_stmt;

SET @agent_latency_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_agent_runs' AND column_name='latency_ms') = 0,
    'ALTER TABLE town_agent_runs ADD COLUMN latency_ms INT UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE agent_latency_stmt FROM @agent_latency_sql; EXECUTE agent_latency_stmt; DEALLOCATE PREPARE agent_latency_stmt;

SET @agent_action_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_agent_runs' AND column_name='action') = 0,
    'ALTER TABLE town_agent_runs ADD COLUMN action VARCHAR(16) NOT NULL DEFAULT \'\'',
    'SELECT 1');
PREPARE agent_action_stmt FROM @agent_action_sql; EXECUTE agent_action_stmt; DEALLOCATE PREPARE agent_action_stmt;

SET @agent_reason_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_agent_runs' AND column_name='reason') = 0,
    'ALTER TABLE town_agent_runs ADD COLUMN reason VARCHAR(500) NOT NULL DEFAULT \'\'',
    'SELECT 1');
PREPARE agent_reason_stmt FROM @agent_reason_sql; EXECUTE agent_reason_stmt; DEALLOCATE PREPARE agent_reason_stmt;

SET @agent_output_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_agent_runs' AND column_name='output_json') = 0,
    'ALTER TABLE town_agent_runs ADD COLUMN output_json JSON NULL',
    'SELECT 1');
PREPARE agent_output_stmt FROM @agent_output_sql; EXECUTE agent_output_stmt; DEALLOCATE PREPARE agent_output_stmt;

CREATE TABLE IF NOT EXISTS town_npc_activity_logs (
    log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    run_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    log_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    summary VARCHAR(500) NOT NULL,
    detail_json JSON NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (log_id),
    KEY ix_town_npc_activity_npc_time (npc_id, created_at),
    KEY ix_town_npc_activity_type_time (log_type, created_at),
    CONSTRAINT fk_town_npc_activity_npc FOREIGN KEY (npc_id) REFERENCES town_npcs(npc_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_npc_activity_run FOREIGN KEY (run_id) REFERENCES town_agent_runs(run_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
