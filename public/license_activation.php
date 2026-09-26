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

    if ($action === 'activate_license') {
        $licenseId = (int)($_POST['license_id'] ?? 0);
        if ($licenseId > 0) {
            $db->prepare('UPDATE licenses SET status = "ACTIVE", updated_at = NOW() WHERE id = ?')->execute([$licenseId]);
            $message = 'License diaktifkan.';
        }
    }

    if ($action === 'suspend_license') {
        $licenseId = (int)($_POST['license_id'] ?? 0);
        if ($licenseId > 0) {
            $db->prepare('UPDATE licenses SET status = "SUSPENDED", updated_at = NOW() WHERE id = ?')->execute([$licenseId]);
            $message = 'License dijeda.';
        }
    }
}

$licenses = $db->query('SELECT l.*, o.name AS organization_name, p.name AS plan_name FROM licenses l LEFT JOIN organizations o ON o.id = l.organization_id LEFT JOIN license_plans p ON p.id = l.plan_id ORDER BY l.id DESC')->fetchAll(PDO::FETCH_ASSOC);
$pageTitle = 'Aktivasi License';
ob_start();
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Aktivasi License</h1>
            <p class="text-muted mb-0">Aktifkan, suspend, atau pantau status lisensi pelanggan.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-primary" href="dashboard.php">Kembali ke Dashboard</a>
            <a class="btn btn-outline-dark" href="licenses.php">Manajemen License</a>
        </div>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-dark text-white">Status lisensi pelanggan</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Organisasi</th>
                        <th>Plan</th>
                        <th>License Key</th>
                        <th>Status</th>
                        <th>Expired</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($licenses as $license): ?>
                        <tr>
                            <td><?= h((string)($license['organization_name'] ?? '-')) ?></td>
                            <td><?= h((string)($license['plan_name'] ?? '-')) ?></td>
                            <td><code><?= h((string)($license['license_key'] ?? '-')) ?></code></td>
                            <td><span class="badge bg-light text-dark"><?= h((string)($license['status'] ?? 'TRIAL')) ?></span></td>
                            <td><?= h((string)($license['expires_at'] ?? '-')) ?></td>
                            <td class="text-end">
                                <?php if (($license['status'] ?? 'TRIAL') === 'ACTIVE'): ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="suspend_license">
                                        <input type="hidden" name="license_id" value="<?= (int)$license['id'] ?>">
                                        <button type="submit" class="btn btn-outline-warning btn-sm">Suspend</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="activate_license">
                                        <input type="hidden" name="license_id" value="<?= (int)$license['id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm">Activate</button>
                                    </form>
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
