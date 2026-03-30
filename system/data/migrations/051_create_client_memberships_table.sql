-- Client membership instances (one per client per plan). start/end and status support renewal and expiry.
CREATE TABLE client_memberships (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    membership_definition_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    starts_at DATE NOT NULL,
    ends_at DATE NOT NULL,
    status ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_client_memberships_client (client_id),
    INDEX idx_client_memberships_definition (membership_definition_id),
    INDEX idx_client_memberships_branch_status (branch_id, status),
    INDEX idx_client_memberships_ends_at (ends_at),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    FOREIGN KEY (membership_definition_id) REFERENCES membership_definitions(id) ON DELETE RESTRICT,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
