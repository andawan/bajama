<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

/* BAJAMA_RBAC_ENFORCED */
\BAJAMA\Core\Auth::requireLogin();
\BAJAMA\Core\License::requireFeature(\db(), 'billing');
\BAJAMA\Core\RBAC::require(\db(), 'billing.manage');


if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function rupiah($value): string
{
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function envLoadPaymentForm(string $file): array
{
    $env = [];

    if (!is_file($file)) {
        return $env;
    }

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);

        $key = trim($key);
        $value = trim($value);

        if (
            strlen($value) >= 2 &&
            (
                ($value[0] === '"' && substr($value, -1) === '"') ||
                ($value[0] === "'" && substr($value, -1) === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        $env[$key] = $value;
    }

    return $env;
}

function getPaymentPDO(): PDO
{
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    $env = envLoadPaymentForm(dirname(__DIR__) . '/.env');

    $host = $env['DB_HOST'] ?? '127.0.0.1';
    $port = $env['DB_PORT'] ?? '3306';
    $db   = $env['DB_DATABASE'] ?? 'bajama';
    $user = $env['DB_USERNAME'] ?? 'bajama';
    $pass = $env['DB_PASSWORD'] ?? '';

    $dsn = 'mysql:host=' . $host .
           ';port=' . $port .
           ';dbname=' . $db .
           ';charset=utf8mb4';

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

function paymentMethodLabel(string $method): string
{
    $labels = [
        'CASH'            => 'Tunai',
        'TRANSFER'        => 'Transfer Bank',
        'QRIS'            => 'QRIS',
        'E-WALLET'        => 'E-Wallet',
        'PAYMENT_GATEWAY' => 'Payment Gateway',
    ];

    return $labels[$method] ?? $method;
}

function parsePaymentAmount(string $value): float
{
    $value = trim($value);

    if ($value === '') {
        return 0;
    }

    /*
     * Form memakai format Rupiah seperti:
     * 160000
     * 160.000
     * Rp 160.000
     */
    $value = preg_replace('/[^\d]/', '', $value);

    if ($value === '' || !ctype_digit($value)) {
        return 0;
    }

    return (float)$value;
}

/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['user_id']) || empty($_SESSION['organization_id'])) {
    header('Location: login.php');
    exit;
}

$organizationId = (int)$_SESSION['organization_id'];
$userId         = (int)$_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

try {
    $pdo = getPaymentPDO();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database connection error.';
    exit;
}

$configuredPaymentMethods = [];
try {
    $configuredPaymentMethods = $pdo->query(
        'SELECT id, name, type, provider_name, account_name, account_number, instructions
         FROM payment_methods
                 WHERE active = 1
                     AND organization_id = ' . (int)$organizationId . '
         ORDER BY type, name'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('BAJAMA payment methods load error: ' . $e->getMessage());
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

$csrfToken = csrf_token();

/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/

$invoiceNumber = trim((string)($_GET['invoice'] ?? $_POST['invoice_number'] ?? ''));

$invoice = null;
$paymentTotal = 0.00;
$remaining = 0.00;

if ($invoiceNumber !== '') {
    $stmt = $pdo->prepare(
        "SELECT
            i.id,
            i.organization_id,
            i.customer_id,
            i.invoice_number,
            i.issue_date,
            i.due_date,
            i.subtotal,
            i.discount,
            i.total,
            i.status,
            i.period_start,
            i.period_end,
            i.description,

            c.customer_code,
            c.name AS customer_name,
            c.phone AS customer_phone,
            c.email AS customer_email

        FROM invoices i
        INNER JOIN customers c
            ON c.id = i.customer_id
           AND c.organization_id = i.organization_id

        WHERE i.organization_id = ?
          AND i.invoice_number = ?
        LIMIT 1"
    );

    $stmt->execute([
        $organizationId,
        $invoiceNumber
    ]);

    $invoice = $stmt->fetch();

    if ($invoice) {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0)
             FROM payments
             WHERE organization_id = ?
               AND invoice_id = ?
               AND status = 'SUCCESS'"
        );

        $stmt->execute([
            $organizationId,
            (int)$invoice['id']
        ]);

        $paymentTotal = (float)$stmt->fetchColumn();

        $remaining = max(
            0,
            (float)$invoice['total'] - $paymentTotal
        );
    }
}

/*
|--------------------------------------------------------------------------
| PROCESS PAYMENT
|--------------------------------------------------------------------------
*/

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {
        verify_csrf((string) ($_POST['csrf_token'] ?? $_POST['_csrf'] ?? ''));
    } catch (Throwable $csrfError) {
        $errors[] = 'Sesi keamanan tidak valid. Silakan muat ulang halaman.';
    }

    $invoiceNumber = trim((string)($_POST['invoice_number'] ?? ''));

    $amountInput = trim((string)($_POST['amount'] ?? ''));
    $amount       = parsePaymentAmount($amountInput);

    $paymentMethod = strtoupper(
        trim((string)($_POST['payment_method'] ?? 'TRANSFER'))
    );

    $paymentMethodId = (int)($_POST['payment_method_id'] ?? 0);

    $paymentChannel = strtoupper(
        trim((string)($_POST['payment_channel'] ?? 'MANUAL'))
    );
    $providerName = trim((string)($_POST['provider_name'] ?? ''));
    $providerTransactionId = trim(
        (string)($_POST['provider_transaction_id'] ?? '')
    );
    $bankName = trim((string)($_POST['bank_name'] ?? ''));
    $ewalletName = trim((string)($_POST['ewallet_name'] ?? ''));

    $referenceNumber = trim(
        (string)($_POST['reference_number'] ?? '')
    );

    $paidAtInput = trim(
        (string)($_POST['paid_at'] ?? '')
    );

    $legacyMethods = [
        'CASH',
        'TRANSFER',
        'QRIS',
        'E-WALLET',
        'PAYMENT_GATEWAY'
    ];

    if ($paymentMethodId > 0) {
        $methodStmt = $pdo->prepare(
            'SELECT id, name, type, provider_name, account_name, account_number
             FROM payment_methods
               WHERE id = ? AND organization_id = ? AND active = 1
             LIMIT 1'
        );
           $methodStmt->execute([$paymentMethodId, $organizationId]);
        $selectedMethod = $methodStmt->fetch(PDO::FETCH_ASSOC);

        if (!$selectedMethod) {
            $errors[] = 'Metode pembayaran tidak ditemukan atau sudah dinonaktifkan.';
        } else {
            $paymentMethod = trim((string)$selectedMethod['name']);
            $selectedMethodType = (string)$selectedMethod['type'];
            if ($selectedMethodType === 'BANK') {
                $paymentChannel = 'BANK';
            } elseif ($selectedMethodType === 'EWALLET') {
                $paymentChannel = 'E_WALLET';
            } elseif ($selectedMethodType === 'GATEWAY') {
                $paymentChannel = 'GATEWAY';
            } else {
                $paymentChannel = 'MANUAL';
            }
            $providerName = $providerName !== ''
                ? $providerName
                : trim((string)($selectedMethod['provider_name'] ?? ''));
            if ($paymentChannel === 'BANK' && $bankName === '') {
                $bankName = trim((string)($selectedMethod['provider_name'] ?? $selectedMethod['account_name'] ?? ''));
            }
            if ($paymentChannel === 'E_WALLET' && $ewalletName === '') {
                $ewalletName = trim((string)($selectedMethod['provider_name'] ?? $selectedMethod['account_name'] ?? ''));
            }
        }
    }

    $allowedMethods = $legacyMethods;
    if ($configuredPaymentMethods) {
        $allowedMethods = [];
        foreach ($configuredPaymentMethods as $method) {
            $allowedMethods[] = trim((string)$method['name']);
        }
    }

    if ($invoiceNumber === '') {
        $errors[] = 'Invoice wajib dipilih.';
    }

    if (!in_array($paymentMethod, $allowedMethods, true)) {
        $errors[] = 'Metode pembayaran tidak valid.';
    }

    if (!in_array($paymentChannel, ['MANUAL', 'BANK', 'E_WALLET', 'GATEWAY'], true)) {
        $errors[] = 'Channel pembayaran tidak valid.';
    }

    if ($amount <= 0) {
        $errors[] = 'Nominal pembayaran harus lebih besar dari Rp 0.';
    }

    /*
     * Validasi tanggal.
     */
    $paidAt = null;

    if ($paidAtInput !== '') {
        $timestamp = strtotime($paidAtInput);

        if ($timestamp === false) {
            $errors[] = 'Tanggal pembayaran tidak valid.';
        } else {
            $paidAt = date('Y-m-d H:i:s', $timestamp);

            if ($timestamp > time() + 300) {
                $errors[] = 'Tanggal pembayaran tidak boleh di masa depan.';
            }
        }
    } else {
        $paidAt = date('Y-m-d H:i:s');
    }

    /*
     * Ambil invoice terbaru sebelum transaksi.
     */
    $invoiceForPayment = null;

    if (!$errors) {
        $stmt = $pdo->prepare(
            "SELECT
                id,
                organization_id,
                customer_id,
                invoice_number,
                total,
                status
             FROM invoices
             WHERE organization_id = ?
               AND invoice_number = ?
             LIMIT 1"
        );

        $stmt->execute([
            $organizationId,
            $invoiceNumber
        ]);

        $invoiceForPayment = $stmt->fetch();

        if (!$invoiceForPayment) {
            $errors[] = 'Invoice tidak ditemukan.';
        } elseif (!in_array((string)$invoiceForPayment['status'], ['UNPAID', 'PARTIAL', 'OVERDUE'], true)) {
            $errors[] = 'Invoice berstatus ' . (string)$invoiceForPayment['status'] . ' tidak dapat menerima pembayaran.';
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            /*
             * Lock invoice untuk mencegah dua pembayaran
             * bersamaan mengambil sisa nominal yang sama.
             */
            $stmt = $pdo->prepare(
                "SELECT
                    id,
                    organization_id,
                    customer_id,
                    invoice_number,
                    total,
                    status
                 FROM invoices
                 WHERE organization_id = ?
                   AND invoice_number = ?
                 LIMIT 1
                 FOR UPDATE"
            );

            $stmt->execute([
                $organizationId,
                $invoiceNumber
            ]);

            $lockedInvoice = $stmt->fetch();

            if (!$lockedInvoice) {
                throw new RuntimeException(
                    'Invoice tidak ditemukan.'
                );
            }

            if (!in_array((string)$lockedInvoice['status'], ['UNPAID', 'PARTIAL', 'OVERDUE'], true)) {
                throw new RuntimeException(
                    'Invoice berstatus ' . (string)$lockedInvoice['status'] . ' tidak dapat menerima pembayaran.'
                );
            }

            /*
             * Hitung ulang pembayaran SUCCESS setelah lock.
             */
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0)
                 FROM payments
                 WHERE organization_id = ?
                   AND invoice_id = ?
                   AND status = 'SUCCESS'"
            );

            $stmt->execute([
                $organizationId,
                (int)$lockedInvoice['id']
            ]);

            $paidBefore = (float)$stmt->fetchColumn();

            $invoiceTotal = (float)$lockedInvoice['total'];

            $remainingBefore = max(
                0,
                $invoiceTotal - $paidBefore
            );

            /*
             * Jangan izinkan pembayaran melebihi invoice.
             */
            if ($remainingBefore <= 0) {
                throw new RuntimeException(
                    'Invoice sudah lunas. Tidak ada sisa pembayaran.'
                );
            }

            if ($amount > $remainingBefore) {
                throw new RuntimeException(
                    'Nominal pembayaran ' .
                    rupiah($amount) .
                    ' melebihi sisa invoice ' .
                    rupiah($remainingBefore) .
                    '.'
                );
            }

            /*
             * Insert payment.
             */
            $stmt = $pdo->prepare(
                "INSERT INTO payments
                (
                    organization_id,
                    invoice_id,
                    amount,
                    payment_method,
                    payment_channel,
                    provider_name,
                    reference_number,
                    provider_transaction_id,
                    bank_name,
                    ewallet_name,
                    paid_at,
                    confirmed_at,
                    confirmed_by_user_id,
                    status
                )
                VALUES
                                (
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        NOW(),
                                        ?,
                                        'SUCCESS'
                                )"
            );

            $stmt->execute([
                $organizationId,
                (int)$lockedInvoice['id'],
                $amount,
                $paymentMethod,
                $paymentChannel,
                $providerName !== '' ? $providerName : null,
                $referenceNumber !== '' ? $referenceNumber : null,
                $providerTransactionId !== '' ? $providerTransactionId : null,
                $bankName !== '' ? $bankName : null,
                $ewalletName !== '' ? $ewalletName : null,
                $paidAt,
                (int)($_SESSION['user_id'] ?? 0)
            ]);

            $paymentId = (int)$pdo->lastInsertId();

            /*
             * Hitung ulang total pembayaran.
             */
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0)
                 FROM payments
                 WHERE organization_id = ?
                   AND invoice_id = ?
                   AND status = 'SUCCESS'"
            );

            $stmt->execute([
                $organizationId,
                (int)$lockedInvoice['id']
            ]);

            $paidAfter = (float)$stmt->fetchColumn();

            /*
             * Tentukan status invoice.
             */
            if ($paidAfter >= $invoiceTotal) {
                $newStatus = 'PAID';
            } elseif ($paidAfter > 0) {
                $newStatus = 'PARTIAL';
            } else {
                $newStatus = 'UNPAID';
            }

            $stmt = $pdo->prepare(
                "UPDATE invoices
                 SET status = ?
                 WHERE id = ?
                   AND organization_id = ?"
            );

            $stmt->execute([
                $newStatus,
                (int)$lockedInvoice['id'],
                $organizationId
            ]);

            /*
             * Audit log jika tabel/API audit tersedia.
             * Tidak menggagalkan pembayaran jika audit belum tersedia.
             */
            try {
                \BAJAMA\Core\Audit::log(
                    $pdo,
                    'PAYMENT_CONFIRMED',
                    'billing',
                    'payment',
                    $paymentId,
                    [
                        'invoice_id' => (int)$lockedInvoice['id'],
                        'invoice_number' => $lockedInvoice['invoice_number'],
                        'amount' => $amount,
                        'payment_method' => $paymentMethod,
                        'status' => 'SUCCESS',
                        'invoice_status' => $newStatus
                    ]
                );
            } catch (Throwable $auditError) {
                error_log(
                    'BAJAMA payment audit error: '
                    . $auditError->getMessage()
                );
            }

            $pdo->commit();

            header(
                'Location: payment_view.php?id=' .
                $paymentId .
                '&saved=1'
            );
            exit;

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('BAJAMA payment form error: ' . $e->getMessage());
            $errors[] = 'Pembayaran tidak dapat disimpan.';
        }
    }

    /*
     * Setelah POST gagal, reload invoice agar
     * informasi sisa tetap akurat.
     */
    if ($invoiceNumber !== '') {
        $stmt = $pdo->prepare(
            "SELECT
                i.id,
                i.organization_id,
                i.customer_id,
                i.invoice_number,
                i.issue_date,
                i.due_date,
                i.subtotal,
                i.discount,
                i.total,
                i.status,
                i.period_start,
                i.period_end,
                i.description,

                c.customer_code,
                c.name AS customer_name,
                c.phone AS customer_phone,
                c.email AS customer_email

             FROM invoices i
             INNER JOIN customers c
                ON c.id = i.customer_id
               AND c.organization_id = i.organization_id

             WHERE i.organization_id = ?
               AND i.invoice_number = ?
             LIMIT 1"
        );

        $stmt->execute([
            $organizationId,
            $invoiceNumber
        ]);

        $invoice = $stmt->fetch();

        if ($invoice) {
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0)
                 FROM payments
                 WHERE organization_id = ?
                   AND invoice_id = ?
                   AND status = 'SUCCESS'"
            );

            $stmt->execute([
                $organizationId,
                (int)$invoice['id']
            ]);

            $paymentTotal = (float)$stmt->fetchColumn();

            $remaining = max(
                0,
                (float)$invoice['total'] - $paymentTotal
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| PAGE DATA
|--------------------------------------------------------------------------
*/

$pageTitle = 'Catat Pembayaran';

$currentAmount = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentAmount = trim((string)($_POST['amount'] ?? ''));
}

