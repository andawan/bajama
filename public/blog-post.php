<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$slug = trim((string)($_GET['slug'] ?? ''));
$post = null;

if ($slug !== '') {
    try {
        $stmt = db()->prepare('SELECT * FROM blog_posts WHERE slug = ? AND status = ? LIMIT 1');
        $stmt->execute([$slug, 'PUBLISHED']);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $post = null;
    }
}

if (!$post) {
    http_response_code(404);
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Halaman tidak ditemukan</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><div class="container py-5"><div class="card shadow-sm border-0"><div class="card-body p-5 text-center"><h1 class="display-6 fw-bold">404</h1><p class="text-muted">Artikel yang Anda cari tidak ditemukan.</p><a href="blog.php" class="btn btn-primary">Kembali ke Blog</a></div></div></div></body></html>';
    exit;
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars((string)$post['title'], ENT_QUOTES, 'UTF-8') ?> - BAJAMA</title>
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

        .article-shell {
            border: 1px solid rgba(148,163,184,0.14);
            border-radius: 24px;
            box-shadow: 0 24px 60px rgba(15,23,42,0.08);
        }

        article {
            font-size: 1.04rem;
            line-height: 1.9;
            color: #334155;
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

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-9">
                <div class="card article-shell border-0">
                    <div class="card-body p-4 p-lg-5">
                        <div class="text-muted small mb-3"><i class="bi bi-calendar3 me-2"></i><?= htmlspecialchars((string)($post['published_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?> • <?= htmlspecialchars((string)($post['author_name'] ?? 'BAJAMA'), ENT_QUOTES, 'UTF-8') ?></div>
                        <h1 class="fw-bold mb-3 fs-2"><?= htmlspecialchars((string)$post['title'], ENT_QUOTES, 'UTF-8') ?></h1>
                        <div class="mb-4 text-muted"><?= htmlspecialchars((string)($post['excerpt'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                        <article>
                            <?= nl2br(htmlspecialchars((string)$post['content'], ENT_QUOTES, 'UTF-8')) ?>
                        </article>
                        <div class="mt-4 pt-3 border-top">
                            <a href="blog.php" class="btn btn-outline-dark">Kembali ke Blog</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
