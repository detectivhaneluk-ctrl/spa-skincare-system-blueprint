CREATE TABLE package_usages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_package_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    usage_type ENUM('use','adjustment','reverse','expire','cancel') NOT NULL,
    quantity INT NOT NULL,
    remaining_after INT NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_package_usages_client_package_created (client_package_id, created_at),
    INDEX idx_package_usages_branch_type_created (branch_id, usage_type, created_at),
    INDEX idx_package_usages_reference (reference_type, reference_id),
    FOREIGN KEY (client_package_id) REFERENCES client_packages(id) ON DELETE RESTRICT,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
