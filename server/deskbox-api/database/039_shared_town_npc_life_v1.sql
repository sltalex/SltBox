-- Shared-town first-version life model: account characters, NPC homes,
-- deterministic economy and model decision frequency policies.
-- The migration is additive and safe to replay on an existing installation.

CREATE TABLE IF NOT EXISTS town_player_characters (
    world_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_name VARCHAR(64) NOT NULL,
    appearance_asset_id VARCHAR(128) NOT NULL DEFAULT '',
    appearance_json JSON NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (world_id, user_id),
    CONSTRAINT fk_town_player_character_world FOREIGN KEY (world_id)
        REFERENCES town_worlds (world_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_player_character_user FOREIGN KEY (user_id)
        REFERENCES auth_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_npc_homes (
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    home_asset_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    home_asset_name VARCHAR(160) NOT NULL DEFAULT '',
    tile_x INT NOT NULL DEFAULT 0,
    tile_y INT NOT NULL DEFAULT 0,
    interaction_json JSON NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (npc_id),
    CONSTRAINT fk_town_npc_home_npc FOREIGN KEY (npc_id)
        REFERENCES town_npcs (npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_npc_wallets (
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    balance BIGINT UNSIGNED NOT NULL DEFAULT 0,
    initialized_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (npc_id),
    CONSTRAINT fk_town_npc_wallet_npc FOREIGN KEY (npc_id)
        REFERENCES town_npcs (npc_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_npc_wallet_admin FOREIGN KEY (initialized_by)
        REFERENCES auth_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_npc_transactions (
    transaction_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    seller_npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    buyer_npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    item_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (transaction_id),
    KEY ix_town_npc_transactions_created (created_at),
    KEY ix_town_npc_transactions_seller (seller_npc_id, created_at),
    KEY ix_town_npc_transactions_buyer (buyer_npc_id, created_at),
    CONSTRAINT fk_town_npc_transaction_seller FOREIGN KEY (seller_npc_id)
        REFERENCES town_npcs (npc_id) ON DELETE CASCADE,
    CONSTRAINT fk_town_npc_transaction_buyer FOREIGN KEY (buyer_npc_id)
        REFERENCES town_npcs (npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_npc_wallet_entries (
    entry_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entry_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    delta BIGINT NOT NULL,
    balance_after BIGINT UNSIGNED NOT NULL,
    transaction_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    reason VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (entry_id),
    KEY ix_town_npc_wallet_entries_npc (npc_id, created_at),
    CONSTRAINT fk_town_npc_wallet_entry_npc FOREIGN KEY (npc_id)
        REFERENCES town_npcs (npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_npc_decision_policies (
    npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    decision_interval_seconds INT UNSIGNED NOT NULL DEFAULT 60,
    observation_interval_seconds INT UNSIGNED NOT NULL DEFAULT 300,
    dialogue_cooldown_seconds INT UNSIGNED NOT NULL DEFAULT 60,
    max_decisions_per_hour INT UNSIGNED NOT NULL DEFAULT 60,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (npc_id),
    CONSTRAINT fk_town_npc_decision_policy_npc FOREIGN KEY (npc_id)
        REFERENCES town_npcs (npc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Spatial fields on memories make observations auditable and allow a later
-- navigation-version change to invalidate stale location knowledge.
SET @memory_subject_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_memories' AND column_name='subject_npc_id') = 0,
    'ALTER TABLE town_npc_memories ADD COLUMN subject_npc_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER npc_id',
    'SELECT 1');
PREPARE memory_subject_stmt FROM @memory_subject_sql; EXECUTE memory_subject_stmt; DEALLOCATE PREPARE memory_subject_stmt;

SET @memory_asset_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_memories' AND column_name='asset_id') = 0,
    'ALTER TABLE town_npc_memories ADD COLUMN asset_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL',
    'SELECT 1');
PREPARE memory_asset_stmt FROM @memory_asset_sql; EXECUTE memory_asset_stmt; DEALLOCATE PREPARE memory_asset_stmt;

SET @memory_asset_name_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_memories' AND column_name='asset_name') = 0,
    'ALTER TABLE town_npc_memories ADD COLUMN asset_name VARCHAR(160) NULL',
    'SELECT 1');
PREPARE memory_asset_name_stmt FROM @memory_asset_name_sql; EXECUTE memory_asset_name_stmt; DEALLOCATE PREPARE memory_asset_name_stmt;

SET @memory_tile_x_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_memories' AND column_name='tile_x') = 0,
    'ALTER TABLE town_npc_memories ADD COLUMN tile_x INT NULL',
    'SELECT 1');
PREPARE memory_tile_x_stmt FROM @memory_tile_x_sql; EXECUTE memory_tile_x_stmt; DEALLOCATE PREPARE memory_tile_x_stmt;

SET @memory_tile_y_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_memories' AND column_name='tile_y') = 0,
    'ALTER TABLE town_npc_memories ADD COLUMN tile_y INT NULL',
    'SELECT 1');
PREPARE memory_tile_y_stmt FROM @memory_tile_y_sql; EXECUTE memory_tile_y_stmt; DEALLOCATE PREPARE memory_tile_y_stmt;

SET @memory_navigation_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='town_npc_memories' AND column_name='navigation_version') = 0,
    'ALTER TABLE town_npc_memories ADD COLUMN navigation_version INT UNSIGNED NULL',
    'SELECT 1');
PREPARE memory_navigation_stmt FROM @memory_navigation_sql; EXECUTE memory_navigation_stmt; DEALLOCATE PREPARE memory_navigation_stmt;

