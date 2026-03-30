CREATE TABLE register_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
    opened_by BIGINT UNSIGNED NOT NULL,
    closed_by BIGINT UNSIGNED NULL,
    opened_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    opening_cash_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    closing_cash_amount DECIMAL(12,2) NULL,
    expected_cash_amount DECIMAL(12,2) NULL,
    variance_amount DECIMAL(12,2) NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_register_sessions_branch_status_opened (branch_id, status, opened_at),
    INDEX idx_register_sessions_opened_by (opened_by),
    INDEX idx_register_sessions_closed_by (closed_by),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cash_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    register_session_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    type ENUM('cash_in','cash_out') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cash_movements_session_created (register_session_id, created_at),
    INDEX idx_cash_movements_branch_type_created (branch_id, type, created_at),
    FOREIGN KEY (register_session_id) REFERENCES register_sessions(id) ON DELETE RESTRICT,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payments
    ADD COLUMN register_session_id BIGINT UNSIGNED NULL AFTER invoice_id,
    ADD INDEX idx_payments_register_session (register_session_id),
    ADD CONSTRAINT fk_payments_register_session
        FOREIGN KEY (register_session_id) REFERENCES register_sessions(id) ON DELETE SET NULL;
