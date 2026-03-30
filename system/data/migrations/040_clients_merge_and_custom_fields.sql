ALTER TABLE clients
    ADD COLUMN merged_into_client_id BIGINT UNSIGNED NULL AFTER updated_by,
    ADD COLUMN merged_at DATETIME NULL AFTER merged_into_client_id,
    ADD CONSTRAINT fk_clients_merged_into FOREIGN KEY (merged_into_client_id) REFERENCES clients(id) ON DELETE SET NULL,
    ADD INDEX idx_clients_merged_into (merged_into_client_id);

CREATE TABLE client_field_definitions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    field_key VARCHAR(100) NOT NULL,
    label VARCHAR(150) NOT NULL,
    field_type ENUM('text','textarea','number','date','select','boolean') NOT NULL DEFAULT 'text',
    options_json TEXT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uk_client_field_definitions_branch_key (branch_id, field_key),
    INDEX idx_client_field_definitions_active (branch_id, is_active, sort_order),
    INDEX idx_client_field_definitions_deleted (deleted_at),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_field_values (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    field_definition_id BIGINT UNSIGNED NOT NULL,
    value_text TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_client_field_values_client_field (client_id, field_definition_id),
    INDEX idx_client_field_values_field (field_definition_id),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (field_definition_id) REFERENCES client_field_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
