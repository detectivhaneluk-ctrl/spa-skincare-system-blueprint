-- migration 141 — service_products pivot (Step 2 of the service wizard)
-- Each row links a service to a product from the canonical product catalog.
-- quantity_used is the decimal amount consumed per service delivery.
-- unit_cost_snapshot captures cost_price at time of assignment for reporting; nullable.
CREATE TABLE service_products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity_used DECIMAL(12,3) NOT NULL DEFAULT 1.000
        COMMENT 'Amount of this product consumed per service delivery.',
    unit_cost_snapshot DECIMAL(12,2) NULL
        COMMENT 'Copy of product cost_price at time of assignment (informational; not kept live).',
    UNIQUE KEY uq_service_product (service_id, product_id),
    INDEX idx_service_products_service (service_id),
    INDEX idx_service_products_product (product_id),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
