-- NPC personality values and structured cognition memories.
-- Safe to replay during rolling deployments.
CREATE TABLE IF NOT EXISTS town_npc_personality (
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    courage TINYINT UNSIGNED NOT NULL DEFAULT 50,
    kindness TINYINT UNSIGNED NOT NULL DEFAULT 50,
    curiosity TINYINT UNSIGNED NOT NULL DEFAULT 50,
    sociability TINYINT UNSIGNED NOT NULL DEFAULT 50,
    diligence TINYINT UNSIGNED NOT NULL DEFAULT 50,
    caution TINYINT UNSIGNED NOT NULL DEFAULT 50,
    creativity TINYINT UNSIGNED NOT NULL DEFAULT 50,
    independence TINYINT UNSIGNED NOT NULL DEFAULT 50,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (npc_id),
    CONSTRAINT fk_town_npc_personality_npc FOREIGN KEY (npc_id) REFERENCES town_npcs(npc_id) ON DELETE CASCADE,
    CONSTRAINT ck_town_npc_personality_range CHECK (courage BETWEEN 1 AND 100 AND kindness BETWEEN 1 AND 100 AND curiosity BETWEEN 1 AND 100 AND sociability BETWEEN 1 AND 100 AND diligence BETWEEN 1 AND 100 AND caution BETWEEN 1 AND 100 AND creativity BETWEEN 1 AND 100 AND independence BETWEEN 1 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_npc_personality_changes (
    change_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    before_json JSON NOT NULL,
    delta_json JSON NOT NULL,
    after_json JSON NOT NULL,
    reason VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (change_id),
    KEY ix_town_npc_personality_changes_npc (npc_id, change_id),
    CONSTRAINT fk_town_npc_personality_changes_npc FOREIGN KEY (npc_id) REFERENCES town_npcs(npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

SET @cognition_observation_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_memories' AND column_name='observation')=0,'ALTER TABLE town_npc_memories ADD COLUMN observation TEXT NULL','SELECT 1');
PREPARE cognition_observation_stmt FROM @cognition_observation_sql; EXECUTE cognition_observation_stmt; DEALLOCATE PREPARE cognition_observation_stmt;
SET @cognition_interpretation_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_memories' AND column_name='interpretation')=0,'ALTER TABLE town_npc_memories ADD COLUMN interpretation TEXT NULL','SELECT 1');
PREPARE cognition_interpretation_stmt FROM @cognition_interpretation_sql; EXECUTE cognition_interpretation_stmt; DEALLOCATE PREPARE cognition_interpretation_stmt;
SET @cognition_thought_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_memories' AND column_name='thought_summary')=0,'ALTER TABLE town_npc_memories ADD COLUMN thought_summary TEXT NULL','SELECT 1');
PREPARE cognition_thought_stmt FROM @cognition_thought_sql; EXECUTE cognition_thought_stmt; DEALLOCATE PREPARE cognition_thought_stmt;
SET @cognition_reason_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_memories' AND column_name='reasoning_summary')=0,'ALTER TABLE town_npc_memories ADD COLUMN reasoning_summary TEXT NULL','SELECT 1');
PREPARE cognition_reason_stmt FROM @cognition_reason_sql; EXECUTE cognition_reason_stmt; DEALLOCATE PREPARE cognition_reason_stmt;
SET @cognition_belief_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_memories' AND column_name='belief')=0,'ALTER TABLE town_npc_memories ADD COLUMN belief TEXT NULL','SELECT 1');
PREPARE cognition_belief_stmt FROM @cognition_belief_sql; EXECUTE cognition_belief_stmt; DEALLOCATE PREPARE cognition_belief_stmt;
SET @cognition_conclusion_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_memories' AND column_name='conclusion')=0,'ALTER TABLE town_npc_memories ADD COLUMN conclusion TEXT NULL','SELECT 1');
PREPARE cognition_conclusion_stmt FROM @cognition_conclusion_sql; EXECUTE cognition_conclusion_stmt; DEALLOCATE PREPARE cognition_conclusion_stmt;
SET @cognition_intention_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_memories' AND column_name='intention')=0,'ALTER TABLE town_npc_memories ADD COLUMN intention TEXT NULL','SELECT 1');
PREPARE cognition_intention_stmt FROM @cognition_intention_sql; EXECUTE cognition_intention_stmt; DEALLOCATE PREPARE cognition_intention_stmt;
SET @cognition_plan_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_memories' AND column_name='plan_json')=0,'ALTER TABLE town_npc_memories ADD COLUMN plan_json JSON NULL','SELECT 1');
PREPARE cognition_plan_stmt FROM @cognition_plan_sql; EXECUTE cognition_plan_stmt; DEALLOCATE PREPARE cognition_plan_stmt;
SET @cognition_confidence_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_memories' AND column_name='confidence')=0,'ALTER TABLE town_npc_memories ADD COLUMN confidence DECIMAL(4,3) NULL','SELECT 1');
PREPARE cognition_confidence_stmt FROM @cognition_confidence_sql; EXECUTE cognition_confidence_stmt; DEALLOCATE PREPARE cognition_confidence_stmt;
SET @cognition_event_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_memories' AND column_name='source_event_id')=0,'ALTER TABLE town_npc_memories ADD COLUMN source_event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL','SELECT 1');
PREPARE cognition_event_stmt FROM @cognition_event_sql; EXECUTE cognition_event_stmt; DEALLOCATE PREPARE cognition_event_stmt;
SET @cognition_task_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_memories' AND column_name='source_task_id')=0,'ALTER TABLE town_npc_memories ADD COLUMN source_task_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL','SELECT 1');
PREPARE cognition_task_stmt FROM @cognition_task_sql; EXECUTE cognition_task_stmt; DEALLOCATE PREPARE cognition_task_stmt;
