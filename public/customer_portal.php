<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$portalLocale = \BAJAMA\Core\I18n::locale();
$portalTranslations = [
    'id' => ['home' => 'Beranda', 'profile' => 'Profil', 'license' => 'Lisensi', 'organization' => 'Organisasi', 'logout' => 'Keluar', 'customer_portal' => 'Portal Pelanggan', 'welcome' => 'Selamat datang', 'portal_intro' => 'Pantau profil, lisensi aktif, dan informasi organisasi Anda dalam satu tampilan yang lebih rapi dan operasional.', 'license_status' => 'Status lisensi', 'portal_access' => 'Akses portal', 'account_profile' => 'Profil Akun', 'role' => 'Peran', 'manage_profile' => 'Kelola profil', 'active_license' => 'Lisensi Aktif', 'features' => 'Fitur', 'expires' => 'Kadaluarsa', 'license_number' => 'Nomor lisensi', 'organization_info' => 'Informasi Organisasi', 'customers' => 'Pelanggan', 'summary' => 'Ringkasan', 'billing' => 'Billing', 'data_customers' => 'Data pelanggan', 'no_data' => 'Belum ada data.', 'invoices' => 'Invoice', 'payments' => 'Pembayaran', 'plan' => 'Plan', 'key' => 'Key', 'status' => 'Status', 'phone' => 'Telepon'],
    'en' => ['home' => 'Home', 'profile' => 'Profile', 'license' => 'License', 'organization' => 'Organization', 'logout' => 'Log out', 'customer_portal' => 'Customer Portal', 'welcome' => 'Welcome', 'portal_intro' => 'Monitor your profile, active licenses, and organization information in one clear operational view.', 'license_status' => 'License status', 'portal_access' => 'Portal access', 'account_profile' => 'Account profile', 'role' => 'Role', 'manage_profile' => 'Manage profile', 'active_license' => 'Active license', 'features' => 'Features', 'expires' => 'Expires', 'license_number' => 'License number', 'organization_info' => 'Organization information', 'customers' => 'Customers', 'summary' => 'Summary', 'billing' => 'Billing', 'data_customers' => 'Customer data', 'no_data' => 'No data available.', 'invoices' => 'Invoices', 'payments' => 'Payments', 'plan' => 'Plan', 'key' => 'Key', 'status' => 'Status', 'phone' => 'Phone'],
    'ms' => ['home' => 'Laman Utama', 'profile' => 'Profil', 'license' => 'Lesen', 'organization' => 'Organisasi', 'logout' => 'Log keluar', 'customer_portal' => 'Portal Pelanggan', 'welcome' => 'Selamat datang', 'portal_intro' => 'Pantau profil, lesen aktif dan maklumat organisasi anda dalam satu paparan operasi yang kemas.', 'license_status' => 'Status lesen', 'portal_access' => 'Akses portal', 'account_profile' => 'Profil akaun', 'role' => 'Peranan', 'manage_profile' => 'Urus profil', 'active_license' => 'Lesen aktif', 'features' => 'Ciri', 'expires' => 'Tamat tempoh', 'license_number' => 'Nombor lesen', 'organization_info' => 'Maklumat organisasi', 'customers' => 'Pelanggan', 'summary' => 'Ringkasan', 'billing' => 'Bil', 'data_customers' => 'Data pelanggan', 'no_data' => 'Tiada data.', 'invoices' => 'Invois', 'payments' => 'Pembayaran', 'plan' => 'Pelan', 'key' => 'Kunci', 'status' => 'Status', 'phone' => 'Telefon'],
];
$pt = static function (string $key) use ($portalTranslations, $portalLocale): string {
    return $portalTranslations[$portalLocale][$key] ?? $portalTranslations['en'][$key] ?? $key;
};

\BAJAMA\Core\Auth::requireLogin();
$db = db();

$userId = (int)($_SESSION['user_id'] ?? 0);
$organizationId = (int)($_SESSION['organization_id'] ?? 0);

$org = null;
$license = null;
$licenses = [];
$customerSummary = [];
$organizationUsers = [];
$invoices = [];
$payments = [];
$userRoles = [];

