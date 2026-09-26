<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\PaymentProof;


\BAJAMA\Core\Auth::requireLogin();

function paymentColumnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ?');
    $stmt->execute([$column]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function ensurePaymentSchema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS payment_methods (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            organization_id BIGINT UNSIGNED NULL,
            name VARCHAR(120) NOT NULL,
            type ENUM('BANK','EWALLET','GATEWAY') NOT NULL DEFAULT 'BANK',
            provider_name VARCHAR(120) NULL,
            account_name VARCHAR(150) NULL,
            account_number VARCHAR(150) NULL,
            instructions TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_payment_method_org_name (organization_id, name)
        ) ENGINE=InnoDB"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS license_registrations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_name VARCHAR(180) NOT NULL,
            full_name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(50) NULL,
            package_name VARCHAR(120) NOT NULL,
            plan_id BIGINT UNSIGNED NULL,
            payment_method_id BIGINT UNSIGNED NULL,
            notes TEXT NULL,
            status ENUM('PENDING','PAID','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
            payment_reference VARCHAR(150) NULL,
            processed_by_user_id BIGINT UNSIGNED NULL,
            processed_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_registration_status (status),
            INDEX idx_registration_email (email)
        ) ENGINE=InnoDB"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS roles (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL UNIQUE,
            description VARCHAR(255) NULL
        ) ENGINE=InnoDB"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS permissions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            description VARCHAR(255) NULL
        ) ENGINE=InnoDB"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS role_permissions (
            role_id BIGINT UNSIGNED NOT NULL,
            permission_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (role_id, permission_id),
            INDEX idx_role_permissions_permission (permission_id)
        ) ENGINE=InnoDB"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS user_roles (
            user_id BIGINT UNSIGNED NOT NULL,
            role_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, role_id),
            INDEX idx_user_roles_role (role_id)
        ) ENGINE=InnoDB"
    );

    $tableChecks = [
        'payment_methods' => [
            ['organization_id', 'ALTER TABLE payment_methods ADD COLUMN organization_id BIGINT UNSIGNED NULL AFTER id'],
            ['type', "ALTER TABLE payment_methods ADD COLUMN type ENUM('BANK','EWALLET','GATEWAY') NOT NULL DEFAULT 'BANK' AFTER name"],
            ['provider_name', 'ALTER TABLE payment_methods ADD COLUMN provider_name VARCHAR(120) NULL AFTER type'],
            ['account_name', 'ALTER TABLE payment_methods ADD COLUMN account_name VARCHAR(150) NULL AFTER provider_name'],
            ['account_number', 'ALTER TABLE payment_methods ADD COLUMN account_number VARCHAR(150) NULL AFTER account_name'],
            ['instructions', 'ALTER TABLE payment_methods ADD COLUMN instructions TEXT NULL AFTER account_number'],
            ['active', 'ALTER TABLE payment_methods ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER instructions'],
        ],
        'license_registrations' => [
            ['package_name', 'ALTER TABLE license_registrations ADD COLUMN package_name VARCHAR(120) NOT NULL DEFAULT \'' . addslashes('Lisensi') . '\' AFTER phone'],
            ['payment_method_id', 'ALTER TABLE license_registrations ADD COLUMN payment_method_id BIGINT UNSIGNED NULL AFTER plan_id'],
            ['notes', 'ALTER TABLE license_registrations ADD COLUMN notes TEXT NULL AFTER payment_method_id'],
            ['status', 'ALTER TABLE license_registrations ADD COLUMN status ENUM(\'PENDING\',\'PAID\',\'APPROVED\',\'REJECTED\') NOT NULL DEFAULT \'PENDING\' AFTER notes'],
            ['payment_reference', 'ALTER TABLE license_registrations ADD COLUMN payment_reference VARCHAR(150) NULL AFTER status'],
            ['processed_by_user_id', 'ALTER TABLE license_registrations ADD COLUMN processed_by_user_id BIGINT UNSIGNED NULL AFTER payment_reference'],
            ['processed_at', 'ALTER TABLE license_registrations ADD COLUMN processed_at DATETIME NULL AFTER processed_by_user_id'],
        ],
    ];

    foreach ($tableChecks as $tableName => $columns) {
        foreach ($columns as [$columnName, $alterSql]) {
            if (!paymentColumnExists($db, $tableName, $columnName)) {
                try {
                    $db->exec($alterSql);
                } catch (Throwable $e) {
                    error_log('BAJAMA schema sync warning: ' . $e->getMessage());
                }
            }
        }
    }

    $rbacRoles = [
        'SUPER_ADMIN' => 'Pemilik platform BAJAMA dengan akses penuh ke seluruh platform.',
        'OWNER' => 'Pemilik organisasi ISP; terbatas pada organisasi yang ia pimpin.',
        'ADMIN' => 'Administrator organisasi.',
        'FINANCE' => 'Manajer pembiayaan dan tagihan.',
        'NOC' => 'Tim network operations center.',
        'OPERATOR' => 'Operator operasional umum.',
        'TECHNICIAN' => 'Teknisi jaringan dan device.',
    ];

    foreach ($rbacRoles as $roleName => $description) {
        try {
            $db->prepare('INSERT INTO roles (name, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description)')->execute([$roleName, $description]);
        } catch (Throwable $e) {
            error_log('BAJAMA RBAC role sync warning: ' . $e->getMessage());
        }
    }
}

