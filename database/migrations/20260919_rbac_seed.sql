-- RBAC seed untuk BAJAMA
-- Tujuan:
-- 1) Pastikan role SUPER_ADMIN, OWNER, ADMIN, FINANCE, NOC, OPERATOR, TECHNICIAN ada.
-- 2) Pastikan permission platform/billing/network tersedia.
-- 3) Semua data billing/network harus dibatasi dengan organization_id dan user yang sah.

INSERT INTO roles (name, description)
VALUES
    ('SUPER_ADMIN', 'Pemilik platform BAJAMA dengan akses penuh ke seluruh platform.'),
    ('OWNER', 'Pemilik organisasi ISP; terbatas pada organisasi yang ia pimpin.'),
    ('ADMIN', 'Administrator organisasi.'),
    ('FINANCE', 'Manajer pembiayaan dan tagihan.'),
    ('NOC', 'Tim network operations center.'),
    ('OPERATOR', 'Operator operasional umum.'),
    ('TECHNICIAN', 'Teknisi jaringan dan device.')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO permissions (name, description)
VALUES
    ('dashboard.view', 'Akses dashboard.'),
    ('organization.view', 'Lihat data organisasi.'),
    ('organization.manage', 'Kelola organisasi.'),
    ('users.view', 'Lihat user.'),
    ('users.manage', 'Kelola user dan role.'),
    ('roles.manage', 'Kelola role.'),
    ('license.view', 'Lihat lisensi.'),
    ('license.manage', 'Kelola lisensi.'),
    ('audit.view', 'Lihat audit log.'),
    ('customers.view', 'Lihat customer.'),
    ('customers.manage', 'Kelola customer.'),
    ('billing.view', 'Lihat billing.'),
    ('billing.manage', 'Kelola billing.'),
    ('billing.delete', 'Hapus billing tertentu.'),
    ('invoices.delete', 'Hapus invoice.'),
    ('payments.delete', 'Hapus pembayaran.'),
    ('subscriptions.delete', 'Hapus langganan.'),
    ('service_plans.delete', 'Hapus paket layanan.'),
    ('network.view', 'Lihat network.'),
    ('network.manage', 'Kelola network.'),
    ('mikrotik.view', 'Lihat MikroTik.'),
    ('mikrotik.manage', 'Kelola MikroTik.'),
    ('fiber.view', 'Lihat fiber.'),
    ('fiber.manage', 'Kelola fiber.'),
    ('noc.view', 'Lihat NOC.'),
    ('noc.manage', 'Kelola NOC.'),
    ('traffic_catalog.view', 'Lihat traffic catalog.'),
    ('traffic_catalog.submit', 'Submit traffic.'),
    ('traffic_catalog.review', 'Review traffic.'),
    ('traffic_catalog.manage', 'Kelola traffic catalog.')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Role permissions default
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p
  ON p.name IN (
      'dashboard.view',
      'organization.view',
      'organization.manage',
      'customers.view',
      'customers.manage',
      'billing.view',
      'billing.manage',
      'billing.delete',
      'invoices.delete',
      'payments.delete',
      'subscriptions.delete',
      'service_plans.delete',
      'network.view',
      'network.manage',
      'mikrotik.view',
      'mikrotik.manage',
      'fiber.view',
      'fiber.manage',
      'noc.view',
      'noc.manage',
      'traffic_catalog.view',
      'traffic_catalog.submit',
      'traffic_catalog.review',
      'traffic_catalog.manage'
  )
WHERE r.name = 'OWNER';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p
  ON p.name IN (
      'dashboard.view',
      'organization.view',
      'customers.view',
      'customers.manage',
      'billing.view',
      'billing.manage',
      'network.view',
      'network.manage',
      'mikrotik.view',
      'mikrotik.manage',
      'fiber.view',
      'fiber.manage'
  )
WHERE r.name = 'ADMIN';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p
  ON p.name IN (
      'dashboard.view',
      'customers.view',
      'billing.view',
      'billing.manage',
      'billing.delete',
      'invoices.delete',
      'payments.delete',
      'subscriptions.delete',
      'service_plans.delete'
  )
WHERE r.name = 'FINANCE';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p
  ON p.name IN (
      'dashboard.view',
      'billing.view',
      'billing.manage',
      'billing.delete',
      'network.view',
      'network.manage',
      'mikrotik.view',
      'mikrotik.manage',
      'fiber.view',
      'fiber.manage',
      'noc.view',
      'noc.manage'
  )
WHERE r.name = 'NOC';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p
  ON p.name IN (
      'dashboard.view',
      'customers.view',
      'customers.manage',
      'network.view',
      'billing.view'
  )
WHERE r.name = 'OPERATOR';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p
  ON p.name IN (
      'dashboard.view',
      'network.view',
      'network.manage',
      'mikrotik.view',
      'mikrotik.manage',
      'fiber.view',
      'fiber.manage',
      'billing.view'
  )
WHERE r.name = 'TECHNICIAN';

-- SUPER_ADMIN full access
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON 1 = 1
WHERE r.name = 'SUPER_ADMIN';

-- Catatan penting:
-- Semua tabel billing dan networking harus mengandung organization_id.
-- Seluruh query harus memakai filter WHERE organization_id = Tenant::id()
-- dan/atau user_id yang sesuai agar data tidak tercampur antar organisasi maupun user.
-- Contoh pola aman:
-- SELECT * FROM customers WHERE organization_id = ?;
-- SELECT * FROM invoices WHERE organization_id = ? AND user_id = ?;
-- SELECT * FROM mikrotik_routers WHERE organization_id = ?;
