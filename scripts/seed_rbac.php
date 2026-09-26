<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

$db = db();

$roles = [
    'SUPER_ADMIN' => 'Pemilik platform BAJAMA dengan akses penuh ke seluruh platform.',
    'OWNER' => 'Pemilik organisasi ISP; terbatas pada organisasi yang ia pimpin.',
    'ADMIN' => 'Administrator organisasi.',
    'FINANCE' => 'Manajer pembiayaan dan tagihan.',
    'NOC' => 'Tim network operations center.',
    'OPERATOR' => 'Operator operasional umum.',
    'TECHNICIAN' => 'Teknisi jaringan dan device.',
];

$permissions = [
    'dashboard.view',
    'organization.view',
    'organization.manage',
    'users.view',
    'users.manage',
    'roles.manage',
    'license.view',
    'license.manage',
    'license_plans.manage',
    'audit.view',
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
    'traffic_catalog.manage',
];

$rolePermissions = [
    'SUPER_ADMIN' => ['*'],
    'OWNER' => [
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
        'traffic_catalog.manage',
    ],
    'ADMIN' => [
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
        'fiber.manage',
    ],
    'FINANCE' => [
        'dashboard.view',
        'customers.view',
        'billing.view',
        'billing.manage',
        'billing.delete',
        'invoices.delete',
        'payments.delete',
        'subscriptions.delete',
        'service_plans.delete',
    ],
    'NOC' => [
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
        'noc.manage',
    ],
    'OPERATOR' => [
        'dashboard.view',
        'customers.view',
        'customers.manage',
        'network.view',
        'billing.view',
    ],
    'TECHNICIAN' => [
        'dashboard.view',
        'network.view',
        'network.manage',
        'mikrotik.view',
        'mikrotik.manage',
        'fiber.view',
        'fiber.manage',
        'billing.view',
    ],
];

function ensureTableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function ensureColumnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetchColumn();
}

function roleId(PDO $db, string $name): int
{
    $stmt = $db->prepare('SELECT id FROM roles WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : 0;
}

function permissionId(PDO $db, string $name): int
{
    $stmt = $db->prepare('SELECT id FROM permissions WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : 0;
}

function upsertRole(PDO $db, string $name, string $description): void
{
    $stmt = $db->prepare(
        'INSERT INTO roles (name, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description)'
    );
    $stmt->execute([$name, $description]);
}

function upsertPermission(PDO $db, string $name, string $description): void
{
    $stmt = $db->prepare(
        'INSERT INTO permissions (name, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description)'
    );
    $stmt->execute([$name, $description]);
}

function assignRolePermissions(PDO $db, string $roleName, array $permissions): void
{
    $role = roleId($db, $roleName);
    if ($role <= 0) {
        throw new RuntimeException("Role '{$roleName}' belum dibuat.");
    }

    foreach ($permissions as $permissionName) {
        if ($permissionName === '*') {
            foreach (permissionId($db, 'dashboard.view') ?: [] as $dummy) {
                // no-op; placeholder to keep logic explicit
            }
            $all = $db->query('SELECT name FROM permissions')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($all as $perm) {
                $permission = permissionId($db, (string)$perm);
                if ($permission <= 0) {
                    continue;
                }
                $db->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)')
                    ->execute([$role, $permission]);
            }
            continue;
        }

        $permission = permissionId($db, $permissionName);
        if ($permission <= 0) {
            throw new RuntimeException("Permission '{$permissionName}' belum dibuat.");
        }

        $db->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)')
            ->execute([$role, $permission]);
    }
}

function assignUserRole(PDO $db, int $userId, int $roleId): void
{
    if ($userId <= 0 || $roleId <= 0) {
        throw new RuntimeException('user_id dan role_id harus valid.');
    }

    $db->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)')
        ->execute([$userId, $roleId]);
}

$requiredTables = ['roles', 'permissions', 'role_permissions', 'users', 'user_roles'];
foreach ($requiredTables as $table) {
    if (!ensureTableExists($db, $table)) {
        throw new RuntimeException("Tabel '{$table}' belum ada di database. Jalankan migration schema terlebih dahulu.");
    }
}

foreach ($roles as $roleName => $description) {
    upsertRole($db, $roleName, $description);
}

foreach ($permissions as $permissionName) {
    $description = 'Permission ' . $permissionName;
    upsertPermission($db, $permissionName, $description);
}

foreach ($rolePermissions as $roleName => $permissionsForRole) {
    assignRolePermissions($db, $roleName, $permissionsForRole);
}

$superadminUserId = $_SERVER['argv'][1] ?? null;
if ($superadminUserId === null || !ctype_digit((string)$superadminUserId)) {
    echo "RBAC seed selesai. Tidak menambah user secara otomatis. Gunakan: php scripts/seed_rbac.php 12\n";
} else {
    $id = (int)$superadminUserId;
    $role = roleId($db, 'SUPER_ADMIN');
    if ($role > 0) {
        assignUserRole($db, $id, $role);
        echo "User #{$id} ditetapkan sebagai SUPER_ADMIN.\n";
    }
}

$orgScopedTables = ['customers', 'subscriptions', 'invoices', 'payments', 'mikrotik_routers', 'licenses', 'audit_logs'];
foreach ($orgScopedTables as $table) {
    if (!ensureTableExists($db, $table)) {
        echo "INFO: tabel {$table} tidak ada, skip pengecekan data separation.\n";
        continue;
    }

    if (ensureColumnExists($db, $table, 'organization_id')) {
        echo "OK: {$table} sudah memakai organization_id.\n";
    } else {
        echo "WARNING: {$table} belum memiliki organization_id. Semua query harus filter berdasarkan organisasi agar data tidak tercampur.\n";
    }
}

echo "RBAC dan pemisahan data per organisasi sudah siap.\n";
