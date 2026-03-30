-- CATALOG-CANONICAL-FOUNDATION-01: service category hierarchy, normalized product taxonomy FKs, backward-compatible.

-- A) Service categories: optional parent (NULL = root / flat behavior unchanged for existing rows).
ALTER TABLE service_categories
    ADD COLUMN parent_id BIGINT UNSIGNED NULL AFTER branch_id,
    ADD CONSTRAINT fk_service_categories_parent FOREIGN KEY (parent_id) REFERENCES service_categories(id) ON DELETE SET NULL,
    ADD INDEX idx_service_categories_parent (parent_id);

-- B) Normalized product taxonomy (branch NULL = global; same merge idea as service categories).
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

-- C) Products: optional FKs; legacy `category` / `brand` string columns remain authoritative for existing rows until backfilled.
ALTER TABLE products
    ADD COLUMN product_category_id BIGINT UNSIGNED NULL AFTER brand,
    ADD COLUMN product_brand_id BIGINT UNSIGNED NULL AFTER product_category_id,
    ADD CONSTRAINT fk_products_product_category FOREIGN KEY (product_category_id) REFERENCES product_categories(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_products_product_brand FOREIGN KEY (product_brand_id) REFERENCES product_brands(id) ON DELETE SET NULL,
    ADD INDEX idx_products_product_category (product_category_id),
    ADD INDEX idx_products_product_brand (product_brand_id);
