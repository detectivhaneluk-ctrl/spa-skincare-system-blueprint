-- IMAGE-PIPELINE-FOUNDATION-01 / REAL-REAPPLY-08: DB + PHP upload gateway + quarantine + job enqueue.
-- Variant files and public serving are a later wave; table media_asset_variants exists for that future worker.

INSERT IGNORE INTO permissions (code, name) VALUES
('media.upload', 'Upload images through the media pipeline (async processing)'),
('media.view', 'View processed media assets and variant URLs for a branch');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.code IN ('media.upload', 'media.view')
WHERE r.code IN ('owner', 'admin');

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
