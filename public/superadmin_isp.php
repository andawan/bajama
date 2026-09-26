<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

\BAJAMA\Core\Auth::requireLogin();
$db = db();
if (!in_array('SUPER_ADMIN', \BAJAMA\Core\RBAC::roles($db), true)) {
    http_response_code(403);
    exit('Halaman ini hanya dapat diakses oleh superadmin.');
}

function sa_isp_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf((string)($_POST['_csrf'] ?? ''));
        $action = (string)($_POST['action'] ?? '');
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $organizationId = (int)($_POST['organization_id'] ?? 0);

        if ($action === 'save_customer') {
            $name = trim((string)($_POST['name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $status = strtoupper((string)($_POST['status'] ?? 'ACTIVE'));
            if ($organizationId <= 0 || $name === '' || !in_array($status, ['ACTIVE', 'INACTIVE', 'BLOCKED'], true)) {
                throw new RuntimeException('Organisasi, nama, dan status customer wajib valid.');
            }
            $orgCheck = $db->prepare('SELECT id FROM organizations WHERE id = ? LIMIT 1');
            $orgCheck->execute([$organizationId]);
            if (!$orgCheck->fetchColumn()) {
                throw new RuntimeException('Organisasi tidak ditemukan.');
            }

            if ($customerId > 0) {
                $stmt = $db->prepare('UPDATE customers SET name = ?, email = ?, phone = ?, address = ?, status = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?');
                $stmt->execute([$name, $email ?: null, $phone ?: null, $address ?: null, $status, $customerId, $organizationId]);
                if ($stmt->rowCount() < 1) {
                    throw new RuntimeException('Customer tidak ditemukan atau tidak ada perubahan.');
                }
                \BAJAMA\Core\Audit::log($db, 'CUSTOMER_UPDATED_PLATFORM', 'billing', 'customer', $customerId, ['organization_id' => $organizationId, 'status' => $status], $organizationId);
                $message = 'Customer berhasil diperbarui.';
            } else {
                $code = 'CUS-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $stmt = $db->prepare('INSERT INTO customers (organization_id, customer_code, name, email, phone, address, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$organizationId, $code, $name, $email ?: null, $phone ?: null, $address ?: null, $status]);
                $customerId = (int)$db->lastInsertId();
                \BAJAMA\Core\Audit::log($db, 'CUSTOMER_CREATED_PLATFORM', 'billing', 'customer', $customerId, ['organization_id' => $organizationId, 'customer_code' => $code], $organizationId);
                $message = 'Customer ISP berhasil dibuat.';
            }
        }

        if ($action === 'subscription_status' && $customerId > 0) {
            $status = strtoupper((string)($_POST['status'] ?? 'SUSPENDED'));
            if (!in_array($status, ['ACTIVE', 'GRACE_PERIOD', 'SUSPENDED', 'TERMINATED'], true)) {
                throw new RuntimeException('Status subscription tidak valid.');
            }
            $orgStmt = $db->prepare('SELECT organization_id FROM subscriptions WHERE id = ? LIMIT 1');
            $orgStmt->execute([$customerId]);
            $subscriptionOrganizationId = (int)$orgStmt->fetchColumn();
            if ($subscriptionOrganizationId <= 0) {
                throw new RuntimeException('Subscription tidak ditemukan.');
            }
            $stmt = $db->prepare('UPDATE subscriptions SET status = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$status, $customerId]);
            \BAJAMA\Core\Audit::log($db, 'SUBSCRIPTION_STATUS_UPDATED_PLATFORM', 'billing', 'subscription', $customerId, ['organization_id' => $subscriptionOrganizationId, 'status' => $status], $subscriptionOrganizationId);
            $message = 'Status subscription berhasil diperbarui.';
        }
    } catch (Throwable $e) {
        error_log('BAJAMA superadmin ISP action error: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}

$organizations = $db->query('SELECT id, name, status FROM organizations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$editId = (int)($_GET['edit_id'] ?? 0);
$editCustomer = null;
if ($editId > 0) {
    $stmt = $db->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $editCustomer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$customers = $db->query(
    "SELECT c.*, o.name AS organization_name,
            (SELECT COUNT(*) FROM subscriptions s WHERE s.customer_id = c.id AND s.organization_id = c.organization_id) AS subscription_count,
            (SELECT COUNT(*) FROM invoices i WHERE i.customer_id = c.id AND i.organization_id = c.organization_id) AS invoice_count
     FROM customers c
     INNER JOIN organizations o ON o.id = c.organization_id
     ORDER BY c.id DESC
     LIMIT 300"
)->fetchAll(PDO::FETCH_ASSOC);

$subscriptions = $db->query(
    "SELECT s.id, s.service_name, s.status, s.next_due_date, c.name AS customer_name, c.customer_code, o.name AS organization_name
     FROM subscriptions s
     INNER JOIN customers c ON c.id = s.customer_id AND c.organization_id = s.organization_id
     INNER JOIN organizations o ON o.id = s.organization_id
     ORDER BY s.id DESC
     LIMIT 300"
)->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Superadmin ISP Control';
ob_start();
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div><div class="text-primary fw-semibold small">PLATFORM ISP CONTROL</div><h1 class="fw-bold mb-1">Pelanggan & Subscription ISP</h1><p class="text-muted mb-0">Kontrol lintas organisasi untuk data customer dan status layanan.</p></div>
        <div class="d-flex gap-2"><a href="superadmin.php" class="btn btn-outline-secondary">Superadmin</a><a href="isp_payment_methods.php" class="btn btn-outline-primary">Payment Methods</a><a href="superadmin_billing.php" class="btn btn-primary">Billing Global</a></div>
    </div>
    <?php if ($message): ?><div class="alert alert-success"><?= sa_isp_h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= sa_isp_h($error) ?></div><?php endif; ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white"><?= $editCustomer ? 'Edit Customer ISP' : 'Tambah Customer ISP' ?></div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="_csrf" value="<?= sa_isp_h(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_customer">
                <input type="hidden" name="customer_id" value="<?= (int)($editCustomer['id'] ?? 0) ?>">
                <div class="col-md-4"><label class="form-label">Organisasi ISP</label><select name="organization_id" class="form-select" required><?php foreach ($organizations as $organization): ?><option value="<?= (int)$organization['id'] ?>" <?= ((int)($editCustomer['organization_id'] ?? 0) === (int)$organization['id']) ? 'selected' : '' ?>><?= sa_isp_h($organization['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label">Nama customer</label><input name="name" class="form-control" value="<?= sa_isp_h($editCustomer['name'] ?? '') ?>" required></div>
                <div class="col-md-4"><label class="form-label">Status</label><select name="status" class="form-select"><?php foreach (['ACTIVE', 'INACTIVE', 'BLOCKED'] as $status): ?><option value="<?= $status ?>" <?= (($editCustomer['status'] ?? 'ACTIVE') === $status) ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= sa_isp_h($editCustomer['email'] ?? '') ?>"></div>
                <div class="col-md-4"><label class="form-label">Telepon</label><input name="phone" class="form-control" value="<?= sa_isp_h($editCustomer['phone'] ?? '') ?>"></div>
                <div class="col-md-12"><label class="form-label">Alamat</label><textarea name="address" class="form-control" rows="2"><?= sa_isp_h($editCustomer['address'] ?? '') ?></textarea></div>
                <div class="col-12"><button class="btn btn-primary">Simpan Customer</button><?php if ($editCustomer): ?><a href="superadmin_isp.php" class="btn btn-outline-secondary ms-2">Batal</a><?php endif; ?></div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">Customer ISP lintas organisasi</div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Customer</th><th>Organisasi</th><th>Status</th><th>Subscription</th><th>Invoice</th><th>Aksi</th></tr></thead><tbody>
        <?php if (!$customers): ?><tr><td colspan="6" class="text-center text-muted py-4">Belum ada customer.</td></tr><?php endif; ?>
        <?php foreach ($customers as $customer): ?><tr><td><strong><?= sa_isp_h($customer['name']) ?></strong><div class="small text-muted"><?= sa_isp_h($customer['customer_code']) ?></div></td><td><?= sa_isp_h($customer['organization_name']) ?></td><td><span class="badge text-bg-<?= $customer['status'] === 'ACTIVE' ? 'success' : ($customer['status'] === 'BLOCKED' ? 'danger' : 'secondary') ?>"><?= sa_isp_h($customer['status']) ?></span></td><td><?= (int)$customer['subscription_count'] ?></td><td><?= (int)$customer['invoice_count'] ?></td><td><a class="btn btn-sm btn-outline-primary" href="superadmin_isp.php?edit_id=<?= (int)$customer['id'] ?>">Edit</a></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div>

    <div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold">Subscription ISP terbaru</div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Customer</th><th>Organisasi</th><th>Layanan</th><th>Jatuh tempo</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
    <?php if (!$subscriptions): ?><tr><td colspan="6" class="text-center text-muted py-4">Belum ada subscription.</td></tr><?php endif; ?>
    <?php foreach ($subscriptions as $subscription): ?><tr><td><?= sa_isp_h($subscription['customer_name']) ?><div class="small text-muted"><?= sa_isp_h($subscription['customer_code']) ?></div></td><td><?= sa_isp_h($subscription['organization_name']) ?></td><td><?= sa_isp_h($subscription['service_name']) ?></td><td><?= sa_isp_h($subscription['next_due_date'] ?: '-') ?></td><td><span class="badge text-bg-<?= $subscription['status'] === 'ACTIVE' ? 'success' : 'secondary' ?>"><?= sa_isp_h($subscription['status']) ?></span></td><td><form method="post" class="d-flex gap-1"><input type="hidden" name="_csrf" value="<?= sa_isp_h(csrf_token()) ?>"><input type="hidden" name="action" value="subscription_status"><input type="hidden" name="customer_id" value="<?= (int)$subscription['id'] ?>"><select name="status" class="form-select form-select-sm"><?php foreach (['ACTIVE', 'GRACE_PERIOD', 'SUSPENDED', 'TERMINATED'] as $status): ?><option value="<?= $status ?>" <?= $subscription['status'] === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-outline-primary">Simpan</button></form></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../app/layout/layout.php';
