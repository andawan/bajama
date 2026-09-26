<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\RBAC;

Auth::requireLogin(true);
$db = db();
$userId = (int) Auth::userId();
$message = '';
$error = '';
$organizationId = (int) Auth::organizationId();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'start_license_payment') {
    try {
        verify_csrf((string) ($_POST['_csrf'] ?? ''));
        $planId = (int)($_POST['plan_id'] ?? 0);
        $planStmt = $db->prepare('SELECT * FROM license_plans WHERE id = ? AND active = 1 LIMIT 1');
        $planStmt->execute([$planId]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            throw new RuntimeException('Paket lisensi aktif tidak ditemukan.');
        }

        $userStmt = $db->prepare('SELECT u.full_name, u.email, u.username, o.name AS company_name, o.phone FROM users u JOIN organizations o ON o.id = u.organization_id WHERE u.id = ? AND u.organization_id = ? LIMIT 1');
        $userStmt->execute([$userId, $organizationId]);
        $account = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            throw new RuntimeException('Data profile tidak ditemukan.');
        }

        $pendingStmt = $db->prepare("SELECT id FROM license_registrations WHERE email = ? AND status IN ('PENDING','PAID') ORDER BY id DESC LIMIT 1");
        $pendingStmt->execute([(string)$account['email']]);
        $existingRegistrationId = (int)$pendingStmt->fetchColumn();
        if ($existingRegistrationId > 0) {
            $db->prepare('UPDATE license_registrations SET company_name = ?, full_name = ?, email = ?, phone = ?, package_name = ?, plan_id = ?, payment_method_id = NULL, notes = CONCAT(COALESCE(notes, ""), ?) WHERE id = ?')->execute([
                (string)$account['company_name'], (string)$account['full_name'], (string)$account['email'], (string)($account['phone'] ?? ''), (string)$plan['name'], $planId, "\nRenewal/payment initiated from profile.", $existingRegistrationId
            ]);
            $registrationId = $existingRegistrationId;
        } else {
            $insert = $db->prepare('INSERT INTO license_registrations (company_name, full_name, email, phone, package_name, plan_id, payment_method_id, notes, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, "PENDING", NOW())');
            $insert->execute([(string)$account['company_name'], (string)$account['full_name'], (string)$account['email'], (string)($account['phone'] ?? ''), (string)$plan['name'], $planId, 'Payment/renewal initiated from profile.']);
            $registrationId = (int)$db->lastInsertId();
        }

        header('Location: payment.php?registration_id=' . $registrationId);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf((string) ($_POST['_csrf'] ?? ''));
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $newPassword = (string) ($_POST['password'] ?? '');
        $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

        if ($fullName === '') {
            throw new RuntimeException('Nama lengkap wajib diisi.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Format email tidak valid.');
        }
        $emailCheck = $db->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $emailCheck->execute([$email !== '' ? $email : null, $userId]);
        if ($email !== '' && $emailCheck->fetchColumn()) {
            throw new RuntimeException('Email sudah digunakan oleh user lain.');
        }
        if ($newPassword !== '' && strlen($newPassword) < 10) {
            throw new RuntimeException('Password baru minimal 10 karakter.');
        }
        if ($newPassword !== $passwordConfirmation) {
            throw new RuntimeException('Konfirmasi password tidak cocok.');
        }

        $sql = 'UPDATE users SET full_name = ?, email = ?';
        $params = [$fullName, $email !== '' ? $email : null];
        if ($newPassword !== '') {
            $sql .= ', password_hash = ?';
            $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id = ? AND organization_id = ?';
        $params[] = $userId;
        $params[] = (int) Auth::organizationId();
        $db->prepare($sql)->execute($params);
        $_SESSION['full_name'] = $fullName;
        $message = 'Profile berhasil diperbarui.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stmt = $db->prepare('SELECT u.username, u.email, u.full_name, u.status, u.last_login_at, o.name AS organization_name, o.phone AS organization_phone FROM users u INNER JOIN organizations o ON o.id = u.organization_id WHERE u.id = ? AND u.organization_id = ? LIMIT 1');
$stmt->execute([$userId, $organizationId]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$profile) {
    http_response_code(404);
    exit('Profile tidak ditemukan.');
}
$roles = RBAC::roles($db);
$plans = $db->query('SELECT id, name, code, price_monthly FROM license_plans WHERE active = 1 ORDER BY price_monthly ASC')->fetchAll(PDO::FETCH_ASSOC);
$currentLicenseStmt = $db->prepare('SELECT l.*, p.name AS plan_name, p.price_monthly FROM licenses l LEFT JOIN license_plans p ON p.id = l.plan_id WHERE l.organization_id = ? ORDER BY l.id DESC LIMIT 1');
$currentLicenseStmt->execute([$organizationId]);
$currentLicense = $currentLicenseStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$pendingRegistrationStmt = $db->prepare("SELECT id, plan_id, package_name, status, payment_review_status FROM license_registrations WHERE email = ? AND status IN ('PENDING','PAID') ORDER BY id DESC LIMIT 1");
$pendingRegistrationStmt->execute([(string)$profile['email']]);
$pendingRegistration = $pendingRegistrationStmt->fetch(PDO::FETCH_ASSOC) ?: null;

function profile_h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$pageTitle = 'Profile';
ob_start();
?>
<div class="container-fluid py-4">
  <div class="mb-4"><h1>Profile</h1><p class="text-muted mb-0">Kelola informasi akun user yang sedang login.</p></div>
  <?php if ($message): ?><div class="alert alert-success"><?= profile_h($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= profile_h($error) ?></div><?php endif; ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <div class="text-muted small">Lisensi & pembayaran</div>
                    <h2 class="h5 fw-bold mb-1"><?= profile_h($currentLicense['plan_name'] ?? ($pendingRegistration['package_name'] ?? 'Belum memilih paket')) ?></h2>
                    <?php if ($currentLicense): ?>
                        <div class="small text-muted">Status: <?= profile_h($currentLicense['status'] ?? '-') ?> · Berakhir: <?= profile_h($currentLicense['expires_at'] ?? '-') ?></div>
                    <?php else: ?>
                        <div class="small text-muted">Pilih paket untuk memulai pembayaran dan aktivasi lisensi.</div>
                    <?php endif; ?>
                    <?php if ($pendingRegistration): ?><div class="small text-warning mt-1">Pembayaran menunggu review.</div><?php endif; ?>
                </div>
                <?php $licenseExpired = $currentLicense && !empty($currentLicense['expires_at']) && strtotime((string)$currentLicense['expires_at']) < time(); ?>
                <?php if ($pendingRegistration): ?>
                    <a href="payment.php?registration_id=<?= (int)$pendingRegistration['id'] ?>" class="btn btn-warning rounded-pill">Lanjutkan Pembayaran</a>
                <?php else: ?>
                    <button class="btn <?= $licenseExpired ? 'btn-danger' : 'btn-primary' ?> rounded-pill" type="button" data-bs-toggle="collapse" data-bs-target="#profilePaymentForm"><?= $licenseExpired ? 'Renew Lisensi' : 'Bayar / Tambah Masa Aktif' ?></button>
                <?php endif; ?>
            </div>
            <?php if (!$pendingRegistration): ?>
                <div class="collapse mt-4" id="profilePaymentForm">
                    <form method="post" class="row g-3 align-items-end">
                        <input type="hidden" name="_csrf" value="<?= profile_h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="start_license_payment">
                        <div class="col-md-8">
                            <label class="form-label">Pilih paket lisensi</label>
                            <select name="plan_id" class="form-select" required>
                                <option value="">Pilih paket</option>
                                <?php foreach ($plans as $plan): ?>
                                    <option value="<?= (int)$plan['id'] ?>"><?= profile_h($plan['name']) ?> — Rp <?= number_format((float)$plan['price_monthly'], 0, ',', '.') ?>/bulan</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4"><button class="btn btn-primary w-100" type="submit">Masuk ke Payment</button></div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
  <div class="row g-4">
    <div class="col-lg-4"><div class="card"><div class="card-body"><div class="d-flex align-items-center gap-3 mb-3"><div class="user-avatar"><i class="bi bi-person-fill"></i></div><div><h2 class="h5 mb-1"><?= profile_h($profile['full_name'] ?: $profile['username']) ?></h2><div class="text-muted">@<?= profile_h($profile['username']) ?></div></div></div><dl class="row mb-0"><dt class="col-5">Role</dt><dd class="col-7"><?= profile_h(implode(', ', $roles)) ?></dd><dt class="col-5">Organisasi</dt><dd class="col-7"><?= profile_h($profile['organization_name']) ?></dd><dt class="col-5">Status</dt><dd class="col-7"><?= profile_h($profile['status']) ?></dd></dl></div></div></div>
    <div class="col-lg-8"><div class="card"><div class="card-body"><form method="post" class="row g-3"><input type="hidden" name="_csrf" value="<?= profile_h(csrf_token()) ?>"><div class="col-md-6"><label class="form-label">Username</label><input class="form-control" value="<?= profile_h($profile['username']) ?>" disabled></div><div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" value="<?= profile_h($profile['email']) ?>"></div><div class="col-12"><label class="form-label">Nama lengkap</label><input class="form-control" name="full_name" value="<?= profile_h($profile['full_name']) ?>" required></div><div class="col-md-6"><label class="form-label">Password baru</label><input class="form-control" type="password" name="password" minlength="10" placeholder="Kosongkan bila tidak diubah"></div><div class="col-md-6"><label class="form-label">Konfirmasi password</label><input class="form-control" type="password" name="password_confirmation" minlength="10"></div><div class="col-12"><button class="btn btn-primary" type="submit">Simpan Profile</button></div></form></div></div></div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../app/layout/layout.php';
