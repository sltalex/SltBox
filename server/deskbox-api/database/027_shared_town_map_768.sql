-- Expand east and south without changing objects or player positions.
-- Replaying this migration must not shrink a later map or advance its version.
UPDATE town_worlds
SET map_width=GREATEST(map_width,768),map_height=GREATEST(map_height,768),
    terrain_version=GREATEST(terrain_version,5),
    world_version=world_version+1,updated_at=UTC_TIMESTAMP()
WHERE world_id='main' AND (map_width<768 OR map_height<768 OR terrain_version<5);