if ($userId > 0) {
    try {
        $orgStmt = $db->prepare('SELECT * FROM organizations WHERE id = ? LIMIT 1');
        $orgStmt->execute([$organizationId]);
        $org = $orgStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $org = null;
    }

    try {
        $roleStmt = $db->prepare('SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.name');
        $roleStmt->execute([$userId]);
        $userRoles = array_map('strtoupper', array_values($roleStmt->fetchAll(PDO::FETCH_COLUMN)));
    } catch (Throwable $e) {
        $userRoles = [];
    }

    try {
        $licenseStmt = $db->prepare('SELECT l.*, lp.name AS plan_name, lp.code AS plan_code, lp.price_monthly AS plan_price, lp.features AS plan_features FROM licenses l JOIN license_plans lp ON lp.id = l.plan_id WHERE l.organization_id = ? ORDER BY l.id DESC LIMIT 1');
        $licenseStmt->execute([$organizationId]);
        $license = $licenseStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $license = null;
    }

    try {
        $licensesStmt = $db->prepare('SELECT l.*, lp.name AS plan_name, lp.code AS plan_code, lp.price_monthly AS plan_price, lp.features AS plan_features FROM licenses l LEFT JOIN license_plans lp ON lp.id = l.plan_id WHERE l.organization_id = ? ORDER BY l.id DESC LIMIT 10');
        $licensesStmt->execute([$organizationId]);
        $licenses = $licensesStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $licenses = [];
    }

    try {
        $customerStmt = $db->prepare('SELECT * FROM customers WHERE organization_id = ? ORDER BY id DESC LIMIT 10');
        $customerStmt->execute([$organizationId]);
        $customerSummary = $customerStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $customerSummary = [];
    }

    try {
        $userStmt = $db->prepare('SELECT u.id, u.username, u.full_name, u.email, u.status FROM users u WHERE u.organization_id = ? ORDER BY u.full_name ASC LIMIT 10');
        $userStmt->execute([$organizationId]);
        $organizationUsers = $userStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $organizationUsers = [];
    }

    try {
        $invoiceStmt = $db->prepare('SELECT * FROM invoices WHERE organization_id = ? ORDER BY id DESC LIMIT 5');
        $invoiceStmt->execute([$organizationId]);
        $invoices = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $invoices = [];
    }

    try {
        $paymentStmt = $db->prepare('SELECT * FROM payments WHERE organization_id = ? ORDER BY id DESC LIMIT 5');
        $paymentStmt->execute([$organizationId]);
        $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $payments = [];
    }
}

