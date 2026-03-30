-- Operational membership benefit usage: one row per appointment claim (idempotent per appointment).
CREATE TABLE membership_benefit_usages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_membership_id BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_membership_benefit_usages_appointment (appointment_id),
    INDEX idx_mbu_client_membership (client_membership_id),
    INDEX idx_mbu_client (client_id),
    FOREIGN KEY (client_membership_id) REFERENCES client_memberships(id) ON DELETE RESTRICT,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE RESTRICT,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE appointments
    ADD COLUMN client_membership_id BIGINT UNSIGNED NULL AFTER series_id,
    ADD INDEX idx_appointments_client_membership (client_membership_id),
    ADD CONSTRAINT fk_appointments_client_membership
        FOREIGN KEY (client_membership_id) REFERENCES client_memberships(id) ON DELETE SET NULL;