function generateUniqueOrganizationSlug(PDO $db, string $companyName): string
{
    $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $companyName) ?: 'organization', '-'));
    $base = trim($base, '-');

    if ($base === '') {
        $base = 'organization';
    }

    $candidate = $base;
    $counter = 1;
    while (true) {
        $stmt = $db->prepare('SELECT id FROM organizations WHERE slug = ? LIMIT 1');
        $stmt->execute([$candidate]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
        $candidate = $base . '-' . $counter;
        $counter++;
    }
}

function generateUniqueUsername(PDO $db, int $organizationId, string $fullName): string
{
    $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '.', $fullName) ?: 'customer', '.'));
    $base = trim($base, '.');
    if ($base === '') {
        $base = 'customer';
    }

    $candidate = $base;
    $counter = 1;
    while (true) {
        $stmt = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$candidate]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
        $candidate = $base . $counter;
        $counter++;
    }
}

function paymentProofPresentation(int $registrationId, string $proof): array
{
    $proof = trim($proof);
    if ($proof === '') {
        return ['url' => '', 'is_image' => false];
    }

    $localPath = PaymentProof::localPath($proof);
    $isImage = PaymentProof::isImage($proof, $localPath);

    return [
        'url' => PaymentProof::url($registrationId, $proof),
        'image_url' => PaymentProof::imageUrl($registrationId, $proof),
        'is_image' => $isImage,
    ];
}

$currentRoles = \BAJAMA\Core\RBAC::roles($db);
$isSuperAdmin = in_array('SUPER_ADMIN', $currentRoles, true);
if (!$isSuperAdmin) {
    http_response_code(403);
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>403</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><div class="container py-5"><div class="card shadow-sm"><div class="card-body text-center p-5"><h1 class="display-5 fw-bold">403</h1><p class="text-muted">Halaman ini hanya bisa diakses oleh superadmin.</p><a href="dashboard.php" class="btn btn-primary">Kembali</a></div></div></div></body></html>';
    exit;
}

