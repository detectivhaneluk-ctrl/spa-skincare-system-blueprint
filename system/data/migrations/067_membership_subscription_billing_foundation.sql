-- MEMBERSHIPS-SUBSCRIPTION-BILLING-ENGINE-FOUNDATION-01
-- Recurring renewal invoice truth via canonical invoices + idempotent billing cycles.

ALTER TABLE membership_definitions
    ADD COLUMN billing_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER price,
    ADD COLUMN billing_interval_unit ENUM('day','week','month','year') NULL AFTER billing_enabled,
    ADD COLUMN billing_interval_count INT UNSIGNED NULL AFTER billing_interval_unit,
    ADD COLUMN renewal_price DECIMAL(12,2) NULL AFTER billing_interval_count,
    ADD COLUMN renewal_invoice_due_days INT UNSIGNED NOT NULL DEFAULT 14 AFTER renewal_price,
    ADD COLUMN billing_auto_renew_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER renewal_invoice_due_days;

ALTER TABLE client_memberships
    ADD COLUMN next_billing_at DATE NULL AFTER ends_at,
    ADD COLUMN last_billed_at DATE NULL AFTER next_billing_at,
    ADD COLUMN billing_state ENUM('inactive','scheduled','invoiced','overdue') NOT NULL DEFAULT 'inactive' AFTER last_billed_at,
    ADD COLUMN billing_auto_renew_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER billing_state,
    ADD INDEX idx_client_memberships_next_billing (next_billing_at, status, billing_state);

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
