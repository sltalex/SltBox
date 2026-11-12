-- Durable market stock and workshop output records for completed NPC work.

CREATE TABLE IF NOT EXISTS town_market_stocks (
    stock_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    world_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    point_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    stock_key VARCHAR(128) NOT NULL,
    item_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (stock_id),
    UNIQUE KEY ux_town_market_stocks_key (world_id, stock_key),
    KEY ix_town_market_stocks_point (point_id),
    CONSTRAINT fk_town_market_stocks_world FOREIGN KEY (world_id) REFERENCES town_worlds(world_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_market_stocks_point FOREIGN KEY (point_id) REFERENCES town_work_points(point_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_workshop_outputs (
    output_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    world_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    point_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    task_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    item_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    produced_at DATETIME NOT NULL,
    PRIMARY KEY (output_id),
    KEY ix_town_workshop_outputs_world_time (world_id, produced_at),
    KEY ix_town_workshop_outputs_task (task_id),
    CONSTRAINT fk_town_workshop_outputs_world FOREIGN KEY (world_id) REFERENCES town_worlds(world_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_workshop_outputs_point FOREIGN KEY (point_id) REFERENCES town_work_points(point_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_workshop_outputs_npc FOREIGN KEY (npc_id) REFERENCES town_npcs(npc_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_workshop_outputs_task FOREIGN KEY (task_id) REFERENCES town_npc_tasks(task_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO town_market_stocks
    (stock_id, world_id, point_id, stock_key, item_id, quantity, updated_at)
VALUES
    ('30000000000000000000000000000001', 'main', '10000000000000000000000000000002', 'market-stock-1', 'market_goods', 0, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE point_id=VALUES(point_id), item_id=VALUES(item_id);
