-- Staff (users table) password reset: single-use token rows + request log for abuse throttling.

CREATE TABLE user_password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_password_reset_token_hash (token_hash),
    KEY idx_user_password_reset_user_active (user_id, used_at, expires_at),
    CONSTRAINT fk_user_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_password_reset_request_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    normalized_email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_uprrl_email_created (normalized_email, created_at),
    KEY idx_uprrl_ip_created (ip_address, created_at),
    KEY idx_uprrl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
