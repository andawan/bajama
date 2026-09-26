<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\PaymentProof;

\BAJAMA\Core\Auth::requireLogin();
\BAJAMA\Core\RBAC::require(db(), 'license.manage');

$db = db();

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf((string)($_POST['_csrf'] ?? ''));
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'approve_registration') {
        $registrationId = (int)($_POST['registration_id'] ?? 0);
        if ($registrationId > 0) {
            $stmt = $db->prepare('SELECT * FROM license_registrations WHERE id = ? LIMIT 1');
            $stmt->execute([$registrationId]);
            $registration = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$registration) {
                $error = 'Pendaftaran tidak ditemukan.';
            } else {
                $db->beginTransaction();
                try {
                    $companyName = trim((string)($registration['company_name'] ?? ''));
                    $fullName = trim((string)($registration['full_name'] ?? ''));
                    $email = trim((string)($registration['email'] ?? ''));
                    $phone = trim((string)($registration['phone'] ?? ''));
                    $planId = (int)($registration['plan_id'] ?? 0);

                    if ($companyName === '' || $fullName === '' || $email === '') {
                        throw new RuntimeException('Data pendaftaran tidak lengkap.');
                    }

                    $username = '';
                    $password = '';
                    $existingUserStmt = $db->prepare('SELECT u.id, u.username, u.organization_id FROM users u WHERE u.email = ? ORDER BY u.id DESC LIMIT 1');
                    $existingUserStmt->execute([$email]);
                    $existingUser = $existingUserStmt->fetch(PDO::FETCH_ASSOC);

                    if ($existingUser) {
                        $orgId = (int)$existingUser['organization_id'];
                        $userId = (int)$existingUser['id'];
                        $username = (string)$existingUser['username'];
                        $db->prepare('UPDATE users SET full_name = ?, status = "ACTIVE" WHERE id = ?')->execute([$fullName, $userId]);
                        $db->prepare('UPDATE organizations SET name = ?, email = ?, phone = ?, status = "ACTIVE" WHERE id = ?')->execute([$companyName, $email, $phone, $orgId]);
                    } else {
                        $slugBase = trim((string)(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $companyName) ?: 'organization')), '-');
                        $slug = $slugBase !== '' ? $slugBase : 'organization'; $n = 1;
                        while (true) {
                            $exists = $db->prepare('SELECT id FROM organizations WHERE slug = ? LIMIT 1');
                            $exists->execute([$slug]);
                            if (!$exists->fetch()) break;
                            $slug = ($slugBase !== '' ? $slugBase : 'organization') . '-' . $n++;
                        }
                        $orgStmt = $db->prepare('INSERT INTO organizations (name, slug, email, phone, status) VALUES (?, ?, ?, ?, "ACTIVE")');
                        $orgStmt->execute([$companyName, $slug, $email, $phone]);
                        $orgId = (int)$db->lastInsertId();
                        $usernameBase = trim((string)(strtolower(preg_replace('/[^a-z0-9]+/i', '.', $fullName) ?: 'customer')), '.');
                        $username = $usernameBase !== '' ? $usernameBase : 'customer'; $counter = 1;
                        while (true) {
                            $exists = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
                            $exists->execute([$username]);
                            if (!$exists->fetch()) break;
                            $username = ($usernameBase !== '' ? $usernameBase : 'customer') . $counter++;
                        }
                        $password = 'BAJAMA-' . strtoupper(substr(md5((string)time() . $email), 0, 8));
                        $userInsert = $db->prepare('INSERT INTO users (organization_id, username, email, password_hash, full_name, status) VALUES (?, ?, ?, ?, ?, "ACTIVE")');
                        $userInsert->execute([$orgId, $username, $email, password_hash($password), $fullName]);
                        $userId = (int)$db->lastInsertId();
                        $roleStmt = $db->prepare('SELECT id FROM roles WHERE name = "OWNER" LIMIT 1');
                        $roleStmt->execute();
                        $roleId = (int)$roleStmt->fetchColumn();
                        if ($roleId > 0) $db->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $roleId]);
                    }

                    $customerCheck = $db->prepare('SELECT id FROM customers WHERE organization_id = ? AND email = ? LIMIT 1');
                    $customerCheck->execute([$orgId, $email]);
                    if (!$customerCheck->fetchColumn()) {
                        $customerCode = 'CUST-' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 8));
                        $db->prepare('INSERT INTO customers (organization_id, customer_code, name, email, phone, status) VALUES (?, ?, ?, ?, ?, "ACTIVE")')->execute([$orgId, $customerCode, $fullName, $email, $phone]);
                    }

                    if ($planId > 0) {
                        $planStmt = $db->prepare('SELECT features FROM license_plans WHERE id = ? LIMIT 1');
                        $planStmt->execute([$planId]);
                        $planFeatures = $planStmt->fetchColumn();
                        $licenseFeatures = is_string($planFeatures) && $planFeatures !== '' ? $planFeatures : json_encode(['dashboard' => true], JSON_UNESCAPED_UNICODE);
                        $licenseStmt = $db->prepare('SELECT id, license_key, expires_at FROM licenses WHERE organization_id = ? ORDER BY id DESC LIMIT 1');
                        $licenseStmt->execute([$orgId]);
                        $existingLicense = $licenseStmt->fetch(PDO::FETCH_ASSOC);
                        $baseDate = ($existingLicense && !empty($existingLicense['expires_at']) && strtotime((string)$existingLicense['expires_at']) > time()) ? (string)$existingLicense['expires_at'] : date('Y-m-d H:i:s');
                        $expiresAt = date('Y-m-d H:i:s', strtotime($baseDate . ' +365 days'));
                        if ($existingLicense) {
                            $db->prepare('UPDATE licenses SET plan_id = ?, status = "ACTIVE", expires_at = ?, features = ? WHERE id = ?')->execute([$planId, $expiresAt, $licenseFeatures, (int)$existingLicense['id']]);
                        } else {
                            $licenseKey = 'BAJAMA-' . strtoupper(substr(md5((string)time() . $email . $companyName), 0, 12));
                            $db->prepare('INSERT INTO licenses (organization_id, plan_id, license_key, status, issued_at, expires_at, features) VALUES (?, ?, ?, "ACTIVE", NOW(), ?, ?)')->execute([$orgId, $planId, $licenseKey, $expiresAt, $licenseFeatures]);
                        }
                    }

                    $db->prepare('UPDATE license_registrations SET status = "APPROVED", payment_review_status = "VERIFIED", processed_by_user_id = ?, processed_at = NOW(), payment_reference = COALESCE(payment_reference, "APPROVED") WHERE id = ?')->execute([\BAJAMA\Core\Auth::userId(), $registrationId]);
                    $db->commit();
                    $message = 'Pendaftaran disetujui. Username: ' . $username . ' | Password: ' . $password;
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    error_log('BAJAMA registration approve error: ' . $e->getMessage());
                    $error = 'Gagal menyetujui pendaftaran.';
                }
            }
        }
    }

    if ($action === 'reject_registration') {
        $registrationId = (int)($_POST['registration_id'] ?? 0);
        if ($registrationId > 0) {
            $db->prepare('UPDATE license_registrations SET status = "REJECTED", payment_review_status = "REJECTED", processed_by_user_id = ?, processed_at = NOW() WHERE id = ?')->execute([\BAJAMA\Core\Auth::userId(), $registrationId]);
            $message = 'Pendaftaran berhasil ditolak.';
        }
    }

    if ($action === 'delete_registration') {
        $registrationId = (int)($_POST['registration_id'] ?? 0);
        if ($registrationId > 0) {
            $db->prepare('DELETE FROM license_registrations WHERE id = ?')->execute([$registrationId]);
            $message = 'Pendaftaran berhasil dihapus.';
        }
    }
}

