-- Branch admin RBAC parity: ensure branches.* exists and grant to owner/admin plus any role that already
-- has settings-area read/write (settings.*, payment_methods.*, vat_rates.*). Idempotent INSERT IGNORE.
-- Fixes GET /branches 403 for admin accounts that use settings submodule permissions but did not pick up migration 083.

INSERT IGNORE INTO permissions (code, name) VALUES
('branches.view', 'View branches'),
('branches.manage', 'Create, edit, and deactivate branches');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.code IN ('branches.view', 'branches.manage')
WHERE r.code IN ('owner', 'admin');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT DISTINCT rp.role_id, pb.id
FROM role_permissions rp
INNER JOIN permissions ps ON ps.id = rp.permission_id
  AND ps.code IN ('settings.view', 'payment_methods.view', 'vat_rates.view')
INNER JOIN permissions pb ON pb.code = 'branches.view';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT DISTINCT rp.role_id, pb.id
FROM role_permissions rp
INNER JOIN permissions ps ON ps.id = rp.permission_id
  AND ps.code IN ('settings.edit', 'payment_methods.manage', 'vat_rates.manage')
INNER JOIN permissions pb ON pb.code = 'branches.manage';
