<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Otp;
use BAJAMA\Core\I18n;

$message = '';
$locale = I18n::locale();
$tr = static fn (string $key): string => I18n::t($key, $locale);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf((string)($_POST['_csrf'] ?? ''));
    $identifier = strtolower(trim((string)($_POST['identifier'] ?? $_POST['email'] ?? '')));
    if ($identifier === '') {
        $message = $tr('email_or_username_required');
    } else {
        $stmt = $db->prepare('SELECT id, email FROM users WHERE (email = ? OR username = ?) AND status = "ACTIVE" LIMIT 1');
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $userId = (int)($user['id'] ?? 0);
        $email = strtolower(trim((string)($user['email'] ?? '')));
        if ($userId > 0) {
            try {
                Otp::issue($db, $email, 'PASSWORD_RESET', ['user_id' => $userId]);
            } catch (Throwable $e) {
                error_log('BAJAMA password OTP error: ' . $e->getMessage());
            }
        }
        $_SESSION['otp_email'] = $email !== '' ? $email : $identifier;
        $_SESSION['otp_purpose'] = 'PASSWORD_RESET';
        $message = 'Jika email terdaftar, kode OTP telah dikirim. Silakan cek kotak masuk atau folder spam.';
    }
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars($locale, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lupa Sandi BAJAMA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg bg-dark navbar-dark">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">BAJAMA</a>
            <div class="ms-auto d-flex gap-3 small">
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-translate me-1"></i><?= htmlspecialchars(I18n::languageName($locale), ENT_QUOTES, 'UTF-8') ?></button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php foreach (I18n::supported() as $supportedLocale): ?><li><a class="dropdown-item <?= $supportedLocale === $locale ? 'active' : '' ?>" href="?lang=<?= urlencode($supportedLocale) ?>"><?= htmlspecialchars(I18n::languageName($supportedLocale), ENT_QUOTES, 'UTF-8') ?></a></li><?php endforeach; ?>
                    </ul>
                </div>
                <a class="nav-link" href="index.php">Beranda</a>
                <a class="nav-link" href="license_catalog.php">Daftar Lisensi</a>
                <a class="nav-link" href="login.php">Login</a>
            </div>
        </div>
    </nav>

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4 p-lg-5">
                        <h1 class="h3 fw-bold mb-3">Lupa Sandi</h1>
                        <p class="text-muted"><?= htmlspecialchars($tr('recovery_help'), ENT_QUOTES, 'UTF-8') ?></p>

                        <?php if ($message): ?>
                            <div class="alert <?= (strpos($message, 'dikirim') !== false) ? 'alert-success' : 'alert-danger' ?>">
                                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>

                        <form method="post">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                            <div class="mb-3">
                                <label class="form-label"><?= htmlspecialchars($tr('login_identifier'), ENT_QUOTES, 'UTF-8') ?></label>
                                <input type="text" name="identifier" class="form-control" required value="<?= htmlspecialchars((string)($_POST['identifier'] ?? $_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <button class="btn btn-primary w-100" type="submit"><?= htmlspecialchars($tr('send_instructions'), ENT_QUOTES, 'UTF-8') ?></button>
                        </form>

                        <div class="mt-4 text-center small">
                            <a href="login.php" class="text-decoration-none"><?= htmlspecialchars($tr('back_to_login'), ENT_QUOTES, 'UTF-8') ?></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
