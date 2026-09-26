<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../config/security.php';

use BAJAMA\Core\Audit;
use BAJAMA\Core\I18n;

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = null;
$locale = I18n::locale();
$tr = static fn (string $key): string => I18n::t($key, $locale);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf((string)($_POST['_csrf'] ?? $_POST['csrf'] ?? ''));

    $username = strtolower(trim((string)($_POST['username'] ?? $_POST['identifier'] ?? '')));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = $tr('identifier_required');
    } else {
        $ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $attempts = db()->prepare(
            'SELECT COUNT(*)
             FROM login_attempts
             WHERE username = ?
               AND ip_address = ?
               AND successful = 0
               AND created_at >= (NOW() - INTERVAL 15 MINUTE)'
        );
        $attempts->execute([$username, $ipAddress]);

        if ((int)$attempts->fetchColumn() >= 5) {
            $error = $tr('invalid_credentials');
        } else {
            $stmt = db()->prepare(
                'SELECT u.*, o.name AS organization_name
                 FROM users u
                 JOIN organizations o ON o.id = u.organization_id
                 WHERE (u.username = ? OR u.email = ?)
                   AND u.status = "ACTIVE"
                 LIMIT 1'
            );

            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                $recordAttempt = db()->prepare(
                    'INSERT INTO login_attempts (username, ip_address, successful) VALUES (?, ?, 1)'
                );
                $recordAttempt->execute([$username, $ipAddress]);

                session_regenerate_id(true);

                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['organization_id'] = (int)$user['organization_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['organization_name'] = $user['organization_name'];

                $update = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
                $update->execute([$user['id']]);

                $roleStmt = db()->prepare(
                    'SELECT r.name
                     FROM user_roles ur
                     JOIN roles r ON r.id = ur.role_id
                     WHERE ur.user_id = ?
                     ORDER BY r.name'
                );
                $roleStmt->execute([(int)$user['id']]);
                $roles = array_map('strtoupper', array_values($roleStmt->fetchAll(PDO::FETCH_COLUMN)));

                try {
                    Audit::log(db(), 'LOGIN', 'auth', 'user', (int)$user['id']);
                } catch (Throwable $auditError) {
                    error_log('BAJAMA login audit error: ' . $auditError->getMessage());
                }

                $redirectUrl = in_array('SUPER_ADMIN', $roles, true) ? 'dashboard.php' : 'customer_portal.php';
                header('Location: ' . $redirectUrl);
                exit;
            }

            $recordAttempt = db()->prepare(
                'INSERT INTO login_attempts (username, ip_address, successful) VALUES (?, ?, 0)'
            );
            $recordAttempt->execute([$username, $ipAddress]);

            $error = $tr('invalid_credentials');
        }
    }
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars($locale, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login BAJAMA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --bg-1: #081120;
            --bg-2: #0f172a;
            --bg-3: #1d4ed8;
            --card: rgba(15, 23, 42, 0.72);
            --glass: rgba(255,255,255,0.08);
            --line: rgba(148,163,184,0.22);
            --text: #e2e8f0;
            --muted: rgba(226,232,240,0.7);
            --brand: #60a5fa;
            --brand-strong: #2563eb;
        }

        body {
            min-height: 100vh;
            background: radial-gradient(circle at top left, rgba(96,165,250,0.24), transparent 32%),
                        radial-gradient(circle at bottom right, rgba(59,130,246,0.18), transparent 28%),
                        linear-gradient(135deg, var(--bg-1) 0%, var(--bg-2) 35%, var(--bg-3) 100%);
            color: var(--text);
        }

        .brand {
            letter-spacing: .12em;
            font-weight: 800;
        }

        .topbar {
            position: relative;
            z-index: 2;
        }

        .nav-link-soft {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .nav-link-soft:hover {
            color: #fff;
        }

        .auth-shell {
            position: relative;
            z-index: 2;
            max-width: 980px;
            margin: 0 auto;
        }

        .auth-card {
            border-radius: 30px;
            overflow: hidden;
            border: 1px solid var(--line);
            box-shadow: 0 30px 80px rgba(2, 6, 23, 0.42);
            background: rgba(15, 23, 42, 0.22);
            backdrop-filter: blur(12px);
        }

        .auth-aside {
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.92), rgba(30, 41, 59, 0.95));
            border-right: 1px solid var(--line);
        }

        .auth-panel {
            background: rgba(255,255,255,0.96);
        }

        .feature-badge {
            display: inline-flex;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 0.72rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            background: rgba(96,165,250,0.12);
            color: #7dd3fc;
            border: 1px solid rgba(125,211,252,0.25);
        }

        .metric-pill {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(148,163,184,0.08);
            border: 1px solid rgba(148,163,184,0.15);
            border-radius: 12px;
            padding: 12px 14px;
            color: rgba(255,255,255,0.9);
            font-size: 0.92rem;
        }

        .metric-pill i {
            width: 28px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: rgba(96,165,250,0.16);
            color: var(--brand);
        }

        .premium-form .form-control {
            border-radius: 14px;
            height: 54px;
            border: 1px solid #dbe2f0;
            box-shadow: none;
            background: #f8fafc;
            color: #0f172a;
        }

        .premium-form .form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 0.2rem rgba(37,99,235,0.12);
            background: #fff;
        }

        .btn-bajama {
            background: linear-gradient(135deg, #0f172a 0%, #1d4ed8 100%);
            border: none;
            color: #fff;
            min-height: 52px;
            border-radius: 14px;
            font-weight: 700;
            box-shadow: 0 16px 32px rgba(37,99,235,0.2);
        }

        .btn-bajama:hover {
            color: #fff;
            filter: brightness(1.05);
        }
    </style>
</head>
<body>
    <div class="container py-4 topbar">
        <nav class="navbar navbar-expand-lg navbar-dark">
            <div class="container-fluid px-0">
                <a class="navbar-brand brand text-white" href="index.php">BAJAMA</a>
                <div class="ms-auto d-flex gap-2 flex-wrap align-items-center">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-translate me-1"></i><?= htmlspecialchars(I18n::languageName($locale), ENT_QUOTES, 'UTF-8') ?></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php foreach (I18n::supported() as $supportedLocale): ?><li><a class="dropdown-item <?= $supportedLocale === $locale ? 'active' : '' ?>" href="?lang=<?= urlencode($supportedLocale) ?>"><?= htmlspecialchars(I18n::languageName($supportedLocale), ENT_QUOTES, 'UTF-8') ?></a></li><?php endforeach; ?>
                        </ul>
                    </div>
                    <a class="nav-link-soft small" href="index.php">Beranda</a>
                    <a class="nav-link-soft small" href="license_catalog.php">Daftar Lisensi</a>
                    <a class="nav-link-soft small" href="blog.php">Blog</a>
                    <a class="btn btn-outline-light btn-sm px-3" href="register.php">Daftar</a>
                </div>
            </div>
        </nav>
    </div>

    <div class="container auth-shell py-5">
        <div class="card auth-card w-100">
            <div class="row g-0">
                <div class="col-lg-5 auth-aside p-4 p-md-5 d-flex flex-column justify-content-between">
                    <div>
                        <div class="feature-badge mb-3">Customer Portal</div>
                        <h1 class="h2 fw-bold text-white mb-3">Masuk ke sistem lisensi</h1>
                        <p class="text-white-50 mb-4">Akses dashboard pelanggan lisensi, data organisasi, aktivitas bisnis, dan update platform BAJAMA dengan pengalaman yang lebih profesional.</p>
                    </div>

                    <div class="d-grid gap-2">
                        <div class="metric-pill"><i class="bi bi-shield-check"></i>Lisensi terverifikasi</div>
                        <div class="metric-pill"><i class="bi bi-people"></i>Data pelanggan terstruktur</div>
                        <div class="metric-pill"><i class="bi bi-graph-up-arrow"></i>Operasional lebih terukur</div>
                    </div>
                </div>

                <div class="col-lg-7 auth-panel text-dark">
                    <div class="card-body p-4 p-md-5">
                        <div class="text-center mb-4">
                            <div class="brand text-dark" style="font-size: 2rem;">BAJAMA</div>
                            <div class="text-secondary">Building Networks Together</div>
                        </div>

                        <?php if ($error): ?>
                            <div class="alert alert-danger rounded-4"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>

                        <form method="post" class="premium-form">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

                            <div class="mb-3">
                                <label class="form-label fw-semibold"><?= htmlspecialchars($tr('login_identifier'), ENT_QUOTES, 'UTF-8') ?></label>
                                <input type="text" name="identifier" class="form-control form-control-lg" required autofocus value="<?= htmlspecialchars((string)($_POST['identifier'] ?? $_POST['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <div class="form-text"><?= htmlspecialchars($tr('login_identifier_help'), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold">Password</label>
                                <input type="password" name="password" class="form-control form-control-lg" required>
                            </div>

                            <button class="btn btn-bajama btn-lg w-100">Masuk ke BAJAMA</button>
                        </form>

                        <div class="d-flex justify-content-between mt-4 small text-secondary flex-wrap gap-2">
                            <a href="register.php" class="text-decoration-none">Daftar lisensi</a>
                            <a href="forgot-password.php" class="text-decoration-none"><?= htmlspecialchars($tr('forgot_password'), ENT_QUOTES, 'UTF-8') ?></a>
                            <a href="index.php" class="text-decoration-none">Kembali ke beranda</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
