-- Shared-town durable farm plots and the first real work effect.
-- A work point remains the navigation/interaction anchor; plots hold world state.

CREATE TABLE IF NOT EXISTS town_farm_plots (
    plot_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    world_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    point_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    plot_key VARCHAR(128) NOT NULL,
    name VARCHAR(80) NOT NULL,
    crop_id VARCHAR(64) NOT NULL,
    growth_stage TINYINT UNSIGNED NOT NULL DEFAULT 1,
    state VARCHAR(16) NOT NULL DEFAULT 'growing',
    watered_at DATETIME NULL,
    watering_count INT UNSIGNED NOT NULL DEFAULT 0,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (plot_id),
    UNIQUE KEY ux_town_farm_plots_key (world_id, plot_key),
    KEY ix_town_farm_plots_point (point_id, enabled),
    CONSTRAINT fk_town_farm_plots_world FOREIGN KEY (world_id) REFERENCES town_worlds(world_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_farm_plots_point FOREIGN KEY (point_id) REFERENCES town_work_points(point_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO town_farm_plots
    (plot_id, world_id, point_id, plot_key, name, crop_id, growth_stage, state, watered_at, watering_count, enabled, created_at, updated_at)
VALUES
    ('20000000000000000000000000000001', 'main', '10000000000000000000000000000001', 'farm-plot-3-1', '三号农田一号地块', 'wheat', 1, 'growing', NULL, 0, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
    point_id=VALUES(point_id), name=VALUES(name), crop_id=VALUES(crop_id), enabled=VALUES(enabled), updated_at=UTC_TIMESTAMP();
