<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Otp;
use BAJAMA\Core\I18n;

$db = db();
$locale = I18n::locale();
$tr = static fn (string $key): string => I18n::t($key, $locale);
$message = '';
$plans = [];
$paymentMethods = [];
$selectedPlanId = (int)($_GET['plan_id'] ?? 0);
$selectedPlan = null;
$featureLabels = [
    'dashboard' => 'Dashboard', 'billing' => 'Billing tenant', 'mikrotik' => 'MikroTik',
    'pppoe' => 'PPPoE', 'hotspot' => 'Hotspot', 'static' => 'Static IP',
    'fiber' => 'Fiber / FTTH', 'olt' => 'OLT', 'onu' => 'ONU', 'noc' => 'NOC', 'api' => 'API'
];

try {
    $plans = $db->query('SELECT * FROM license_plans WHERE active = 1 ORDER BY price_monthly ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $plans = [];
}

foreach ($plans as $plan) {
    if ((int)($plan['id'] ?? 0) === $selectedPlanId) {
        $selectedPlan = $plan;
        break;
    }
}

if ($selectedPlan === null && !empty($_POST['plan_id'])) {
    $selectedPlanId = (int)$_POST['plan_id'];
    foreach ($plans as $plan) {
        if ((int)($plan['id'] ?? 0) === $selectedPlanId) {
            $selectedPlan = $plan;
            break;
        }
    }
}

try {
    $paymentMethods = $db->query('SELECT * FROM payment_methods WHERE active = 1 AND organization_id IS NULL ORDER BY type, name')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $paymentMethods = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf((string)($_POST['_csrf'] ?? ''));

    $company = trim((string)($_POST['company_name'] ?? ''));
    $name = trim((string)($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirmation = (string)($_POST['password_confirmation'] ?? '');
    $planId = (int)($_POST['plan_id'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($company === '' || $name === '' || $email === '' || $username === '' || $planId <= 0 || $password === '') {
        $message = 'Nama perusahaan, nama lengkap, username, email, password, dan paket lisensi wajib diisi.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Format email tidak valid.';
    } elseif (!preg_match('/^[a-z0-9][a-z0-9._-]{2,99}$/', $username)) {
        $message = $tr('username_invalid');
    } elseif (strlen($password) < 10 || $password !== $passwordConfirmation) {
        $message = $password !== $passwordConfirmation ? 'Konfirmasi password tidak cocok.' : 'Password minimal 10 karakter.';
    } else {
        $selectedPlan = null;
        foreach ($plans as $plan) {
            if ((int)$plan['id'] === $planId) {
                $selectedPlan = $plan;
                break;
            }
        }

        if ($selectedPlan === null) {
            $message = 'Paket lisensi yang dipilih tidak ditemukan.';
        } else {
            try {
                $existingUserStmt = $db->prepare('SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1');
                $existingUserStmt->execute([$email, $username]);
                $existingUser = $existingUserStmt->fetch(PDO::FETCH_ASSOC);
                if ($existingUser) {
                    $emailOwner = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                    $emailOwner->execute([$email]);
                    throw new RuntimeException($emailOwner->fetchColumn() ? $tr('email_taken') : $tr('username_taken'));
                }
                $db->beginTransaction();

                $organizationCheck = $db->prepare('SELECT id FROM organizations WHERE name = ? OR email = ? LIMIT 1');
                $organizationCheck->execute([$company, $email]);
                if ($organizationCheck->fetchColumn()) {
                    throw new RuntimeException('Organisasi atau email organisasi sudah terdaftar.');
                }

                $slugBase = strtolower(trim((string)(preg_replace('/[^a-z0-9]+/i', '-', $company) ?: 'organization'), '-'));
                $slug = $slugBase !== '' ? $slugBase : 'organization';
                $counter = 1;
                while (true) {
                    $slugCheck = $db->prepare('SELECT id FROM organizations WHERE slug = ? LIMIT 1');
                    $slugCheck->execute([$slug]);
                    if (!$slugCheck->fetchColumn()) {
                        break;
                    }
                    $slug = ($slugBase !== '' ? $slugBase : 'organization') . '-' . $counter++;
                }

                $orgStmt = $db->prepare('INSERT INTO organizations (name, slug, email, phone, status) VALUES (?, ?, ?, ?, "ACTIVE")');
                $orgStmt->execute([$company, $slug, $email, $phone !== '' ? $phone : null]);
                $organizationId = (int)$db->lastInsertId();

                $userStmt = $db->prepare('INSERT INTO users (organization_id, username, email, password_hash, full_name, status) VALUES (?, ?, ?, ?, ?, "ACTIVE")');
                $userStmt->execute([$organizationId, $username, $email, password_hash($password, PASSWORD_DEFAULT), $name]);
                $userId = (int)$db->lastInsertId();

                $roleStmt = $db->prepare('SELECT id FROM roles WHERE name = "OWNER" LIMIT 1');
                $roleStmt->execute();
                $ownerRoleId = (int)$roleStmt->fetchColumn();
                if ($ownerRoleId > 0) {
                    $db->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $ownerRoleId]);
                }

                $customerCode = 'CUST-' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 8));
                $customerStmt = $db->prepare('INSERT INTO customers (organization_id, customer_code, name, email, phone, status) VALUES (?, ?, ?, ?, ?, "ACTIVE")');
                $customerStmt->execute([$organizationId, $customerCode, $name, $email, $phone !== '' ? $phone : null]);

                $stmt = $db->prepare(
                    'INSERT INTO license_registrations (company_name, full_name, email, phone, package_name, plan_id, payment_method_id, notes, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NULL, ?, "PENDING", NOW())'
                );
                $stmt->execute([
                    $company,
                    $name,
                    $email,
                    $phone,
                    (string)($selectedPlan['name'] ?? 'Lisensi'),
                    $planId,
                    $notes,
                ]);

                $registrationId = (int)$db->lastInsertId();
                $db->commit();
                Otp::issue($db, $email, 'REGISTER', [
                    'user_id' => $userId,
                    'organization_id' => $organizationId,
                    'registration_id' => $registrationId,
                    'username' => $username,
                    'full_name' => $name,
                    'organization_name' => $company,
                ]);
                $_SESSION['otp_email'] = $email;
                $_SESSION['otp_purpose'] = 'REGISTER';
                header('Location: verify-otp.php');
                exit;
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('BAJAMA registration error: ' . $e->getMessage());
                $message = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Pendaftaran gagal dibuat. Pastikan konfigurasi SMTP Gmail sudah benar, lalu coba lagi.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars($locale, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daftar Lisensi BAJAMA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #edf4ff 100%);
            color: #172033;
        }

        .public-nav {
            background: rgba(15, 23, 42, 0.92) !important;
            backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }

        .public-nav .nav-link,
        .public-nav .navbar-brand {
            color: #fff !important;
        }

        .register-shell {
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.96), rgba(37, 99, 235, 0.85));
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 28px 80px rgba(15, 23, 42, 0.18);
        }

        .register-panel {
            background: rgba(255,255,255,0.03);
            border-left: 1px solid rgba(255,255,255,0.08);
        }

        .premium-form .form-control,
        .premium-form .form-select,
        .premium-form textarea {
            border-radius: 14px;
            border: 1px solid #dfe8f4;
            min-height: 52px;
            box-shadow: none;
        }

        .premium-form .form-control:focus,
        .premium-form .form-select:focus,
        .premium-form textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 0.2rem rgba(37,99,235,0.10);
        }
    </style>
</head>
<body>
    <nav class="public-nav navbar navbar-expand-lg sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">BAJAMA</a>
            <div class="ms-auto d-flex gap-3 small align-items-center flex-wrap">
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-translate me-1"></i><?= htmlspecialchars(I18n::languageName($locale), ENT_QUOTES, 'UTF-8') ?></button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php foreach (I18n::supported() as $supportedLocale): ?><li><a class="dropdown-item <?= $supportedLocale === $locale ? 'active' : '' ?>" href="?lang=<?= urlencode($supportedLocale) ?>"><?= htmlspecialchars(I18n::languageName($supportedLocale), ENT_QUOTES, 'UTF-8') ?></a></li><?php endforeach; ?>
                    </ul>
                </div>
                <a class="nav-link" href="index.php">Beranda</a>
                <a class="nav-link" href="license_catalog.php">Daftar Lisensi</a>
                <a class="nav-link" href="blog.php">Blog</a>
                <a class="nav-link" href="login.php">Login</a>
            </div>
        </div>
    </nav>

    <div class="container py-5">
        <div class="register-shell row g-0 align-items-stretch">
            <div class="col-lg-5 text-white p-4 p-lg-5 d-flex flex-column justify-content-between">
                <div>
                    <div class="text-uppercase small text-info fw-semibold mb-3">Business onboarding</div>
                    <h1 class="fw-bold mb-3">Mulai paket lisensi BAJAMA</h1>
                    <p class="text-light opacity-75 mb-4">Platform untuk bisnis lisensi, pelanggan, dan operasional layanan digital yang siap tumbuh modern dan terukur.</p>
                </div>

                <div class="summary-card mb-4">
                    <div class="small text-uppercase text-info fw-semibold mb-2">Paket terpilih</div>
                    <div class="summary-plan-name">
                        <?= htmlspecialchars((string)($selectedPlan['name'] ?? 'Paket BAJAMA'), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="summary-plan-price">
                        Rp <?= number_format((float)($selectedPlan['price_monthly'] ?? 0), 0, ',', '.') ?>
                        <span>/ bulan</span>
                    </div>
                    <div class="summary-features mt-3">
                        <?php
                        $features = [];
                        if (!empty($selectedPlan['features'])) {
                            $decoded = json_decode((string)$selectedPlan['features'], true);
                            if (is_array($decoded)) {
                                $isSequential = array_keys($decoded) === range(0, count($decoded) - 1);
                                if ($isSequential) {
                                    $features = array_values(array_filter($decoded, static fn ($item) => trim((string)$item) !== ''));
                                } else {
                                    foreach ($decoded as $feature => $enabled) {
                                        if ($enabled === true || $enabled === 1 || $enabled === '1' || strtolower((string)$enabled) === 'true') {
                                            $features[] = (string)$feature;
                                        }
                                    }
                                }
                            }
                        }
                        if (empty($features)) {
                            $features = ['Akses dashboard operasional', 'Pengelolaan pelanggan', 'Bukti pembayaran dan review', 'API & integrasi'];
                        }
                        if (!in_array('dashboard', $features, true)) {
                            array_unshift($features, 'dashboard');
                        }
                        foreach (array_slice($features, 0, 4) as $feature):
                            $featureLabel = $featureLabels[(string)$feature] ?? ucwords(str_replace(['_', '-'], ' ', (string)$feature));
                            echo '<div><i class="bi bi-check-circle-fill me-2"></i>' . htmlspecialchars($featureLabel, ENT_QUOTES, 'UTF-8') . '</div>';
                        endforeach;
                        ?>
                    </div>
                </div>

                <div class="d-grid gap-2 text-light small">
                    <div><i class="bi bi-shield-check text-info me-2"></i>Proses onboarding lebih aman dan jelas</div>
                    <div><i class="bi bi-credit-card-2-front text-info me-2"></i>Pembayaran dan review terstruktur</div>
                    <div><i class="bi bi-people text-info me-2"></i>Support onboarding siap membantu</div>
                </div>
            </div>

            <div class="col-lg-7 bg-white p-4 p-lg-5 register-panel">
                <div class="mb-4 text-center text-lg-start">
                    <span class="badge bg-primary rounded-pill mb-3">Business Register</span>
                    <h2 class="h3 fw-bold mb-1">Form pendaftaran lisensi</h2>
                    <p class="text-muted mb-0">Isi data bisnis Anda untuk langsung melanjutkan ke pembayaran.</p>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-danger rounded-4"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <form method="post" class="premium-form">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nama perusahaan</label>
                            <input type="text" name="company_name" class="form-control" required value="<?= htmlspecialchars((string)($_POST['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nama lengkap</label>
                            <input type="text" name="full_name" class="form-control" required value="<?= htmlspecialchars((string)($_POST['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($tr('username'), ENT_QUOTES, 'UTF-8') ?></label>
                            <input type="text" name="username" class="form-control" required minlength="3" maxlength="100" pattern="[A-Za-z0-9][A-Za-z0-9._-]{2,99}" value="<?= htmlspecialchars((string)($_POST['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <div class="form-text">Username unik untuk login ke BAJAMA.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($tr('email'), ENT_QUOTES, 'UTF-8') ?> bisnis</label>
                            <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nomor telepon</label>
                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars((string)($_POST['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password akun</label>
                            <input type="password" name="password" class="form-control" minlength="10" required placeholder="Minimal 10 karakter">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Konfirmasi password</label>
                            <input type="password" name="password_confirmation" class="form-control" minlength="10" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Pilih paket lisensi</label>
                            <select name="plan_id" class="form-select" required>
                                <option value="">Pilih paket</option>
                                <?php foreach ($plans as $plan): ?>
                                    <?php $selectedAttr = ((string)($_POST['plan_id'] ?? $selectedPlanId) === (string)$plan['id']) ? 'selected' : ''; ?>
                                    <option value="<?= (int)$plan['id'] ?>" <?= $selectedAttr ?>><?= htmlspecialchars((string)($plan['name'] ?? 'Paket'), ENT_QUOTES, 'UTF-8') ?> - Rp <?= number_format((float)($plan['price_monthly'] ?? 0), 0, ',', '.') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Catatan kebutuhan</label>
                            <textarea name="notes" class="form-control" rows="4" placeholder="Jelaskan kebutuhan lisensi, skala operasional, atau kebutuhan integrasi Anda... "><?= htmlspecialchars((string)($_POST['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-4 flex-wrap gap-3">
                        <a href="login.php" class="text-decoration-none fw-semibold">Sudah punya akun? Masuk</a>
                        <button class="btn btn-primary btn-lg px-4" type="submit">Buat Profile</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <style>
        .summary-card {
            background: rgba(15, 23, 42, 0.20);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 22px;
            padding: 20px 18px;
            backdrop-filter: blur(10px);
        }
        .summary-plan-name {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .summary-plan-price {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1.1;
        }
        .summary-plan-price span {
            font-size: 0.9rem;
            color: rgba(255,255,255,0.75);
            font-weight: 500;
        }
        .summary-features {
            display: grid;
            gap: 10px;
            font-size: 0.92rem;
            color: rgba(255,255,255,0.82);
            margin-top: 10px;
        }
        .summary-features i {
            color: #67e8f9;
        }
    </style>
</body>
</html>