$portalFeatureLabels = [
    'dashboard' => 'Dashboard', 'billing' => 'Billing tenant', 'mikrotik' => 'MikroTik',
    'pppoe' => 'PPPoE', 'hotspot' => 'Hotspot', 'static' => 'Static IP',
    'fiber' => 'Fiber / FTTH', 'olt' => 'OLT', 'onu' => 'ONU', 'noc' => 'NOC', 'api' => 'API'
];
$portalFeatures = [];
if (!empty($license['plan_features'])) {
    $decodedFeatures = json_decode((string)$license['plan_features'], true);
    if (is_array($decodedFeatures)) {
        foreach ($decodedFeatures as $feature => $enabled) {
            if ($enabled === true || $enabled === 1 || $enabled === '1' || strtolower((string)$enabled) === 'true') {
                $portalFeatures[] = $portalFeatureLabels[$feature] ?? ucwords(str_replace(['_', '-'], ' ', (string)$feature));
            }
        }
    }
}
if ($license && !in_array('Dashboard', $portalFeatures, true)) {
    array_unshift($portalFeatures, 'Dashboard');
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars($portalLocale, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pt('customer_portal'), ENT_QUOTES, 'UTF-8') ?> BAJAMA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(180deg, #f4f7fb 0%, #edf4ff 100%);
            color: #172033;
        }

        .portal-hero {
            background: radial-gradient(circle at top left, rgba(37,99,235,0.30), transparent 25%),
                linear-gradient(135deg, #0f172a 0%, #111827 45%, #0ea5e9 100%);
        }

        .card-soft {
            border: 0;
            border-radius: 22px;
            box-shadow: 0 24px 50px rgba(15,23,42,.08);
        }

        .quick-action {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 16px;
            padding: 1rem;
            background: linear-gradient(180deg, #ffffff, #f8fafc);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .quick-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.08);
        }

        .mini-stat {
            background: linear-gradient(135deg, rgba(14,165,233,0.10), rgba(15,23,42,0.03));
            border: 1px solid rgba(148,163,184,0.12);
            border-radius: 16px;
            padding: 0.9rem 1rem;
        }

        .tab-card {
            background: #fff;
            border: 0;
            border-radius: 22px;
            box-shadow: 0 24px 50px rgba(15,23,42,.08);
        }

        .status-highlight {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 18px;
            padding: 0.9rem 1rem;
        }

        .customer-portal-nav .navbar-brand,
        .customer-portal-nav .nav-link {
            color: rgba(255,255,255,0.92) !important;
        }

        .customer-portal-nav .nav-link:hover,
        .customer-portal-nav .nav-link:focus,
        .customer-portal-nav .nav-link.active {
            color: #ffffff !important;
        }

        .customer-portal-nav .navbar-brand:hover {
            color: #ffffff !important;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg bg-dark navbar-dark sticky-top customer-portal-nav">
        <div class="container">
            <a class="navbar-brand fw-bold" href="dashboard.php">BAJAMA</a>
            <div class="ms-auto d-flex gap-3 small align-items-center flex-wrap">
                <div class="dropdown" aria-label="Language">
                    <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-translate me-1"></i><?= htmlspecialchars(\BAJAMA\Core\I18n::languageName($portalLocale), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <?php foreach (\BAJAMA\Core\I18n::supported() as $supportedLocale): ?>
                            <li><a class="dropdown-item <?= $supportedLocale === $portalLocale ? 'active' : '' ?>" href="?lang=<?= urlencode($supportedLocale) ?>"><?= htmlspecialchars(\BAJAMA\Core\I18n::languageName($supportedLocale), ENT_QUOTES, 'UTF-8') ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <a class="nav-link" href="dashboard.php"><?= htmlspecialchars($pt('home'), ENT_QUOTES, 'UTF-8') ?></a>
                <a class="nav-link" href="#profile"><?= htmlspecialchars($pt('profile'), ENT_QUOTES, 'UTF-8') ?></a>
                <a class="nav-link" href="#license"><?= htmlspecialchars($pt('license'), ENT_QUOTES, 'UTF-8') ?></a>
                <a class="nav-link" href="#organization"><?= htmlspecialchars($pt('organization'), ENT_QUOTES, 'UTF-8') ?></a>
                <a href="logout.php" class="btn btn-outline-light btn-sm"><?= htmlspecialchars($pt('logout'), ENT_QUOTES, 'UTF-8') ?></a>
            </div>
        </div>
    </nav>

    <header class="portal-hero text-white">
        <div class="container py-5">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <div class="text-uppercase small text-info fw-semibold"><?= htmlspecialchars($pt('customer_portal'), ENT_QUOTES, 'UTF-8') ?></div>
                    <h1 class="fw-bold mb-1"><?= htmlspecialchars($pt('welcome'), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($_SESSION['full_name'] ?? $pt('customers'), ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="mb-0 text-light"><?= htmlspecialchars($pt('portal_intro'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="badge bg-light text-dark px-3 py-2 rounded-pill text-uppercase">
                    <?= htmlspecialchars(implode(', ', $userRoles) ?: 'OWNER', ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>

            <div class="row g-3 mt-3">
                <div class="col-md-4">
                    <div class="status-highlight text-white">
                        <div class="small text-light-emphasis"><?= htmlspecialchars($pt('license_status'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="fw-bold mt-1"><?= htmlspecialchars((string)($license['status'] ?? 'NONE'), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="status-highlight text-white">
                        <div class="small text-light-emphasis"><?= htmlspecialchars($pt('organization'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="fw-bold mt-1"><?= htmlspecialchars((string)($org['name'] ?? 'Belum tersedia'), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="status-highlight text-white">
                        <div class="small text-light-emphasis"><?= htmlspecialchars($pt('portal_access'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="fw-bold mt-1"><?= htmlspecialchars(implode(', ', $userRoles) ?: 'OWNER', ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="container py-5">
        <div class="row g-4 mb-4">
            <div class="col-lg-4" id="profile">
                <div class="card card-soft h-100">
                    <div class="card-body p-4">
                        <div class="text-muted small mb-2"><?= htmlspecialchars($pt('account_profile'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="rounded-circle bg-primary text-white d-flex justify-content-center align-items-center" style="width:52px;height:52px;font-size:1.4rem;font-weight:700;">
                                <?= htmlspecialchars(substr((string)($_SESSION['full_name'] ?? 'P'), 0, 1), ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div>
                                <div class="fw-bold"><?= htmlspecialchars($_SESSION['full_name'] ?? 'Pelanggan', ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="text-muted small"><?= htmlspecialchars($_SESSION['username'] ?? 'customer', ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        </div>
                        <div class="small text-muted mb-1"><?= htmlspecialchars($pt('role'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="fw-semibold mb-3"><?= htmlspecialchars(implode(', ', $userRoles) ?: 'OWNER', ENT_QUOTES, 'UTF-8') ?></div>
                        <a href="profile.php" class="btn btn-outline-dark btn-sm"><?= htmlspecialchars($pt('manage_profile'), ENT_QUOTES, 'UTF-8') ?></a>
                    </div>
                </div>
            </div>

            <div class="col-lg-4" id="license">
                <div class="card card-soft h-100">
                    <div class="card-body p-4">
                        <div class="text-muted small mb-2"><?= htmlspecialchars($pt('active_license'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="fw-bold fs-5"><?= htmlspecialchars((string)($license['plan_name'] ?? $license['plan_code'] ?? 'Belum ada'), ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if ($license): ?><div class="text-primary fw-bold mt-2">Rp <?= number_format((float)($license['plan_price'] ?? 0), 0, ',', '.') ?> <span class="text-muted small fw-normal">/ bulan</span></div><?php endif; ?>
                        <?php if ($portalFeatures): ?><div class="small text-muted mt-2"><?= htmlspecialchars($pt('features'), ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars(implode(', ', $portalFeatures), ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                        <div class="text-muted small mt-2"><?= htmlspecialchars($pt('status'), ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars((string)($license['status'] ?? 'NONE'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="text-muted small"><?= htmlspecialchars($pt('expires'), ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars((string)($license['expires_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="mt-3 small text-muted"><?= htmlspecialchars($pt('license_number'), ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars((string)($license['license_key'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4" id="organization">
                <div class="card card-soft h-100">
                    <div class="card-body p-4">
                        <div class="text-muted small mb-2"><?= htmlspecialchars($pt('organization_info'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="fw-bold fs-5"><?= htmlspecialchars((string)($org['name'] ?? 'Belum tersedia'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="text-muted small mt-2">Email: <?= htmlspecialchars((string)($org['email'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="text-muted small"><?= htmlspecialchars($pt('phone'), ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars((string)($org['phone'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="text-muted small"><?= htmlspecialchars($pt('status'), ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars((string)($org['status'] ?? 'ACTIVE'), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="mini-stat">
                    <div class="text-muted small"><?= htmlspecialchars($pt('license_status'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="fw-bold fs-5 mt-1"><?= htmlspecialchars((string)($license['status'] ?? 'NONE'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="mini-stat">
                    <div class="text-muted small"><?= htmlspecialchars($pt('organization'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="fw-bold fs-5 mt-1"><?= htmlspecialchars((string)($org['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="mini-stat">
                    <div class="text-muted small"><?= htmlspecialchars($pt('customers'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="fw-bold fs-5 mt-1"><?= count($customerSummary) ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="mini-stat">
                    <div class="text-muted small"><?= htmlspecialchars($pt('role'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="fw-bold fs-5 mt-1"><?= htmlspecialchars(implode(', ', $userRoles) ?: 'OWNER', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <a href="profile.php" class="quick-action d-block text-decoration-none text-dark">
                    <div class="fw-semibold mb-1"><?= htmlspecialchars($pt('profile'), ENT_QUOTES, 'UTF-8') ?></div>
                    <small class="text-muted">Edit data akun dan password</small>
                </a>
            </div>
            <div class="col-md-3">
                <a href="#tab-licenses" class="quick-action d-block text-decoration-none text-dark">
                    <div class="fw-semibold mb-1"><?= htmlspecialchars($pt('license'), ENT_QUOTES, 'UTF-8') ?></div>
                    <small class="text-muted">Lihat status dan paket aktif</small>
                </a>
            </div>
            <div class="col-md-3">
                <a href="#tab-customers" class="quick-action d-block text-decoration-none text-dark">
                    <div class="fw-semibold mb-1"><?= htmlspecialchars($pt('customers'), ENT_QUOTES, 'UTF-8') ?></div>
                    <small class="text-muted">Kelola pelanggan organisasi</small>
                </a>
            </div>
            <div class="col-md-3">
                <a href="#tab-billing" class="quick-action d-block text-decoration-none text-dark">
                    <div class="fw-semibold mb-1"><?= htmlspecialchars($pt('billing'), ENT_QUOTES, 'UTF-8') ?></div>
                    <small class="text-muted">Cek invoice dan pembayaran</small>
                </a>
            </div>
        </div>

        <div class="card tab-card mb-4">
            <div class="card-body p-0">
                <ul class="nav nav-tabs nav-fill border-0 px-3 pt-3" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button"><?= htmlspecialchars($pt('summary'), ENT_QUOTES, 'UTF-8') ?></button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-licenses" type="button"><?= htmlspecialchars($pt('license'), ENT_QUOTES, 'UTF-8') ?></button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-customers" type="button"><?= htmlspecialchars($pt('customers'), ENT_QUOTES, 'UTF-8') ?></button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-billing" type="button"><?= htmlspecialchars($pt('billing'), ENT_QUOTES, 'UTF-8') ?></button></li>
                </ul>
                <div class="tab-content p-3">
                    <div class="tab-pane fade show active" id="tab-overview">
                        <div class="row g-4">
                            <div class="col-lg-8">
                                <div class="card border-0 bg-light h-100">
                                    <div class="card-body">
                                        <h3 class="h6 fw-bold mb-3"><?= htmlspecialchars($pt('data_customers'), ENT_QUOTES, 'UTF-8') ?></h3>
                                        <div class="table-responsive">
                                            <table class="table mb-0 align-middle">
                                                <thead>
                                                    <tr><th>Nama</th><th>Email</th><th>Telepon</th><th>Status</th></tr>
                                                </thead>
                                                <tbody>
                                                    <?php if ($customerSummary): ?>
                                                        <?php foreach ($customerSummary as $customer): ?>
                                                            <tr>
                                                                <td><?= htmlspecialchars((string)($customer['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                                                <td><?= htmlspecialchars((string)($customer['email'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                                                <td><?= htmlspecialchars((string)($customer['phone'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                                                <td><span class="badge bg-success"><?= htmlspecialchars((string)($customer['status'] ?? 'ACTIVE'), ENT_QUOTES, 'UTF-8') ?></span></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <tr><td colspan="4" class="text-center text-muted py-4"><?= htmlspecialchars($pt('no_data'), ENT_QUOTES, 'UTF-8') ?></td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="card border-0 bg-light h-100">
                                    <div class="card-body">
                                        <h3 class="h6 fw-bold mb-3"><?= htmlspecialchars($pt('summary'), ENT_QUOTES, 'UTF-8') ?></h3>
                                        <div class="d-flex justify-content-between border-bottom pb-2 mb-2"><span>Status lisensi</span><strong><?= htmlspecialchars((string)($license['status'] ?? 'NONE'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                                        <div class="d-flex justify-content-between border-bottom pb-2 mb-2"><span>Jumlah pelanggan</span><strong><?= count($customerSummary) ?></strong></div>
                                        <div class="d-flex justify-content-between border-bottom pb-2 mb-2"><span>Organisasi</span><strong><?= htmlspecialchars((string)($org['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                                        <div class="d-flex justify-content-between"><span>Peran</span><strong><?= htmlspecialchars(implode(', ', $userRoles) ?: 'OWNER', ENT_QUOTES, 'UTF-8') ?></strong></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="tab-licenses">
                        <div class="table-responsive">
                            <table class="table mb-0 align-middle">
                                <thead><tr><th>Plan</th><th>Key</th><th>Status</th><th>Expired</th></tr></thead>
                                <tbody>
                                    <?php if ($licenses): ?>
                                        <?php foreach ($licenses as $licenseItem): ?>
                                            <tr>
                                                <td><div class="fw-semibold"><?= htmlspecialchars((string)($licenseItem['plan_name'] ?? $licenseItem['plan_code'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div><small class="text-muted">Rp <?= number_format((float)($licenseItem['plan_price'] ?? 0), 0, ',', '.') ?>/bulan</small></td>
                                                <td><?= htmlspecialchars((string)($licenseItem['license_key'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><span class="badge bg-success"><?= htmlspecialchars((string)($licenseItem['status'] ?? 'ACTIVE'), ENT_QUOTES, 'UTF-8') ?></span></td>
                                                <td><?= htmlspecialchars((string)($licenseItem['expires_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="text-center text-muted py-4"><?= htmlspecialchars($pt('no_data'), ENT_QUOTES, 'UTF-8') ?></td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="tab-customers">
                        <div class="table-responsive">
                            <table class="table mb-0 align-middle">
                                <thead><tr><th>Nama</th><th>Email</th><th>Telepon</th><th>Status</th></tr></thead>
                                <tbody>
                                    <?php if ($organizationUsers): ?>
                                        <?php foreach ($organizationUsers as $orgUser): ?>
                                            <tr>
                                                <td><?= htmlspecialchars((string)($orgUser['full_name'] ?? $orgUser['username'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string)($orgUser['email'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td>-</td>
                                                <td><span class="badge bg-light text-dark"><?= htmlspecialchars((string)($orgUser['status'] ?? 'ACTIVE'), ENT_QUOTES, 'UTF-8') ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="text-center text-muted py-4"><?= htmlspecialchars($pt('no_data'), ENT_QUOTES, 'UTF-8') ?></td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="tab-billing">
                        <div class="row g-4">
                            <div class="col-lg-6">
                                <h3 class="h6 fw-bold mb-3"><?= htmlspecialchars($pt('invoices'), ENT_QUOTES, 'UTF-8') ?></h3>
                                <div class="table-responsive">
                                    <table class="table mb-0 align-middle">
                                        <thead><tr><th>ID</th><th>Total</th><th>Status</th></tr></thead>
                                        <tbody>
                                            <?php if ($invoices): ?>
                                                <?php foreach ($invoices as $invoice): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars((string)($invoice['invoice_number'] ?? $invoice['id'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars((string)($invoice['total'] ?? '0'), ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><span class="badge bg-light text-dark"><?= htmlspecialchars((string)($invoice['status'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="3" class="text-center text-muted py-4"><?= htmlspecialchars($pt('no_data'), ENT_QUOTES, 'UTF-8') ?></td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <h3 class="h6 fw-bold mb-3"><?= htmlspecialchars($pt('payments'), ENT_QUOTES, 'UTF-8') ?></h3>
                                <div class="table-responsive">
                                    <table class="table mb-0 align-middle">
                                        <thead><tr><th>Jumlah</th><th>Metode</th><th>Status</th></tr></thead>
                                        <tbody>
                                            <?php if ($payments): ?>
                                                <?php foreach ($payments as $payment): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars((string)($payment['amount'] ?? '0'), ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars((string)($payment['payment_method'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><span class="badge bg-success"><?= htmlspecialchars((string)($payment['status'] ?? 'SUCCESS'), ENT_QUOTES, 'UTF-8') ?></span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="3" class="text-center text-muted py-4"><?= htmlspecialchars($pt('no_data'), ENT_QUOTES, 'UTF-8') ?></td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
