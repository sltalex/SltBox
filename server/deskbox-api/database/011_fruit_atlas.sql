CREATE TABLE IF NOT EXISTS fruit_catalog (
    id VARCHAR(80) PRIMARY KEY, name VARCHAR(120) NOT NULL, category VARCHAR(40) NOT NULL,
    atlas_index INT NOT NULL, enabled TINYINT NOT NULL DEFAULT 1, probability DECIMAL(12,4) NOT NULL DEFAULT 1,
    min_price INT NOT NULL DEFAULT 15, max_price INT NOT NULL DEFAULT 20, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
);
CREATE TABLE IF NOT EXISTS user_fruit_inventory (
    user_id VARCHAR(64) NOT NULL, fruit_id VARCHAR(80) NOT NULL, quantity BIGINT NOT NULL DEFAULT 0,
    discovered_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(user_id, fruit_id)
);
CREATE TABLE IF NOT EXISTS user_fruit_discoveries (
    user_id VARCHAR(64) NOT NULL, fruit_id VARCHAR(80) NOT NULL, discovered_at DATETIME NOT NULL,
    PRIMARY KEY(user_id, fruit_id)
);
CREATE TABLE IF NOT EXISTS user_fruit_pending (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(64) NOT NULL, fruit_id VARCHAR(80) NOT NULL,
    created_at DATETIME NOT NULL, INDEX idx_fruit_pending_user(user_id, id)
);
CREATE TABLE IF NOT EXISTS user_fruit_legacy (
    user_id VARCHAR(64) NOT NULL PRIMARY KEY,
    remaining BIGINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL
);
CREATE TABLE IF NOT EXISTS fruit_daily_prices (
    fruit_id VARCHAR(80) NOT NULL, price_day DATE NOT NULL, price INT NOT NULL,
    PRIMARY KEY(fruit_id, price_day), INDEX idx_fruit_prices_day(price_day)
);
CREATE TABLE IF NOT EXISTS fruit_sale_operations (
    user_id VARCHAR(64) NOT NULL, operation_id VARCHAR(128) NOT NULL,
    fruit_id VARCHAR(80) NOT NULL, quantity BIGINT NOT NULL,
    unit_price INT NOT NULL, total BIGINT NOT NULL, created_at DATETIME NOT NULL,
    PRIMARY KEY(user_id, operation_id)
);
CREATE TABLE IF NOT EXISTS fruit_pick_operations (
    user_id VARCHAR(64) NOT NULL, operation_id VARCHAR(128) NOT NULL,
    pending_id BIGINT NOT NULL, fruit_id VARCHAR(80) NOT NULL, quantity INT NOT NULL, created_at DATETIME NOT NULL,
    PRIMARY KEY(user_id, operation_id)
);
