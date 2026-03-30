ALTER TABLE clients
    ADD COLUMN preferred_contact_method VARCHAR(20) NULL AFTER gender,
    ADD COLUMN marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0 AFTER preferred_contact_method;

CREATE TABLE client_registration_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    full_name VARCHAR(200) NOT NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(255) NULL,
    notes TEXT NULL,
    source VARCHAR(50) NOT NULL DEFAULT 'manual',
    status ENUM('new','reviewed','converted','rejected') NOT NULL DEFAULT 'new',
    linked_client_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_client_registration_requests_status (status),
    INDEX idx_client_registration_requests_branch_status (branch_id, status),
    INDEX idx_client_registration_requests_email (email),
    INDEX idx_client_registration_requests_phone (phone),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (linked_client_id) REFERENCES clients(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_issue_flags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    type VARCHAR(50) NOT NULL,
    status ENUM('open','resolved') NOT NULL DEFAULT 'open',
    title VARCHAR(200) NOT NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    resolved_by BIGINT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_client_issue_flags_client_status (client_id, status, created_at),
    INDEX idx_client_issue_flags_branch_status (branch_id, status, created_at),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
