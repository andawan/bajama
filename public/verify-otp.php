<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Otp;
use BAJAMA\Core\I18n;

$locale = I18n::locale();

$email = trim((string)($_SESSION['otp_email'] ?? ''));
$purpose = (string)($_SESSION['otp_purpose'] ?? '');
$message = '';
$error = '';

if ($email === '' || !in_array($purpose, ['REGISTER', 'PASSWORD_RESET'], true)) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf((string)($_POST['_csrf'] ?? ''));
        $payload = Otp::consume(db(), $email, $purpose, (string)($_POST['otp'] ?? ''));
        if ($purpose === 'REGISTER') {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)($payload['user_id'] ?? 0);
            $_SESSION['organization_id'] = (int)($payload['organization_id'] ?? 0);
            $_SESSION['username'] = (string)($payload['username'] ?? '');
            $_SESSION['full_name'] = (string)($payload['full_name'] ?? '');
            $_SESSION['organization_name'] = (string)($payload['organization_name'] ?? '');
            $_SESSION['pending_registration_id'] = (int)($payload['registration_id'] ?? 0);
            unset($_SESSION['otp_email'], $_SESSION['otp_purpose']);
            header('Location: profile.php');
            exit;
        }
        $_SESSION['password_reset_user_id'] = (int)($payload['user_id'] ?? 0);
        unset($_SESSION['otp_email'], $_SESSION['otp_purpose']);
        header('Location: reset-password.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars($locale, ENT_QUOTES, 'UTF-8') ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Verifikasi OTP BAJAMA</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><div class="container py-5"><div class="row justify-content-center"><div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body p-4 p-lg-5"><h1 class="h3 fw-bold">Verifikasi kode OTP</h1><p class="text-muted">Kode dikirim ke <strong><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></strong>.</p><?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?><form method="post"><input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><label class="form-label">Kode OTP 6 digit</label><input class="form-control form-control-lg text-center letter-spacing" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus><button class="btn btn-primary w-100 mt-3">Verifikasi</button></form><div class="mt-3 text-center"><a href="login.php">Kembali ke login</a></div></div></div></div></div></div></body></html>
