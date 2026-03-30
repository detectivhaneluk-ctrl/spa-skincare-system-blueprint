-- Payroll / commissions foundation: explicit rules, deterministic calculation from paid appointment-linked service invoices, runs and frozen lines.
-- Product/package/gift-card line commissions deferred (no authoritative staff attribution on invoice_items).

INSERT IGNORE INTO permissions (code, name) VALUES
('payroll.view', 'View payroll runs and own commission lines'),
('payroll.manage', 'Manage compensation rules and payroll runs');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r INNER JOIN permissions p ON p.code IN ('payroll.view', 'payroll.manage')
WHERE r.code = 'owner';

CREATE TABLE payroll_compensation_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    staff_id BIGINT UNSIGNED NULL,
    service_id BIGINT UNSIGNED NULL,
    service_category_id BIGINT UNSIGNED NULL,
    rule_kind ENUM('percent_service_line','fixed_per_appointment') NOT NULL,
    name VARCHAR(200) NULL,
    rate_percent DECIMAL(10,4) NULL,
    fixed_amount DECIMAL(12,2) NULL,
    currency VARCHAR(10) NULL,
    priority INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payroll_rules_branch (branch_id),
    INDEX idx_payroll_rules_active (is_active),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY (service_category_id) REFERENCES service_categories(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payroll_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    status ENUM('draft','calculated','locked','settled') NOT NULL DEFAULT 'draft',
    settled_at TIMESTAMP NULL,
    settled_by BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payroll_runs_branch (branch_id),
    INDEX idx_payroll_runs_status (status),
    INDEX idx_payroll_runs_period (branch_id, period_start, period_end),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (settled_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payroll_commission_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_run_id BIGINT UNSIGNED NOT NULL,
    compensation_rule_id BIGINT UNSIGNED NULL,
    source_kind ENUM('service_invoice_item','appointment_fixed') NOT NULL,
    source_ref BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NULL,
    invoice_id BIGINT UNSIGNED NULL,
    invoice_item_id BIGINT UNSIGNED NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    base_amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(10) NOT NULL,
    rate_percent DECIMAL(10,4) NULL,
    rule_fixed_amount DECIMAL(12,2) NULL,
    calculated_amount DECIMAL(12,2) NOT NULL,
    derivation_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payroll_line_run_source (payroll_run_id, source_kind, source_ref),
    INDEX idx_payroll_lines_run (payroll_run_id),
    INDEX idx_payroll_lines_staff (staff_id),
    FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (compensation_rule_id) REFERENCES payroll_compensation_rules(id) ON DELETE SET NULL,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    FOREIGN KEY (invoice_item_id) REFERENCES invoice_items(id) ON DELETE SET NULL,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE RESTRICT,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
