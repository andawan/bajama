ALTER TABLE license_plans
    ADD COLUMN features JSON NULL AFTER description;

UPDATE license_plans
SET features = JSON_OBJECT(
    'dashboard', true,
    'billing', true,
    'mikrotik', true,
    'pppoe', true,
    'hotspot', true,
    'static', true,
    'fiber', true,
    'olt', true,
    'onu', true,
    'noc', true,
    'api', true
)
WHERE features IS NULL;

INSERT INTO license_plans
    (code, name, description, features, max_customers, max_routers, max_olts, max_onus, price_monthly, active)
SELECT
    'STARTER',
    'Starter',
    'Paket awal untuk organisasi kecil yang mulai mengelola jaringan dan pelanggan.',
    JSON_OBJECT('dashboard', true, 'billing', true, 'mikrotik', true, 'pppoe', true, 'hotspot', true, 'static', true),
    100, 5, 1, 128, 299000, 1
WHERE NOT EXISTS (SELECT 1 FROM license_plans WHERE code = 'STARTER');

INSERT INTO license_plans
    (code, name, description, features, max_customers, max_routers, max_olts, max_onus, price_monthly, active)
SELECT
    'PRO',
    'Professional',
    'Paket operasional lengkap untuk ISP berkembang dan jaringan multi-kategori.',
    JSON_OBJECT('dashboard', true, 'billing', true, 'mikrotik', true, 'pppoe', true, 'hotspot', true, 'static', true, 'fiber', true, 'olt', true, 'onu', true, 'noc', true),
    1000, 25, 5, 2048, 799000, 1
WHERE NOT EXISTS (SELECT 1 FROM license_plans WHERE code = 'PRO');

INSERT INTO license_plans
    (code, name, description, features, max_customers, max_routers, max_olts, max_onus, price_monthly, active)
SELECT
    'ENTERPRISE',
    'Enterprise',
    'Paket tanpa batas operasional untuk organisasi besar dengan kebutuhan advanced integration dan support prioritas.',
    JSON_OBJECT('dashboard', true, 'billing', true, 'mikrotik', true, 'pppoe', true, 'hotspot', true, 'static', true, 'fiber', true, 'olt', true, 'onu', true, 'noc', true, 'api', true),
    0, 0, 0, 0, 2500000, 1
WHERE NOT EXISTS (SELECT 1 FROM license_plans WHERE code = 'ENTERPRISE');
