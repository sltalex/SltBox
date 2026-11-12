-- Extend the map east/south; existing object coordinates stay unchanged.
-- Online presence expires, while player positions belong to the account.
CREATE TABLE IF NOT EXISTS town_player_positions (
    world_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    pixel_x DECIMAL(10,2) NOT NULL,
    pixel_y DECIMAL(10,2) NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (world_id, user_id),
    CONSTRAINT fk_town_player_position_world FOREIGN KEY (world_id)
        REFERENCES town_worlds (world_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_player_position_user FOREIGN KEY (user_id)
        REFERENCES auth_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Preserve any last known position from the old online roster on upgrade.
INSERT IGNORE INTO town_player_positions (world_id,user_id,pixel_x,pixel_y,updated_at)
SELECT world_id,user_id,tile_x * 16 + 8,tile_y * 16 + 15,last_seen_at
FROM town_presence;

UPDATE town_worlds
SET map_width=384,map_height=384,terrain_version=4,
    world_version=world_version+1,updated_at=UTC_TIMESTAMP()
WHERE world_id='main' AND (map_width<384 OR map_height<384 OR terrain_version<4);