ensurePaymentSchema($db);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf((string)($_POST['_csrf'] ?? ''));
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_payment_method') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $type = in_array((string)($_POST['type'] ?? 'BANK'), ['BANK', 'EWALLET', 'GATEWAY'], true) ? (string)$_POST['type'] : 'BANK';
        $providerName = trim((string)($_POST['provider_name'] ?? ''));
        $accountName = trim((string)($_POST['account_name'] ?? ''));
        $accountNumber = trim((string)($_POST['account_number'] ?? ''));
        $instructions = trim((string)($_POST['instructions'] ?? ''));
        $active = isset($_POST['active']) ? 1 : 0;

        $type = strtoupper($type);
        $name = trim(preg_replace('/\s+/', ' ', $name) ?: $name);

        if ($name === '') {
            $error = 'Nama metode pembayaran wajib diisi.';
        } elseif ($type === 'BANK' && ($accountName === '' || $accountNumber === '')) {
            $error = 'Untuk BANK, kolom nama rekening dan nomor rekening wajib diisi.';
        } elseif ($type === 'EWALLET' && ($accountName === '' || $accountNumber === '')) {
            $error = 'Untuk E-Wallet, kolom nama pemilik dan nomor HP/akun aktif wajib diisi.';
        } elseif ($type === 'GATEWAY' && $providerName === '' && $accountName === '') {
            $error = 'Untuk payment gateway internasional, kolom provider atau nama akun / email wajib diisi.';
        } else {
            try {
                $duplicateSql = 'SELECT id FROM payment_methods WHERE organization_id IS NULL AND LOWER(name) = LOWER(?)' . ($id > 0 ? ' AND id <> ?' : '') . ' LIMIT 1';
                $duplicateStmt = $db->prepare($duplicateSql);
                $duplicateParams = [$name];
                if ($id > 0) {
                    $duplicateParams[] = $id;
                }
                $duplicateStmt->execute($duplicateParams);

                if ($duplicateStmt->fetch()) {
                    $error = 'Nama metode pembayaran sudah ada. Gunakan nama lain atau edit data yang sudah tersimpan.';
                } else {
                    if ($id > 0) {
                        $stmt = $db->prepare('UPDATE payment_methods SET name = ?, type = ?, provider_name = ?, account_name = ?, account_number = ?, instructions = ?, active = ?, updated_at = NOW() WHERE id = ? AND organization_id IS NULL');
                        $stmt->execute([$name, $type, $providerName, $accountName, $accountNumber, $instructions, $active, $id]);
                        $message = 'Metode pembayaran berhasil diperbarui.';
                    } else {
                        $stmt = $db->prepare('INSERT INTO payment_methods (name, type, provider_name, account_name, account_number, instructions, active) VALUES (?, ?, ?, ?, ?, ?, ?)');
                        $stmt->execute([$name, $type, $providerName, $accountName, $accountNumber, $instructions, $active]);
                        $message = 'Metode pembayaran baru berhasil ditambahkan.';
                    }
                }
            } catch (Throwable $e) {
                error_log('BAJAMA payment method save error: ' . $e->getMessage());
                $error = 'Gagal menyimpan metode pembayaran. Cek nama yang duplikat, struktur tabel, atau data yang tidak valid.';
            }
        }
    }

    if ($action === 'toggle_payment_method') {
        $methodId = (int)($_POST['method_id'] ?? 0);
        if ($methodId > 0) {
            $targetStatus = isset($_POST['active']) ? 1 : 0;
            $db->prepare('UPDATE payment_methods SET active = ?, updated_at = NOW() WHERE id = ? AND organization_id IS NULL')->execute([$targetStatus, $methodId]);
            $message = $targetStatus === 1 ? 'Metode pembayaran diaktifkan.' : 'Metode pembayaran dinonaktifkan.';
        }
    }

    if ($action === 'approve_registration') {
        $registrationId = (int)($_POST['registration_id'] ?? 0);
        if ($registrationId > 0) {
            $stmt = $db->prepare('SELECT * FROM license_registrations WHERE id = ? LIMIT 1');
            $stmt->execute([$registrationId]);
            $registration = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($registration) {
                $currentStatus = strtoupper((string)($registration['status'] ?? 'PENDING'));
                if ($currentStatus === 'APPROVED') {
                    $message = 'Pendaftaran ini sudah berhasil disetujui sebelumnya.';
                } else {
                    $db->beginTransaction();

                    try {
                        $companyName = trim((string)($registration['company_name'] ?? ''));
                        $fullName = trim((string)($registration['full_name'] ?? ''));
                        $email = trim((string)($registration['email'] ?? ''));
                        $phone = trim((string)($registration['phone'] ?? ''));
                        $planId = (int)($registration['plan_id'] ?? 0);

                        if ($companyName !== '' && $fullName !== '' && $email !== '') {
                            $existingOrgId = null;
                            $orgStmt = $db->prepare('SELECT id FROM organizations WHERE email = ? LIMIT 1');
                            $orgStmt->execute([$email]);
                            $existingOrgId = (int)$orgStmt->fetchColumn();

                            if ($existingOrgId <= 0) {
                                $slug = generateUniqueOrganizationSlug($db, $companyName);
                                $orgInsert = $db->prepare('INSERT INTO organizations (name, slug, email, phone, status) VALUES (?, ?, ?, ?, "ACTIVE")');
                                $orgInsert->execute([$companyName, $slug, $email, $phone]);
                                $existingOrgId = (int)$db->lastInsertId();
                            } else {
                                $orgUpdate = $db->prepare('UPDATE organizations SET name = ?, email = ?, phone = ?, status = "ACTIVE", updated_at = NOW() WHERE id = ?');
                                $orgUpdate->execute([$companyName, $email, $phone, $existingOrgId]);
                            }

                            $username = generateUniqueUsername($db, $existingOrgId, $fullName);
                            $password = 'BAJAMA-' . strtoupper(substr(md5((string)time() . $email . $companyName), 0, 8));

                            $userStmt = $db->prepare('SELECT id FROM users WHERE organization_id = ? AND email = ? LIMIT 1');
                            $userStmt->execute([$existingOrgId, $email]);
                            $existingUserId = (int)$userStmt->fetchColumn();

                            if ($existingUserId > 0) {
                                $db->prepare('UPDATE users SET username = ?, full_name = ?, status = "ACTIVE" WHERE id = ?')->execute([$username, $fullName, $existingUserId]);
                                $userId = $existingUserId;
                            } else {
                                $db->prepare('INSERT INTO users (organization_id, username, email, password_hash, full_name, status) VALUES (?, ?, ?, ?, ?, "ACTIVE")')->execute([$existingOrgId, $username, $email, password_hash($password), $fullName]);
                                $userId = (int)$db->lastInsertId();
                            }

                            $ownerRoleId = 0;
                            try {
                                $roleStmt = $db->prepare('SELECT id FROM roles WHERE name = ? LIMIT 1');
                                $roleStmt->execute(['OWNER']);
                                $ownerRoleId = (int)$roleStmt->fetchColumn();
                            } catch (Throwable $e) {
                                $ownerRoleId = 0;
                            }

                            if ($ownerRoleId <= 0) {
                                try {
                                    $db->prepare('INSERT INTO roles (name, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description)')->execute(['OWNER', 'Pemilik organisasi ISP; terbatas pada organisasi yang ia pimpin.']);
                                    $ownerRoleId = (int)$db->lastInsertId();
                                } catch (Throwable $e) {
                                    $ownerRoleId = 0;
                                }
                            }

                            if ($ownerRoleId > 0) {
                                $db->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $ownerRoleId]);
                            }

                            $customerCode = 'CUST-' . strtoupper(substr(md5((string)time() . $email), 0, 8));
                            $customerStmt = $db->prepare('SELECT id FROM customers WHERE organization_id = ? AND email = ? LIMIT 1');
                            $customerStmt->execute([$existingOrgId, $email]);
                            if (!$customerStmt->fetch()) {
                                $db->prepare('INSERT INTO customers (organization_id, customer_code, name, email, phone, status) VALUES (?, ?, ?, ?, ?, "ACTIVE")')->execute([$existingOrgId, $customerCode, $fullName, $email, $phone]);
                            }

                            $licenseKey = 'BAJAMA-' . strtoupper(substr(md5((string)time() . $email . $companyName), 0, 12));
                            $expiresAt = date('d-m-Y H:i:s', strtotime('+365 days'));
                            if ($planId > 0) {
                                $licenseStmt = $db->prepare('SELECT id FROM licenses WHERE organization_id = ? ORDER BY id DESC LIMIT 1');
                                $licenseStmt->execute([$existingOrgId]);
                                $existingLicenseId = (int)$licenseStmt->fetchColumn();

                                if ($existingLicenseId > 0) {
                                    $db->prepare('UPDATE licenses SET plan_id = ?, license_key = ?, status = "ACTIVE", issued_at = NOW(), expires_at = ?, features = ?, updated_at = NOW() WHERE id = ?')->execute([$planId, $licenseKey, $expiresAt, json_encode([], JSON_UNESCAPED_UNICODE), $existingLicenseId]);
                                } else {
                                    $db->prepare('INSERT INTO licenses (organization_id, plan_id, license_key, status, issued_at, expires_at, features) VALUES (?, ?, ?, "ACTIVE", NOW(), ?, ?)')->execute([$existingOrgId, $planId, $licenseKey, $expiresAt, json_encode([], JSON_UNESCAPED_UNICODE)]);
                                }
                            }

                            $db->prepare('UPDATE license_registrations SET status = "APPROVED", payment_review_status = "VERIFIED", processed_by_user_id = ?, processed_at = NOW(), payment_reference = COALESCE(payment_reference, "APPROVED") WHERE id = ?')->execute([\BAJAMA\Core\Auth::userId(), $registrationId]);
                            $db->commit();
                            $message = 'Pendaftaran disetujui dan akun pelanggan berhasil dibuat. Username: ' . $username . ' | Password: ' . $password;
                        } else {
                            $db->rollBack();
                            $error = 'Data pendaftaran tidak valid untuk approval.';
                        }
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        error_log('BAJAMA approve registration error: ' . $e->getMessage());
                        $error = 'Gagal menyetujui pendaftaran. Silakan cek ulang data pelanggan dan organisasi.';
                    }
                }
            } else {
                $error = 'Pendaftaran tidak ditemukan.';
            }
        }
    }

    if ($action === 'reject_registration') {
        $registrationId = (int)($_POST['registration_id'] ?? 0);
        if ($registrationId > 0) {
            $db->prepare('UPDATE license_registrations SET status = "REJECTED", processed_by_user_id = ?, processed_at = NOW() WHERE id = ?')->execute([\BAJAMA\Core\Auth::userId(), $registrationId]);
            $message = 'Pendaftaran ditolak.';
        }
    }
}

