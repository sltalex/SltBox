-- P2-2 bounded social matters.  A matter is a server-owned, non-combat
-- coordination record; model output may select only one of the stored options.
CREATE TABLE IF NOT EXISTS town_social_matters (
    matter_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    world_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_event_id BIGINT UNSIGNED NULL,
    creator_npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open',
    public_summary VARCHAR(255) NOT NULL,
    options_json JSON NOT NULL,
    deadline_at DATETIME NULL,
    idempotency_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    failure_reason VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    PRIMARY KEY (matter_id),
    UNIQUE KEY ux_town_social_matters_idempotency (world_id, idempotency_key),
    KEY ix_town_social_matters_state (world_id, state, deadline_at),
    CONSTRAINT fk_town_social_matters_world FOREIGN KEY (world_id)
        REFERENCES town_worlds (world_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_social_matters_source_event FOREIGN KEY (source_event_id)
        REFERENCES town_world_events (event_id) ON DELETE SET NULL,
    CONSTRAINT fk_town_social_matters_creator FOREIGN KEY (creator_npc_id)
        REFERENCES town_npcs (npc_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_social_responses (
    response_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    matter_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    resident_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    option_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    response_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'support',
    public_text VARCHAR(255) NOT NULL DEFAULT '',
    idempotency_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (response_id),
    UNIQUE KEY ux_town_social_responses_resident (matter_id, resident_id),
    UNIQUE KEY ux_town_social_responses_idempotency (matter_id, idempotency_key),
    KEY ix_town_social_responses_matter (matter_id, created_at),
    CONSTRAINT fk_town_social_responses_matter FOREIGN KEY (matter_id)
        REFERENCES town_social_matters (matter_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_social_responses_resident FOREIGN KEY (resident_id)
        REFERENCES town_npcs (npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_social_commitments (
    commitment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    matter_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    option_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conditions_json JSON NOT NULL,
    state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'assigned',
    due_at DATETIME NULL,
    failure_reason VARCHAR(255) NOT NULL DEFAULT '',
    idempotency_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    PRIMARY KEY (commitment_id),
    UNIQUE KEY ux_town_social_commitments_idempotency (matter_id, idempotency_key),
    KEY ix_town_social_commitments_matter (matter_id, state),
    KEY ix_town_social_commitments_owner (owner_npc_id, state),
    CONSTRAINT fk_town_social_commitments_matter FOREIGN KEY (matter_id)
        REFERENCES town_social_matters (matter_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_social_commitments_owner FOREIGN KEY (owner_npc_id)
        REFERENCES town_npcs (npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
