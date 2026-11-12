-- NPC navigation items and exclusive work-point reservations.

CREATE TABLE IF NOT EXISTS town_npc_inventory (
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    item_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (npc_id, item_id),
    CONSTRAINT fk_town_npc_inventory_npc FOREIGN KEY (npc_id) REFERENCES town_npcs(npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_work_point_reservations (
    reservation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    point_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    task_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    reserved_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    released_at DATETIME NULL,
    PRIMARY KEY (reservation_id),
    UNIQUE KEY ux_town_work_point_reservation_status (point_id, status),
    KEY ix_town_work_point_reservation_task (task_id, status),
    CONSTRAINT fk_town_work_point_reservation_point FOREIGN KEY (point_id) REFERENCES town_work_points(point_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_work_point_reservation_npc FOREIGN KEY (npc_id) REFERENCES town_npcs(npc_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_work_point_reservation_task FOREIGN KEY (task_id) REFERENCES town_npc_tasks(task_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
