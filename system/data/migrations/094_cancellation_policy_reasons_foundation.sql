CREATE TABLE appointment_cancellation_reasons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(120) NOT NULL,
    applies_to ENUM('cancellation', 'no_show', 'both') NOT NULL DEFAULT 'cancellation',
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    live_code VARCHAR(64) AS (IF(deleted_at IS NULL, code, NULL)) STORED,
    INDEX idx_appt_cancel_reasons_org_scope (organization_id, branch_id, applies_to, is_active),
    INDEX idx_appt_cancel_reasons_deleted (deleted_at),
    CONSTRAINT fk_appt_cancel_reasons_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT uk_appt_cancel_reasons_live_code UNIQUE (organization_id, branch_id, live_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE appointments
    ADD COLUMN cancellation_reason_id BIGINT UNSIGNED NULL AFTER notes,
    ADD COLUMN no_show_reason_id BIGINT UNSIGNED NULL AFTER cancellation_reason_id,
    ADD INDEX idx_appointments_cancellation_reason (cancellation_reason_id),
    ADD INDEX idx_appointments_no_show_reason (no_show_reason_id),
    ADD CONSTRAINT fk_appointments_cancellation_reason FOREIGN KEY (cancellation_reason_id) REFERENCES appointment_cancellation_reasons(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_appointments_no_show_reason FOREIGN KEY (no_show_reason_id) REFERENCES appointment_cancellation_reasons(id) ON DELETE SET NULL;

