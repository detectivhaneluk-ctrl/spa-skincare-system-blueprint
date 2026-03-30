-- FOUNDATION-38 (F-37 S1): organization registry schema delta + user↔organization membership pivot.
-- Additive only; no runtime/auth/context changes; no users.branch_id removal; no data backfill in this file.

ALTER TABLE organizations
    ADD COLUMN suspended_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at;

CREATE TABLE user_organization_memberships (
    user_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    default_branch_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, organization_id),
    CONSTRAINT fk_user_organization_memberships_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_organization_memberships_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_user_organization_memberships_default_branch FOREIGN KEY (default_branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    KEY idx_user_organization_memberships_organization_id (organization_id),
    KEY idx_user_organization_memberships_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
