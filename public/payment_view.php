<?php

require_once __DIR__ . '/../app/bootstrap.php';

/* BAJAMA_RBAC_ENFORCED */
\BAJAMA\Core\Auth::requireLogin();
\BAJAMA\Core\License::requireFeature(\db(), 'billing');
\BAJAMA\Core\RBAC::require(\db(), 'billing.view');


if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['user_id']) || empty($_SESSION['organization_id'])) {
    header('Location: login.php');
    exit;
}

$orgId = (int) $_SESSION['organization_id'];

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rupiahPaymentView($amount)
{
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}

$paymentId = (int) ($_GET['id'] ?? 0);

if ($paymentId <= 0) {
    header('Location: payments.php');
    exit;
}

require_once __DIR__ . '/../config/config.php';

$pdo = $GLOBALS['pdo'] ?? null;

if (!$pdo instanceof PDO) {
    $envFile = __DIR__ . '/../.env';
    $env = [];

    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            $parts = explode('=', $line, 2);

            if (count($parts) === 2) {
                $env[trim($parts[0])] = trim($parts[1], "\"'");
            }
        }
    }

    $pdo = new PDO(
        'mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1') .
        ';port=' . ($env['DB_PORT'] ?? '3306') .
        ';dbname=' . ($env['DB_DATABASE'] ?? 'bajama') .
        ';charset=utf8mb4',
        $env['DB_USERNAME'] ?? 'bajama',
        $env['DB_PASSWORD'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
}