$stats = [
    'pending' => 0,
    'paid' => 0,
    'approved' => 0,
    'rejected' => 0,
    'customers' => 0,
    'orgs' => 0,
    'licenses' => 0,
    'blogs' => 0,
];

try { $stats['pending'] = (int)$db->query("SELECT COUNT(*) FROM license_registrations WHERE status = 'PENDING'")->fetchColumn(); } catch (Throwable $e) {}
try { $stats['paid'] = (int)$db->query("SELECT COUNT(*) FROM license_registrations WHERE status = 'PAID'")->fetchColumn(); } catch (Throwable $e) {}
try { $stats['approved'] = (int)$db->query("SELECT COUNT(*) FROM license_registrations WHERE status = 'APPROVED'")->fetchColumn(); } catch (Throwable $e) {}
try { $stats['rejected'] = (int)$db->query("SELECT COUNT(*) FROM license_registrations WHERE status = 'REJECTED'")->fetchColumn(); } catch (Throwable $e) {}
try { $stats['customers'] = (int)$db->query('SELECT COUNT(*) FROM customers')->fetchColumn(); } catch (Throwable $e) {}
try { $stats['orgs'] = (int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn(); } catch (Throwable $e) {}
try { $stats['licenses'] = (int)$db->query('SELECT COUNT(*) FROM licenses')->fetchColumn(); } catch (Throwable $e) {}
try { $stats['blogs'] = (int)$db->query("SELECT COUNT(*) FROM blog_posts WHERE status = 'PUBLISHED'")->fetchColumn(); } catch (Throwable $e) {}

$paymentMethods = [];
try { $paymentMethods = $db->query('SELECT * FROM payment_methods WHERE organization_id IS NULL ORDER BY type, name')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $paymentMethods = []; }

$editingMethodId = (int)($_GET['edit_id'] ?? 0);
$editingMethod = null;
if ($editingMethodId > 0) {
    try {
        $editingStmt = $db->prepare('SELECT * FROM payment_methods WHERE id = ? AND organization_id IS NULL LIMIT 1');
        $editingStmt->execute([$editingMethodId]);
        $editingMethod = $editingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $editingMethod = null;
    }
}

$registrations = [];
try { $registrations = $db->query('SELECT r.*, p.name AS plan_name, pm.name AS payment_method_name FROM license_registrations r LEFT JOIN license_plans p ON p.id = r.plan_id LEFT JOIN payment_methods pm ON pm.id = r.payment_method_id ORDER BY r.created_at DESC')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $registrations = []; }

$pageTitle = 'Superadmin Management';
ob_start();
?>
<div class="container-fluid py-4 superadmin-shell">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Superadmin Control Center</h1>
            <p class="text-muted mb-0">Mengurus bisnis BAJAMA secara terpusat: pelanggan, lisensi, metode pembayaran, pendaftaran, dan konten publik.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
                <a class="btn btn-outline-dark" href="dashboard.php">Dashboard</a>
                <a class="btn btn-primary" href="registrations.php">Kelola Pendaftaran</a>
        </div>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card admin-stat border-0 h-100"><div class="card-body"><div class="text-muted small">Pendaftaran pending</div><div class="display-6 fw-bold"><?= $stats['pending'] ?></div></div></div></div>
        <div class="col-md-3"><div class="card admin-stat border-0 h-100"><div class="card-body"><div class="text-muted small">Sudah bayar</div><div class="display-6 fw-bold"><?= $stats['paid'] ?></div></div></div></div>
        <div class="col-md-3"><div class="card admin-stat border-0 h-100"><div class="card-body"><div class="text-muted small">Disetujui</div><div class="display-6 fw-bold"><?= $stats['approved'] ?></div></div></div></div>
        <div class="col-md-3"><div class="card admin-stat border-0 h-100"><div class="card-body"><div class="text-muted small">Ditolak</div><div class="display-6 fw-bold"><?= $stats['rejected'] ?></div></div></div></div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-3"><div class="card admin-stat border-0 h-100"><div class="card-body"><div class="text-muted small">Total pelanggan</div><div class="display-6 fw-bold"><?= $stats['customers'] ?></div></div></div></div>
        <div class="col-md-3"><div class="card admin-stat border-0 h-100"><div class="card-body"><div class="text-muted small">Organisasi</div><div class="display-6 fw-bold"><?= $stats['orgs'] ?></div></div></div></div>
        <div class="col-md-3"><div class="card admin-stat border-0 h-100"><div class="card-body"><div class="text-muted small">Lisensi aktif</div><div class="display-6 fw-bold"><?= $stats['licenses'] ?></div></div></div></div>
        <div class="col-md-3"><div class="card admin-stat border-0 h-100"><div class="card-body"><div class="text-muted small">Artikel publik</div><div class="display-6 fw-bold"><?= $stats['blogs'] ?></div></div></div></div>
    </div>

    <style>
        .superadmin-shell {
            background: linear-gradient(180deg, #f8fafc 0%, #eef4ff 100%);
        }

        .admin-stat {
            background: linear-gradient(135deg, #ffffff 0%, #eef6ff 100%);
            border: 1px solid rgba(15, 23, 42, 0.05);
            border-radius: 20px;
            box-shadow: 0 22px 48px rgba(15, 23, 42, 0.06);
            min-height: 120px;
        }

        .admin-stat .display-6 {
            font-size: clamp(1.8rem, 3vw, 2.5rem);
        }

        .super-admin-spotlight {
            background: linear-gradient(135deg, rgba(37,99,235,0.12), rgba(14,165,233,0.06));
            border: 1px solid rgba(148,163,184,0.18);
            border-radius: 20px;
            box-shadow: 0 22px 48px rgba(15, 23, 42, 0.06);
        }
    </style>

    <div class="card super-admin-spotlight border-0 mb-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <div class="text-uppercase small text-primary fw-semibold">Executive Overview</div>
                    <h2 class="h4 fw-bold mb-0">Kelola bisnis lisensi, pelanggan, dan operasional BAJAMA dari satu dashboard.</h2>
                </div>
                <div class="badge bg-dark text-white px-3 py-2 rounded-pill">Superadmin Active</div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white"><?= $editingMethod ? 'Edit Metode Pembayaran' : 'Metode Pembayaran' ?></div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="save_payment_method">
                <input type="hidden" name="id" value="<?= (int)($editingMethod['id'] ?? 0) ?>">
                <div class="col-md-3">
                    <label class="form-label">Nama metode</label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars((string)($editingMethod['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Bank BCA / Dana / Midtrans" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Tipe</label>
                    <select name="type" class="form-select">
                        <option value="BANK" <?= (($editingMethod['type'] ?? 'BANK') === 'BANK') ? 'selected' : '' ?>>BANK</option>
                        <option value="EWALLET" <?= (($editingMethod['type'] ?? 'BANK') === 'EWALLET') ? 'selected' : '' ?>>EWALLET</option>
                        <option value="GATEWAY" <?= (($editingMethod['type'] ?? 'BANK') === 'GATEWAY') ? 'selected' : '' ?>>GATEWAY</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Provider</label>
                    <input type="text" name="provider_name" class="form-control" value="<?= htmlspecialchars((string)($editingMethod['provider_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="BCA / DANA / PayPal / Stripe">
                </div>
                <div class="col-md-2">
                    <label class="form-label" id="account-name-label">Nama akun</label>
                    <input type="text" name="account_name" id="account_name_input" class="form-control" value="<?= htmlspecialchars((string)($editingMethod['account_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="PT BAJAMA">
                </div>
                <div class="col-md-2">
                    <label class="form-label" id="account-number-label">Nomor rekening</label>
                    <input type="text" name="account_number" id="account_number_input" class="form-control" value="<?= htmlspecialchars((string)($editingMethod['account_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="1234567890">
                </div>
                <div class="col-md-12">
                    <label class="form-label">Instruksi pembayaran</label>
                    <textarea name="instructions" class="form-control" rows="3" placeholder="Contoh: transfer ke rekening ... lalu kirim bukti pembayaran"><?= htmlspecialchars((string)($editingMethod['instructions'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div class="col-md-12 d-flex align-items-center gap-3 flex-wrap">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="active" <?= ((int)($editingMethod['active'] ?? 1) === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label">Aktif</label>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= $editingMethod ? 'Update metode pembayaran' : 'Simpan metode pembayaran' ?></button>
                    <?php if ($editingMethod): ?>
                        <a href="superadmin.php" class="btn btn-outline-secondary">Batal</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white">Daftar metode pembayaran</div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Tipe</th>
                        <th>Provider</th>
                        <th>Nomor / Akun</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($paymentMethods as $method): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)($method['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($method['type'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($method['provider_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($method['account_number'] ?? $method['account_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?= (int)$method['active'] === 1 ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Nonaktif</span>' ?>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a class="btn btn-outline-primary" href="superadmin.php?edit_id=<?= (int)$method['id'] ?>">Edit</a>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="toggle_payment_method">
                                        <input type="hidden" name="method_id" value="<?= (int)$method['id'] ?>">
                                        <input type="hidden" name="active" value="<?= (int)$method['active'] === 1 ? 0 : 1 ?>">
                                        <button type="submit" class="btn btn-outline-dark"><?= (int)$method['active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-dark text-white">Pendaftaran pelanggan baru</div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Perusahaan</th>
                        <th>Kontak</th>
                        <th>Paket</th>
                        <th>Metode</th>
                        <th>Bukti pembayaran</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registrations as $registration): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars((string)($registration['company_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                <small class="text-muted"><?= htmlspecialchars((string)($registration['email'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></small>
                            </td>
                            <td><?= htmlspecialchars((string)($registration['full_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?><br><small><?= htmlspecialchars((string)($registration['phone'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></small></td>
                            <td><?= htmlspecialchars((string)($registration['plan_name'] ?? $registration['package_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($registration['payment_method_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php $proof = paymentProofPresentation((int)$registration['id'], (string)($registration['payment_proof'] ?? '')); ?>
                                <?php if ($proof['url'] !== ''): ?>
                                        <div class="btn-group btn-group-sm" role="group" aria-label="Bukti pembayaran">
                                            <?php if ($proof['image_url'] !== ''): ?>
                                                <a href="<?= htmlspecialchars($proof['image_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-outline-primary"><i class="bi bi-image me-1"></i>Buka gambar</a>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-outline-secondary" disabled><i class="bi bi-image me-1"></i>Buka gambar</button>
                                            <?php endif; ?>
                                            <a href="<?= htmlspecialchars($proof['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-outline-dark"><i class="bi bi-link-45deg me-1"></i>Buka link</a>
                                        </div>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-light text-dark"><?= htmlspecialchars((string)($registration['status'] ?? 'PENDING'), ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td>
                                <?php if ($registration['status'] === 'PENDING' || $registration['status'] === 'PAID'): ?>
                                    <div class="btn-group btn-group-sm">
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="approve_registration">
                                            <input type="hidden" name="registration_id" value="<?= (int)$registration['id'] ?>">
                                            <button type="submit" class="btn btn-success">Approve</button>
                                        </form>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="reject_registration">
                                            <input type="hidden" name="registration_id" value="<?= (int)$registration['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger">Reject</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Superadmin BAJAMA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --superadmin-bg: #f3f7ff;
            --superadmin-primary: #173d8d;
            --superadmin-primary-soft: #eaf1ff;
            --superadmin-dark: #0f172a;
            --superadmin-text: #14213d;
            --superadmin-border: rgba(15, 23, 42, 0.08);
            --superadmin-card: rgba(255, 255, 255, 0.96);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: linear-gradient(180deg, #edf4ff 0%, #f8fafd 100%);
            color: var(--superadmin-text);
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a { text-decoration: none; }

        .superadmin-header {
            position: sticky;
            top: 0;
            z-index: 30;
            background: rgba(15, 23, 42, 0.96);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 10px 35px rgba(15, 23, 42, 0.12);
        }

        .superadmin-header-inner {
            max-width: 1500px;
            margin: 0 auto;
            padding: 16px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .superadmin-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #fff;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .superadmin-brand-mark {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, #3b82f6, #22d3ee);
            color: #fff;
            box-shadow: 0 10px 26px rgba(59,130,246,0.35);
        }

        .superadmin-nav {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .superadmin-nav a {
            padding: 10px 14px;
            border-radius: 10px;
            color: rgba(255, 255, 255, 0.8);
            font-size: 13px;
            font-weight: 600;
            transition: 0.2s ease;
        }

        .superadmin-nav a:hover,
        .superadmin-nav a.active {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
        }

        .superadmin-user {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #fff;
            font-size: 13px;
        }

        .superadmin-user-badge {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255,255,255,0.12);
        }

        .superadmin-main {
            max-width: 1500px;
            margin: 0 auto;
            padding: 28px 24px 32px;
        }

        .admin-shell-topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .admin-shell-topbar h1 {
            margin: 0;
            font-size: clamp(2rem, 2.5vw, 2.8rem);
            font-weight: 800;
            color: var(--superadmin-text);
        }

        .admin-shell-topbar .meta {
            color: #64748b;
            margin-top: 6px;
        }

        .superadmin-summary {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .superadmin-summary .pill {
            background: #fff;
            border: 1px solid var(--superadmin-border);
            padding: 10px 14px;
            border-radius: 999px;
            color: #1e293b;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .superadmin-content {
            background: transparent;
        }

        .superadmin-content .card,
        .superadmin-content .table,
        .superadmin-content .alert {
            border-radius: 18px !important;
        }

        .superadmin-content .card {
            border: 1px solid var(--superadmin-border) !important;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.04);
        }

        .superadmin-content .card-header {
            border-bottom: 1px solid var(--superadmin-border) !important;
            font-weight: 700;
        }

        @media (max-width: 992px) {
            .superadmin-header-inner {
                flex-wrap: wrap;
                justify-content: center;
            }

            .superadmin-user {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <header class="superadmin-header">
        <div class="superadmin-header-inner">
            <div class="superadmin-brand">
                <div class="superadmin-brand-mark"><i class="bi bi-diagram-3-fill"></i></div>
                <span>BAJAMA</span>
            </div>

            <nav class="superadmin-nav" aria-label="Main navigation">
                <a class="active" href="superadmin.php"><i class="bi bi-house-door me-1"></i> Overview</a>
                <a href="customers.php"><i class="bi bi-people me-1"></i> Pelanggan</a>
                <a href="registrations.php"><i class="bi bi-person-plus me-1"></i> Pendaftaran</a>
                <a href="licenses.php"><i class="bi bi-patch-check me-1"></i> Lisensi</a>
                <a href="superadmin_billing.php"><i class="bi bi-receipt-cutoff me-1"></i> Billing Global</a>
                <a href="superadmin_isp.php"><i class="bi bi-router me-1"></i> ISP Control</a>
                <a href="payment_methods.php"><i class="bi bi-credit-card me-1"></i> Metode Pembayaran</a>
                <a href="blog_admin.php"><i class="bi bi-journal-richtext me-1"></i> Blog</a>
                <a href="users_roles.php"><i class="bi bi-shield-check me-1"></i> User & Role</a>
                <a href="logout.php" class="text-danger"><i class="bi bi-box-arrow-right me-1"></i> Logout</a>
            </nav>

            <div class="superadmin-user">
                <div class="superadmin-user-badge"><i class="bi bi-person-fill"></i></div>
                <div>
                    <div class="fw-bold">Superadmin</div>
                    <small class="text-white-50">BAJAMA Platform</small>
                </div>
            </div>
        </div>
    </header>

    <main class="superadmin-main">
        <div class="admin-shell-topbar">
            <div>
                <h1>Superadmin Control Center</h1>
                <div class="meta">Fokus khusus pelayanan pelanggan lisensi, pendaftaran, blog, pembayaran, dan operasional platform.</div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a class="btn btn-primary" href="dashboard.php">Dashboard Internal</a>
                <a class="btn btn-outline-dark" href="blog.php" target="_blank">Lihat Blog Publik</a>
            </div>
        </div>

        <div class="superadmin-summary">
            <span class="pill">Pelanggan Lisensi</span>
            <span class="pill">Pendaftaran</span>
            <span class="pill">Pembayaran</span>
            <span class="pill">Blog</span>
            <span class="pill">Platform Operasional</span>
        </div>

        <div class="superadmin-content">
            <?= $content ?>
        </div>
    </main>

    <script>
        (function () {
            const typeSelect = document.querySelector('select[name="type"]');
            const accountNameLabel = document.getElementById('account-name-label');
            const accountNumberLabel = document.getElementById('account-number-label');
            const accountNameInput = document.getElementById('account_name_input');
            const accountNumberInput = document.getElementById('account_number_input');

            function applyPaymentFieldLabels() {
                if (!typeSelect || !accountNameLabel || !accountNumberLabel || !accountNameInput || !accountNumberInput) {
                    return;
                }

                const type = typeSelect.value;

                if (type === 'BANK') {
                    accountNameLabel.textContent = 'Nama rekening';
                    accountNumberLabel.textContent = 'Nomor rekening';
                    accountNameInput.placeholder = 'PT BAJAMA';
                    accountNumberInput.placeholder = '1234567890';
                } else if (type === 'EWALLET') {
                    accountNameLabel.textContent = 'Nama pemilik';
                    accountNumberLabel.textContent = 'Nomor HP aktif';
                    accountNameInput.placeholder = 'Nama pemilik akun';
                    accountNumberInput.placeholder = '0812-3456-7890';
                } else {
                    accountNameLabel.textContent = 'Nama akun / email';
                    accountNumberLabel.textContent = 'ID / akun internasional';
                    accountNameInput.placeholder = 'contoh: customer@example.com';
                    accountNumberInput.placeholder = 'PayPal ID / akun gateway';
                }
            }

            if (typeSelect) {
                typeSelect.addEventListener('change', applyPaymentFieldLabels);
                applyPaymentFieldLabels();
            }
        })();
    </script>
</body>
</html>
