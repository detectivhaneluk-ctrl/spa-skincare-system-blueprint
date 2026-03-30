-- FOUNDATION-39 (F-37 S2): platform + organization-profile permission catalog only.
-- INSERT IGNORE is idempotent. No role_permissions here — catalog gap closure only (no default grants).

INSERT IGNORE INTO permissions (code, name) VALUES
('platform.organizations.view', 'View all organizations (platform operator)'),
('platform.organizations.manage', 'Create, suspend, and manage organizations across tenants (platform operator)'),
('organizations.profile.manage', 'Edit current organization profile (name/code) within resolved organization context');
