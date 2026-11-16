-- Divya
-- Table for 2fa login, and codes
CREATE TABLE 2fa (
    token VARCHAR(64) PRIMARY KEY,
    user_id INT NOT NULL,
    code VARCHAR(6) NULL,
    code_expires_at BIGINT NULL,
    last_sent_at BIGINT NOT NULL DEFAULT 0,
    attempts INT NOT NULL DEFAULT 0,
    expires_at BIGINT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