$stmt = $pdo->prepare("
    SELECT
        p.*,

        i.invoice_number,
        i.total AS invoice_total,
        i.status AS invoice_status,
        i.issue_date,
        i.due_date,
          i.router_id,
          r.name AS router_name,

        c.name AS customer_name,
        c.customer_code,
        c.email AS customer_email,
        c.phone AS customer_phone,
        c.address AS customer_address,

        o.name AS organization_name,
        o.email AS organization_email,
        o.phone AS organization_phone,
        o.logo AS organization_logo

    FROM payments p

    INNER JOIN invoices i
        ON i.id = p.invoice_id
       AND i.organization_id = p.organization_id

    INNER JOIN customers c
        ON c.id = i.customer_id
       AND c.organization_id = i.organization_id

      LEFT JOIN mikrotik_routers r
          ON r.id = i.router_id
         AND r.organization_id = i.organization_id

    INNER JOIN organizations o
        ON o.id = p.organization_id

    WHERE p.id = ?
      AND p.organization_id = ?

    LIMIT 1
");

$stmt->execute([
    $paymentId,
    $orgId
]);

$payment = $stmt->fetch();

if (!$payment) {
    http_response_code(404);
    exit('Pembayaran tidak ditemukan.');
}

$paidDate = $payment['paid_at'] ?: $payment['created_at'];

$pageTitle = 'Detail Pembayaran';

ob_start();
?>

<div class="container-fluid py-3">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">

        <div>
            <div class="text-primary fw-semibold small">
                BAJAMA BILLING
            </div>

            <h1 class="h3 mb-1">
                Detail Pembayaran
            </h1>

            <div class="text-muted">
                Informasi transaksi pembayaran.
            </div>

              <div class="small text-muted mt-1">
                  Router: <?= h($payment['router_name'] ?: 'Router belum dipetakan') ?>
                  &middot;
                  <?= h($payment['payment_channel'] ?: $payment['payment_method']) ?>
                  <?php if (!empty($payment['provider_name'])): ?>
                      &middot; <?= h($payment['provider_name']) ?>
                  <?php endif; ?>
              </div>
        </div>

        <div class="d-flex gap-2">

            <a
                href="payments.php"
                class="btn btn-outline-secondary"
            >
                <i class="bi bi-arrow-left me-1"></i>
                Kembali
            </a>

            <a
                href="payment_receipt.php?id=<?= (int) $payment['id'] ?>"
                target="_blank"
                class="btn btn-primary"
            >
                <i class="bi bi-printer me-1"></i>
                Cetak Kuitansi
            </a>

        </div>

    </div>

    <div class="row g-4">

        <div class="col-lg-8">

            <div class="card border-0 shadow-sm">

                <div class="card-header bg-white py-3">

                    <div class="d-flex justify-content-between align-items-center">

                        <strong>
                            <i class="bi bi-receipt-cutoff me-2"></i>
                            Informasi Transaksi
                        </strong>

                        <?php if ($payment['status'] === 'SUCCESS'): ?>

                            <span class="badge bg-success">
                                <i class="bi bi-check-circle me-1"></i>
                                SUCCESS
                            </span>

                        <?php elseif ($payment['status'] === 'PENDING'): ?>

                            <span class="badge bg-warning text-dark">
                                PENDING
                            </span>

                        <?php elseif ($payment['status'] === 'REFUNDED'): ?>

                            <span class="badge bg-dark">
                                REFUNDED
                            </span>

                        <?php else: ?>

                            <span class="badge bg-danger">
                                <?= h($payment['status']) ?>
                            </span>

                        <?php endif; ?>

                    </div>

                </div>

                <div class="card-body">

                    <div class="row g-4">

                        <div class="col-md-6">

                            <small class="text-muted">
                                Payment ID
                            </small>

                            <div class="fw-bold">
                                #<?= (int) $payment['id'] ?>
                            </div>

                        </div>

                        <div class="col-md-6">

                            <small class="text-muted">
                                Referensi
                            </small>

                            <div class="fw-bold font-monospace">
                                <?= h($payment['reference_number'] ?: '-') ?>
                            </div>

                        </div>

                        <div class="col-md-6">

                            <small class="text-muted">
                                Invoice
                            </small>

                            <div>
                                <a
                                    href="invoice_view.php?invoice=<?= urlencode($payment['invoice_number']) ?>"
                                    class="fw-bold text-decoration-none"
                                >
                                    <?= h($payment['invoice_number']) ?>
                                </a>
                            </div>

                        </div>

                        <div class="col-md-6">

                            <small class="text-muted">
                                Metode Pembayaran
                            </small>

                            <div class="fw-semibold">
                                <?= h($payment['payment_method']) ?>
                            </div>

                        </div>

                        <div class="col-md-6">

                            <small class="text-muted">
                                Tanggal Pembayaran
                            </small>

                            <div class="fw-semibold">
                                <?= h(date('d/m/Y H:i:s', strtotime($paidDate))) ?>
                            </div>

                        </div>

                        <div class="col-md-6">

                            <small class="text-muted">
                                Status Invoice
                            </small>

                            <div class="fw-semibold">
                                <?= h($payment['invoice_status']) ?>
                            </div>

                        </div>

                    </div>

                    <hr class="my-4">

                    <div class="p-4 bg-light rounded-3 text-center">

                        <div class="text-muted mb-1">
                            Nominal Pembayaran
                        </div>

                        <div class="display-6 fw-bold text-success">
                            <?= rupiahPaymentView($payment['amount']) ?>
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-lg-4">

            <div class="card border-0 shadow-sm mb-4">

                <div class="card-header bg-white py-3">
                    <strong>
                        <i class="bi bi-person-circle me-2"></i>
                        Customer
                    </strong>
                </div>

                <div class="card-body">

                    <div class="fw-bold fs-5">
                        <?= h($payment['customer_name']) ?>
                    </div>

                    <?php if (!empty($payment['customer_code'])): ?>

                        <div class="text-muted">
                            <?= h($payment['customer_code']) ?>
                        </div>

                    <?php endif; ?>

                    <?php if (!empty($payment['customer_phone'])): ?>

                        <div class="mt-3">
                            <i class="bi bi-telephone me-2"></i>
                            <?= h($payment['customer_phone']) ?>
                        </div>

                    <?php endif; ?>

                    <?php if (!empty($payment['customer_email'])): ?>

                        <div class="mt-2">
                            <i class="bi bi-envelope me-2"></i>
                            <?= h($payment['customer_email']) ?>
                        </div>

                    <?php endif; ?>

                </div>

            </div>

            <div class="card border-0 shadow-sm">

                <div class="card-header bg-white py-3">
                    <strong>
                        <i class="bi bi-calculator me-2"></i>
                        Ringkasan Invoice
                    </strong>
                </div>

                <div class="card-body">

                    <div class="d-flex justify-content-between mb-2">
                        <span>Total Invoice</span>
                        <strong>
                            <?= rupiahPaymentView($payment['invoice_total']) ?>
                        </strong>
                    </div>

                    <div class="d-flex justify-content-between">
                        <span>Invoice Status</span>
                        <strong>
                            <?= h($payment['invoice_status']) ?>
                        </strong>
                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

<?php
$content = ob_get_clean();

require __DIR__ . '/../app/layout/layout.php';