$defaultPaidAt = date('Y-m-d\TH:i');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['paid_at'])) {
    $defaultPaidAt = (string)$_POST['paid_at'];
}

$defaultMethod = 'TRANSFER';
$defaultPaymentMethodId = (int)($_POST['payment_method_id'] ?? 0);

if ($defaultPaymentMethodId <= 0 && $configuredPaymentMethods) {
    $defaultPaymentMethodId = (int)$configuredPaymentMethods[0]['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $defaultMethod = strtoupper(
        (string)($_POST['payment_method'] ?? 'TRANSFER')
    );
}

$defaultReference = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $defaultReference = trim(
        (string)($_POST['reference_number'] ?? '')
    );
}

?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?= h($pageTitle) ?> - BAJAMA</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <style>
        body {
            background: #f5f7fb;
        }

        .page-wrap {
            max-width: 1100px;
            margin: 40px auto;
            padding: 0 18px;
        }

        .page-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            box-shadow: 0 8px 30px rgba(0,0,0,.05);
            overflow: hidden;
        }

        .page-header {
            padding: 24px 26px;
            border-bottom: 1px solid #edf0f4;
        }

        .page-body {
            padding: 26px;
        }

        .invoice-summary {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 20px;
            background: #fafbfc;
        }

        .summary-label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .summary-value {
            font-weight: 700;
            font-size: 17px;
        }

        .remaining-box {
            border-radius: 14px;
            padding: 18px;
            background: #eef6ff;
            border: 1px solid #cfe4ff;
        }

        .remaining-amount {
            font-size: 27px;
            font-weight: 800;
        }

        .form-control,
        .form-select {
            min-height: 46px;
            border-radius: 10px;
        }

        .btn {
            border-radius: 10px;
        }

        .amount-input {
            font-size: 22px;
            font-weight: 700;
        }

        .customer-name {
            font-size: 20px;
            font-weight: 750;
        }

        .muted {
            color: #6b7280;
        }
    </style>
