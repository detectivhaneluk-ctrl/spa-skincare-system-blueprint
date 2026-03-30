-- ONLINE-COMMERCE-EXPANSION-FOUNDATION-01: public catalog eligibility + auditable purchase rows.

ALTER TABLE packages
    ADD COLUMN public_online_eligible TINYINT(1) NOT NULL DEFAULT 0 AFTER price;

ALTER TABLE membership_definitions
    ADD COLUMN public_online_eligible TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

CREATE TABLE public_commerce_purchases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    product_kind ENUM('gift_card','package','membership') NOT NULL,
    package_id BIGINT UNSIGNED NULL,
    membership_definition_id BIGINT UNSIGNED NULL,
    gift_card_amount DECIMAL(12,2) NULL,
    membership_sale_id BIGINT UNSIGNED NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    client_package_id BIGINT UNSIGNED NULL,
    gift_card_id BIGINT UNSIGNED NULL,
    fulfillment_applied_at TIMESTAMP NULL,
    status ENUM('pending_payment','fulfilled','failed') NOT NULL DEFAULT 'pending_payment',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_public_commerce_token_hash (token_hash),
    UNIQUE KEY uq_public_commerce_invoice (invoice_id),
    KEY idx_public_commerce_branch (branch_id),
    KEY idx_public_commerce_client (client_id),
    CONSTRAINT fk_public_commerce_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    CONSTRAINT fk_public_commerce_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_public_commerce_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL,
    CONSTRAINT fk_public_commerce_membership_def FOREIGN KEY (membership_definition_id) REFERENCES membership_definitions(id) ON DELETE SET NULL,
    CONSTRAINT fk_public_commerce_membership_sale FOREIGN KEY (membership_sale_id) REFERENCES membership_sales(id) ON DELETE SET NULL,
    CONSTRAINT fk_public_commerce_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
    CONSTRAINT fk_public_commerce_client_package FOREIGN KEY (client_package_id) REFERENCES client_packages(id) ON DELETE SET NULL,
    CONSTRAINT fk_public_commerce_gift_card FOREIGN KEY (gift_card_id) REFERENCES gift_cards(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
