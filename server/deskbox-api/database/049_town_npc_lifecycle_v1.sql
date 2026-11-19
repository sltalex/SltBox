-- P2-1 resident lifecycle.  States are reversible in this first slice;
-- irreversible death/replacement is deliberately outside the current scope.
CREATE TABLE IF NOT EXISTS town_npc_lifecycle (
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    reason_code VARCHAR(64) NOT NULL DEFAULT '',
    source_event_id BIGINT UNSIGNED NULL,
    started_at DATETIME NOT NULL,
    expected_return_at DATETIME NULL,
    replacement_npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (npc_id),
    KEY ix_town_npc_lifecycle_due (state, expected_return_at),
    CONSTRAINT fk_town_npc_lifecycle_npc FOREIGN KEY (npc_id) REFERENCES town_npcs(npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO town_npc_lifecycle (npc_id,state,reason_code,started_at,revision,updated_at)
SELECT n.npc_id,'active','',n.created_at,1,n.updated_at
FROM town_npcs n
LEFT JOIN town_npc_lifecycle l ON l.npc_id=n.npc_id
WHERE l.npc_id IS NULL;
