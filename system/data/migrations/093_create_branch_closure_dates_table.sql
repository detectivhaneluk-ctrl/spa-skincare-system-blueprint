CREATE TABLE branch_closure_dates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
    closure_date DATE NOT NULL,
    title VARCHAR(150) NOT NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    live_closure_date DATE AS (IF(deleted_at IS NULL, closure_date, NULL)) STORED,
    INDEX idx_branch_closure_dates_branch_date (branch_id, closure_date),
    INDEX idx_branch_closure_dates_deleted (deleted_at),
    CONSTRAINT fk_branch_closure_dates_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    CONSTRAINT fk_branch_closure_dates_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT uk_branch_closure_dates_live UNIQUE (branch_id, live_closure_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
