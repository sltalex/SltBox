-- Needs, goals and auditable decision context for the NPC life loop.
-- Every statement is guarded so rolling deployments can replay the migration.
SET @npc_runtime_goal_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_runtime_states' AND column_name='current_goal')=0,'ALTER TABLE town_npc_runtime_states ADD COLUMN current_goal VARCHAR(255) NOT NULL DEFAULT \'\'','SELECT 1');
PREPARE npc_runtime_goal_stmt FROM @npc_runtime_goal_sql; EXECUTE npc_runtime_goal_stmt; DEALLOCATE PREPARE npc_runtime_goal_stmt;
SET @npc_runtime_desire_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_runtime_states' AND column_name='desire_json')=0,'ALTER TABLE town_npc_runtime_states ADD COLUMN desire_json JSON NULL','SELECT 1');
PREPARE npc_runtime_desire_stmt FROM @npc_runtime_desire_sql; EXECUTE npc_runtime_desire_stmt; DEALLOCATE PREPARE npc_runtime_desire_stmt;
SET @npc_runtime_plan_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_runtime_states' AND column_name='plan_json')=0,'ALTER TABLE town_npc_runtime_states ADD COLUMN plan_json JSON NULL','SELECT 1');
PREPARE npc_runtime_plan_stmt FROM @npc_runtime_plan_sql; EXECUTE npc_runtime_plan_stmt; DEALLOCATE PREPARE npc_runtime_plan_stmt;
SET @npc_runtime_needs_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_runtime_states' AND column_name='needs_json')=0,'ALTER TABLE town_npc_runtime_states ADD COLUMN needs_json JSON NULL','SELECT 1');
PREPARE npc_runtime_needs_stmt FROM @npc_runtime_needs_sql; EXECUTE npc_runtime_needs_stmt; DEALLOCATE PREPARE npc_runtime_needs_stmt;
SET @npc_runtime_decision_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_runtime_states' AND column_name='last_decision_context_json')=0,'ALTER TABLE town_npc_runtime_states ADD COLUMN last_decision_context_json JSON NULL','SELECT 1');
PREPARE npc_runtime_decision_stmt FROM @npc_runtime_decision_sql; EXECUTE npc_runtime_decision_stmt; DEALLOCATE PREPARE npc_runtime_decision_stmt;
SET @npc_runtime_reflection_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_runtime_states' AND column_name='last_reflection_at')=0,'ALTER TABLE town_npc_runtime_states ADD COLUMN last_reflection_at DATETIME NULL','SELECT 1');
PREPARE npc_runtime_reflection_stmt FROM @npc_runtime_reflection_sql; EXECUTE npc_runtime_reflection_stmt; DEALLOCATE PREPARE npc_runtime_reflection_stmt;
SET @npc_runtime_tick_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_runtime_states' AND column_name='last_life_tick_at')=0,'ALTER TABLE town_npc_runtime_states ADD COLUMN last_life_tick_at DATETIME NULL','SELECT 1');
PREPARE npc_runtime_tick_stmt FROM @npc_runtime_tick_sql; EXECUTE npc_runtime_tick_stmt; DEALLOCATE PREPARE npc_runtime_tick_stmt;
SET @npc_memory_state_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_memories' AND column_name='memory_state')=0,'ALTER TABLE town_npc_memories ADD COLUMN memory_state VARCHAR(16) NOT NULL DEFAULT \'confirmed\'','SELECT 1');
PREPARE npc_memory_state_stmt FROM @npc_memory_state_sql; EXECUTE npc_memory_state_stmt; DEALLOCATE PREPARE npc_memory_state_stmt;
SET @npc_memory_channel_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_memories' AND column_name='channel')=0,'ALTER TABLE town_npc_memories ADD COLUMN channel VARCHAR(24) NOT NULL DEFAULT \'private\'','SELECT 1');
PREPARE npc_memory_channel_stmt FROM @npc_memory_channel_sql; EXECUTE npc_memory_channel_stmt; DEALLOCATE PREPARE npc_memory_channel_stmt;
