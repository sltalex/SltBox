-- Server-authoritative global decision frequency slots.
-- Slots are short audit markers for decisions started in the last hour; the
-- global policy is checked while holding the Worker lease transaction.

CREATE TABLE IF NOT EXISTS town_agent_decision_slots (
    slot_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    worker_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (slot_id),
    KEY ix_town_agent_decision_slots_created (created_at),
    KEY ix_town_agent_decision_slots_npc_created (npc_id, created_at),
    CONSTRAINT fk_town_agent_decision_slot_npc FOREIGN KEY (npc_id)
        REFERENCES town_npcs (npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
