-- Marketing Special Offers Foundation
-- Branch-scoped promotions/offres speciales catalog used by Marketing module.

CREATE TABLE marketing_special_offers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    code VARCHAR(60) NOT NULL,
    origin VARCHAR(60) NOT NULL DEFAULT 'manual',
    adjustment_type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    adjustment_value DECIMAL(12,2) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mkt_special_offers_branch_deleted (branch_id, deleted_at),
    INDEX idx_mkt_special_offers_branch_sort (branch_id, sort_order, id),
    INDEX idx_mkt_special_offers_branch_code (branch_id, code),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

