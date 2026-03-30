-- Runtime backfill: migrations 074 and 076 attached marketing.* and payroll.* only to role `owner`.
-- Users with role `admin` (common for test / back-office accounts) lacked marketing.view / payroll.view → 403 on
-- GET /marketing/campaigns and GET /payroll/runs. Idempotent: INSERT IGNORE only.

INSERT IGNORE INTO permissions (code, name) VALUES
('marketing.view', 'View marketing campaigns and run history'),
('marketing.manage', 'Create and run marketing campaigns'),
('payroll.view', 'View payroll runs and own commission lines'),
('payroll.manage', 'Manage compensation rules and payroll runs');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.code IN (
    'marketing.view',
    'marketing.manage',
    'payroll.view',
    'payroll.manage'
)
WHERE r.code IN ('owner', 'admin');
