-- Reserve monthly model tokens before a worker starts a completion.
-- A reservation prevents concurrent model calls from crossing the configured
-- monthly quota. Settled reservations are linked to the eventual run and are
-- retained until the run is recorded, so a worker crash cannot silently lose
-- the consumed-token accounting.
CREATE TABLE IF NOT EXISTS town_agent_token_reservations (
    reservation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    worker_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reserved_tokens INT UNSIGNED NOT NULL,
    actual_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'reserved',
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    settled_at DATETIME NULL,
    PRIMARY KEY (reservation_id),
    KEY ix_town_agent_reservation_provider_status (provider_id, status, expires_at),
    KEY ix_town_agent_reservation_created (created_at),
    CONSTRAINT fk_town_agent_reservation_provider FOREIGN KEY (provider_id) REFERENCES town_model_providers(provider_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
