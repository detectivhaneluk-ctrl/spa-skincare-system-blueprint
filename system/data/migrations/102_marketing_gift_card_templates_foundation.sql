-- Marketing Gift Card Templates Foundation
-- Branch-scoped template catalog and reusable image library for marketing admin.

CREATE TABLE marketing_gift_card_images (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
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
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
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

