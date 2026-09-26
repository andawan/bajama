<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$db = db();
$posts = [];
try {
    $stmt = $db->prepare('SELECT * FROM blog_posts WHERE status = ? ORDER BY published_at DESC, id DESC LIMIT 3');
    $stmt->execute(['PUBLISHED']);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $posts = [];
}

$contact = null;
try {
    $contactStmt = $db->query('SELECT * FROM site_contacts WHERE active = 1 ORDER BY is_primary DESC, id DESC LIMIT 1');
    $contact = $contactStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $contact = null;
}

if (!$contact) {
    $contact = [
        'display_name' => 'BAJAMA Support',
        'email' => 'hello@bajama.id',
        'phone' => '+62 812-3456-7890',
        'whatsapp' => '6281234567890',
        'address' => 'Indonesia',
    ];
}

$waPhone = preg_replace('/\D+/', '', (string)($contact['whatsapp'] ?? ''));
$waLink = $waPhone !== '' ? 'https://wa.me/' . $waPhone . '?text=' . rawurlencode('Halo, saya ingin bertanya tentang lisensi dan layanan di BAJAMA.') : '#';

function hero_excerpt(string $text, int $length = 160): string
{
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
    if (strlen($plain) <= $length) {
        return $plain;
    }
    return substr($plain, 0, $length) . '...';
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BAJAMA — Platform Lisensi & Pelanggan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-strong: #1d4ed8;
            --dark: #0f172a;
            --dark-soft: #111827;
            --light-bg: #f5f7fb;
            --card: #ffffff;
            --text: #172033;
            --muted: #64748b;
            --line: #e2e8f0;
            --success: #16a34a;
            --shadow-soft: 0 24px 60px rgba(15, 23, 42, 0.08);
        }

        body {
            background: linear-gradient(180deg, #f8fafc 0%, #eef4ff 100%);
            color: var(--text);
            font-family: Inter, "Segoe UI", sans-serif;
        }

        .public-nav {
            background: rgba(15, 23, 42, 0.9) !important;
            backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .public-nav .nav-link,
        .public-nav .navbar-brand {
            color: #ffffff !important;
        }

        .public-nav .nav-link:hover,
        .public-nav .nav-link:focus {
            color: #cfe3ff !important;
        }

        .hero {
            background: radial-gradient(circle at top left, rgba(37,99,235,0.35), transparent 28%),
                linear-gradient(135deg, #0f172a 0%, #111827 42%, #0ea5e9 100%);
            overflow: hidden;
        }

        .hero-badge {
            background: rgba(14, 165, 233, 0.12);
            border: 1px solid rgba(125, 211, 252, 0.35);
            color: #dff6ff;
        }

        .stat-card,
        .feature-card,
        .article-card,
        .glass-card {
            border: 0;
            border-radius: 22px;
            box-shadow: var(--shadow-soft);
        }

        .feature-card {
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            border: 1px solid rgba(148,163,184,0.12);
        }

        .feature-icon {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #dbeafe, #e0f2fe);
            color: var(--primary);
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        .section-title {
            font-size: clamp(1.9rem, 3vw, 2.7rem);
            letter-spacing: -0.04em;
        }

        .article-card {
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .article-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 26px 60px rgba(15, 23, 42, 0.12);
        }

        .premium-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border-radius: 999px;
            padding: 0.5rem 0.9rem;
            background: rgba(37, 99, 235, 0.08);
            color: var(--primary);
            font-size: 0.8rem;
            font-weight: 700;
        }

        .soft-divider {
            border-top: 1px solid var(--line);
        }

        .metric-box {
            border: 1px solid rgba(148,163,184,0.2);
            background: rgba(255,255,255,0.06);
            border-radius: 18px;
            padding: 1rem;
        }
    </style>
</head>
<body>
    <nav class="public-nav navbar navbar-expand-lg sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">BAJAMA</a>
            <div class="ms-auto d-flex gap-3 align-items-center small flex-wrap">
                <a class="nav-link" href="index.php">Beranda</a>
                <a class="nav-link" href="license_catalog.php">Paket</a>
                <a class="nav-link" href="blog.php">Blog</a>
                <a class="nav-link" href="login.php">Masuk</a>
                <a class="btn btn-primary btn-sm px-3" href="register.php">Daftar</a>
            </div>
        </div>
    </nav>

    <header class="hero text-white">
        <div class="container py-5 py-lg-6">
            <div class="row align-items-center g-4">
                <div class="col-lg-7">
                    <span class="hero-badge badge rounded-pill mb-3">Sukses bersama BAJAMA</span>
                    <h1 class="display-5 fw-bold mb-3 lh-sm">Platform lisensi dan layanan pelanggan untuk bisnis ISP / SaaS / RT RW Net yang lebih modern</h1>
                    <p class="lead text-light mb-4">BAJAMA siap membantu mengelola pelanggan, pembayaran, dan komunikasi secara lebih rapi, aman, dan profesional.</p>
                    <div class="d-flex gap-3 flex-wrap mt-4">
                        <a href="license_catalog.php" class="btn btn-primary btn-lg px-4">Lihat Paket</a>
                        <a href="register.php" class="btn btn-outline-light btn-lg px-4">Daftar Sekarang</a>
                    </div>
                    <div class="d-flex gap-3 flex-wrap mt-4 text-light small">
                        <span class="premium-pill"><i class="bi bi-shield-check"></i> Aman & Terpercaya</span>
                        <span class="premium-pill"><i class="bi bi-rocket-takeoff"></i> Mudah & Cepat</span>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card stat-card border-0 bg-white text-dark">
                        <div class="card-body p-4">
                            <div class="row g-3 text-center">
                                <div class="col-6">
                                    <div class="metric-box">
                                        <div class="display-6 fw-bold text-primary">24/7</div>
                                        <small class="text-muted">Layanan</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="metric-box">
                                        <div class="display-6 fw-bold text-primary">3+</div>
                                        <small class="text-muted">Paket</small>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="p-3 rounded-4 bg-dark text-white">
                                        <div class="fw-semibold mb-1"><?= htmlspecialchars((string)($contact['display_name'] ?? 'BAJAMA Support'), ENT_QUOTES, 'UTF-8') ?></div>
                                        <small class="text-light">Admin siap membantu kebutuhan operasional perangkat Anda.</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="container py-5">
        <div class="row g-4 mb-5">
            <div class="col-lg-4">
                <div class="card feature-card h-100">
                    <div class="card-body p-4">
                        <div class="feature-icon"><i class="bi bi-people"></i></div>
                        <h3 class="h5 fw-bold">Manajemen Pelanggan</h3>
                        <p class="text-muted">BAJAMA mempermudah pengelolaan data pelanggan, pembayaran, dan kebutuhan operasional dalam satu platform.</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card feature-card h-100">
                    <div class="card-body p-4">
                        <div class="feature-icon"><i class="bi bi-box-seam"></i></div>
                        <h3 class="h5 fw-bold">Paket Internet</h3>
                        <p class="text-muted">Tersedia pilihan paket internet sesuai kebutuhan ISP anda, lengkap dengan fitur yang dapat disesuaikan.</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card feature-card h-100">
                    <div class="card-body p-4">
                        <div class="feature-icon"><i class="bi bi-chat-dots"></i></div>
                        <h3 class="h5 fw-bold">Komunikasi Cepat</h3>
                        <p class="text-muted">Pelanggan bisa langsung langsung menghubungi anda melalui WhatsApp untuk konsultasi, pendaftaran, atau kebutuhan internet lanjutan.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-5 align-items-stretch">
            <div class="col-lg-7">
                <div class="card feature-card h-100 border-0">
                    <div class="card-body p-4 p-lg-5">
                        <span class="premium-pill mb-3"><i class="bi bi-info-circle"></i> Tentang BAJAMA</span>
                        <h2 class="fw-bold mb-3">Apa itu BAJAMA?</h2>
                        <p class="text-muted">BAJAMA adalah platform layanan berlisensi yang dirancang khusus untuk membantu bisnis internet anda dalam mengelola pelanggan, paket produk, dokumentasi, hingga proses administrasi digital dengan lebih tertata.</p>
                        <p class="text-muted">Dengan pendekatan yang sederhana namun profesional, BAJAMA mendukung operasional harian mulai dari bisnis lisensi, registrasi pelanggan, hingga komunikasi pelanggan yang lebih efektif.</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card feature-card h-100 border-0">
                    <div class="card-body p-4 p-lg-5">
                        <h3 class="fw-bold mb-3">Hubungi Admin</h3>
                        <ul class="list-unstyled mb-4 text-muted">
                            <li class="mb-2"><i class="bi bi-person-lines-fill me-2 text-primary"></i><?= htmlspecialchars((string)($contact['display_name'] ?? 'BAJAMA Support'), ENT_QUOTES, 'UTF-8') ?></li>
                            <li class="mb-2"><i class="bi bi-envelope me-2 text-primary"></i><?= htmlspecialchars((string)($contact['email'] ?? 'hello@bajama.id'), ENT_QUOTES, 'UTF-8') ?></li>
                            <li class="mb-2"><i class="bi bi-telephone me-2 text-primary"></i><?= htmlspecialchars((string)($contact['phone'] ?? '+62 812-3456-7890'), ENT_QUOTES, 'UTF-8') ?></li>
                            <li class="mb-2"><i class="bi bi-geo-alt me-2 text-primary"></i><?= htmlspecialchars((string)($contact['address'] ?? 'Indonesia'), ENT_QUOTES, 'UTF-8') ?></li>
                        </ul>
                        <a href="<?= htmlspecialchars($waLink, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-success btn-lg w-100" target="_blank" rel="noopener">
                            <i class="bi bi-whatsapp me-2"></i> Chat WhatsApp Admin
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h2 class="section-title fw-bold mb-0">Artikel terbaru</h2>
            <a href="blog.php" class="btn btn-link text-decoration-none p-0">Lihat semua artikel</a>
        </div>

        <?php if ($posts): ?>
            <div class="row g-4">
                <?php foreach ($posts as $post): ?>
                    <div class="col-lg-4">
                        <div class="card article-card h-100 border-0">
                            <div class="card-body p-4">
                                <div class="text-muted small mb-2"><?= htmlspecialchars((string)($post['published_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                <h3 class="h5 fw-bold"><?= htmlspecialchars((string)($post['title'] ?? 'Judul artikel'), ENT_QUOTES, 'UTF-8') ?></h3>
                                <p class="text-muted mb-3"><?= htmlspecialchars(hero_excerpt((string)($post['content'] ?? '')), ENT_QUOTES, 'UTF-8') ?></p>
                                <a href="blog-post.php?slug=<?= urlencode((string)($post['slug'] ?? '')) ?>" class="btn btn-outline-dark">Baca artikel</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-info rounded-4">Artikel belum tersedia untuk ditampilkan.</div>
        <?php endif; ?>
    </main>

    <footer class="soft-divider mt-5">
        <div class="container py-4 text-center text-muted small">
            © <?= date('Y') ?> BAJAMA — Platform lisensi, layanan pelanggan, dan bisnis digital.
        </div>
    </footer>
</body>
</html>
