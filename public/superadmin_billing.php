<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

\BAJAMA\Core\Auth::requireLogin();

$db = db();
$roles = \BAJAMA\Core\RBAC::roles($db);
if (!in_array('SUPER_ADMIN', $roles, true)) {
    http_response_code(403);
    exit('Halaman ini hanya dapat diakses oleh superadmin.');
}

function sa_billing_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function sa_billing_rupiah(mixed $value): string
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

$stats = [
    'invoices' => 0,
    'unpaid' => 0,
    'outstanding' => 0,
    'paid' => 0,
    'payments' => 0,
    'pending_payments' => 0,
];
$invoices = [];
$payments = [];
$error = '';
$message = '';
$filterOrgId = (int)($_GET['organization_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf((string)($_POST['_csrf'] ?? ''));
        $action = (string)($_POST['action'] ?? '');
        $targetId = (int)($_POST['target_id'] ?? 0);

        if ($action === 'cancel_invoice' && $targetId > 0) {
            $stmt = $db->prepare('SELECT id, organization_id, status FROM invoices WHERE id = ? LIMIT 1');
            $stmt->execute([$targetId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice || in_array($invoice['status'], ['PAID', 'CANCELLED'], true)) {
                throw new RuntimeException('Invoice tidak ditemukan atau tidak dapat dibatalkan.');
            }
            $db->prepare('UPDATE invoices SET status = "CANCELLED" WHERE id = ? AND status NOT IN ("PAID", "CANCELLED")')->execute([$targetId]);
            \BAJAMA\Core\Audit::log($db, 'INVOICE_CANCELLED_PLATFORM', 'billing', 'invoice', $targetId, ['organization_id' => (int)$invoice['organization_id']], (int)$invoice['organization_id']);
            $message = 'Invoice berhasil dibatalkan dan tercatat di audit platform.';
        }

        if ($action === 'void_payment' && $targetId > 0) {
            $db->beginTransaction();
            $stmt = $db->prepare('SELECT id, organization_id, invoice_id, amount, status FROM payments WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$targetId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$payment || $payment['status'] !== 'SUCCESS') {
                throw new RuntimeException('Pembayaran tidak ditemukan atau sudah tidak dapat di-void.');
            }
            $db->prepare('UPDATE payments SET status = "REFUNDED" WHERE id = ?')->execute([$targetId]);
            $statusStmt = $db->prepare(
                'UPDATE invoices i SET status = CASE
                    WHEN COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id AND p.status = "SUCCESS"), 0) >= i.total THEN "PAID"
                    WHEN COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id AND p.status = "SUCCESS"), 0) > 0 THEN "PARTIAL"
                    WHEN i.due_date < CURDATE() THEN "OVERDUE"
                    ELSE "UNPAID" END
                 WHERE i.id = ?'
            );
            $statusStmt->execute([(int)$payment['invoice_id']]);
            \BAJAMA\Core\Audit::log($db, 'PAYMENT_VOIDED_PLATFORM', 'billing', 'payment', $targetId, ['organization_id' => (int)$payment['organization_id'], 'invoice_id' => (int)$payment['invoice_id'], 'amount' => (float)$payment['amount']], (int)$payment['organization_id']);
            $db->commit();
            $message = 'Pembayaran berhasil di-void dan invoice dihitung ulang.';
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('BAJAMA superadmin billing action error: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}

$organizations = $db->query('SELECT id, name FROM organizations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$invoiceScope = $filterOrgId > 0 ? ' WHERE i.organization_id = ?' : '';
$paymentScope = $filterOrgId > 0 ? ' WHERE p.organization_id = ?' : '';
$scopeParams = $filterOrgId > 0 ? [$filterOrgId] : [];

try {
    $statsStmt = $db->prepare(
        "SELECT
            COUNT(*) AS invoices,
            COALESCE(SUM(CASE WHEN i.status IN ('UNPAID','PARTIAL','OVERDUE') THEN 1 ELSE 0 END), 0) AS unpaid,
            COALESCE(SUM(CASE WHEN i.status IN ('UNPAID','PARTIAL','OVERDUE') THEN i.total ELSE 0 END), 0) AS outstanding,
            COALESCE(SUM(CASE WHEN i.status = 'PAID' THEN i.total ELSE 0 END), 0) AS paid
            FROM invoices i{$invoiceScope}"
    );
        $statsStmt->execute($scopeParams);
        $stats = array_merge($stats, $statsStmt->fetch(PDO::FETCH_ASSOC) ?: []);

    $paymentStatsStmt = $db->prepare(
        "SELECT
            COUNT(*) AS payments,
            COALESCE(SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END), 0) AS pending_payments
            FROM payments p{$paymentScope}"
        );
        $paymentStatsStmt->execute($scopeParams);
        $paymentStats = $paymentStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats = array_merge($stats, $paymentStats);

    $invoicesStmt = $db->prepare(
        "SELECT
            i.id,
            i.invoice_number,
            i.issue_date,
            i.due_date,
            i.total,
            i.status,
            o.name AS organization_name,
            c.name AS customer_name,
            c.customer_code,
            COALESCE(SUM(CASE WHEN p.status = 'SUCCESS' THEN p.amount ELSE 0 END), 0) AS paid_amount
         FROM invoices i
         INNER JOIN organizations o ON o.id = i.organization_id
         INNER JOIN customers c ON c.id = i.customer_id AND c.organization_id = i.organization_id
         LEFT JOIN payments p ON p.invoice_id = i.id AND p.organization_id = i.organization_id
         {$invoiceScope}
         GROUP BY i.id, i.invoice_number, i.issue_date, i.due_date, i.total, i.status,
                  o.name, c.name, c.customer_code
         ORDER BY i.id DESC
         LIMIT 100"
    );
    $invoicesStmt->execute($scopeParams);
    $invoices = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);

    $paymentsStmt = $db->prepare(
        "SELECT
            p.id,
            p.amount,
            p.payment_method,
            p.reference_number,
            p.paid_at,
            p.status,
            p.invoice_id,
            i.invoice_number,
            o.name AS organization_name,
            c.name AS customer_name
            FROM payments p
         INNER JOIN invoices i ON i.id = p.invoice_id AND i.organization_id = p.organization_id
         INNER JOIN organizations o ON o.id = p.organization_id
         INNER JOIN customers c ON c.id = i.customer_id AND c.organization_id = i.organization_id
            {$paymentScope}
         ORDER BY COALESCE(p.paid_at, p.created_at) DESC, p.id DESC
         LIMIT 100"
    );
    $paymentsStmt->execute($scopeParams);
    $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('BAJAMA superadmin billing error: ' . $e->getMessage());
    $error = 'Data billing global belum dapat dimuat. Pastikan schema invoice dan payment sudah diterapkan.';
}