try {
    $db->query("SHOW COLUMNS FROM `license_registrations` LIKE 'payment_proof'");
} catch (Throwable $e) {
    $db->exec('ALTER TABLE license_registrations ADD COLUMN payment_proof VARCHAR(255) NULL AFTER payment_reference');
}

try {
    $db->query("SHOW COLUMNS FROM `license_registrations` LIKE 'payment_review_status'");
} catch (Throwable $e) {
    $db->exec("ALTER TABLE `license_registrations` ADD COLUMN `payment_review_status` ENUM('WAITING_REVIEW','VERIFIED','REJECTED') NOT NULL DEFAULT 'WAITING_REVIEW' AFTER `payment_proof`");
}

$registrations = $db->query('SELECT r.*, p.name AS plan_name, pm.name AS payment_method_name FROM license_registrations r LEFT JOIN license_plans p ON p.id = r.plan_id LEFT JOIN payment_methods pm ON pm.id = r.payment_method_id ORDER BY r.created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Pendaftaran Pelanggan';
ob_start();
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Pendaftaran Pelanggan</h1>
            <p class="text-muted mb-0">Kelola semua pendaftaran lisensi dari calon pelanggan baru.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-primary" href="dashboard.php">Kembali ke Dashboard</a>
            <a class="btn btn-outline-dark" href="payment_methods.php">Metode Pembayaran</a>
        </div>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-dark text-white">Daftar pendaftaran</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Perusahaan</th>
                        <th>Kontak</th>
                        <th>Paket</th>
                        <th>Metode</th>
                        <th>Bukti</th>
                        <th>Status Pembayaran</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registrations as $registration): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= h((string)($registration['company_name'] ?? '-')) ?></div>
                                <small class="text-muted"><?= h((string)($registration['email'] ?? '-')) ?></small>
                            </td>
                            <td>
                                <?= h((string)($registration['full_name'] ?? '-')) ?><br>
                                <small><?= h((string)($registration['phone'] ?? '-')) ?></small>
                            </td>
                            <td><?= h((string)($registration['plan_name'] ?? $registration['package_name'] ?? '-')) ?></td>
                            <td><?= h((string)($registration['payment_method_name'] ?? '-')) ?></td>
                            <td>
                                <?php $proofValue = (string)($registration['payment_proof'] ?? ''); ?>
                                <?php $proof = PaymentProof::url((int)$registration['id'], $proofValue); ?>
                                <?php if ($proof !== ''): ?>
                                    <?php $imageUrl = PaymentProof::imageUrl((int)$registration['id'], $proofValue); ?>
                                    <div class="d-flex flex-column gap-2">
                                        <div class="btn-group btn-group-sm" role="group" aria-label="Bukti pembayaran">
                                            <?php if ($imageUrl !== ''): ?>
                                                <a href="<?= h($imageUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline-primary"><i class="bi bi-image me-1"></i>Buka gambar</a>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-outline-secondary" disabled><i class="bi bi-image me-1"></i>Buka gambar</button>
                                            <?php endif; ?>
                                            <a href="<?= h($proof) ?>" target="_blank" rel="noopener" class="btn btn-outline-dark"><i class="bi bi-link-45deg me-1"></i>Buka link</a>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $reviewStatus = (string)($registration['payment_review_status'] ?? 'WAITING_REVIEW'); ?>
                                <?php if ($reviewStatus === 'VERIFIED'): ?>
                                    <span class="badge bg-success-subtle text-success-emphasis">Sudah diverifikasi</span>
                                <?php elseif ($reviewStatus === 'REJECTED'): ?>
                                    <span class="badge bg-danger-subtle text-danger-emphasis">Ditolak</span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis">Menunggu review</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-light text-dark"><?= h((string)($registration['status'] ?? 'PENDING')) ?></span></td>
                            <td class="text-end">
                                <?php if (($registration['status'] ?? 'PENDING') === 'PENDING' || ($registration['status'] ?? 'PENDING') === 'PAID'): ?>
                                    <div class="btn-group btn-group-sm">
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="approve_registration">
                                            <input type="hidden" name="registration_id" value="<?= (int)$registration['id'] ?>">
                                            <button type="submit" class="btn btn-success">Approve</button>
                                        </form>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="reject_registration">
                                            <input type="hidden" name="registration_id" value="<?= (int)$registration['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger">Reject</button>
                                        </form>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Hapus pendaftaran ini?');">
                                            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_registration">
                                            <input type="hidden" name="registration_id" value="<?= (int)$registration['id'] ?>">
                                            <button type="submit" class="btn btn-outline-dark">Hapus</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <div class="btn-group btn-group-sm">
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Hapus pendaftaran ini?');">
                                            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_registration">
                                            <input type="hidden" name="registration_id" value="<?= (int)$registration['id'] ?>">
                                            <button type="submit" class="btn btn-outline-dark">Hapus</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../app/layout/layout.php';
