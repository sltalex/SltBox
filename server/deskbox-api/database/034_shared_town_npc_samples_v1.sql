-- Add two deterministic sample villagers for the first-version acceptance set.
-- This migration is additive and never overwrites an existing administrator
-- configuration, position, pause flag, task, inventory, or schedule.

INSERT IGNORE INTO town_npcs
    (npc_id, world_id, name, avatar_asset_id, appearance_json, occupation,
     enabled, agent_paused, created_at, updated_at)
VALUES
    ('8cbb3e8f37e8f5bb9b3a0123d1a1a004', 'main', '沈禾',
     'town-source-npc-alex', '{"hair":"brown","shirt":"green","accent":"wheat"}',
     'farmer', 1, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('8cbb3e8f37e8f5bb9b3a0123d1a1a005', 'main', '顾宁',
     'town-source-npc-caroline', '{"hair":"blue","shirt":"purple","accent":"lavender"}',
     'merchant', 1, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP());

INSERT IGNORE INTO town_npc_profiles
    (npc_id, personality_json, family_background, short_term_goal,
     long_term_goal, speech_style, values_json, fears_json)
VALUES
    ('8cbb3e8f37e8f5bb9b3a0123d1a1a004',
     '{"traits":["耐心","务实","爱观察"],"temperament":"温和而专注"}',
     '家里世代种麦子，母亲教会她记录天气和土壤变化。',
     '照料农田并记录当天的生长情况。',
     '把村里的荒地变成四季都有收成的试验田。',
     '说话慢而具体，喜欢先描述观察到的事实。',
     '["耐心","可持续","诚实"]', '["突然的暴雨","浪费粮食"]'),
    ('8cbb3e8f37e8f5bb9b3a0123d1a1a005',
     '{"traits":["机敏","外向","爱帮忙"],"temperament":"热络而有分寸"}',
     '父母经营小杂货铺，她从小负责整理货架和听邻居讲需求。',
     '整理集市货物并找到缺货的生活用品。',
     '建立一个让村民互相交换余货的公平集市。',
     '先问候再谈正事，常用轻松的比喻解释价格。',
     '["公平","互助","可靠"]', '["囤货造成浪费","有人被排除在交易外"]');

INSERT IGNORE INTO town_npc_runtime_states
    (npc_id, tile_x, tile_y, state, current_task_id, dialogue,
     capabilities_json, state_version, updated_at)
VALUES
    ('8cbb3e8f37e8f5bb9b3a0123d1a1a004', 136, 120, 'rest', NULL, '', '[]', 1, UTC_TIMESTAMP()),
    ('8cbb3e8f37e8f5bb9b3a0123d1a1a005', 104, 96, 'rest', NULL, '', '[]', 1, UTC_TIMESTAMP());

-- Seed a seven-day deterministic schedule only when the new NPC has no rows.
INSERT INTO town_npc_schedules
    (npc_id, weekday, start_minute, end_minute, action, target_id, job, priority)
SELECT seed.npc_id, seed.weekday, seed.start_minute, seed.end_minute,
       seed.action, seed.target_id, seed.job, seed.priority
FROM (
    SELECT '8cbb3e8f37e8f5bb9b3a0123d1a1a004' AS npc_id, d.weekday,
           480 AS start_minute, 660 AS end_minute, 'work' AS action,
           'farm-zone-3' AS target_id, 'water_crop' AS job, 10 AS priority
    FROM (SELECT 0 AS weekday UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) d
    UNION ALL
    SELECT '8cbb3e8f37e8f5bb9b3a0123d1a1a004', d.weekday, 720, 780, 'rest', '', '', 1
    FROM (SELECT 0 AS weekday UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) d
    UNION ALL
    SELECT '8cbb3e8f37e8f5bb9b3a0123d1a1a004', d.weekday, 840, 1020, 'work', 'farm-zone-3', 'clear_weeds', 8
    FROM (SELECT 0 AS weekday UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) d
    UNION ALL
    SELECT '8cbb3e8f37e8f5bb9b3a0123d1a1a005', d.weekday, 540, 690, 'work', 'market-zone-1', 'restock_market', 10
    FROM (SELECT 0 AS weekday UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) d
    UNION ALL
    SELECT '8cbb3e8f37e8f5bb9b3a0123d1a1a005', d.weekday, 720, 780, 'talk', 'town-square', '', 5
    FROM (SELECT 0 AS weekday UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) d
    UNION ALL
    SELECT '8cbb3e8f37e8f5bb9b3a0123d1a1a005', d.weekday, 900, 1080, 'work', 'market-zone-1', 'serve_customer', 8
    FROM (SELECT 0 AS weekday UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) d
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM town_npc_schedules existing WHERE existing.npc_id = seed.npc_id
);
