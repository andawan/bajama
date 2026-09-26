<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\I18n;

$locale = I18n::locale();

$userId = (int)($_SESSION['password_reset_user_id'] ?? 0);
if ($userId <= 0) { header('Location: forgot-password.php'); exit; }
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf((string)($_POST['_csrf'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (strlen($password) < 10 || $password !== (string)($_POST['password_confirmation'] ?? '')) throw new RuntimeException('Password minimal 10 karakter dan konfirmasi harus sama.');
        $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND status = "ACTIVE"');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        unset($_SESSION['password_reset_user_id']);
        $message = 'Password berhasil diubah. Silakan login kembali.';
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
?>
<!doctype html><html lang="<?= htmlspecialchars($locale, ENT_QUOTES, 'UTF-8') ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Reset Sandi BAJAMA</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><div class="container py-5"><div class="row justify-content-center"><div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body p-4 p-lg-5"><h1 class="h3 fw-bold">Buat password baru</h1><?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?><br><a href="login.php">Login sekarang</a></div><?php else: ?><?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?><form method="post"><input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input class="form-control mb-3" type="password" name="password" minlength="10" placeholder="Password baru" required><input class="form-control mb-3" type="password" name="password_confirmation" minlength="10" placeholder="Konfirmasi password" required><button class="btn btn-primary w-100">Simpan password</button></form><?php endif; ?></div></div></div></div></div></body></html>
