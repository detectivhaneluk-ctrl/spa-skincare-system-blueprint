-- VAT types: global (branch_id NULL) and branch-specific.
-- services.vat_rate_id can reference this table; FK not added here to preserve backward compatibility with existing data.

CREATE TABLE vat_rates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(100) NOT NULL,
    rate_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_vat_rates_branch_code (branch_id, code),
    INDEX idx_vat_rates_branch_active (branch_id, is_active),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
