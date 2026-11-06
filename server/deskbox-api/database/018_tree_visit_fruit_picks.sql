CREATE TABLE IF NOT EXISTS tree_visit_fruit_picks (
    operation_id VARCHAR(128) PRIMARY KEY,
    owner_id VARCHAR(64) NOT NULL,
    visitor_id VARCHAR(64) NOT NULL,
    fruit_id VARCHAR(80) NOT NULL,
    pick_day DATE NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_tree_visit_fruit_daily (owner_id, visitor_id, fruit_id, pick_day),
    INDEX idx_tree_visit_fruit_owner (owner_id, fruit_id, pick_day),
    CONSTRAINT fk_tree_visit_fruit_owner FOREIGN KEY (owner_id) REFERENCES auth_users (id) ON DELETE CASCADE,
    CONSTRAINT fk_tree_visit_fruit_visitor FOREIGN KEY (visitor_id) REFERENCES auth_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
