<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

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

    if ($action === 'save_payment_method') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $type = in_array((string)($_POST['type'] ?? 'BANK'), ['BANK', 'EWALLET', 'GATEWAY'], true) ? (string)$_POST['type'] : 'BANK';
        $providerName = trim((string)($_POST['provider_name'] ?? ''));
        $accountName = trim((string)($_POST['account_name'] ?? ''));
        $accountNumber = trim((string)($_POST['account_number'] ?? ''));
        $instructions = trim((string)($_POST['instructions'] ?? ''));
        $active = isset($_POST['active']) ? 1 : 0;

        if ($name === '') {
            $error = 'Nama metode pembayaran wajib diisi.';
        } elseif ($type === 'BANK' && ($accountName === '' || $accountNumber === '')) {
            $error = 'Untuk BANK, nama rekening dan nomor rekening wajib diisi.';
        } elseif ($type === 'EWALLET' && ($accountName === '' || $accountNumber === '')) {
            $error = 'Untuk E-Wallet, nama pemilik dan nomor HP aktif wajib diisi.';
        } elseif ($type === 'GATEWAY' && $providerName === '' && $accountName === '') {
            $error = 'Untuk payment gateway internasional, provider atau akun wajib diisi.';
        } else {
            try {
                $duplicateSql = 'SELECT id FROM payment_methods WHERE organization_id IS NULL AND LOWER(name) = LOWER(?)' . ($id > 0 ? ' AND id <> ?' : '') . ' LIMIT 1';
                $duplicateStmt = $db->prepare($duplicateSql);
                $params = [$name];
                if ($id > 0) {
                    $params[] = $id;
                }
                $duplicateStmt->execute($params);

                if ($duplicateStmt->fetch()) {
                    $error = 'Nama metode pembayaran sudah ada.';
                } else {
                    if ($id > 0) {
                        $db->prepare('UPDATE payment_methods SET name = ?, type = ?, provider_name = ?, account_name = ?, account_number = ?, instructions = ?, active = ?, updated_at = NOW() WHERE id = ? AND organization_id IS NULL')->execute([$name, $type, $providerName, $accountName, $accountNumber, $instructions, $active, $id]);
                        $message = 'Metode pembayaran diperbarui.';
                    } else {
                        $db->prepare('INSERT INTO payment_methods (name, type, provider_name, account_name, account_number, instructions, active) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$name, $type, $providerName, $accountName, $accountNumber, $instructions, $active]);
                        $message = 'Metode pembayaran baru disimpan.';
                    }
                }
            } catch (Throwable $e) {
                error_log('BAJAMA payment method save error: ' . $e->getMessage());
                $error = 'Gagal menyimpan metode pembayaran.';
            }
        }
    }

    if ($action === 'toggle_payment_method') {
        $methodId = (int)($_POST['method_id'] ?? 0);
        if ($methodId > 0) {
            $targetStatus = isset($_POST['active']) ? 1 : 0;
            $db->prepare('UPDATE payment_methods SET active = ?, updated_at = NOW() WHERE id = ? AND organization_id IS NULL')->execute([$targetStatus, $methodId]);
            $message = $targetStatus === 1 ? 'Metode diaktifkan.' : 'Metode dinonaktifkan.';
        }
    }

    if ($action === 'delete_payment_method') {
        $methodId = (int)($_POST['method_id'] ?? 0);
        if ($methodId > 0) {
            try {
                $db->beginTransaction();
                $db->prepare('UPDATE license_registrations SET payment_method_id = NULL WHERE payment_method_id = ?')->execute([$methodId]);
                $db->prepare('DELETE FROM payment_methods WHERE id = ? AND organization_id IS NULL')->execute([$methodId]);
                $db->commit();
                $message = 'Metode pembayaran berhasil dihapus.';
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('BAJAMA delete payment method error: ' . $e->getMessage());
                $error = 'Gagal menghapus metode pembayaran.';
            }
        }
    }
}

