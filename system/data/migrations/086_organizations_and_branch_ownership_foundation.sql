-- FOUNDATION-08: canonical organization table + branch ownership (FOUNDATION-07 wave A).
-- Idempotent-friendly: CREATE TABLE IF NOT EXISTS; backfill UPDATE is safe to re-run;
-- ALTER tolerates duplicate column / duplicate FK name when migrate.php runs in legacy mode.

CREATE TABLE organizations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    UNIQUE KEY uk_organizations_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO organizations (name, code)
SELECT 'Default organization', NULL
FROM DUAL
WHERE (SELECT COUNT(*) FROM organizations) = 0;

ALTER TABLE branches ADD COLUMN organization_id BIGINT UNSIGNED NULL AFTER code;

UPDATE branches b
CROSS JOIN (SELECT MIN(id) AS id FROM organizations) o
SET b.organization_id = o.id
WHERE b.organization_id IS NULL AND o.id IS NOT NULL;

ALTER TABLE branches MODIFY organization_id BIGINT UNSIGNED NOT NULL;

ALTER TABLE branches ADD INDEX idx_branches_organization (organization_id);

ALTER TABLE branches ADD CONSTRAINT fk_branches_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT;