$pageTitle = 'Superadmin Billing';
ob_start();
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <div class="text-primary fw-semibold small">PLATFORM BILLING</div>
            <h1 class="fw-bold mb-1">Invoice & Pembayaran ISP</h1>
            <p class="text-muted mb-0">Monitoring lintas organisasi tanpa mengubah batas data tenant.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="superadmin.php" class="btn btn-outline-secondary">Superadmin</a>
            <a href="dashboard.php" class="btn btn-primary">Dashboard</a>
        </div>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= sa_billing_h($error) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="alert alert-success"><?= sa_billing_h($message) ?></div><?php endif; ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Filter organisasi ISP</label>
                    <select name="organization_id" class="form-select">
                        <option value="0">Semua organisasi</option>
                        <?php foreach ($organizations as $organization): ?>
                            <option value="<?= (int)$organization['id'] ?>" <?= $filterOrgId === (int)$organization['id'] ? 'selected' : '' ?>><?= sa_billing_h($organization['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto"><button class="btn btn-primary">Terapkan</button></div>
                <div class="col-auto"><a href="superadmin_billing.php" class="btn btn-outline-secondary">Reset</a></div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xl-2 col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Total invoice</div><div class="fs-3 fw-bold"><?= number_format((int)$stats['invoices'], 0, ',', '.') ?></div></div></div></div>
        <div class="col-xl-2 col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Belum lunas</div><div class="fs-3 fw-bold text-warning"><?= number_format((int)$stats['unpaid'], 0, ',', '.') ?></div></div></div></div>
        <div class="col-xl-3 col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Outstanding</div><div class="fs-3 fw-bold text-danger"><?= sa_billing_rupiah($stats['outstanding']) ?></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Invoice lunas</div><div class="fs-3 fw-bold text-success"><?= sa_billing_rupiah($stats['paid']) ?></div></div></div></div>
        <div class="col-xl-2 col-md-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Transaksi</div><div class="fs-3 fw-bold"><?= number_format((int)$stats['payments'], 0, ',', '.') ?></div><small class="text-muted">Pending: <?= (int)$stats['pending_payments'] ?></small></div></div></div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <span>Invoice terbaru lintas ISP</span><span class="badge bg-light text-dark">100 terbaru</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Invoice</th><th>Organisasi</th><th>Pelanggan</th><th>Terbit / Jatuh tempo</th><th class="text-end">Total</th><th class="text-end">Terbayar</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php if (!$invoices): ?><tr><td colspan="8" class="text-center text-muted py-4">Belum ada invoice.</td></tr><?php endif; ?>
                <?php foreach ($invoices as $invoice): ?>
                    <?php $statusClass = ['PAID' => 'success', 'PARTIAL' => 'warning', 'OVERDUE' => 'danger', 'CANCELLED' => 'secondary'][$invoice['status']] ?? 'dark'; ?>
                    <tr>
                        <td><strong><?= sa_billing_h($invoice['invoice_number']) ?></strong><div class="small text-muted">ID #<?= (int)$invoice['id'] ?></div></td>
                        <td><?= sa_billing_h($invoice['organization_name']) ?></td>
                        <td><?= sa_billing_h($invoice['customer_name']) ?><div class="small text-muted"><?= sa_billing_h($invoice['customer_code']) ?></div></td>
                        <td><div><?= sa_billing_h($invoice['issue_date']) ?></div><small class="text-muted">Due: <?= sa_billing_h($invoice['due_date']) ?></small></td>
                        <td class="text-end"><?= sa_billing_rupiah($invoice['total']) ?></td>
                        <td class="text-end text-success"><?= sa_billing_rupiah($invoice['paid_amount']) ?></td>
                        <td><span class="badge text-bg-<?= $statusClass ?>"><?= sa_billing_h($invoice['status']) ?></span></td>
                        <td>
                            <?php if (!in_array($invoice['status'], ['PAID', 'CANCELLED'], true)): ?>
                                <form method="post" onsubmit="return confirm('Batalkan invoice ini?');">
                                    <input type="hidden" name="_csrf" value="<?= sa_billing_h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="cancel_invoice">
                                    <input type="hidden" name="target_id" value="<?= (int)$invoice['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">Cancel</button>
                                </form>
                            <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center"><span class="fw-semibold">Transaksi pembayaran terbaru</span><span class="text-muted small">Pending: <?= (int)$stats['pending_payments'] ?></span></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Tanggal</th><th>Invoice</th><th>Organisasi</th><th>Pelanggan</th><th>Metode / Referensi</th><th class="text-end">Nominal</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php if (!$payments): ?><tr><td colspan="8" class="text-center text-muted py-4">Belum ada transaksi pembayaran.</td></tr><?php endif; ?>
                <?php foreach ($payments as $payment): ?>
                    <?php $paymentClass = ['SUCCESS' => 'success', 'PENDING' => 'warning', 'FAILED' => 'danger', 'REFUNDED' => 'secondary'][$payment['status']] ?? 'dark'; ?>
                    <tr>
                        <td><?= sa_billing_h($payment['paid_at'] ?: '-') ?></td>
                        <td><strong><?= sa_billing_h($payment['invoice_number']) ?></strong><div class="small text-muted">ID #<?= (int)$payment['invoice_id'] ?></div></td>
                        <td><?= sa_billing_h($payment['organization_name']) ?></td>
                        <td><?= sa_billing_h($payment['customer_name']) ?></td>
                        <td><?= sa_billing_h($payment['payment_method'] ?: '-') ?><div class="small text-muted"><?= sa_billing_h($payment['reference_number'] ?: '-') ?></div></td>
                        <td class="text-end fw-semibold"><?= sa_billing_rupiah($payment['amount']) ?></td>
                        <td><span class="badge text-bg-<?= $paymentClass ?>"><?= sa_billing_h($payment['status']) ?></span></td>
                        <td>
                            <?php if ($payment['status'] === 'SUCCESS'): ?>
                                <form method="post" onsubmit="return confirm('Void pembayaran ini dan hitung ulang invoice?');">
                                    <input type="hidden" name="_csrf" value="<?= sa_billing_h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="void_payment">
                                    <input type="hidden" name="target_id" value="<?= (int)$payment['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">Void</button>
                                </form>
                            <?php else: ?><span class="text-muted">-</span><?php endif; ?>
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
