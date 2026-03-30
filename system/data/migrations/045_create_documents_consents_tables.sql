-- Document/consent definitions (templates) per branch. Metadata only; no file storage in this phase.
CREATE TABLE document_definitions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    valid_duration_days INT NULL COMMENT 'NULL = no expiry after sign',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uk_document_definitions_branch_code (branch_id, code),
    INDEX idx_document_definitions_branch_active (branch_id, is_active),
    INDEX idx_document_definitions_deleted (deleted_at),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Client consent records: one current row per client per definition (re-sign updates row).
CREATE TABLE client_consents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    document_definition_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','signed','expired','revoked') NOT NULL DEFAULT 'pending',
    signed_at DATETIME NULL,
    expires_at DATE NULL,
    branch_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_client_consents_client_definition (client_id, document_definition_id),
    INDEX idx_client_consents_client (client_id),
    INDEX idx_client_consents_definition (document_definition_id),
    INDEX idx_client_consents_branch_status (branch_id, status),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (document_definition_id) REFERENCES document_definitions(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which consents are required for a service (e.g. treatment consent for a given service).
CREATE TABLE service_required_consents (
    service_id BIGINT UNSIGNED NOT NULL,
    document_definition_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (service_id, document_definition_id),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY (document_definition_id) REFERENCES document_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
