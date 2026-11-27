CREATE TABLE IF NOT EXISTS pet_social_lock (id INT PRIMARY KEY) ENGINE=InnoDB;
INSERT IGNORE INTO pet_social_lock (id) VALUES (1);
CREATE TABLE IF NOT EXISTS pet_profiles (
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 friend_code VARCHAR(16) NOT NULL UNIQUE, name VARCHAR(80) NOT NULL,
 outfit_json TEXT NOT NULL, revision INT NOT NULL DEFAULT 0,
 coins INT NOT NULL DEFAULT 20, reward_day VARCHAR(10) NOT NULL DEFAULT '',
 CONSTRAINT fk_pet_owner FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS pet_friends (
 pair_id VARCHAR(130) PRIMARY KEY, requester VARCHAR(64) NOT NULL,
 recipient VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS pet_sessions (
 id VARCHAR(32) PRIMARY KEY, kind VARCHAR(16) NOT NULL,
 sender VARCHAR(64) NOT NULL, recipient VARCHAR(64) NOT NULL,
 status VARCHAR(16) NOT NULL, expires_at BIGINT NOT NULL,
 duration_seconds INT NOT NULL, started_at BIGINT NOT NULL DEFAULT 0,
 KEY ix_pet_sessions_sender(sender,status), KEY ix_pet_sessions_recipient(recipient,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS pet_operations (
 user_id VARCHAR(64) NOT NULL, operation_id VARCHAR(64) NOT NULL,
 body_hash VARCHAR(64) NOT NULL, created_at BIGINT NOT NULL,
 PRIMARY KEY(user_id,operation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS pet_events (
 id VARCHAR(32) PRIMARY KEY, sender VARCHAR(64) NOT NULL,
 recipient VARCHAR(64) NOT NULL, kind VARCHAR(16) NOT NULL,
 created_at BIGINT NOT NULL, KEY ix_pet_events_time(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
