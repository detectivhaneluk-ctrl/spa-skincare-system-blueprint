-- Canonical schema snapshot for current implemented modules.
-- Primarily CREATE TABLE statements; includes one bootstrap INSERT for the default organization
-- so `branches.organization_id` (NOT NULL, FK) can be satisfied on empty installs (086 / FOUNDATION-08).
-- Includes migration-level artifacts through 087 (organization registry + membership pivot / FOUNDATION-38).
-- Snapshot omits some cross-table FKs where CREATE order would not allow them; migrations are authoritative for constraints.

SET NAMES utf8mb4;

CREATE TABLE organizations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    suspended_at TIMESTAMP NULL DEFAULT NULL,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uk_organizations_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE branches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(50) NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uk_branches_code (code),
    INDEX idx_branches_organization (organization_id),
    CONSTRAINT fk_branches_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE branch_operating_hours (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
    day_of_week TINYINT NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_branch_operating_hours_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    CONSTRAINT uk_branch_operating_hours_branch_day UNIQUE (branch_id, day_of_week),
    CONSTRAINT chk_branch_operating_hours_day CHECK (day_of_week BETWEEN 0 AND 6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO organizations (name, code)
SELECT 'Default organization', NULL
FROM DUAL
WHERE (SELECT COUNT(*) FROM organizations) = 0;

CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    password_changed_at TIMESTAMP NULL DEFAULT NULL,
    control_plane_totp_secret_ciphertext VARBINARY(512) NULL DEFAULT NULL,
    control_plane_totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    session_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    name VARCHAR(255) NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_organization_memberships (
    user_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    default_branch_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, organization_id),
    CONSTRAINT fk_user_organization_memberships_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_organization_memberships_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_user_organization_memberships_default_branch FOREIGN KEY (default_branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    KEY idx_user_organization_memberships_organization_id (organization_id),
    KEY idx_user_organization_memberships_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL,
    `value` TEXT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'string',
    setting_group VARCHAR(50) NULL,
    organization_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_settings_key_org_branch (`key`, organization_id, branch_id),
    INDEX idx_settings_group (setting_group),
    INDEX idx_settings_organization (organization_id),
    INDEX idx_settings_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(50) NOT NULL,
    outcome VARCHAR(24) NULL DEFAULT NULL,
    action_category VARCHAR(64) NULL DEFAULT NULL,
    target_type VARCHAR(100) NOT NULL DEFAULT 'unknown',
    target_id BIGINT UNSIGNED NULL,
    branch_id BIGINT UNSIGNED NULL,
    organization_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    metadata_json JSON NULL,
    request_id VARCHAR(64) NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    INDEX idx_actor_created (actor_user_id, created_at),
    INDEX idx_target (target_type, target_id),
    INDEX idx_branch_created (branch_id, created_at),
    INDEX idx_audit_logs_org_created (organization_id, created_at),
    INDEX idx_audit_logs_request (request_id),
    INDEX idx_audit_logs_outcome_action (outcome, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier_created (identifier, created_at),
    INDEX idx_login_attempts_ip_created (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_password_reset_token_hash (token_hash),
    KEY idx_user_password_reset_user_active (user_id, used_at, expires_at),
    CONSTRAINT fk_user_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_password_reset_request_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    normalized_email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_uprrl_email_created (normalized_email, created_at),
    KEY idx_uprrl_ip_created (ip_address, created_at),
    KEY idx_uprrl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE public_booking_abuse_hits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket VARCHAR(64) NOT NULL,
    throttle_key VARCHAR(191) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_public_booking_abuse_bucket_key_created (bucket, throttle_key, created_at),
    INDEX idx_public_booking_abuse_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NULL,
    entity_type VARCHAR(100) NULL,
    entity_id BIGINT UNSIGNED NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    INDEX idx_notifications_branch_created (branch_id, created_at),
    INDEX idx_notifications_user_read (user_id, is_read),
    INDEX idx_notifications_entity (entity_type, entity_id),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_reads (
    notification_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id, user_id),
    FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notification_reads_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(50) NULL,
    phone_digits VARCHAR(32) NULL,
    phone_home VARCHAR(50) NULL,
    phone_home_digits VARCHAR(32) NULL,
    phone_mobile VARCHAR(50) NULL,
    phone_mobile_digits VARCHAR(32) NULL,
    mobile_operator VARCHAR(100) NULL,
    phone_work VARCHAR(50) NULL,
    phone_work_digits VARCHAR(32) NULL,
    phone_work_ext VARCHAR(30) NULL,
    email VARCHAR(255) NULL,
    email_lc VARCHAR(255) NULL,
    birth_date DATE NULL,
    anniversary DATE NULL,
    gender VARCHAR(20) NULL,
    occupation VARCHAR(200) NULL,
    language VARCHAR(50) NULL,
    preferred_contact_method VARCHAR(20) NULL,
    marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,
    receive_emails TINYINT(1) NOT NULL DEFAULT 0,
    receive_sms TINYINT(1) NOT NULL DEFAULT 0,
    booking_alert VARCHAR(500) NULL,
    check_in_alert VARCHAR(500) NULL,
    check_out_alert VARCHAR(500) NULL,
    referral_information TEXT NULL,
    referral_history TEXT NULL,
    referred_by VARCHAR(200) NULL,
    customer_origin VARCHAR(120) NULL,
    emergency_contact_name VARCHAR(200) NULL,
    emergency_contact_phone VARCHAR(50) NULL,
    emergency_contact_relationship VARCHAR(120) NULL,
    inactive_flag TINYINT(1) NOT NULL DEFAULT 0,
    home_address_1 VARCHAR(255) NULL,
    home_address_2 VARCHAR(255) NULL,
    home_city VARCHAR(120) NULL,
    home_postal_code VARCHAR(32) NULL,
    home_country VARCHAR(100) NULL,
    delivery_same_as_home TINYINT(1) NOT NULL DEFAULT 0,
    delivery_address_1 VARCHAR(255) NULL,
    delivery_address_2 VARCHAR(255) NULL,
    delivery_city VARCHAR(120) NULL,
    delivery_postal_code VARCHAR(32) NULL,
    delivery_country VARCHAR(100) NULL,
    notes TEXT NULL,
    branch_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    merged_into_client_id BIGINT UNSIGNED NULL,
    merged_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (merged_into_client_id) REFERENCES clients(id) ON DELETE SET NULL,
    INDEX idx_clients_branch (branch_id),
    INDEX idx_clients_deleted (deleted_at),
    INDEX idx_clients_name (last_name, first_name),
    INDEX idx_clients_email (email),
    INDEX idx_clients_phone (phone),
    INDEX idx_clients_merged_into (merged_into_client_id),
    INDEX idx_clients_branch_deleted_name (branch_id, deleted_at, last_name, first_name),
    INDEX idx_clients_email_lc (email_lc),
    INDEX idx_clients_phone_digits (phone_digits),
    INDEX idx_clients_phone_home_digits (phone_home_digits),
    INDEX idx_clients_phone_mobile_digits (phone_mobile_digits),
    INDEX idx_clients_phone_work_digits (phone_work_digits)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_client_notes_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_field_definitions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    field_key VARCHAR(100) NOT NULL,
    label VARCHAR(150) NOT NULL,
    field_type VARCHAR(32) NOT NULL DEFAULT 'text',
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

-- CLIENT-MERGE-ASYNC-JOB-HARDENING-01 (aligned with migration 120_client_merge_jobs.sql)
CREATE TABLE client_merge_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id INT UNSIGNED NOT NULL,
    branch_id INT UNSIGNED NOT NULL,
    primary_client_id INT UNSIGNED NOT NULL,
    secondary_client_id INT UNSIGNED NOT NULL,
    requested_by_user_id INT UNSIGNED NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'queued',
    current_step VARCHAR(64) NULL,
    error_code VARCHAR(64) NULL,
    error_message_public VARCHAR(512) NULL,
    error_detail_internal TEXT NULL,
    merge_notes TEXT NULL,
    result_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_client_merge_jobs_org_status (organization_id, status, id),
    KEY idx_client_merge_jobs_pair_active (organization_id, primary_client_id, secondary_client_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(255) NULL,
    job_title VARCHAR(100) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    branch_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_staff_branch (branch_id),
    INDEX idx_staff_active (is_active),
    INDEX idx_staff_user (user_id),
    INDEX idx_staff_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_schedules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id BIGINT UNSIGNED NOT NULL,
    day_of_week TINYINT NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
    INDEX idx_staff_schedules_staff (staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_breaks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id BIGINT UNSIGNED NOT NULL,
    day_of_week TINYINT NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    title VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
    INDEX idx_staff_breaks_staff_dow (staff_id, day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_availability_exceptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    exception_date DATE NOT NULL,
    kind VARCHAR(20) NOT NULL COMMENT 'closed=full day off, open=working segment for this date, unavailable=time off within working hours',
    start_time TIME NULL,
    end_time TIME NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    INDEX idx_staff_avail_ex_staff_date (staff_id, exception_date),
    INDEX idx_staff_avail_ex_date (exception_date),
    INDEX idx_staff_avail_ex_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    branch_id BIGINT UNSIGNED NULL,
    parent_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_id) REFERENCES service_categories(id) ON DELETE SET NULL,
    INDEX idx_service_categories_deleted (deleted_at),
    INDEX idx_service_categories_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rooms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    maintenance_mode TINYINT(1) NOT NULL DEFAULT 0,
    branch_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    UNIQUE KEY uk_rooms_code_branch (code, branch_id),
    INDEX idx_rooms_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE equipment (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) NULL,
    serial_number VARCHAR(100) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    maintenance_mode TINYINT(1) NOT NULL DEFAULT 0,
    branch_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    UNIQUE KEY uk_equipment_code_branch (code, branch_id),
    INDEX idx_equipment_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE services (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id BIGINT UNSIGNED NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    duration_minutes INT NOT NULL DEFAULT 60,
    buffer_before_minutes INT NOT NULL DEFAULT 0,
    buffer_after_minutes INT NOT NULL DEFAULT 0,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    vat_rate_id BIGINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    branch_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (category_id) REFERENCES service_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_services_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_staff (
    service_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (service_id, staff_id),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_staff_groups_branch_active (branch_id, is_active),
    INDEX idx_staff_groups_name (name),
    INDEX idx_staff_groups_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_group_members (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_group_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_group_id) REFERENCES staff_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_staff_group_members_pair (staff_group_id, staff_id),
    INDEX idx_staff_group_members_staff (staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_group_permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_group_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    FOREIGN KEY (staff_group_id) REFERENCES staff_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    UNIQUE KEY uq_staff_group_permissions_pair (staff_group_id, permission_id),
    INDEX idx_staff_group_permissions_group (staff_group_id),
    INDEX idx_staff_group_permissions_permission (permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_staff_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_id BIGINT UNSIGNED NOT NULL,
    staff_group_id BIGINT UNSIGNED NOT NULL,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY (staff_group_id) REFERENCES staff_groups(id) ON DELETE CASCADE,
    UNIQUE KEY uq_service_staff_groups_pair (service_id, staff_group_id),
    INDEX idx_service_staff_groups_service (service_id),
    INDEX idx_service_staff_groups_staff_group (staff_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_rooms (
    service_id BIGINT UNSIGNED NOT NULL,
    room_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (service_id, room_id),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_equipment (
    service_id BIGINT UNSIGNED NOT NULL,
    equipment_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (service_id, equipment_id),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointment_series (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    recurrence_type ENUM('weekly', 'biweekly') NOT NULL,
    interval_weeks TINYINT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    occurrences_count INT UNSIGNED NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    status ENUM('active', 'cancelled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE RESTRICT,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE RESTRICT,
    INDEX idx_appointment_series_branch_status (branch_id, status),
    INDEX idx_appointment_series_staff_start (staff_id, start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NULL,
    staff_id BIGINT UNSIGNED NULL,
    room_id BIGINT UNSIGNED NULL,
    branch_id BIGINT UNSIGNED NULL,
    series_id BIGINT UNSIGNED NULL,
    client_membership_id BIGINT UNSIGNED NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'scheduled',
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE SET NULL,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (series_id) REFERENCES appointment_series(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_appointments_branch (branch_id),
    INDEX idx_appointments_client (client_id),
    INDEX idx_appointments_staff_start (staff_id, start_at),
    INDEX idx_appointments_room_start (room_id, start_at),
    INDEX idx_appointments_deleted (deleted_at),
    INDEX idx_appointments_status (status),
    INDEX idx_appointments_series (series_id),
    UNIQUE KEY uq_appointments_series_start (series_id, start_at),
    INDEX idx_appointments_client_membership (client_membership_id),
    INDEX idx_appointments_branch_staff_range (branch_id, staff_id, start_at, end_at),
    INDEX idx_appointments_branch_room_range (branch_id, room_id, start_at, end_at),
    INDEX idx_appointments_branch_deleted_start (branch_id, deleted_at, start_at),
    INDEX idx_appointments_staff_deleted_start (staff_id, deleted_at, start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE public_booking_manage_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    appointment_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    last_used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_public_booking_manage_appointment (appointment_id),
    UNIQUE KEY uq_public_booking_manage_token_hash (token_hash),
    KEY idx_public_booking_manage_lookup (token_hash, revoked_at, expires_at),
    KEY idx_public_booking_manage_branch (branch_id),
    CONSTRAINT fk_public_booking_manage_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    CONSTRAINT fk_public_booking_manage_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointment_waitlist (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    client_id BIGINT UNSIGNED NULL,
    service_id BIGINT UNSIGNED NULL,
    preferred_staff_id BIGINT UNSIGNED NULL,
    preferred_date DATE NOT NULL,
    preferred_time_from TIME NULL,
    preferred_time_to TIME NULL,
    notes TEXT NULL,
    offer_started_at TIMESTAMP NULL DEFAULT NULL,
    offer_expires_at TIMESTAMP NULL DEFAULT NULL,
    status ENUM('waiting','offered','matched','booked','cancelled') NOT NULL DEFAULT 'waiting',
    created_by BIGINT UNSIGNED NULL,
    matched_appointment_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_waitlist_branch_status_date (branch_id, status, preferred_date),
    INDEX idx_waitlist_client (client_id),
    INDEX idx_waitlist_service (service_id),
    INDEX idx_waitlist_staff (preferred_staff_id),
    INDEX idx_waitlist_matched_appointment (matched_appointment_id),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
    FOREIGN KEY (preferred_staff_id) REFERENCES staff(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (matched_appointment_id) REFERENCES appointments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointment_blocked_slots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    staff_id BIGINT UNSIGNED NULL,
    title VARCHAR(150) NOT NULL,
    block_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    INDEX idx_blocked_slots_date_branch_staff (block_date, branch_id, staff_id),
    INDEX idx_blocked_slots_deleted (deleted_at),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_definitions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    valid_duration_days INT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uk_document_definitions_branch_code (branch_id, code),
    INDEX idx_document_definitions_branch_active (branch_id, is_active),
    INDEX idx_document_definitions_deleted (deleted_at),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE service_required_consents (
    service_id BIGINT UNSIGNED NOT NULL,
    document_definition_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (service_id, document_definition_id),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY (document_definition_id) REFERENCES document_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    extension VARCHAR(20) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    storage_disk VARCHAR(50) NOT NULL DEFAULT 'local',
    storage_path VARCHAR(500) NOT NULL,
    checksum_sha256 CHAR(64) NULL,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    uploaded_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_documents_storage_path (storage_path),
    INDEX idx_documents_branch_status (branch_id, status),
    INDEX idx_documents_deleted (deleted_at),
    INDEX idx_documents_checksum (checksum_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    owner_type ENUM('client','appointment','invoice','staff') NOT NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    status ENUM('active','detached') NOT NULL DEFAULT 'active',
    linked_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (linked_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_document_links_active_owner (document_id, owner_type, owner_id, status),
    INDEX idx_document_links_owner (owner_type, owner_id, status),
    INDEX idx_document_links_document_status (document_id, status),
    INDEX idx_document_links_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) NOT NULL,
    client_id BIGINT UNSIGNED NULL,
    appointment_id BIGINT UNSIGNED NULL,
    branch_id BIGINT UNSIGNED NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    subtotal_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    issued_at TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uk_invoices_number (invoice_number),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_invoices_branch (branch_id),
    INDEX idx_invoices_client (client_id),
    INDEX idx_invoices_status (status),
    INDEX idx_invoices_deleted (deleted_at),
    INDEX idx_invoices_branch_deleted_created (branch_id, deleted_at, created_at),
    INDEX idx_invoices_client_deleted_created (client_id, deleted_at, created_at),
    INDEX idx_invoices_branch_deleted_issued_at (branch_id, deleted_at, issued_at),
    INDEX idx_invoices_client_deleted_issued_at (client_id, deleted_at, issued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NOT NULL,
    item_type VARCHAR(32) NOT NULL,
    source_id BIGINT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    line_meta JSON NULL,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    INDEX idx_invoice_items_invoice (invoice_id),
    INDEX idx_invoice_items_invoice_sort (invoice_id, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE register_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
    opened_by BIGINT UNSIGNED NOT NULL,
    closed_by BIGINT UNSIGNED NULL,
    opened_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    opening_cash_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    closing_cash_amount DECIMAL(12,2) NULL,
    expected_cash_amount DECIMAL(12,2) NULL,
    variance_amount DECIMAL(12,2) NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_register_sessions_branch_status_opened (branch_id, status, opened_at),
    INDEX idx_register_sessions_opened_by (opened_by),
    INDEX idx_register_sessions_closed_by (closed_by),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_number_sequences (
    organization_id BIGINT UNSIGNED NOT NULL,
    sequence_key VARCHAR(50) NOT NULL,
    next_number BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id, sequence_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Invoice counters: PK (organization_id, sequence_key). Row (0,invoice) is legacy depot from migration 043 — unused by new allocation.';

CREATE TABLE cash_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    register_session_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    type ENUM('cash_in','cash_out') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cash_movements_session_created (register_session_id, created_at),
    INDEX idx_cash_movements_branch_type_created (branch_id, type, created_at),
    FOREIGN KEY (register_session_id) REFERENCES register_sessions(id) ON DELETE RESTRICT,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NOT NULL,
    register_session_id BIGINT UNSIGNED NULL,
    entry_type ENUM('payment','refund') NOT NULL DEFAULT 'payment',
    parent_payment_id BIGINT UNSIGNED NULL,
    payment_method VARCHAR(30) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    transaction_reference VARCHAR(100) NULL,
    paid_at TIMESTAMP NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (register_session_id) REFERENCES register_sessions(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_payments_invoice (invoice_id),
    INDEX idx_payments_register_session (register_session_id),
    INDEX idx_payments_entry_type (entry_type),
    INDEX idx_payments_parent_payment (parent_payment_id),
    INDEX idx_payments_status (status),
    INDEX idx_payments_invoice_created (invoice_id, created_at),
    INDEX idx_payments_register_session_method_status (register_session_id, payment_method, status),
    INDEX idx_payments_parent_entry_status (parent_payment_id, entry_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_methods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_payment_methods_branch_code (branch_id, code),
    INDEX idx_payment_methods_branch_active (branch_id, is_active),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE product_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT UNSIGNED NULL,
    branch_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (parent_id) REFERENCES product_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    INDEX idx_product_categories_branch_deleted (branch_id, deleted_at),
    INDEX idx_product_categories_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_brands (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    UNIQUE KEY uk_product_brands_branch_name (branch_id, name),
    INDEX idx_product_brands_branch_deleted (branch_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    sku VARCHAR(100) NOT NULL,
    barcode VARCHAR(100) NULL,
    category VARCHAR(100) NULL,
    brand VARCHAR(100) NULL,
    product_category_id BIGINT UNSIGNED NULL,
    product_brand_id BIGINT UNSIGNED NULL,
    product_type VARCHAR(30) NOT NULL DEFAULT 'retail',
    cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    sell_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    vat_rate DECIMAL(5,2) NULL,
    stock_quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
    reorder_level DECIMAL(12,3) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    branch_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uk_products_sku (sku),
    INDEX idx_products_barcode (barcode),
    INDEX idx_products_branch_deleted (branch_id, deleted_at),
    INDEX idx_products_active (is_active),
    INDEX idx_products_type (product_type),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (product_category_id) REFERENCES product_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (product_brand_id) REFERENCES product_brands(id) ON DELETE SET NULL,
    INDEX idx_products_product_category (product_category_id),
    INDEX idx_products_product_brand (product_brand_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suppliers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    contact_name VARCHAR(150) NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(255) NULL,
    address TEXT NULL,
    notes TEXT NULL,
    branch_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    INDEX idx_suppliers_name (name),
    INDEX idx_suppliers_branch_deleted (branch_id, deleted_at),
    INDEX idx_suppliers_email (email),
    INDEX idx_suppliers_phone (phone),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    movement_type VARCHAR(30) NOT NULL,
    quantity DECIMAL(12,3) NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    branch_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_stock_movements_product_created (product_id, created_at),
    INDEX idx_stock_movements_branch_created (branch_id, created_at),
    INDEX idx_stock_movements_type_created (movement_type, created_at),
    INDEX idx_stock_movements_reference (reference_type, reference_id),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_counts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    expected_quantity DECIMAL(12,3) NOT NULL,
    counted_quantity DECIMAL(12,3) NOT NULL,
    variance_quantity DECIMAL(12,3) NOT NULL,
    notes TEXT NULL,
    branch_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_inventory_counts_product_created (product_id, created_at),
    INDEX idx_inventory_counts_branch_created (branch_id, created_at),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gift_cards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    client_id BIGINT UNSIGNED NULL,
    code VARCHAR(100) NOT NULL,
    original_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    issued_at DATETIME NOT NULL,
    expires_at DATETIME NULL,
    status ENUM('active','used','expired','cancelled') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uk_gift_cards_code (code),
    INDEX idx_gift_cards_branch_status (branch_id, status),
    INDEX idx_gift_cards_client (client_id),
    INDEX idx_gift_cards_expires (expires_at),
    INDEX idx_gift_cards_deleted (deleted_at),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gift_card_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gift_card_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    type ENUM('issue','redeem','adjustment','expire','cancel') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gct_gift_card_created (gift_card_id, created_at),
    INDEX idx_gct_branch_type_created (branch_id, type, created_at),
    INDEX idx_gct_reference (reference_type, reference_id),
    FOREIGN KEY (gift_card_id) REFERENCES gift_cards(id) ON DELETE RESTRICT,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE packages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    total_sessions INT UNSIGNED NOT NULL,
    validity_days INT UNSIGNED NULL,
    price DECIMAL(12,2) NULL,
    public_online_eligible TINYINT(1) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    INDEX idx_packages_branch_status (branch_id, status),
    INDEX idx_packages_deleted (deleted_at),
    INDEX idx_packages_name (name),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_packages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    package_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    assigned_sessions INT UNSIGNED NOT NULL,
    remaining_sessions INT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL,
    starts_at DATETIME NULL,
    expires_at DATETIME NULL,
    status ENUM('active','used','expired','cancelled') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    package_snapshot_json JSON NULL COMMENT 'Immutable package entitlement snapshot at assignment/fulfillment',
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_client_packages_package (package_id),
    INDEX idx_client_packages_client (client_id),
    INDEX idx_client_packages_branch_status (branch_id, status),
    INDEX idx_client_packages_expires (expires_at),
    FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE RESTRICT,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE membership_definitions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    duration_days INT UNSIGNED NOT NULL,
    price DECIMAL(12,2) NULL,
    billing_enabled TINYINT(1) NOT NULL DEFAULT 0,
    billing_interval_unit ENUM('day','week','month','year') NULL,
    billing_interval_count INT UNSIGNED NULL,
    renewal_price DECIMAL(12,2) NULL,
    renewal_invoice_due_days INT UNSIGNED NOT NULL DEFAULT 14,
    billing_auto_renew_enabled TINYINT(1) NOT NULL DEFAULT 1,
    benefits_json JSON NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    public_online_eligible TINYINT(1) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    INDEX idx_membership_definitions_branch_status (branch_id, status),
    INDEX idx_membership_definitions_deleted (deleted_at),
    INDEX idx_membership_definitions_name (name),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_memberships (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    membership_definition_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    starts_at DATE NOT NULL,
    ends_at DATE NOT NULL,
    next_billing_at DATE NULL,
    last_billed_at DATE NULL,
    billing_state ENUM('inactive','scheduled','invoiced','overdue') NOT NULL DEFAULT 'inactive',
    billing_auto_renew_enabled TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('active','expired','cancelled','paused') NOT NULL DEFAULT 'active',
    cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
    cancelled_at TIMESTAMP NULL,
    paused_at TIMESTAMP NULL,
    lifecycle_reason VARCHAR(500) NULL,
    notes TEXT NULL,
    entitlement_snapshot_json JSON NULL COMMENT 'Immutable entitlement truth at grant (from sale snapshot or manual assign moment)',
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_client_memberships_client (client_id),
    INDEX idx_client_memberships_definition (membership_definition_id),
    INDEX idx_client_memberships_client_def_branch (client_id, membership_definition_id, branch_id),
    INDEX idx_client_memberships_branch_status (branch_id, status),
    INDEX idx_client_memberships_ends_at (ends_at),
    INDEX idx_client_memberships_next_billing (next_billing_at, status, billing_state),
    INDEX idx_client_memberships_cancel_scheduled (cancel_at_period_end, status, ends_at),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    FOREIGN KEY (membership_definition_id) REFERENCES membership_definitions(id) ON DELETE RESTRICT,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE membership_billing_cycles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_membership_id BIGINT UNSIGNED NOT NULL,
    billing_period_start DATE NOT NULL,
    billing_period_end DATE NOT NULL,
    due_at DATE NOT NULL,
    invoice_id BIGINT UNSIGNED NULL,
    status ENUM('pending','invoiced','overdue','paid','void') NOT NULL DEFAULT 'pending',
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    renewal_applied_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mbc_membership_period (client_membership_id, billing_period_start, billing_period_end),
    INDEX idx_mbc_status_due (status, due_at),
    INDEX idx_mbc_invoice (invoice_id),
    FOREIGN KEY (client_membership_id) REFERENCES client_memberships(id) ON DELETE RESTRICT,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE membership_sales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    membership_definition_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    invoice_id BIGINT UNSIGNED NULL,
    client_membership_id BIGINT UNSIGNED NULL,
    status ENUM('draft','invoiced','paid','activated','void','cancelled','refund_review') NOT NULL DEFAULT 'draft',
    activation_applied_at TIMESTAMP NULL,
    starts_at DATE NULL,
    ends_at DATE NULL,
    sold_by_user_id BIGINT UNSIGNED NULL,
    definition_snapshot_json JSON NULL COMMENT 'Immutable snapshot of sold membership definition at sale creation',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_membership_sales_invoice (invoice_id),
    INDEX idx_membership_sales_client (client_id),
    INDEX idx_membership_sales_definition (membership_definition_id),
    INDEX idx_membership_sales_status (status),
    FOREIGN KEY (membership_definition_id) REFERENCES membership_definitions(id) ON DELETE RESTRICT,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    FOREIGN KEY (client_membership_id) REFERENCES client_memberships(id) ON DELETE SET NULL,
    FOREIGN KEY (sold_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE outbound_notification_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    channel ENUM('email','sms') NOT NULL,
    event_key VARCHAR(80) NOT NULL,
    template_key VARCHAR(80) NOT NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    recipient_type ENUM('client','staff','user','raw') NOT NULL,
    recipient_id BIGINT UNSIGNED NULL,
    recipient_address VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NULL,
    body_text MEDIUMTEXT NOT NULL,
    payload_json JSON NULL,
    entity_type VARCHAR(100) NULL,
    entity_id BIGINT UNSIGNED NULL,
    status ENUM('pending','processing','sent','failed','skipped','captured_locally','handoff_accepted') NOT NULL DEFAULT 'pending',
    skip_reason VARCHAR(500) NULL,
    error_summary VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    scheduled_at TIMESTAMP NULL,
    claimed_at TIMESTAMP NULL,
    sent_at TIMESTAMP NULL,
    failed_at TIMESTAMP NULL,
    UNIQUE KEY uk_outbound_idempotency (idempotency_key),
    KEY idx_outbound_status_created (status, created_at),
    KEY idx_outbound_processing_claimed (status, claimed_at),
    KEY idx_outbound_branch (branch_id),
    KEY idx_outbound_entity (entity_type, entity_id),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE outbound_notification_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    attempt_no INT UNSIGNED NOT NULL DEFAULT 1,
    transport VARCHAR(64) NOT NULL,
    status ENUM('success','failed') NOT NULL,
    error_text TEXT NULL,
    detail_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES outbound_notification_messages(id) ON DELETE CASCADE,
    KEY idx_outbound_attempts_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE intake_form_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    required_before_appointment TINYINT(1) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    INDEX idx_intake_templates_branch_active (branch_id, is_active, deleted_at),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE intake_form_template_fields (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id BIGINT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    field_key VARCHAR(64) NOT NULL,
    label VARCHAR(255) NOT NULL,
    field_type ENUM('text','textarea','checkbox','select','date','email','phone','number') NOT NULL,
    required TINYINT(1) NOT NULL DEFAULT 0,
    options_json JSON NULL,
    UNIQUE KEY uq_intake_field_template_key (template_id, field_key),
    INDEX idx_intake_fields_template_sort (template_id, sort_order),
    FOREIGN KEY (template_id) REFERENCES intake_form_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE intake_form_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NULL,
    branch_id BIGINT UNSIGNED NULL,
    status ENUM('pending','opened','completed','expired','cancelled') NOT NULL DEFAULT 'pending',
    token_hash CHAR(64) NOT NULL,
    token_expires_at TIMESTAMP NULL,
    assigned_by BIGINT UNSIGNED NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opened_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    cancelled_at TIMESTAMP NULL,
    cancel_reason VARCHAR(500) NULL,
    UNIQUE KEY uq_intake_assignment_token (token_hash),
    INDEX idx_intake_assign_client (client_id, status),
    INDEX idx_intake_assign_appt (appointment_id, status),
    INDEX idx_intake_assign_template (template_id),
    FOREIGN KEY (template_id) REFERENCES intake_form_templates(id) ON DELETE RESTRICT,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE intake_form_submissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id BIGINT UNSIGNED NOT NULL,
    template_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NULL,
    status ENUM('completed') NOT NULL DEFAULT 'completed',
    validation_errors_json JSON NULL,
    submitted_from ENUM('public_token','staff') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_intake_submission_assignment (assignment_id),
    INDEX idx_intake_submission_client (client_id),
    FOREIGN KEY (assignment_id) REFERENCES intake_form_assignments(id) ON DELETE RESTRICT,
    FOREIGN KEY (template_id) REFERENCES intake_form_templates(id) ON DELETE RESTRICT,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE intake_form_submission_values (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id BIGINT UNSIGNED NOT NULL,
    field_key VARCHAR(64) NOT NULL,
    value_text LONGTEXT NULL,
    UNIQUE KEY uq_intake_subval (submission_id, field_key),
    FOREIGN KEY (submission_id) REFERENCES intake_form_submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Public commerce purchases (see migrations 075–079, 088); canonical DDL for blueprint snapshot.
CREATE TABLE public_commerce_purchases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    client_resolution_reason VARCHAR(64) NULL DEFAULT NULL,
    product_kind ENUM('gift_card','package','membership') NOT NULL,
    package_id BIGINT UNSIGNED NULL,
    membership_definition_id BIGINT UNSIGNED NULL,
    package_snapshot_json JSON NULL COMMENT 'Immutable package snapshot at purchase initiation (public-commerce)',
    gift_card_amount DECIMAL(12,2) NULL,
    membership_sale_id BIGINT UNSIGNED NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    client_package_id BIGINT UNSIGNED NULL,
    gift_card_id BIGINT UNSIGNED NULL,
    fulfillment_applied_at TIMESTAMP NULL,
    fulfillment_reversed_at TIMESTAMP NULL,
    fulfillment_reconcile_recovery_at TIMESTAMP NULL,
    fulfillment_reconcile_recovery_trigger VARCHAR(64) NULL,
    fulfillment_reconcile_recovery_error TEXT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'initiated',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    finalize_attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    finalize_last_request_hash CHAR(64) NULL DEFAULT NULL,
    finalize_last_received_at TIMESTAMP NULL DEFAULT NULL,
    verification_queue_sort_at TIMESTAMP NOT NULL GENERATED ALWAYS AS (COALESCE(finalize_last_received_at, updated_at)) STORED COMMENT 'Queue sort key for awaiting_verification staff list (PUBLIC-COMMERCE-QUEUE-INDEX-HARDENING-01)',
    UNIQUE KEY uq_public_commerce_token_hash (token_hash),
    UNIQUE KEY uq_public_commerce_invoice (invoice_id),
    KEY idx_public_commerce_branch (branch_id),
    KEY idx_public_commerce_client (client_id),
    KEY idx_pc_purchase_fulfillment_recovery (fulfillment_reconcile_recovery_at),
    KEY idx_pc_verification_queue_branch_status (branch_id, status, verification_queue_sort_at DESC, id DESC),
    KEY idx_pc_verification_queue_status_sort (status, verification_queue_sort_at DESC, id DESC),
    CONSTRAINT fk_public_commerce_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    CONSTRAINT fk_public_commerce_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_public_commerce_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL,
    CONSTRAINT fk_public_commerce_membership_def FOREIGN KEY (membership_definition_id) REFERENCES membership_definitions(id) ON DELETE SET NULL,
    CONSTRAINT fk_public_commerce_membership_sale FOREIGN KEY (membership_sale_id) REFERENCES membership_sales(id) ON DELETE SET NULL,
    CONSTRAINT fk_public_commerce_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
    CONSTRAINT fk_public_commerce_client_package FOREIGN KEY (client_package_id) REFERENCES client_packages(id) ON DELETE SET NULL,
    CONSTRAINT fk_public_commerce_gift_card FOREIGN KEY (gift_card_id) REFERENCES gift_cards(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE marketing_contact_lists (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    archived_at TIMESTAMP NULL,
    archived_by BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_marketing_contact_lists_branch (branch_id),
    INDEX idx_marketing_contact_lists_archived (archived_at),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE marketing_contact_list_members (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    list_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_marketing_contact_list_members_list_client (list_id, client_id),
    INDEX idx_marketing_contact_list_members_client (client_id),
    FOREIGN KEY (list_id) REFERENCES marketing_contact_lists(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marketing automations (migration 099; end-state aligned with stamped migrations)
CREATE TABLE marketing_automations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
    automation_key VARCHAR(80) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    config_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_marketing_automation_branch_key (branch_id, automation_key),
    KEY idx_marketing_automation_branch (branch_id),
    KEY idx_marketing_automation_key (automation_key),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marketing special offers (migrations 106 + 107 + 108 end-state; required by MarketingSpecialOfferRepository)
CREATE TABLE marketing_special_offers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    code VARCHAR(60) NOT NULL,
    origin VARCHAR(60) NOT NULL DEFAULT 'manual',
    adjustment_type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    adjustment_value DECIMAL(12,2) NOT NULL,
    offer_option VARCHAR(60) NOT NULL DEFAULT 'all',
    sort_order INT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    start_date DATE NULL,
    end_date DATE NULL,
    deleted_at TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mkt_special_offers_branch_deleted (branch_id, deleted_at),
    INDEX idx_mkt_special_offers_branch_sort (branch_id, sort_order, id),
    INDEX idx_mkt_special_offers_branch_code (branch_id, code),
    INDEX idx_mkt_special_offers_option (offer_option),
    INDEX idx_mkt_special_offers_active_window (branch_id, is_active, start_date, end_date),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Media image pipeline (migration 103; foundation + future variant table)
CREATE TABLE media_assets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_basename VARCHAR(128) NOT NULL,
    mime_detected VARCHAR(80) NOT NULL,
    width INT UNSIGNED NULL,
    height INT UNSIGNED NULL,
    bytes_original BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','processing','ready','failed') NOT NULL DEFAULT 'pending',
    checksum CHAR(64) NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_media_assets_stored_basename (stored_basename),
    INDEX idx_media_assets_org_branch_status (organization_id, branch_id, status),
    INDEX idx_media_assets_branch_status (branch_id, status),
    CONSTRAINT fk_media_assets_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_media_assets_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    CONSTRAINT fk_media_assets_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE media_asset_variants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    media_asset_id BIGINT UNSIGNED NOT NULL,
    format ENUM('avif','webp','jpg') NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    bytes BIGINT UNSIGNED NOT NULL,
    relative_path VARCHAR(512) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    variant_kind ENUM('responsive','thumb') NOT NULL DEFAULT 'responsive',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_media_variant_path (relative_path),
    INDEX idx_media_variants_asset (media_asset_id),
    CONSTRAINT fk_media_variants_asset FOREIGN KEY (media_asset_id) REFERENCES media_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE media_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    media_asset_id BIGINT UNSIGNED NOT NULL,
    job_type VARCHAR(64) NOT NULL,
    status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at TIMESTAMP NULL DEFAULT NULL,
    error_message VARCHAR(2000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_media_jobs_claim (status, available_at, id),
    INDEX idx_media_jobs_asset (media_asset_id),
    CONSTRAINT fk_media_jobs_asset FOREIGN KEY (media_asset_id) REFERENCES media_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marketing gift card templates (migration 102 + 105 media bridge; defined after media_assets for FK)
CREATE TABLE marketing_gift_card_images (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
    media_asset_id BIGINT UNSIGNED NULL,
    title VARCHAR(160) NULL,
    storage_path VARCHAR(255) NOT NULL,
    filename VARCHAR(190) NOT NULL,
    mime_type VARCHAR(80) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mkt_gc_images_branch_deleted (branch_id, deleted_at),
    INDEX idx_mkt_gc_images_media_asset (media_asset_id),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    FOREIGN KEY (media_asset_id) REFERENCES media_assets(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE marketing_gift_card_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    clone_source_template_id BIGINT UNSIGNED NULL,
    sell_in_store_enabled TINYINT(1) NOT NULL DEFAULT 1,
    sell_online_enabled TINYINT(1) NOT NULL DEFAULT 1,
    image_id BIGINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mkt_gc_templates_branch_deleted (branch_id, deleted_at),
    INDEX idx_mkt_gc_templates_clone (clone_source_template_id),
    INDEX idx_mkt_gc_templates_image (image_id),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    FOREIGN KEY (clone_source_template_id) REFERENCES marketing_gift_card_templates(id) ON DELETE SET NULL,
    FOREIGN KEY (image_id) REFERENCES marketing_gift_card_images(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE runtime_async_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    queue VARCHAR(64) NOT NULL,
    job_type VARCHAR(128) NOT NULL,
    payload_json JSON NOT NULL,
    status VARCHAR(24) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
    available_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    reserved_at DATETIME(3) NULL DEFAULT NULL,
    last_error VARCHAR(4000) NULL DEFAULT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_runtime_async_jobs_queue_pick (queue, status, available_at, id),
    KEY idx_runtime_async_jobs_queue_status_updated (queue, status, updated_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
