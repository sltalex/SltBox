-- Optional per-species management overrides. The API also creates this table on first use.
CREATE TABLE IF NOT EXISTS wildlife_catalog_settings (
    species_id VARCHAR(80) NOT NULL PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    enabled TINYINT NOT NULL DEFAULT 1,
    encounter_weight INT NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
