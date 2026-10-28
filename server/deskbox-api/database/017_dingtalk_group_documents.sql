CREATE TABLE IF NOT EXISTS dingtalk_group_document_indexes (
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    documents_json LONGTEXT NOT NULL,
    sync_interval_hours SMALLINT UNSIGNED NOT NULL DEFAULT 6,
    last_sync_completed_at VARCHAR(40) NOT NULL DEFAULT '',
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_dingtalk_document_index_user FOREIGN KEY (user_id) REFERENCES auth_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
