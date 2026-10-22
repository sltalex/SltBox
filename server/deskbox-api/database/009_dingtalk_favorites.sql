CREATE TABLE IF NOT EXISTS dingtalk_group_document_favorites (
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    document_urls_json TEXT NOT NULL,
    documents_json TEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_dingtalk_favorites_user FOREIGN KEY (user_id) REFERENCES auth_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
