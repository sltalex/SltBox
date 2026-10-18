-- Aquarium layouts may contain every owned instance. The request body is capped
-- at 1 MiB, so MySQL TEXT (64 KiB) is too small for a valid large layout.
ALTER TABLE aquarium_profiles MODIFY COLUMN scene_json MEDIUMTEXT NOT NULL;
