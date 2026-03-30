-- Client intake / forms foundation (templates, fields, assignments, submissions, values).
-- Public completion uses token_hash (SHA-256 of raw token); raw token is never stored.

INSERT IGNORE INTO permissions (code, name) VALUES
('intake.view', 'View intake forms and submissions'),
('intake.edit', 'Create and edit intake form templates'),
('intake.assign', 'Assign intake forms to clients and appointments');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r INNER JOIN permissions p ON p.code IN ('intake.view', 'intake.edit', 'intake.assign')
WHERE r.code = 'owner';

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
