<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$posts = [];
try {
    $stmt = db()->prepare('SELECT * FROM blog_posts WHERE status = ? ORDER BY published_at DESC, id DESC LIMIT 12');
    $stmt->execute(['PUBLISHED']);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $posts = [];
}

function excerpt(string $text, int $length = 160): string
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
    <title>Blog BAJAMA</title>
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

        .blog-hero {
            background: radial-gradient(circle at top left, rgba(37,99,235,0.25), transparent 28%),
                linear-gradient(135deg, #0f172a 0%, #111827 45%, #0ea5e9 100%);
        }

        .post-card {
            border: 1px solid rgba(148,163,184,0.15);
            border-radius: 22px;
            box-shadow: 0 22px 45px rgba(15,23,42,0.08);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .post-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 26px 55px rgba(15,23,42,0.12);
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

    <header class="blog-hero text-white">
        <div class="container py-5 text-center">
            <span class="badge bg-light text-primary rounded-pill mb-3 px-3 py-2">Blog & Update</span>
            <h1 class="fw-bold mb-3">Informasi terbaru untuk pelanggan lisensi</h1>
            <p class="text-light mx-auto mb-0" style="max-width: 760px;">Artikel yang dikelola oleh superadmin untuk pelanggan lisensi, calon pelanggan, dan komunitas BAJAMA.</p>
        </div>
    </header>

    <div class="container py-5">
        <?php if ($posts): ?>
            <div class="row g-4">
                <?php foreach ($posts as $post): ?>
                    <div class="col-lg-4">
                        <div class="card h-100 post-card border-0">
                            <div class="card-body p-4">
                                <div class="text-muted small mb-2"><?= htmlspecialchars((string)($post['published_at'] ?? date('Y-m-d H:i:s')), ENT_QUOTES, 'UTF-8') ?></div>
                                <h2 class="h5 fw-bold"><?= htmlspecialchars((string)($post['title'] ?? 'Judul'), ENT_QUOTES, 'UTF-8') ?></h2>
                                <p class="text-muted mb-3"><?= htmlspecialchars(excerpt((string)($post['content'] ?? '')), ENT_QUOTES, 'UTF-8') ?></p>
                                <a href="blog-post.php?slug=<?= urlencode((string)($post['slug'] ?? '')) ?>" class="btn btn-outline-dark">Baca selengkapnya</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-info rounded-4">Belum ada artikel publik yang dipublikasikan.</div>
        <?php endif; ?>
    </div>
</body>
</html>
