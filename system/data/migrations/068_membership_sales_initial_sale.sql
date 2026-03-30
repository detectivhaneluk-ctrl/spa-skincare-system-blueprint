-- MEMBERSHIP-SALE-ACTIVATION-PIPELINE-01
-- Canonical initial membership sale → invoice → payment → activation (exact-once via activation_applied_at + row lock).

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
