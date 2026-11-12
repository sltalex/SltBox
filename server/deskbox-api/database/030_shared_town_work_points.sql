-- Shared-town configurable work points and durable work results.
-- Work points replace the worker's fixed demo coordinates while retaining
-- the same point keys for existing schedules.

CREATE TABLE IF NOT EXISTS town_work_points (
    point_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    world_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    point_key VARCHAR(128) NOT NULL,
    name VARCHAR(80) NOT NULL,
    kind VARCHAR(32) NOT NULL DEFAULT 'workshop',
    tile_x INT NOT NULL,
    tile_y INT NOT NULL,
    interaction_json JSON NOT NULL,
    result_json JSON NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (point_id),
    UNIQUE KEY ux_town_work_points_key (world_id, point_key),
    KEY ix_town_work_points_world (world_id, enabled),
    CONSTRAINT fk_town_work_points_world FOREIGN KEY (world_id) REFERENCES town_worlds(world_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_work_runs (
    run_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    point_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    task_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    job VARCHAR(64) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'completed',
    result_json JSON NOT NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    PRIMARY KEY (run_id),
    KEY ix_town_work_runs_point_time (point_id, completed_at),
    KEY ix_town_work_runs_npc_time (npc_id, completed_at),
    CONSTRAINT fk_town_work_runs_point FOREIGN KEY (point_id) REFERENCES town_work_points(point_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_work_runs_npc FOREIGN KEY (npc_id) REFERENCES town_npcs(npc_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_work_runs_task FOREIGN KEY (task_id) REFERENCES town_npc_tasks(task_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO town_work_points
    (point_id, world_id, point_key, name, kind, tile_x, tile_y, interaction_json, result_json, enabled, created_at, updated_at)
VALUES
    ('10000000000000000000000000000001', 'main', 'farm-zone-3', '三号农田', 'farm', 128, 120,
        '{"radius":1,"occupations":["farmer"]}', '{"effect":"water_crop","resource":"crop_watered"}', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('10000000000000000000000000000002', 'main', 'market-zone-1', '一号集市', 'market', 96, 96,
        '{"radius":1,"occupations":["merchant"]}', '{"effect":"restock_market","resource":"market_stock"}', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('10000000000000000000000000000003', 'main', 'workshop-zone-2', '二号工坊', 'workshop', 112, 96,
        '{"radius":1,"occupations":["craftsman"]}', '{"effect":"craft_item","resource":"workshop_output"}', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
    name=VALUES(name), kind=VALUES(kind), tile_x=VALUES(tile_x), tile_y=VALUES(tile_y),
    interaction_json=VALUES(interaction_json), result_json=VALUES(result_json), enabled=VALUES(enabled), updated_at=UTC_TIMESTAMP();
