<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$db = db();
$plans = [];
$selectedPlanId = (int)($_GET['plan_id'] ?? 0);

try {
    $plans = $db->query('SELECT * FROM license_plans WHERE active = 1 ORDER BY price_monthly ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('BAJAMA license catalog query failed: ' . $e->getMessage());
    $plans = [];
}

function normalizePlanFeatures($value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $value = $decoded;
        } else {
            $value = [];
        }
    }

    if (!is_array($value)) {
        return [];
    }

    $isSequential = ($value === []) || (array_keys($value) === range(0, count($value) - 1));
    if ($isSequential) {
        return array_values(array_filter(
            array_map(static fn ($item) => trim((string) $item), $value),
            static fn ($item) => $item !== ''
        ));
    }

    $features = [];
    foreach ($value as $key => $enabled) {
        if ($enabled === true || $enabled === 1 || $enabled === '1' || strtolower((string) $enabled) === 'true') {
            $features[] = trim((string) $key);
        }
    }

    return $features;
}

$featureLabels = [
    'dashboard' => 'Dashboard', 'billing' => 'Billing tenant', 'mikrotik' => 'MikroTik',
    'pppoe' => 'PPPoE', 'hotspot' => 'Hotspot', 'static' => 'Static IP',
    'fiber' => 'Fiber / FTTH', 'olt' => 'OLT', 'onu' => 'ONU', 'noc' => 'NOC', 'api' => 'API'
];

function displayPlanFeature(string $feature, array $featureLabels): string
{
    return $featureLabels[$feature] ?? ucwords(str_replace(['_', '-'], ' ', $feature));
}

function formatMoney($amount)
{
    return 'Rp ' . number_format((float)$amount, 0, ',', '.');
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daftar Lisensi BAJAMA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(180deg, #f8fafc 0%, #edf4ff 100%);
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

        .pricing-hero {
            background: radial-gradient(circle at top left, rgba(37,99,235,0.25), transparent 30%),
                linear-gradient(135deg, #0f172a 0%, #111827 45%, #0ea5e9 100%);
        }

        .plan-featured {
            border: 2px solid #2563eb !important;
            transform: translateY(-4px);
            box-shadow: 0 1.5rem 2.5rem rgba(37,99,235,0.20) !important;
            position: relative;
        }

        .plan-featured::before {
            content: "Popular";
            position: absolute;
            top: 12px;
            right: 12px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.08em;
            padding: 6px 10px;
            border-radius: 999px;
        }

        .plan-card {
            border: 1px solid rgba(148,163,184,0.22);
            border-radius: 22px;
            box-shadow: 0 22px 48px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }

        .plan-price {
            font-size: clamp(2rem, 3vw, 2.8rem);
            line-height: 1.1;
        }

        .feature-list li {
            padding: 0.3rem 0;
        }
    </style>
</head>
<body>
    <nav class="public-nav navbar navbar-expand-lg sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">BAJAMA</a>
            <div class="ms-auto d-flex gap-3 small align-items-center flex-wrap">
                <a class="nav-link" href="index.php">Beranda</a>
                <a class="nav-link" href="license_catalog.php">Daftar Lisensi</a>
                <a class="nav-link" href="blog.php">Blog</a>
                <a class="nav-link" href="login.php">Login</a>
                <a class="btn btn-primary btn-sm px-3" href="register.php">Daftar</a>
            </div>
        </div>
    </nav>

    <header class="pricing-hero text-white">
        <div class="container py-5 text-center">
            <span class="badge bg-light text-primary rounded-pill mb-3 px-3 py-2">Lisensi</span>
            <h1 class="fw-bold mb-3">Pilih lisensi sesuai kebutuhan bisnis Anda</h1>
            <p class="text-light mx-auto mb-0" style="max-width: 760px;">Semua paket dirancang untuk pelanggan lisensi BAJAMA, baik yang sudah aktif maupun yang baru ingin berlangganan dengan skala bisnis yang lebih siap berkembang.</p>
        </div>
    </header>

    <div class="container py-5">
        <?php if (empty($plans)): ?>
            <div class="alert alert-info mt-4">Saat ini belum ada paket lisensi aktif yang tersedia.</div>
        <?php else: ?>
        <div class="row g-4 align-items-stretch">
            <?php foreach ($plans as $plan): ?>
                <?php $features = normalizePlanFeatures($plan['features'] ?? []); if (!in_array('dashboard', $features, true)) { array_unshift($features, 'dashboard'); } ?>
                <?php $isFeatured = strcasecmp((string)($plan['name'] ?? ''), 'Professional') === 0; ?>
                <div class="col-lg-4">
                    <div class="card h-100 plan-card <?= $isFeatured ? 'plan-featured' : '' ?> bg-white border-0">
                        <div class="card-body p-4 p-lg-5">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="badge bg-dark-subtle text-dark"><?= htmlspecialchars((string)($plan['code'] ?? 'PLAN'), ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="text-muted small"><?= htmlspecialchars((string)($plan['name'] ?? 'Paket'), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <h2 class="h4 fw-bold"><?= htmlspecialchars((string)($plan['name'] ?? 'Paket'), ENT_QUOTES, 'UTF-8') ?></h2>
                            <p class="text-muted"><?= htmlspecialchars((string)($plan['description'] ?? 'Paket lisensi BAJAMA.'), ENT_QUOTES, 'UTF-8') ?></p>
                            <div class="plan-price fw-bold mb-3"><?= formatMoney((float)($plan['price_monthly'] ?? 0)) ?></div>
                            <ul class="list-unstyled feature-list small mb-4">
                                <?php foreach ($features as $feature): ?>
                                    <li><i class="bi bi-check-circle-fill text-success me-2"></i><?= htmlspecialchars(displayPlanFeature((string)$feature, $featureLabels), ENT_QUOTES, 'UTF-8') ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <a href="register.php?plan_id=<?= (int)($plan['id'] ?? 0) ?>" class="btn <?= $isFeatured ? 'btn-primary' : 'btn-outline-primary' ?> w-100">Daftar Paket</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="row mt-5 g-4">
            <div class="col-lg-6">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body p-4 p-lg-5">
                        <h3 class="h5 fw-bold">Untuk pelanggan lisensi yang aktif</h3>
                        <p class="text-muted">Pelanggan lisensi dapat masuk ke portal internal, mengecek status aktivasi, melihat fitur yang tersedia, dan mengakses informasi platform secara cepat.</p>
                        <a href="login.php" class="btn btn-outline-dark">Masuk portal</a>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100 border-0 shadow-sm bg-dark text-white">
                    <div class="card-body p-4 p-lg-5">
                        <h3 class="h5 fw-bold text-white">Belum berlangganan lisensi?</h3>
                        <p class="text-light">Mulai dengan paket yang sesuai dan tim BAJAMA siap membantu analisis kebutuhan jaringan, lisensi, dan integrasi operasional Anda.</p>
                        <a href="register.php" class="btn btn-light">Ajukan pendaftaran</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
