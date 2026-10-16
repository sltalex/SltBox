ALTER TABLE auth_users ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'active';

CREATE TABLE admin_bootstrap (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    CONSTRAINT fk_admin_owner FOREIGN KEY (user_id) REFERENCES auth_users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO admin_bootstrap (id, user_id) VALUES (1, NULL);
UPDATE admin_bootstrap SET user_id =
    (SELECT id FROM auth_users ORDER BY created_at, id LIMIT 1)
    WHERE id = 1 AND user_id IS NULL;

CREATE TABLE admin_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(500) NOT NULL,
    result VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    details_json TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    KEY ix_admin_audit_created (created_at, id),
    KEY ix_admin_audit_target (target_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
