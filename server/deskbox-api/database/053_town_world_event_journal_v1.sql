-- Append-only journal used as the replay cursor after a world snapshot.
-- The idempotency key makes retries safe during a rolling deploy.
CREATE TABLE IF NOT EXISTS town_world_event_journal (
    sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    world_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_json JSON NOT NULL,
    schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    idempotency_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (sequence),
    UNIQUE KEY ux_town_world_event_journal_idempotency (world_id, idempotency_key),
    KEY ix_town_world_event_journal_world (world_id, sequence),
    CONSTRAINT fk_town_world_event_journal_world FOREIGN KEY (world_id)
        REFERENCES town_worlds (world_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
