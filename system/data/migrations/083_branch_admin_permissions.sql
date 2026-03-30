-- Branch administration UI: list/create/edit/deactivate. Idempotent INSERT IGNORE.

INSERT IGNORE INTO permissions (code, name) VALUES
('branches.view', 'View branches'),
('branches.manage', 'Create, edit, and deactivate branches');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.code IN ('branches.view', 'branches.manage')
WHERE r.code IN ('owner', 'admin');
