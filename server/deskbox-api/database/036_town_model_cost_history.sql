-- Model pricing, provider revisions, and immutable per-run cost snapshots.
-- Prices are deliberately separate from the encrypted provider row so a key
-- rotation never rewrites historical cost evidence.
CREATE TABLE IF NOT EXISTS town_model_provider_pricing (
    provider_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'USD',
    input_per_million DECIMAL(18,8) NOT NULL DEFAULT 0,
    output_per_million DECIMAL(18,8) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (provider_id),
    CONSTRAINT fk_town_model_pricing_provider FOREIGN KEY (provider_id) REFERENCES town_model_providers(provider_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_model_provider_revisions (
    revision_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    snapshot_json JSON NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (revision_id),
    KEY ix_town_model_revisions_provider_created (provider_id, created_at),
    CONSTRAINT fk_town_model_revision_provider FOREIGN KEY (provider_id) REFERENCES town_model_providers(provider_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS town_agent_run_costs (
    run_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    input_per_million DECIMAL(18,8) NOT NULL DEFAULT 0,
    output_per_million DECIMAL(18,8) NOT NULL DEFAULT 0,
    amount DECIMAL(24,10) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (run_id),
    KEY ix_town_agent_run_costs_created (created_at),
    CONSTRAINT fk_town_agent_run_costs_run FOREIGN KEY (run_id) REFERENCES town_agent_runs(run_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