</head>

<body>

<div class="page-wrap">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <div class="text-muted small">BAJAMA</div>
            <h1 class="h3 mb-1">Catat Pembayaran</h1>
            <div class="text-muted">
                Mencatat pembayaran pelanggan secara aman.
            </div>
        </div>

        <a href="payments.php" class="btn btn-light border">
            <i class="bi bi-arrow-left"></i>
            Kembali
        </a>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger shadow-sm">
            <div class="fw-bold mb-1">
                <i class="bi bi-exclamation-triangle-fill"></i>
                Pembayaran belum disimpan
            </div>

            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="page-card">

        <div class="page-header">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-credit-card-fill fs-4 text-primary"></i>

                <div>
                    <div class="fw-bold">
                        Pembayaran Invoice
                    </div>

                    <div class="small text-muted">
                        Data pembayaran akan dicatat sebagai transaksi SUCCESS
                        setelah berhasil diproses.
                    </div>
                </div>
            </div>
        </div>

        <div class="page-body">

            <?php if (!$invoice): ?>

                <div class="text-center py-5">
                    <div class="display-5 text-muted mb-3">
                        <i class="bi bi-receipt"></i>
                    </div>

                    <h4>Invoice belum dipilih</h4>

                    <p class="text-muted">
                        Buka halaman invoice lalu pilih pembayaran,
                        atau masukkan nomor invoice.
                    </p>

                    <form method="get" class="row g-2 justify-content-center mt-4">
                        <div class="col-md-6">
                            <input
                                type="text"
                                name="invoice"
                                class="form-control"
                                placeholder="Contoh: INV-202609-ORG001-SUB000002"
                                required
                            >
                        </div>

                        <div class="col-auto">
                            <button class="btn btn-primary">
                                <i class="bi bi-search"></i>
                                Cari Invoice
                            </button>
                        </div>
                    </form>
                </div>

            <?php else: ?>

                <div class="invoice-summary mb-4">

                    <div class="row g-4">

                        <div class="col-md-6">

                            <div class="summary-label">
                                Invoice
                            </div>

                            <div class="summary-value">
                                <?= h($invoice['invoice_number']) ?>
                            </div>

                            <div class="mt-3 customer-name">
                                <?= h($invoice['customer_name']) ?>
                            </div>

                            <div class="muted">
                                <?= h($invoice['customer_code']) ?>
                            </div>

                            <?php if (!empty($invoice['customer_phone'])): ?>
                                <div class="small mt-2">
                                    <i class="bi bi-telephone"></i>
                                    <?= h($invoice['customer_phone']) ?>
                                </div>
                            <?php endif; ?>

                        </div>

                        <div class="col-md-6">

                            <div class="row g-3">

                                <div class="col-6">
                                    <div class="summary-label">
                                        Total Invoice
                                    </div>

                                    <div class="summary-value">
                                        <?= rupiah($invoice['total']) ?>
                                    </div>
                                </div>

                                <div class="col-6">
                                    <div class="summary-label">
                                        Sudah Dibayar
                                    </div>

                                    <div class="summary-value text-success">
                                        <?= rupiah($paymentTotal) ?>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <div class="remaining-box">

                                        <div class="summary-label">
                                            Sisa Pembayaran
                                        </div>

                                        <div class="remaining-amount text-primary">
                                            <?= rupiah($remaining) ?>
                                        </div>

                                        <?php if ($remaining <= 0): ?>
                                            <div class="text-success small mt-1">
                                                <i class="bi bi-check-circle-fill"></i>
                                                Invoice sudah lunas.
                                            </div>
                                        <?php endif; ?>

                                    </div>
                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <?php if ($remaining <= 0): ?>

                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill"></i>
                        Invoice
                        <strong><?= h($invoice['invoice_number']) ?></strong>
                        sudah lunas dan tidak dapat menerima pembayaran baru.
                    </div>

                    <div class="d-flex gap-2">
                        <a
                            href="invoice_view.php?invoice=<?= urlencode($invoice['invoice_number']) ?>"
                            class="btn btn-primary"
                        >
                            <i class="bi bi-receipt"></i>
                            Lihat Invoice
                        </a>

                        <a
                            href="payments.php"
                            class="btn btn-light border"
                        >
                            Kembali
                        </a>
                    </div>

                <?php else: ?>

                    <form method="post" autocomplete="off">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= h($csrfToken) ?>"
                        >

                        <input
                            type="hidden"
                            name="invoice_number"
                            value="<?= h($invoice['invoice_number']) ?>"
                        >

                        <div class="row g-4">

                            <div class="col-md-6">

                                <label class="form-label fw-semibold">
                                    Nominal Pembayaran
                                    <span class="text-danger">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="amount"
                                    id="amount"
                                    class="form-control amount-input"
                                    inputmode="numeric"
                                    placeholder="Contoh: 160000"
                                    value="<?= h($currentAmount) ?>"
                                    required
                                >

                                <div class="form-text">
                                    Maksimal:
                                    <strong><?= rupiah($remaining) ?></strong>
                                </div>

                            </div>

                            <div class="col-md-6">

                                <label class="form-label fw-semibold">
                                    Metode Pembayaran
                                    <span class="text-danger">*</span>
                                </label>

                                <?php if ($configuredPaymentMethods): ?>
                                    <select name="payment_method_id" class="form-select" required>
                                        <?php foreach ($configuredPaymentMethods as $method): ?>
                                            <option value="<?= (int)$method['id'] ?>" <?= $defaultPaymentMethodId === (int)$method['id'] ? 'selected' : '' ?>>
                                                <?= h((string)$method['name']) ?><?= !empty($method['provider_name']) ? ' - ' . h((string)$method['provider_name']) : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">
                                        Metode aktif dikelola superadmin. Detail rekening/provider akan tersimpan pada transaksi.
                                    </div>
                                <?php else: ?>
                                    <select name="payment_method" class="form-select" required>
                                        <?php foreach ([
                                            'CASH' => 'Tunai',
                                            'TRANSFER' => 'Transfer Bank',
                                            'QRIS' => 'QRIS',
                                            'E-WALLET' => 'E-Wallet',
                                            'PAYMENT_GATEWAY' => 'Payment Gateway'
                                        ] as $value => $label): ?>
                                            <option value="<?= h($value) ?>" <?= $defaultMethod === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text text-warning">Belum ada metode aktif dari superadmin. Silakan konfigurasi di Payment Methods.</div>
                                <?php endif; ?>

                            </div>

                            <div class="col-md-6">

                                <label class="form-label fw-semibold">
                                    Payment Channel
                                </label>

                                <select name="payment_channel" class="form-select">
                                    <option value="MANUAL">Manual / Cashier</option>
                                    <option value="BANK">Bank</option>
                                    <option value="E_WALLET">E-Wallet</option>
                                    <option value="GATEWAY">Payment Gateway API</option>
                                </select>

                            </div>

                            <div class="col-md-6">

                                <label class="form-label fw-semibold">Provider</label>
                                <input
                                    type="text"
                                    name="provider_name"
                                    class="form-control"
                                    maxlength="100"
                                    placeholder="Contoh: Midtrans, BCA, GoPay"
                                >

                            </div>

                            <div class="col-md-6">

                                <label class="form-label fw-semibold">Provider Transaction ID</label>
                                <input
                                    type="text"
                                    name="provider_transaction_id"
                                    class="form-control"
                                    maxlength="190"
                                    placeholder="ID transaksi gateway/bank/e-wallet"
                                >

                            </div>

                            <div class="col-md-6">

                                <label class="form-label fw-semibold">Bank</label>
                                <input
                                    type="text"
                                    name="bank_name"
                                    class="form-control"
                                    maxlength="120"
                                    placeholder="Nama bank jika transfer"
                                >

                            </div>

                            <div class="col-md-6">

                                <label class="form-label fw-semibold">E-Wallet</label>
                                <input
                                    type="text"
                                    name="ewallet_name"
                                    class="form-control"
                                    maxlength="120"
                                    placeholder="OVO, DANA, GoPay, ShopeePay"
                                >

                            </div>

                            <div class="col-md-6">

                                <label class="form-label fw-semibold">
                                    Nomor Referensi
                                </label>

                                <input
                                    type="text"
                                    name="reference_number"
                                    class="form-control"
                                    maxlength="150"
                                    placeholder="No. transfer / referensi"
                                    value="<?= h($defaultReference) ?>"
                                >

                                <div class="form-text">
                                    Opsional. Disarankan untuk transfer/QRIS.
                                </div>

                            </div>

                            <div class="col-md-6">

                                <label class="form-label fw-semibold">
                                    Tanggal Pembayaran
                                    <span class="text-danger">*</span>
                                </label>

                                <input
                                    type="datetime-local"
                                    name="paid_at"
                                    class="form-control"
                                    value="<?= h($defaultPaidAt) ?>"
                                    required
                                >

                                <div class="form-text">
                                    Tidak boleh di masa depan.
                                </div>

                            </div>

                        </div>

                        <hr class="my-4">

                        <div class="alert alert-light border">
                            <div class="fw-semibold mb-1">
                                <i class="bi bi-shield-check text-success"></i>
                                Pemeriksaan otomatis
                            </div>

                            <ul class="small mb-0">
                                <li>Pembayaran dibatasi sesuai sisa invoice.</li>
                                <li>Invoice dikunci selama transaksi diproses.</li>
                                <li>Status invoice dihitung ulang setelah pembayaran.</li>
                                <li>Pembayaran berhasil dicatat sebagai <strong>SUCCESS</strong>.</li>
                            </ul>
                        </div>

                        <div class="d-flex flex-wrap justify-content-between gap-2 mt-4">

                            <a
                                href="invoice_view.php?invoice=<?= urlencode($invoice['invoice_number']) ?>"
                                class="btn btn-light border"
                            >
                                <i class="bi bi-arrow-left"></i>
                                Kembali ke Invoice
                            </a>

                            <button
                                type="submit"
                                class="btn btn-primary px-4"
                                id="submitPayment"
                            >
                                <i class="bi bi-check-circle-fill"></i>
                                Simpan Pembayaran
                            </button>

                        </div>

                    </form>

                <?php endif; ?>

            <?php endif; ?>

        </div>
    </div>

</div>

<script>
(function () {
    const amount = document.getElementById('amount');

    if (amount) {
        amount.addEventListener('input', function () {
            let value = this.value.replace(/[^\d]/g, '');

            if (value === '') {
                this.value = '';
                return;
            }

            this.value = new Intl.NumberFormat('id-ID').format(
                parseInt(value, 10)
            );
        });
    }

    const form = document.querySelector('form[method="post"]');
    const submitButton = document.getElementById('submitPayment');

    if (form && submitButton) {
        form.addEventListener('submit', function () {
            submitButton.disabled = true;
            submitButton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2"></span>' +
                'Memproses...';
        });
    }
})();
</script>

</body>
</html>
