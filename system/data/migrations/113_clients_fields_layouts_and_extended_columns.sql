-- CLIENTS-FIELDS-AND-PAGE-LAYOUTS-FOUNDATION-01: extended client columns, layout tables, field_type flexibility

ALTER TABLE clients
    ADD COLUMN anniversary DATE NULL AFTER birth_date,
    ADD COLUMN occupation VARCHAR(200) NULL AFTER anniversary,
    ADD COLUMN language VARCHAR(50) NULL AFTER occupation,
    ADD COLUMN receive_emails TINYINT(1) NOT NULL DEFAULT 0 AFTER marketing_opt_in,
    ADD COLUMN receive_sms TINYINT(1) NOT NULL DEFAULT 0 AFTER receive_emails,
    ADD COLUMN booking_alert VARCHAR(500) NULL AFTER receive_sms,
    ADD COLUMN check_in_alert VARCHAR(500) NULL AFTER booking_alert,
    ADD COLUMN check_out_alert VARCHAR(500) NULL AFTER check_in_alert,
    ADD COLUMN referral_information TEXT NULL AFTER check_out_alert,
    ADD COLUMN referral_history TEXT NULL AFTER referral_information,
    ADD COLUMN referred_by VARCHAR(200) NULL AFTER referral_history,
    ADD COLUMN customer_origin VARCHAR(120) NULL AFTER referred_by,
    ADD COLUMN emergency_contact_name VARCHAR(200) NULL AFTER customer_origin,
    ADD COLUMN emergency_contact_phone VARCHAR(50) NULL AFTER emergency_contact_name,
    ADD COLUMN emergency_contact_relationship VARCHAR(120) NULL AFTER emergency_contact_phone,
    ADD COLUMN inactive_flag TINYINT(1) NOT NULL DEFAULT 0 AFTER emergency_contact_relationship;

UPDATE clients SET receive_emails = IFNULL(marketing_opt_in, 0);

ALTER TABLE client_field_definitions
    MODIFY COLUMN field_type VARCHAR(32) NOT NULL DEFAULT 'text';

CREATE TABLE client_page_layout_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    profile_key VARCHAR(64) NOT NULL,
    display_label VARCHAR(150) NOT NULL,
    is_runtime_consumed TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_client_page_layout_profiles_org_key (organization_id, profile_key),
    INDEX idx_client_page_layout_profiles_org (organization_id),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_page_layout_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    profile_id BIGINT UNSIGNED NOT NULL,
    field_key VARCHAR(120) NOT NULL,
    position INT NOT NULL DEFAULT 0,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_client_page_layout_items_profile_field (profile_id, field_key),
    INDEX idx_client_page_layout_items_profile_pos (profile_id, position),
    FOREIGN KEY (profile_id) REFERENCES client_page_layout_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
