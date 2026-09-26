<?php

require_once __DIR__ . '/../app/bootstrap.php';
/* BAJAMA_RBAC_ENFORCED */
\BAJAMA\Core\Auth::requireLogin();
\BAJAMA\Core\License::requireFeature(\db(), 'dashboard');
\BAJAMA\Core\RBAC::require(\db(), 'dashboard.view');


if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (function_exists('requireLogin')) {
    requireLogin();
} elseif (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Dashboard';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['username'] ?? 'User';

$organizationName = 'BAJAMA';
$licensePlan = 'TRIAL';
$licenseStatus = 'ACTIVE';
$licenseExpires = null;

$customerCount = 0;
$subscriptionCount = 0;
$invoiceCount = 0;
$paymentCount = 0;
$routerCount = 0;

$superAdminLicenseCustomers = 0;
$superAdminOrganizations = 0;
$superAdminLicenses = 0;
$superAdminPendingRegistrations = 0;

$db = null;


/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION
|--------------------------------------------------------------------------
| Dibuat terpisah supaya Dashboard tidak mengubah sistem database/login.
|--------------------------------------------------------------------------
*/

try {

    $env = [];

    $envFile = dirname(__DIR__) . '/.env';

    if (is_readable($envFile)) {

        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {

            $line = trim($line);

            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            if (strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $env[trim($key)] = trim($value);
        }
    }

    $dbHost = $env['DB_HOST'] ?? '127.0.0.1';
    $dbPort = $env['DB_PORT'] ?? '3306';
    $dbName = $env['DB_DATABASE'] ?? 'bajama';
    $dbUser = $env['DB_USERNAME'] ?? 'bajama';
    $dbPass = $env['DB_PASSWORD'] ?? '';

    $db = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );


    /*
    |--------------------------------------------------------------------------
    | CURRENT USER / ORGANIZATION
    |--------------------------------------------------------------------------
    */

    if ($userId > 0) {

        $stmt = $db->prepare("
            SELECT
                u.id,
                u.username,
                u.email,
                u.organization_id,
                o.name AS organization_name
            FROM users u
            LEFT JOIN organizations o
                ON o.id = u.organization_id
            WHERE u.id = ?
            LIMIT 1
        ");

        $stmt->execute([$userId]);

        $currentUser = $stmt->fetch();

        if ($currentUser) {

            if (!empty($currentUser['organization_name'])) {
                $organizationName = $currentUser['organization_name'];
            }

            if (!empty($currentUser['username'])) {
                $userName = $currentUser['username'];
            }

            $organizationId = (int) ($currentUser['organization_id'] ?? 0);
        } else {
            $organizationId = 0;
        }

        $roleStmt = $db->prepare("
            SELECT r.name
            FROM user_roles ur
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ?
            ORDER BY r.name
        ");
        $roleStmt->execute([$userId]);
        $userRoles = array_map('strtoupper', array_values($roleStmt->fetchAll(PDO::FETCH_COLUMN)));
        $isSuperAdmin = in_array('SUPER_ADMIN', $userRoles, true);
        $isOwner = in_array('OWNER', $userRoles, true);

    } else {
        $organizationId = 0;
        $userRoles = [];
        $isSuperAdmin = false;
        $isOwner = false;
    }


    /*
    |--------------------------------------------------------------------------
    | LICENSE
    |--------------------------------------------------------------------------
    */

    if ($isSuperAdmin) {
        $licensePlan = 'BAJAMA SUPERADMIN';
        $licenseStatus = 'FULL ACCESS';
        $licenseExpires = null;
    } elseif ($organizationId > 0) {

        $stmt = $db->prepare("
            SELECT
                l.status,
                l.expires_at,
                p.code AS plan_code,
                p.name AS plan_name
            FROM licenses l
            LEFT JOIN license_plans p
                ON p.id = l.plan_id
            WHERE l.organization_id = ?
            ORDER BY l.id DESC
            LIMIT 1
        ");

        $stmt->execute([$organizationId]);

        $license = $stmt->fetch();

        if ($license) {

            $licenseStatus = \BAJAMA\Core\License::status($db);

            $licensePlan =
                $license['plan_name']
                ?: ($license['plan_code'] ?? 'TRIAL');

            $licenseExpires = $license['expires_at'] ?? null;
        }

    }


    /*
    |--------------------------------------------------------------------------
    | CUSTOMER COUNT
    |--------------------------------------------------------------------------
    */

    if ($organizationId > 0) {

        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM customers
            WHERE organization_id = ?
        ");

        $stmt->execute([$organizationId]);

        $customerCount = (int) $stmt->fetchColumn();


        /*
        |--------------------------------------------------------------------------
        | SUBSCRIPTIONS
        |--------------------------------------------------------------------------
        */

        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM subscriptions
            WHERE organization_id = ?
        ");

        $stmt->execute([$organizationId]);

        $subscriptionCount = (int) $stmt->fetchColumn();


        /*
        |--------------------------------------------------------------------------
        | INVOICES
        |--------------------------------------------------------------------------
        */

        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM invoices
            WHERE organization_id = ?
        ");

        $stmt->execute([$organizationId]);

        $invoiceCount = (int) $stmt->fetchColumn();


        /*
        |--------------------------------------------------------------------------
        | PAYMENTS
        |--------------------------------------------------------------------------
        */

        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM payments
            WHERE organization_id = ?
        ");

        $stmt->execute([$organizationId]);

        $paymentCount = (int) $stmt->fetchColumn();

    }

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | IMPORTANT
    |--------------------------------------------------------------------------
    | Dashboard tidak boleh membuat login gagal hanya karena statistik gagal.
    |--------------------------------------------------------------------------
    */

    $customerCount = 0;
    $subscriptionCount = 0;
    $invoiceCount = 0;
    $paymentCount = 0;
}

if ($isSuperAdmin && $db instanceof PDO) {
    try {
        $superAdminLicenseCustomers = (int) $db->query('SELECT COUNT(*) FROM customers')->fetchColumn();
        $superAdminOrganizations = (int) $db->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
        $superAdminLicenses = (int) $db->query('SELECT COUNT(*) FROM licenses WHERE status = "ACTIVE"')->fetchColumn();
        $superAdminPendingRegistrations = (int) $db->query("SELECT COUNT(*) FROM license_registrations WHERE status = 'PENDING'")->fetchColumn();
    } catch (Throwable $e) {
        $superAdminLicenseCustomers = 0;
        $superAdminOrganizations = 0;
        $superAdminLicenses = 0;
        $superAdminPendingRegistrations = 0;
    }
}

if ($isSuperAdmin && $db instanceof PDO) {
    $superAdminStats = [
        'pending' => 0,
        'paid' => 0,
        'approved' => 0,
        'rejected' => 0,
        'customers' => 0,
        'orgs' => 0,
        'licenses' => 0,
        'blogs' => 0,
    ];

    try {
        $superAdminStats['pending'] = (int) $db->query("SELECT COUNT(*) FROM license_registrations WHERE status = 'PENDING'")->fetchColumn();
        $superAdminStats['paid'] = (int) $db->query("SELECT COUNT(*) FROM license_registrations WHERE status = 'PAID'")->fetchColumn();
        $superAdminStats['approved'] = (int) $db->query("SELECT COUNT(*) FROM license_registrations WHERE status = 'APPROVED'")->fetchColumn();
        $superAdminStats['rejected'] = (int) $db->query("SELECT COUNT(*) FROM license_registrations WHERE status = 'REJECTED'")->fetchColumn();
        $superAdminStats['customers'] = (int) $db->query('SELECT COUNT(*) FROM customers')->fetchColumn();
        $superAdminStats['orgs'] = (int) $db->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
        $superAdminStats['licenses'] = (int) $db->query('SELECT COUNT(*) FROM licenses')->fetchColumn();
        $superAdminStats['blogs'] = (int) $db->query("SELECT COUNT(*) FROM blog_posts WHERE status = 'PUBLISHED'")->fetchColumn();
    } catch (Throwable $e) {
        $superAdminStats = [
            'pending' => 0,
            'paid' => 0,
            'approved' => 0,
            'rejected' => 0,
            'customers' => 0,
            'orgs' => 0,
            'licenses' => 0,
            'blogs' => 0,
        ];
    }
}

ob_start();

?>

<?php if ($isSuperAdmin): ?>
<div class="container-fluid py-4 superadmin-shell">
    <div class="sa-hero card border-0 mb-4">
        <div class="card-body p-4 p-xl-5">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
                <div>
                    <span class="eyebrow">BAJAMA CONTROL CENTER</span>
                    <h1 class="fw-bold mb-1 text-white">Superadmin dashboard</h1>
                    <p class="mb-0 text-white-50">Platform lisensi, pelanggan, dan operasional bisnis BAJAMA dalam satu ekosistem modern.</p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a class="btn btn-light btn-sm px-3" href="customers.php">Pelanggan</a>
                    <a class="btn btn-outline-light btn-sm px-3" href="blog_admin.php">Kelola Blog</a>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-3">
                    <div class="mini-stat">
                        <div class="mini-stat-label">Pendaftaran</div>
                        <div class="mini-stat-value"><?= (int)($superAdminStats['pending'] ?? 0) ?></div>
                        <div class="mini-stat-foot">Menunggu review</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mini-stat">
                        <div class="mini-stat-label">Sudah bayar</div>
                        <div class="mini-stat-value"><?= (int)($superAdminStats['paid'] ?? 0) ?></div>
                        <div class="mini-stat-foot">Pembayaran masuk</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mini-stat">
                        <div class="mini-stat-label">Disetujui</div>
                        <div class="mini-stat-value"><?= (int)($superAdminStats['approved'] ?? 0) ?></div>
                        <div class="mini-stat-foot">Akun aktif</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mini-stat">
                        <div class="mini-stat-label">Pelanggan</div>
                        <div class="mini-stat-value"><?= (int)($superAdminStats['customers'] ?? 0) ?></div>
                        <div class="mini-stat-foot">Lisensi aktif</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($superAdminMessage)): ?><div class="alert alert-success rounded-4"><?= htmlspecialchars($superAdminMessage, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if (!empty($superAdminError)): ?><div class="alert alert-danger rounded-4"><?= htmlspecialchars($superAdminError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm h-100 panel-card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center border-0 py-3 px-4">
                    <div>
                        <h5 class="mb-0 fw-bold">Pipeline lisensi</h5>
                        <small class="text-muted">Ringkasan lead dan status pembayaran</small>
                    </div>
                    <span class="badge text-bg-light rounded-pill">Live</span>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-8">
                            <div class="chart-bars" aria-label="Bar chart registration stats">
                                <?php
                                $chartSeries = [
                                    ['label' => 'Pending', 'value' => (int)($superAdminStats['pending'] ?? 0), 'color' => '#3b82f6'],
                                    ['label' => 'Paid', 'value' => (int)($superAdminStats['paid'] ?? 0), 'color' => '#10b981'],
                                    ['label' => 'Approved', 'value' => (int)($superAdminStats['approved'] ?? 0), 'color' => '#06b6d4'],
                                    ['label' => 'Rejected', 'value' => (int)($superAdminStats['rejected'] ?? 0), 'color' => '#f97316'],
                                ];
                                $chartMax = max(1, max(array_column($chartSeries, 'value')));
                                foreach ($chartSeries as $series):
                                    $barHeight = max(10, (int) round(($series['value'] / $chartMax) * 100));
                                    echo '<div class="chart-bar-item">';
                                    echo '<div class="chart-bar-wrap"><span class="chart-bar" style="height:' . $barHeight . '%; background:' . $series['color'] . ';"></span></div>';
                                    echo '<small>' . htmlspecialchars($series['label'], ENT_QUOTES, 'UTF-8') . '</small>';
                                    echo '<strong>' . (int)$series['value'] . '</strong>';
                                    echo '</div>';
                                endforeach;
                                ?>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="donut-card">
                                <div class="donut-chart" style="background: conic-gradient(#3b82f6 0 35%, #10b981 35% 65%, #06b6d4 65% 100%);"></div>
                                <div class="donut-center">
                                    <strong><?= (int)(($superAdminStats['approved'] ?? 0) + ($superAdminStats['paid'] ?? 0)) ?></strong>
                                    <small>Hot leads</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card border-0 shadow-sm h-100 panel-card">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center border-0 py-3 px-4">
                    <span>Platform health</span>
                    <span class="badge text-bg-success rounded-pill">Online</span>
                </div>
                <div class="card-body p-4">
                    <div class="platform-metric">
                        <div class="metric-header"><span>Pelanggan lisensi</span><strong><?= (int)($superAdminStats['customers'] ?? 0) ?></strong></div>
                        <div class="progress"><div class="progress-bar bg-primary" style="width: 82%"></div></div>
                    </div>
                    <div class="platform-metric">
                        <div class="metric-header"><span>Organisasi aktif</span><strong><?= (int)($superAdminStats['orgs'] ?? 0) ?></strong></div>
                        <div class="progress"><div class="progress-bar bg-success" style="width: 68%"></div></div>
                    </div>
                    <div class="platform-metric">
                        <div class="metric-header"><span>Lisensi aktif</span><strong><?= (int)($superAdminStats['licenses'] ?? 0) ?></strong></div>
                        <div class="progress"><div class="progress-bar bg-warning" style="width: 73%"></div></div>
                    </div>
                    <div class="platform-metric">
                        <div class="metric-header"><span>Konten publik</span><strong><?= (int)($superAdminStats['blogs'] ?? 0) ?></strong></div>
                        <div class="progress"><div class="progress-bar bg-info" style="width: 60%"></div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card admin-stat border-0 h-100"><div class="card-body"><div class="text-muted small">Total pelanggan</div><div class="display-6 fw-bold"><?= (int)($superAdminStats['customers'] ?? 0) ?></div></div></div></div>
        <div class="col-md-3"><div class="card admin-stat border-0 h-100"><div class="card-body"><div class="text-muted small">Organisasi</div><div class="display-6 fw-bold"><?= (int)($superAdminStats['orgs'] ?? 0) ?></div></div></div></div>
        <div class="col-md-3"><div class="card admin-stat border-0 h-100"><div class="card-body"><div class="text-muted small">Lisensi aktif</div><div class="display-6 fw-bold"><?= (int)($superAdminStats['licenses'] ?? 0) ?></div></div></div></div>
        <div class="col-md-3"><div class="card admin-stat border-0 h-100"><div class="card-body"><div class="text-muted small">Artikel publik</div><div class="display-6 fw-bold"><?= (int)($superAdminStats['blogs'] ?? 0) ?></div></div></div></div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-4">
            <div class="card border-0 shadow-sm h-100 panel-card">
                <div class="card-header bg-white border-0 py-3 px-4 d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0 fw-bold">Quick actions</h5>
                        <small class="text-muted">Workflow utama</small>
                    </div>
                    <i class="bi bi-lightning-charge-fill text-warning"></i>
                </div>
                <div class="card-body p-3">
                    <div class="d-grid gap-2">
                        <a href="customers.php" class="action-panel"><i class="bi bi-people"></i><span>Kelola pelanggan lisensi</span></a>
                        <a href="licenses.php" class="action-panel"><i class="bi bi-patch-check"></i><span>Kelola lisensi</span></a>
                        <a href="registrations.php" class="action-panel"><i class="bi bi-clipboard-check"></i><span>Review pendaftaran</span></a>
                        <a href="blog_admin.php" class="action-panel"><i class="bi bi-journal-richtext"></i><span>Kelola blog publik</span></a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <div class="card border-0 shadow-sm h-100 panel-card">
                <div class="card-header bg-white border-0 py-3 px-4 d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0 fw-bold">Recent activity</h5>
                        <small class="text-muted">Aktivitas operasional terkini</small>
                    </div>
                    <a class="btn btn-sm btn-outline-primary rounded-pill" href="audit_logs.php">Audit</a>
                </div>
                <div class="card-body p-0">
                    <div class="activity-list">
                        <div class="activity-item">
                            <span class="activity-bullet bg-primary"></span>
                            <div>
                                <strong>Pendaftaran menunggu approval</strong>
                                <small><?= (int)($superAdminStats['pending'] ?? 0) ?> antrian baru</small>
                            </div>
                        </div>
                        <div class="activity-item">
                            <span class="activity-bullet bg-success"></span>
                            <div>
                                <strong>Pelanggan aktif</strong>
                                <small><?= (int)($superAdminStats['customers'] ?? 0) ?> pelanggan terdaftar</small>
                            </div>
                        </div>
                        <div class="activity-item">
                            <span class="activity-bullet bg-info"></span>
                            <div>
                                <strong>Lisensi aktif</strong>
                                <small><?= (int)($superAdminStats['licenses'] ?? 0) ?> lisensi berjalan</small>
                            </div>
                        </div>
                        <div class="activity-item">
                            <span class="activity-bullet bg-warning"></span>
                            <div>
                                <strong>Konten publik</strong>
                                <small><?= (int)($superAdminStats['blogs'] ?? 0) ?> artikel dipublikasikan</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .superadmin-shell {
            background: linear-gradient(180deg, #f8fafc 0%, #eef4ff 100%);
            min-height: 100%;
        }
        .sa-hero {
            background: linear-gradient(135deg, #0f172a 0%, #122b66 40%, #2563eb 100%);
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 28px 70px rgba(15, 23, 42, 0.16);
        }
        .eyebrow {
            display: inline-block;
            color: rgba(255,255,255,0.75);
            letter-spacing: 0.12em;
            font-size: 0.72rem;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .mini-stat {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 18px;
            padding: 18px 16px;
            backdrop-filter: blur(8px);
        }
        .mini-stat-label {
            color: rgba(255,255,255,0.72);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .mini-stat-value {
            font-size: clamp(1.7rem, 2vw, 2.5rem);
            font-weight: 800;
            line-height: 1.2;
            margin: 8px 0 4px;
            color: #ffffff;
        }
        .mini-stat-foot {
            font-size: 0.8rem;
            color: rgba(255,255,255,0.7);
        }
        .panel-card {
            border-radius: 22px;
        }
        .admin-stat {
            background: linear-gradient(135deg, #ffffff 0%, #eef6ff 100%);
            border: 1px solid rgba(15, 23, 42, 0.05);
            border-radius: 22px;
            box-shadow: 0 22px 48px rgba(15, 23, 42, 0.06);
            min-height: 140px;
        }
        .admin-stat .display-6 { font-size: clamp(1.8rem, 3vw, 2.5rem); }
        .chart-bars {
            display: flex; align-items: end; justify-content: space-between; gap: 18px; height: 210px; padding: 16px 8px 8px;
            border: 1px solid rgba(148, 163, 184, 0.22); border-radius: 18px; background: linear-gradient(180deg, #f8fbff, #edf5ff);
        }
        .chart-bar-item { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: end; gap: 8px; min-width: 58px; }
        .chart-bar-wrap { width: 100%; height: 150px; display: flex; align-items: end; justify-content: center; }
        .chart-bar { width: 72%; border-radius: 12px 12px 0 0; display: block; min-height: 12px; box-shadow: inset 0 -8px 20px rgba(255,255,255,0.16); }
        .chart-bar-item small { color: #64748b; font-weight: 600; }
        .chart-bar-item strong { font-size: 0.82rem; }
        .donut-card {
            position: relative; width: 150px; height: 150px; margin: 8px auto 0; display: grid; place-items: center;
        }
        .donut-chart {
            width: 150px; height: 150px; border-radius: 50%; position: absolute; inset: 0;
            box-shadow: inset 0 0 18px rgba(15, 23, 42, 0.08);
        }
        .donut-center {
            position: relative; width: 90px; height: 90px; border-radius: 50%; background: white; display: flex; flex-direction: column; align-items: center; justify-content: center; box-shadow: 0 16px 30px rgba(15, 23, 42, 0.08);
        }
        .donut-center strong { font-size: 1.2rem; }
        .donut-center small { color: #64748b; }
        .platform-metric { margin-bottom: 18px; }
        .metric-header {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; color: #334155; font-size: 0.92rem;
        }
        .metric-header strong { color: #0f172a; }
        .progress { height: 10px; border-radius: 999px; background: rgba(148,163,184,0.14); }
        .progress-bar { border-radius: 999px; }
        .action-panel {
            display: flex; align-items: center; gap: 12px; padding: 13px 14px; border-radius: 14px; border: 1px solid rgba(148,163,184,0.2);
            background: linear-gradient(135deg, #f8fbff, #f0f7ff); color: #0f172a; text-decoration: none; font-weight: 600;
        }
        .action-panel i { width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; background: rgba(59,130,246,0.1); color: #2563eb; }
        .activity-list { padding: 8px 0; }
        .activity-item {
            display: flex; align-items: center; gap: 12px; padding: 15px 18px; border-bottom: 1px solid rgba(148,163,184,0.18);
        }
        .activity-item:last-child { border-bottom: 0; }
        .activity-bullet {
            width: 12px; height: 12px; border-radius: 50%; display: inline-block; box-shadow: 0 0 0 5px rgba(148,163,184,0.1);
        }
        .activity-item strong { display: block; font-size: 0.95rem; }
        .activity-item small { color: #64748b; }
    </style>
</div>
<?php else: ?>
<div class="dashboard-welcome">
    <h2>Selamat datang, <?= htmlspecialchars($userName) ?> 👋</h2>
    <p>Di BAJAMA. Silahkan kelola bisnis ISP anda dan infrastruktur jaringan Anda dari satu platform.</p>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-top">
                <div>
                    <div class="stat-title">Customers</div>
                    <div class="stat-value"><?= number_format($customerCount) ?></div>
                </div>
                <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
            </div>
            <div class="stat-footer">Di daftarkan</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-top">
                <div>
                    <div class="stat-title">Subscriptions</div>
                    <div class="stat-value"><?= number_format($subscriptionCount) ?></div>
                </div>
                <div class="stat-icon"><i class="bi bi-receipt-cutoff"></i></div>
            </div>
            <div class="stat-footer">Paket aktif</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-top">
                <div>
                    <div class="stat-title">Tagihan</div>
                    <div class="stat-value"><?= number_format($invoiceCount) ?></div>
                </div>
                <div class="stat-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
            </div>
            <div class="stat-footer">Total tagihan</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-top">
                <div>
                    <div class="stat-title">Payments</div>
                    <div class="stat-value"><?= number_format($paymentCount) ?></div>
                </div>
                <div class="stat-icon"><i class="bi bi-cash-stack"></i></div>
            </div>
            <div class="stat-footer">Transaksi pembayaran</div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="dashboard-card">
            <div class="dashboard-card-header">
                <div><h5>Quick Actions</h5><small>Menu cepat BAJAMA</small></div>
            </div>
            <div class="dashboard-card-body">
                <div class="row g-2">
                    <div class="col-12 col-md-6">
                        <a href="customer_form.php" class="quick-action">
                            <div class="quick-action-icon"><i class="bi bi-person-plus-fill"></i></div>
                            <div><strong>Add Customer</strong><small>Tambah pelanggan ISP</small></div>
                        </a>
                    </div>
                    <div class="col-12 col-md-6">
                        <a href="mikrotik_routers.php" class="quick-action">
                            <div class="quick-action-icon"><i class="bi bi-router-fill"></i></div>
                            <div><strong>Add MikroTik</strong><small>Hubungkan router</small></div>
                        </a>
                    </div>
                    <div class="col-12 col-md-6">
                        <a href="invoices.php" class="quick-action">
                            <div class="quick-action-icon"><i class="bi bi-receipt"></i></div>
                            <div><strong>Create Invoice</strong><small>Buat tagihan pelanggan</small></div>
                        </a>
                    </div>
                    <div class="col-12 col-md-6">
                        <a href="mikrotik.php" class="quick-action">
                            <div class="quick-action-icon"><i class="bi bi-activity"></i></div>
                            <div><strong>Open NOC</strong><small>Monitoring jaringan</small></div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="dashboard-card">
            <div class="dashboard-card-header">
                <div><h5>License</h5><small>Platform paket</small></div>
                <i class="bi bi-patch-check-fill text-primary"></i>
            </div>
            <div class="dashboard-card-body">
                <div class="license-box">
                    <div class="license-label">Paket anda sekarang</div>
                    <div class="license-plan"><?= htmlspecialchars($licensePlan) ?></div>
                    <div class="license-description">Status: <strong><?= htmlspecialchars($licenseStatus) ?></strong></div>
                    <?php if ($licenseExpires): ?>
                        <div class="mt-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="small text-muted">Berakhir pada:</span>
                                <span class="small fw-semibold"><?= htmlspecialchars($licenseExpires) ?></span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="mt-3 small text-muted">Masa tenggang license belum tersedia.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="dashboard-card">
            <div class="dashboard-card-header">
                <div><h5>Perusahaan</h5><small>Informasi kepemilikan lisensi</small></div>
                <i class="bi bi-building-fill text-primary"></i>
            </div>
            <div class="dashboard-card-body">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon"><i class="bi bi-building"></i></div>
                    <div>
                        <div class="fw-bold"><?= htmlspecialchars($organizationName) ?></div>
                        <div class="small text-muted">Bajama sudah mengaktifkan hak akses remote mikrotik, billing, dan lainnya berdasarkan paket yang anda pilih.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="dashboard-card">
            <div class="dashboard-card-header">
                <div><h5>Aplikasi BAJAMA</h5><small>Informasi server yang berjalan</small></div>
                <span class="badge text-bg-success">ONLINE</span>
            </div>
            <div class="dashboard-card-body">
                <div class="d-flex justify-content-between mb-2"><span class="small">Core</span><strong class="small text-success">Operational</strong></div>
                <div class="progress mb-3"><div class="progress-bar bg-success" style="width:100%"></div></div>
                <div class="d-flex justify-content-between mb-2"><span class="small">Database</span><strong class="small text-success">Connected</strong></div>
                <div class="progress"><div class="progress-bar bg-success" style="width:100%"></div></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();

/*
|--------------------------------------------------------------------------
| NETWORK LAYOUT
|--------------------------------------------------------------------------
| Dashboard menggunakan sidebar Network BAJAMA standar yang sama
| dengan MikroTik, Router Management, ISP/WAN dan LAN/VLAN.
|--------------------------------------------------------------------------
*/
$networkLayout = false;
$networkActive = 'dashboard';

require __DIR__ . '/../app/layout/layout.php';
