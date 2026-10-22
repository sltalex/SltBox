CREATE TABLE auth_users (
    id TEXT PRIMARY KEY,
    username TEXT NOT NULL,
    username_normalized TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active'
);

CREATE TABLE admin_bootstrap (
    id INTEGER PRIMARY KEY,
    user_id TEXT NULL REFERENCES auth_users(id) ON DELETE RESTRICT
);
INSERT INTO admin_bootstrap (id, user_id) VALUES (1, NULL);

CREATE TABLE admin_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id TEXT NOT NULL,
    target_id TEXT NOT NULL,
    action TEXT NOT NULL,
    reason TEXT NOT NULL,
    result TEXT NOT NULL,
    details_json TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE auth_sessions (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    device_id TEXT NOT NULL,
    access_token_hash TEXT NOT NULL UNIQUE,
    refresh_token_hash TEXT NOT NULL UNIQUE,
    access_expires_at TEXT NOT NULL,
    refresh_expires_at TEXT NOT NULL,
    revoked_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE CASCADE
);

CREATE TABLE auth_rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bucket_key TEXT NOT NULL,
    action TEXT NOT NULL,
    window_started_at TEXT NOT NULL,
    attempts INTEGER NOT NULL,
    UNIQUE(bucket_key, action)
);

CREATE TABLE account_identities (
 id TEXT PRIMARY KEY, user_id TEXT NOT NULL, provider TEXT NOT NULL,
 corp_id TEXT NOT NULL, dingtalk_user_id TEXT NOT NULL,
 display_name TEXT NOT NULL, organization_name TEXT NOT NULL,
 department_name TEXT NOT NULL, avatar_url TEXT NOT NULL DEFAULT '',
 raw_scope_summary TEXT NOT NULL DEFAULT 'client_reported_basic_profile',
 created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
 UNIQUE(user_id, provider), UNIQUE(provider, corp_id, dingtalk_user_id),
 FOREIGN KEY(user_id) REFERENCES auth_users(id) ON DELETE CASCADE
);

CREATE TABLE account_backup_snapshots (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    device_id TEXT NOT NULL,
    app_version TEXT NOT NULL,
    file_count INTEGER NOT NULL,
    total_bytes INTEGER NOT NULL,
    sha256 TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE CASCADE
);

CREATE TABLE account_backup_files (
    snapshot_id TEXT NOT NULL,
    relative_path TEXT NOT NULL,
    byte_count INTEGER NOT NULL,
    sha256 TEXT NOT NULL,
    json_content TEXT NOT NULL,
    PRIMARY KEY (snapshot_id, relative_path),
    FOREIGN KEY (snapshot_id) REFERENCES account_backup_snapshots(id) ON DELETE CASCADE
);

CREATE TABLE tree_profiles (
    user_id TEXT PRIMARY KEY,
    participation_enabled INTEGER NOT NULL DEFAULT 1,
    total_energy INTEGER NOT NULL DEFAULT 0,
    fruit_total INTEGER NOT NULL DEFAULT 0,
    harvested_total INTEGER NOT NULL DEFAULT 0,
    stage INTEGER NOT NULL DEFAULT 1,
    state_version INTEGER NOT NULL DEFAULT 0,
    fruit_energy INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE CASCADE
);

CREATE TABLE tree_daily_progress (
    user_id TEXT NOT NULL,
    progress_day TEXT NOT NULL,
    growth INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, progress_day),
    FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE CASCADE
);

CREATE TABLE tree_progress_operations (
    user_id TEXT NOT NULL,
    operation_id TEXT NOT NULL,
    device_id TEXT NOT NULL,
    progress_day TEXT NOT NULL,
    energy_delta INTEGER NOT NULL,
    day_growth_delta INTEGER NOT NULL,
    fruit_delta INTEGER NOT NULL,
    harvested_delta INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    PRIMARY KEY (user_id, operation_id),
    FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE CASCADE
);

CREATE TABLE account_backup_uploads (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    device_id TEXT NOT NULL,
    app_version TEXT NOT NULL,
    total_bytes INTEGER NOT NULL,
    sha256 TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE CASCADE
);

CREATE TABLE account_backup_upload_parts (
    upload_id TEXT NOT NULL,
    part_start INTEGER NOT NULL,
    part_end INTEGER NOT NULL,
    sha256 TEXT NOT NULL,
    PRIMARY KEY (upload_id, part_start),
    FOREIGN KEY (upload_id) REFERENCES account_backup_uploads(id) ON DELETE CASCADE
);

CREATE TABLE aquarium_profiles (
    user_id TEXT PRIMARY KEY REFERENCES auth_users(id) ON DELETE CASCADE,
    initialized INTEGER NOT NULL DEFAULT 0,
    coins INTEGER NOT NULL DEFAULT 0 CHECK (coins >= 0),
    scene_version INTEGER NOT NULL DEFAULT 0,
    scene_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE aquarium_inventory (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES aquarium_profiles(user_id) ON DELETE CASCADE,
    item_id TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE aquarium_wallet_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id TEXT NOT NULL REFERENCES aquarium_profiles(user_id) ON DELETE CASCADE,
    operation_id TEXT NOT NULL,
    delta INTEGER NOT NULL,
    balance INTEGER NOT NULL CHECK (balance >= 0),
    reason TEXT NOT NULL,
    item_id TEXT NULL,
    created_at TEXT NOT NULL,
    UNIQUE(user_id, operation_id)
);

CREATE TABLE aquarium_operations (
    user_id TEXT NOT NULL REFERENCES aquarium_profiles(user_id) ON DELETE CASCADE,
    operation_id TEXT NOT NULL,
    action TEXT NOT NULL,
    request_hash TEXT NOT NULL,
    created_at TEXT NOT NULL,
    PRIMARY KEY(user_id, operation_id)
);

CREATE TABLE fruit_recycle_settings (
    id INTEGER PRIMARY KEY,
    price_per_fruit INTEGER NOT NULL DEFAULT 1 CHECK (price_per_fruit >= 0),
    updated_by TEXT NULL REFERENCES auth_users(id) ON DELETE SET NULL,
    updated_at TEXT NOT NULL
);
INSERT INTO fruit_recycle_settings (id, price_per_fruit, updated_at) VALUES (1, 1, '2026-01-01 00:00:00');

CREATE TABLE aquarium_catalog_settings (
    id INTEGER PRIMARY KEY,
    card_pack_price INTEGER NOT NULL DEFAULT 200 CHECK (card_pack_price >= 0),
    updated_by TEXT NULL REFERENCES auth_users(id) ON DELETE SET NULL,
    updated_at TEXT NOT NULL
);
INSERT INTO aquarium_catalog_settings (id, card_pack_price, updated_at) VALUES (1, 200, '2026-01-01 00:00:00');
