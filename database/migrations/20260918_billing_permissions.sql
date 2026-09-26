-- Billing permissions for router-linked operations and audited correction actions.
INSERT INTO permissions (name, description) VALUES
    ('billing.delete', 'Cancel invoices and void payments with audit trail'),
    ('invoices.delete', 'Cancel invoices with audit trail'),
    ('payments.delete', 'Void payments with audit trail'),
    ('subscriptions.delete', 'Delete subscriptions when no financial records exist'),
    ('service_plans.delete', 'Delete unused service plans')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- MikroTik operators must be able to see and manage the billing context
-- attached to their routers. Delete remains separately controlled.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT mr.role_id, p.id
FROM role_permissions mr
INNER JOIN permissions mp ON mp.id = mr.permission_id
INNER JOIN permissions p ON p.name IN ('billing.view', 'billing.manage')
WHERE mp.name = 'mikrotik.manage';

-- Existing billing managers receive the explicit delete permissions only
-- where they already have billing management authority.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT br.role_id, p.id
FROM role_permissions br
INNER JOIN permissions bp ON bp.id = br.permission_id
INNER JOIN permissions p ON p.name IN (
    'billing.delete',
    'invoices.delete',
    'payments.delete',
    'subscriptions.delete',
    'service_plans.delete'
)
WHERE bp.name = 'billing.manage';
