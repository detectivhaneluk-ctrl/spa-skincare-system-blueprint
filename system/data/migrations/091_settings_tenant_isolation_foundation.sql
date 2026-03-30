-- SETTINGS-TENANT-ISOLATION-01:
-- Introduce organization-aware settings precedence foundation.

ALTER TABLE settings
    ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER setting_group;

UPDATE settings s
INNER JOIN branches b ON b.id = s.branch_id
SET s.organization_id = b.organization_id
WHERE s.branch_id <> 0;

UPDATE settings
SET organization_id = 0
WHERE branch_id = 0;

ALTER TABLE settings
    DROP INDEX uk_settings_key_branch,
    ADD INDEX idx_settings_organization (organization_id),
    ADD UNIQUE KEY uk_settings_key_org_branch (`key`, organization_id, branch_id);
