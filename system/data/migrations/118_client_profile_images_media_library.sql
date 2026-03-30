-- Client profile photo library: same media_asset bridge pattern as marketing_gift_card_images (migration 105).
-- Rows link clients to canonical media_assets + pipeline variants for staff-facing client photos.

CREATE TABLE client_profile_images (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
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
    INDEX idx_client_profile_images_branch_client_deleted (branch_id, client_id, deleted_at),
    INDEX idx_client_profile_images_media_asset (media_asset_id),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (media_asset_id) REFERENCES media_assets(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