$editingMethodId = (int)($_GET['edit_id'] ?? 0);
$editingMethod = null;
if ($editingMethodId > 0) {
    $editingStmt = $db->prepare('SELECT * FROM payment_methods WHERE id = ? AND organization_id IS NULL LIMIT 1');
    $editingStmt->execute([$editingMethodId]);
    $editingMethod = $editingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$paymentMethods = $db->query('SELECT * FROM payment_methods WHERE organization_id IS NULL ORDER BY type, name')->fetchAll(PDO::FETCH_ASSOC);
$pageTitle = 'Metode Pembayaran';
ob_start();
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Metode Pembayaran</h1>
            <p class="text-muted mb-0">Atur bank, e-wallet, dan payment gateway yang tersedia untuk pelanggan lisensi.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-primary" href="dashboard.php">Kembali ke Dashboard</a>
            <a class="btn btn-outline-dark" href="registrations.php">Pendaftaran</a>
        </div>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white"><?= $editingMethod ? 'Edit Metode Pembayaran' : 'Tambah Metode Pembayaran' ?></div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_payment_method">
                <input type="hidden" name="id" value="<?= (int)($editingMethod['id'] ?? 0) ?>">

                <div class="col-md-3">
                    <label class="form-label">Nama metode</label>
                    <input type="text" name="name" class="form-control" value="<?= h((string)($editingMethod['name'] ?? '')) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Tipe</label>
                    <select name="type" class="form-select">
                        <option value="BANK" <?= (($editingMethod['type'] ?? 'BANK') === 'BANK') ? 'selected' : '' ?>>BANK</option>
                        <option value="EWALLET" <?= (($editingMethod['type'] ?? 'BANK') === 'EWALLET') ? 'selected' : '' ?>>EWALLET</option>
                        <option value="GATEWAY" <?= (($editingMethod['type'] ?? 'BANK') === 'GATEWAY') ? 'selected' : '' ?>>GATEWAY</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Provider</label>
                    <input type="text" name="provider_name" class="form-control" value="<?= h((string)($editingMethod['provider_name'] ?? '')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label" id="account-name-label">Nama akun</label>
                    <input type="text" name="account_name" id="account_name_input" class="form-control" value="<?= h((string)($editingMethod['account_name'] ?? '')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label" id="account-number-label">Nomor rekening</label>
                    <input type="text" name="account_number" id="account_number_input" class="form-control" value="<?= h((string)($editingMethod['account_number'] ?? '')) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Instruksi</label>
                    <textarea name="instructions" rows="3" class="form-control"><?= h((string)($editingMethod['instructions'] ?? '')) ?></textarea>
                </div>
                <div class="col-12 d-flex align-items-center gap-3 flex-wrap">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="active" <?= ((int)($editingMethod['active'] ?? 1) === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label">Aktif</label>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= $editingMethod ? 'Update' : 'Simpan' ?></button>
                    <?php if ($editingMethod): ?><a href="payment_methods.php" class="btn btn-outline-secondary">Batal</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">Daftar metode pembayaran</div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Tipe</th>
                        <th>Provider</th>
                        <th>Nomor / Akun</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($paymentMethods as $method): ?>
                        <tr>
                            <td><?= h((string)($method['name'] ?? '-')) ?></td>
                            <td><?= h((string)($method['type'] ?? '-')) ?></td>
                            <td><?= h((string)($method['provider_name'] ?? '-')) ?></td>
                            <td><?= h((string)($method['account_number'] ?? $method['account_name'] ?? '-')) ?></td>
                            <td><?= (int)$method['active'] === 1 ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Nonaktif</span>' ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a class="btn btn-outline-primary" href="payment_methods.php?edit_id=<?= (int)$method['id'] ?>">Edit</a>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="toggle_payment_method">
                                        <input type="hidden" name="method_id" value="<?= (int)$method['id'] ?>">
                                        <input type="hidden" name="active" value="<?= (int)$method['active'] === 1 ? 0 : 1 ?>">
                                        <button type="submit" class="btn btn-outline-dark"><?= (int)$method['active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                                    </form>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Hapus metode pembayaran ini?');">
                                        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_payment_method">
                                        <input type="hidden" name="method_id" value="<?= (int)$method['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger">Hapus</button>
                                    </form>
                                </div>
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
