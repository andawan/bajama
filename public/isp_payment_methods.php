<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

\BAJAMA\Core\Auth::requireLogin();
$db = db();
\BAJAMA\Core\License::requireFeature($db, 'billing');
\BAJAMA\Core\RBAC::require($db, 'billing.manage');

$roles = \BAJAMA\Core\RBAC::roles($db);
$isSuperAdmin = in_array('SUPER_ADMIN', $roles, true);
$sessionOrganizationId = (int)\BAJAMA\Core\Tenant::id();
$organizationId = $isSuperAdmin
    ? (int)($_GET['organization_id'] ?? $_POST['organization_id'] ?? $sessionOrganizationId)
    : $sessionOrganizationId;

if ($organizationId <= 0) {
    http_response_code(403);
    exit('Organisasi ISP tidak ditemukan.');
}

function isp_pm_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$orgStmt = $db->prepare('SELECT id, name FROM organizations WHERE id = ? LIMIT 1');
$orgStmt->execute([$organizationId]);
$organization = $orgStmt->fetch(PDO::FETCH_ASSOC);
if (!$organization) {
    http_response_code(404);
    exit('Organisasi tidak ditemukan.');
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf((string)($_POST['_csrf'] ?? ''));
        $action = (string)($_POST['action'] ?? '');
        $methodId = (int)($_POST['method_id'] ?? 0);

        if ($action === 'save') {
            $name = trim((string)($_POST['name'] ?? ''));
            $type = strtoupper((string)($_POST['type'] ?? 'BANK'));
            $provider = trim((string)($_POST['provider_name'] ?? ''));
            $accountName = trim((string)($_POST['account_name'] ?? ''));
            $accountNumber = trim((string)($_POST['account_number'] ?? ''));
            $instructions = trim((string)($_POST['instructions'] ?? ''));
            $active = isset($_POST['active']) ? 1 : 0;

            if ($name === '' || !in_array($type, ['BANK', 'EWALLET', 'GATEWAY'], true)) {
                throw new RuntimeException('Nama dan tipe payment method wajib valid.');
            }
            if ($type === 'BANK' && ($accountName === '' || $accountNumber === '')) {
                throw new RuntimeException('BANK membutuhkan nama rekening dan nomor rekening.');
            }
            if ($type === 'EWALLET' && ($accountName === '' || $accountNumber === '')) {
                throw new RuntimeException('E-Wallet membutuhkan nama pemilik dan nomor akun.');
            }

            $duplicate = $db->prepare('SELECT id FROM payment_methods WHERE organization_id = ? AND LOWER(name) = LOWER(?) AND id <> ? LIMIT 1');
            $duplicate->execute([$organizationId, $name, $methodId]);
            if ($duplicate->fetchColumn()) {
                throw new RuntimeException('Nama payment method sudah digunakan organisasi ini.');
            }

            if ($methodId > 0) {
                $stmt = $db->prepare('UPDATE payment_methods SET name = ?, type = ?, provider_name = ?, account_name = ?, account_number = ?, instructions = ?, active = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?');
                $stmt->execute([$name, $type, $provider ?: null, $accountName ?: null, $accountNumber ?: null, $instructions ?: null, $active, $methodId, $organizationId]);
                \BAJAMA\Core\Audit::log($db, 'ISP_PAYMENT_METHOD_UPDATED', 'billing', 'payment_method', $methodId, ['organization_id' => $organizationId], $organizationId);
                $message = 'Payment method ISP berhasil diperbarui.';
            } else {
                $stmt = $db->prepare('INSERT INTO payment_methods (organization_id, name, type, provider_name, account_name, account_number, instructions, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$organizationId, $name, $type, $provider ?: null, $accountName ?: null, $accountNumber ?: null, $instructions ?: null, $active]);
                $newId = (int)$db->lastInsertId();
                \BAJAMA\Core\Audit::log($db, 'ISP_PAYMENT_METHOD_CREATED', 'billing', 'payment_method', $newId, ['organization_id' => $organizationId], $organizationId);
                $message = 'Payment method ISP berhasil dibuat.';
            }
        }

        if ($action === 'toggle' && $methodId > 0) {
            $stmt = $db->prepare('UPDATE payment_methods SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END, updated_at = NOW() WHERE id = ? AND organization_id = ?');
            $stmt->execute([$methodId, $organizationId]);
            $message = 'Status payment method berhasil diubah.';
        }

        if ($action === 'delete' && $methodId > 0) {
            $stmt = $db->prepare('DELETE FROM payment_methods WHERE id = ? AND organization_id = ?');
            $stmt->execute([$methodId, $organizationId]);
            $message = 'Payment method berhasil dihapus.';
        }
    } catch (Throwable $e) {
        error_log('BAJAMA ISP payment method error: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}

$editId = (int)($_GET['edit_id'] ?? 0);
$edit = null;
if ($editId > 0) {
    $stmt = $db->prepare('SELECT * FROM payment_methods WHERE id = ? AND organization_id = ? LIMIT 1');
    $stmt->execute([$editId, $organizationId]);
    $edit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$stmt = $db->prepare('SELECT * FROM payment_methods WHERE organization_id = ? ORDER BY active DESC, type, name');
$stmt->execute([$organizationId]);
$methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
$organizations = $isSuperAdmin ? $db->query('SELECT id, name FROM organizations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) : [];

$pageTitle = 'Payment Method ISP';
ob_start();
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div><div class="text-primary fw-semibold small">ISP BILLING</div><h1 class="fw-bold mb-1">Payment Method Organisasi</h1><p class="text-muted mb-0">Metode ini hanya tampil pada invoice pelanggan <?= isp_pm_h($organization['name']) ?>.</p></div>
        <div class="d-flex gap-2"><a href="invoices.php" class="btn btn-outline-primary">Invoice</a><a href="payments.php" class="btn btn-outline-primary">Payments</a></div>
    </div>
    <?php if ($message): ?><div class="alert alert-success"><?= isp_pm_h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= isp_pm_h($error) ?></div><?php endif; ?>

    <?php if ($isSuperAdmin): ?><form method="get" class="card border-0 shadow-sm mb-4"><div class="card-body row g-2 align-items-end"><div class="col-md-6"><label class="form-label">Organisasi ISP</label><select name="organization_id" class="form-select"><?php foreach ($organizations as $org): ?><option value="<?= (int)$org['id'] ?>" <?= $organizationId === (int)$org['id'] ? 'selected' : '' ?>><?= isp_pm_h($org['name']) ?></option><?php endforeach; ?></select></div><div class="col-auto"><button class="btn btn-primary">Pilih</button></div></div></form><?php endif; ?>

    <div class="card border-0 shadow-sm mb-4"><div class="card-header bg-dark text-white"><?= $edit ? 'Edit Payment Method ISP' : 'Tambah Payment Method ISP' ?></div><div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="_csrf" value="<?= isp_pm_h(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="method_id" value="<?= (int)($edit['id'] ?? 0) ?>"><input type="hidden" name="organization_id" value="<?= $organizationId ?>">
            <div class="col-md-3"><label class="form-label">Nama</label><input name="name" class="form-control" value="<?= isp_pm_h($edit['name'] ?? '') ?>" placeholder="BCA ISP / QRIS ISP" required></div>
            <div class="col-md-2"><label class="form-label">Tipe</label><select name="type" class="form-select"><option value="BANK" <?= (($edit['type'] ?? 'BANK') === 'BANK') ? 'selected' : '' ?>>BANK</option><option value="EWALLET" <?= (($edit['type'] ?? '') === 'EWALLET') ? 'selected' : '' ?>>E-WALLET</option><option value="GATEWAY" <?= (($edit['type'] ?? '') === 'GATEWAY') ? 'selected' : '' ?>>GATEWAY</option></select></div>
            <div class="col-md-3"><label class="form-label">Provider</label><input name="provider_name" class="form-control" value="<?= isp_pm_h($edit['provider_name'] ?? '') ?>" placeholder="BCA, DANA, Midtrans"></div>
            <div class="col-md-2"><label class="form-label">Nama akun</label><input name="account_name" class="form-control" value="<?= isp_pm_h($edit['account_name'] ?? '') ?>" required></div>
            <div class="col-md-2"><label class="form-label">Nomor akun</label><input name="account_number" class="form-control" value="<?= isp_pm_h($edit['account_number'] ?? '') ?>" required></div>
            <div class="col-12"><label class="form-label">Instruksi pembayaran</label><textarea name="instructions" class="form-control" rows="2"><?= isp_pm_h($edit['instructions'] ?? '') ?></textarea></div>
            <div class="col-12"><label class="form-check"><input type="checkbox" name="active" class="form-check-input" <?= ((int)($edit['active'] ?? 1) === 1) ? 'checked' : '' ?>> <span class="form-check-label">Aktif untuk invoice ISP</span></label></div>
            <div class="col-12"><button class="btn btn-primary">Simpan</button><?php if ($edit): ?><a href="isp_payment_methods.php?organization_id=<?= $organizationId ?>" class="btn btn-outline-secondary ms-2">Batal</a><?php endif; ?></div>
        </form>
    </div></div>

    <div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold">Metode pembayaran <?= isp_pm_h($organization['name']) ?></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Nama</th><th>Tipe</th><th>Provider</th><th>Akun</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php if (!$methods): ?><tr><td colspan="6" class="text-center text-muted py-4">Belum ada payment method organisasi.</td></tr><?php endif; ?><?php foreach ($methods as $method): ?><tr><td><?= isp_pm_h($method['name']) ?></td><td><?= isp_pm_h($method['type']) ?></td><td><?= isp_pm_h($method['provider_name'] ?: '-') ?></td><td><?= isp_pm_h($method['account_number'] ?: $method['account_name'] ?: '-') ?></td><td><span class="badge text-bg-<?= (int)$method['active'] === 1 ? 'success' : 'secondary' ?>"><?= (int)$method['active'] === 1 ? 'AKTIF' : 'NONAKTIF' ?></span></td><td><div class="btn-group btn-group-sm"><a href="isp_payment_methods.php?organization_id=<?= $organizationId ?>&edit_id=<?= (int)$method['id'] ?>" class="btn btn-outline-primary">Edit</a><form method="post"><input type="hidden" name="_csrf" value="<?= isp_pm_h(csrf_token()) ?>"><input type="hidden" name="organization_id" value="<?= $organizationId ?>"><input type="hidden" name="method_id" value="<?= (int)$method['id'] ?>"><input type="hidden" name="action" value="toggle"><button class="btn btn-outline-dark">Toggle</button></form><form method="post" onsubmit="return confirm('Hapus payment method ini?');"><input type="hidden" name="_csrf" value="<?= isp_pm_h(csrf_token()) ?>"><input type="hidden" name="organization_id" value="<?= $organizationId ?>"><input type="hidden" name="method_id" value="<?= (int)$method['id'] ?>"><input type="hidden" name="action" value="delete"><button class="btn btn-outline-danger">Hapus</button></form></div></td></tr><?php endforeach; ?></tbody></table></div></div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../app/layout/layout.php';
