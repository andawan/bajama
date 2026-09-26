<?php

require_once __DIR__ . '/../app/bootstrap.php';

/* BAJAMA_RBAC_ENFORCED */
\BAJAMA\Core\Auth::requireLogin();
\BAJAMA\Core\License::requireFeature(\db(), 'billing');
\BAJAMA\Core\RBAC::require(\db(), 'billing.view');

$canDeletePayments = \BAJAMA\Core\RBAC::hasPermission(
    \db(),
    'payments.delete'
);


if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['user_id']) || empty($_SESSION['organization_id'])) {
    header('Location: login.php');
    exit;
}

$orgId = (int) $_SESSION['organization_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canDeletePayments || ($_POST['action'] ?? '') !== 'delete') {
        http_response_code(403);
        exit('Akses void payment ditolak.');
    }

    verify_csrf((string)($_POST['_csrf'] ?? ''));
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT invoice_id FROM payments
             WHERE id = ? AND organization_id = ? AND status = "SUCCESS"
             FOR UPDATE'
        );
        $stmt->execute([$paymentId, $orgId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            throw new RuntimeException('Payment tidak ditemukan atau sudah void.');
        }

        $stmt = $pdo->prepare(
            'UPDATE payments SET status = "REFUNDED"
             WHERE id = ? AND organization_id = ?'
        );
        $stmt->execute([$paymentId, $orgId]);

        $stmt = $pdo->prepare(
            'UPDATE invoices i SET i.status = CASE
                WHEN COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id AND p.organization_id = i.organization_id AND p.status = "SUCCESS"), 0) >= i.total THEN "PAID"
                WHEN COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id AND p.organization_id = i.organization_id AND p.status = "SUCCESS"), 0) > 0 THEN "PARTIAL"
                ELSE "UNPAID" END
             WHERE i.id = ? AND i.organization_id = ?'
        );
        $stmt->execute([(int)$payment['invoice_id'], $orgId]);
        \BAJAMA\Core\Audit::log($pdo, 'PAYMENT_VOIDED', 'billing', 'payment', $paymentId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('BAJAMA payment void error: ' . $e->getMessage());
    }

    header('Location: payments.php?msg=' . urlencode('Payment di-void dan tercatat di audit.'));
    exit;
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function envLoadPayments()
{
    $file = __DIR__ . '/../.env';
    $env = [];

    if (!is_file($file)) {
        return $env;
    }

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        $parts = explode('=', $line, 2);

        if (count($parts) === 2) {
            $env[trim($parts[0])] = trim($parts[1], "\"'");
        }
    }

    return $env;
}

function rupiah($amount)
{
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}

function paymentStatusBadge($status)
{
    switch ($status) {
        case 'SUCCESS':
            return '<span class="badge bg-success-subtle text-success border border-success-subtle">
                        <i class="bi bi-check-circle me-1"></i>SUCCESS
                    </span>';

        case 'PENDING':
            return '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                        <i class="bi bi-clock me-1"></i>PENDING
                    </span>';

        case 'FAILED':
            return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                        <i class="bi bi-x-circle me-1"></i>FAILED
                    </span>';

        case 'REFUNDED':
            return '<span class="badge bg-dark-subtle text-dark border border-dark-subtle">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>REFUNDED
                    </span>';

        default:
            return '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">'
                . h($status)
                . '</span>';
    }
}

function paymentMethodLabel($method)
{
    $labels = [
        'CASH' => 'Cash',
        'TRANSFER' => 'Bank Transfer',
        'QRIS' => 'QRIS',
        'E-WALLET' => 'E-Wallet',
        'PAYMENT_GATEWAY' => 'Payment Gateway'
    ];

    return $labels[$method] ?? $method;
}

$env = envLoadPayments();

$pdo = new PDO(
    'mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1') .
    ';port=' . ($env['DB_PORT'] ?? '3306') .
    ';dbname=' . ($env['DB_DATABASE'] ?? 'bajama') .
    ';charset=utf8mb4',
    $env['DB_USERNAME'] ?? 'bajama',
    $env['DB_PASSWORD'] ?? '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]
);

/*
|--------------------------------------------------------------------------
| FILTER
|--------------------------------------------------------------------------
*/

$search = trim($_GET['q'] ?? '');
$status = strtoupper(trim($_GET['status'] ?? ''));
$method = strtoupper(trim($_GET['method'] ?? ''));
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$allowedStatuses = [
    '',
    'SUCCESS',
    'PENDING',
    'FAILED',
    'REFUNDED'
];

$allowedMethods = [
    '',
    'CASH',
    'TRANSFER',
    'QRIS',
    'E-WALLET',
    'PAYMENT_GATEWAY'
];

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

if (!in_array($method, $allowedMethods, true)) {
    $method = '';
}

if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}

if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$where = [
    'p.organization_id = ?'
];

$params = [
    $orgId
];

if ($search !== '') {
    $where[] = "
        (
            p.reference_number LIKE ?
            OR i.invoice_number LIKE ?
            OR c.name LIKE ?
            OR c.customer_code LIKE ?
        )
    ";

    $like = '%' . $search . '%';

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($status !== '') {
    $where[] = 'p.status = ?';
    $params[] = $status;
}

if ($method !== '') {
    $where[] = 'p.payment_method = ?';
    $params[] = $method;
}

if ($dateFrom !== '') {
    $where[] = 'DATE(COALESCE(p.paid_at, p.created_at)) >= ?';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = 'DATE(COALESCE(p.paid_at, p.created_at)) <= ?';
    $params[] = $dateTo;
}

$whereSql = implode(' AND ', $where);

/*
|--------------------------------------------------------------------------
| DASHBOARD SUMMARY
|--------------------------------------------------------------------------
*/

$summaryStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(
            CASE
                WHEN p.status = 'SUCCESS'
                THEN p.amount
                ELSE 0
            END
        ), 0) AS total_paid,

        COALESCE(SUM(
            CASE
                WHEN p.status = 'SUCCESS'
                 AND DATE(COALESCE(p.paid_at, p.created_at)) = CURDATE()
                THEN p.amount
                ELSE 0
            END
        ), 0) AS today_paid,

        COALESCE(SUM(
            CASE
                WHEN p.status = 'SUCCESS'
                 AND YEAR(COALESCE(p.paid_at, p.created_at)) = YEAR(CURDATE())
                 AND MONTH(COALESCE(p.paid_at, p.created_at)) = MONTH(CURDATE())
                THEN p.amount
                ELSE 0
            END
        ), 0) AS month_paid,

        COUNT(*) AS total_transactions,

        COALESCE(SUM(
            CASE
                WHEN p.status = 'PENDING'
                THEN 1
                ELSE 0
            END
        ), 0) AS pending_count

    FROM payments p
    INNER JOIN invoices i
        ON i.id = p.invoice_id
       AND i.organization_id = p.organization_id
    INNER JOIN customers c
        ON c.id = i.customer_id
       AND c.organization_id = i.organization_id
    WHERE {$whereSql}
");

$summaryStmt->execute($params);
$summary = $summaryStmt->fetch();

$totalPaid = (float) ($summary['total_paid'] ?? 0);
$todayPaid = (float) ($summary['today_paid'] ?? 0);
$monthPaid = (float) ($summary['month_paid'] ?? 0);
$totalTransactions = (int) ($summary['total_transactions'] ?? 0);
$pendingCount = (int) ($summary['pending_count'] ?? 0);

/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM payments p
    INNER JOIN invoices i
        ON i.id = p.invoice_id
       AND i.organization_id = p.organization_id
    INNER JOIN customers c
        ON c.id = i.customer_id
       AND c.organization_id = i.organization_id
    WHERE {$whereSql}
");

$countStmt->execute($params);

$totalRows = (int) $countStmt->fetchColumn();

$totalPages = max(
    1,
    (int) ceil($totalRows / $perPage)
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| PAYMENT LIST
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        p.id,
        p.invoice_id,
        p.amount,
        p.payment_method,
        p.payment_channel,
        p.provider_name,
        p.reference_number,
        p.provider_transaction_id,
        p.bank_name,
        p.ewallet_name,
        p.paid_at,
        p.status,
        p.created_at,

        i.invoice_number,
        i.total AS invoice_total,
        i.status AS invoice_status,

        c.name AS customer_name,
        c.customer_code

    FROM payments p

    INNER JOIN invoices i
        ON i.id = p.invoice_id
       AND i.organization_id = p.organization_id

    INNER JOIN customers c
        ON c.id = i.customer_id
       AND c.organization_id = i.organization_id

    WHERE {$whereSql}

    ORDER BY
        COALESCE(p.paid_at, p.created_at) DESC,
        p.id DESC

    LIMIT {$perPage}
    OFFSET {$offset}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| PAGINATION URL
|--------------------------------------------------------------------------
*/

$queryParams = $_GET;
unset($queryParams['page']);

function pageUrl($page, $queryParams)
{
    $queryParams['page'] = $page;

    return 'payments.php?' . http_build_query($queryParams);
}

$pageTitle = 'Payments';

ob_start();
?>

<div class="container-fluid py-3">

    <!-- HEADER -->

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">

        <div>
            <div class="text-primary fw-semibold small mb-1">
                BAJAMA BILLING
            </div>

            <h1 class="h3 mb-1">
                <i class="bi bi-credit-card-2-front me-2"></i>
                Payments
            </h1>

            <div class="text-muted">
                Kelola dan pantau seluruh transaksi pembayaran pelanggan.
            </div>
        </div>

        <div class="d-flex gap-2">

            <a
                href="invoices.php"
                class="btn btn-outline-primary"
            >
                <i class="bi bi-receipt me-1"></i>
                Invoice
            </a>

        </div>

    </div>

    <?php if (!empty($_GET['msg'])): ?>

        <div class="alert alert-success alert-dismissible fade show shadow-sm">
            <i class="bi bi-check-circle me-2"></i>
            <?= h($_GET['msg']) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>
        </div>

    <?php endif; ?>

    <!-- SUMMARY -->

    <div class="row g-3 mb-4">

        <div class="col-xl-3 col-md-6">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>
                            <div class="text-muted small mb-1">
                                Total Pembayaran
                            </div>

                            <div class="fs-4 fw-bold text-success">
                                <?= rupiah($totalPaid) ?>
                            </div>
                        </div>

                        <div class="text-success fs-3">
                            <i class="bi bi-cash-stack"></i>
                        </div>

                    </div>

                    <small class="text-muted">
                        Berdasarkan filter aktif
                    </small>

                </div>

            </div>

        </div>

        <div class="col-xl-3 col-md-6">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>
                            <div class="text-muted small mb-1">
                                Hari Ini
                            </div>

                            <div class="fs-4 fw-bold">
                                <?= rupiah($todayPaid) ?>
                            </div>
                        </div>

                        <div class="text-primary fs-3">
                            <i class="bi bi-calendar-day"></i>
                        </div>

                    </div>

                    <small class="text-muted">
                        Pembayaran sukses
                    </small>

                </div>

            </div>

        </div>

        <div class="col-xl-3 col-md-6">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>
                            <div class="text-muted small mb-1">
                                Bulan Ini
                            </div>

                            <div class="fs-4 fw-bold">
                                <?= rupiah($monthPaid) ?>
                            </div>
                        </div>

                        <div class="text-info fs-3">
                            <i class="bi bi-bar-chart-line"></i>
                        </div>

                    </div>

                    <small class="text-muted">
                        Pembayaran sukses
                    </small>

                </div>

            </div>

        </div>

        <div class="col-xl-3 col-md-6">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>
                            <div class="text-muted small mb-1">
                                Transaksi
                            </div>

                            <div class="fs-4 fw-bold">
                                <?= number_format($totalTransactions, 0, ',', '.') ?>
                            </div>
                        </div>

                        <div class="text-warning fs-3">
                            <i class="bi bi-list-check"></i>
                        </div>

                    </div>

                    <small class="text-muted">
                        Pending: <?= number_format($pendingCount, 0, ',', '.') ?>
                    </small>

                </div>

            </div>

        </div>

    </div>

    <!-- FILTER -->

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-body">

            <form method="get">

                <div class="row g-2">

                    <div class="col-xl-4 col-lg-6">

                        <label class="form-label small fw-semibold">
                            Pencarian
                        </label>

                        <input
                            type="search"
                            name="q"
                            value="<?= h($search) ?>"
                            class="form-control"
                            placeholder="Invoice, customer, kode atau referensi..."
                        >

                    </div>

                    <div class="col-xl-2 col-md-6">

                        <label class="form-label small fw-semibold">
                            Status
                        </label>

                        <select name="status" class="form-select">

                            <option value="">
                                Semua Status
                            </option>

                            <?php foreach ($allowedStatuses as $item): ?>

                                <?php if ($item === '') continue; ?>

                                <option
                                    value="<?= h($item) ?>"
                                    <?= $status === $item ? 'selected' : '' ?>
                                >
                                    <?= h($item) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-xl-2 col-md-6">

                        <label class="form-label small fw-semibold">
                            Metode
                        </label>

                        <select name="method" class="form-select">

                            <option value="">
                                Semua Metode
                            </option>

                            <?php foreach ($allowedMethods as $item): ?>

                                <?php if ($item === '') continue; ?>

                                <option
                                    value="<?= h($item) ?>"
                                    <?= $method === $item ? 'selected' : '' ?>
                                >
                                    <?= h(paymentMethodLabel($item)) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-xl-2 col-md-6">

                        <label class="form-label small fw-semibold">
                            Dari
                        </label>

                        <input
                            type="date"
                            name="date_from"
                            value="<?= h($dateFrom) ?>"
                            class="form-control"
                        >

                    </div>

                    <div class="col-xl-2 col-md-6">

                        <label class="form-label small fw-semibold">
                            Sampai
                        </label>

                        <input
                            type="date"
                            name="date_to"
                            value="<?= h($dateTo) ?>"
                            class="form-control"
                        >

                    </div>

                    <div class="col-12 d-flex gap-2 mt-3">

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            <i class="bi bi-funnel me-1"></i>
                            Terapkan Filter
                        </button>

                        <a
                            href="payments.php"
                            class="btn btn-outline-secondary"
                        >
                            <i class="bi bi-arrow-counterclockwise me-1"></i>
                            Reset
                        </a>

                    </div>

                </div>

            </form>

        </div>

    </div>

    <!-- TABLE -->

    <div class="card border-0 shadow-sm">

        <div class="card-header bg-white py-3">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">

                <div>
                    <strong>
                        <i class="bi bi-clock-history me-2"></i>
                        Riwayat Pembayaran
                    </strong>

                    <div class="small text-muted">
                        Menampilkan <?= number_format($totalRows, 0, ',', '.') ?> transaksi
                    </div>
                </div>

                <?php if ($totalPages > 1): ?>

                    <div class="small text-muted">
                        Halaman <?= $page ?> / <?= $totalPages ?>
                    </div>

                <?php endif; ?>

            </div>

        </div>

        <div class="table-responsive bajama-table-responsive payments-table-responsive">

            <table class="table table-hover align-middle mb-0 bajama-table" data-responsive-table="true">

                <thead class="table-light">

                    <tr>
                        <th data-label="Tanggal">Tanggal</th>
                        <th data-label="Invoice">Invoice</th>
                        <th data-label="Customer">Customer</th>
                        <th data-label="Referensi">Referensi</th>
                        <th data-label="Metode">Metode</th>
                        <th class="text-end" data-label="Nominal">Nominal</th>
                        <th data-label="Status">Status</th>
                        <th class="text-end" data-label="Aksi">Aksi</th>
                    </tr>

                </thead>

                <tbody>

                <?php if (!$payments): ?>

                    <tr>

                        <td colspan="8" class="text-center py-5">

                            <div class="text-muted">

                                <i class="bi bi-credit-card-2-front fs-1 d-block mb-2"></i>

                                <div class="fw-semibold">
                                    Tidak ada pembayaran
                                </div>

                                <small>
                                    Belum ada transaksi yang sesuai dengan filter.
                                </small>

                            </div>

                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($payments as $payment): ?>

                        <?php
                        $paymentDate =
                            $payment['paid_at'] ?: $payment['created_at'];
                        ?>

                        <tr class="bajama-table-row">

                            <td class="text-nowrap" data-label="Tanggal">

                                <div class="fw-semibold">
                                    <?= h(date('d/m/Y', strtotime($paymentDate))) ?>
                                </div>

                                <small class="text-muted">
                                    <?= h(date('H:i', strtotime($paymentDate))) ?>
                                </small>

                            </td>

                            <td data-label="Invoice">

                                <a
                                    href="invoice_view.php?invoice=<?= urlencode($payment['invoice_number']) ?>"
                                    class="fw-semibold text-decoration-none"
                                >
                                    <?= h($payment['invoice_number']) ?>
                                </a>

                                <div class="small text-muted">
                                    Invoice:
                                    <?= rupiah($payment['invoice_total']) ?>
                                </div>

                            </td>

                            <td data-label="Customer">

                                <div class="fw-semibold">
                                    <?= h($payment['customer_name']) ?>
                                </div>

                                <?php if (!empty($payment['customer_code'])): ?>

                                    <small class="text-muted">
                                        <?= h($payment['customer_code']) ?>
                                    </small>

                                <?php endif; ?>

                            </td>

                            <td data-label="Referensi">

                                <span class="font-monospace small">
                                    <?= h($payment['reference_number'] ?: '-') ?>
                                </span>

                                <?php if (!empty($payment['provider_transaction_id'])): ?>
                                    <div class="small text-muted">
                                        Tx: <?= h($payment['provider_transaction_id']) ?>
                                    </div>
                                <?php endif; ?>

                            </td>

                            <td data-label="Metode">

                                <span class="badge bg-light text-dark border">

                                    <?php
                                    switch ($payment['payment_method']) {
                                        case 'TRANSFER':
                                            $icon = 'bi-bank';
                                            break;

                                        case 'QRIS':
                                            $icon = 'bi-qr-code';
                                            break;

                                        case 'CASH':
                                            $icon = 'bi-cash';
                                            break;

                                        case 'E-WALLET':
                                            $icon = 'bi-phone';
                                            break;

                                        default:
                                            $icon = 'bi-credit-card';
                                            break;
                                    }
                                    ?>

                                    <i class="bi <?= $icon ?> me-1"></i>

                                    <?= h(
                                        paymentMethodLabel(
                                            $payment['payment_method']
                                        )
                                    ) ?>

                                </span>

                                <div class="small text-muted">
                                    <?= h($payment['provider_name'] ?: $payment['bank_name'] ?: $payment['ewallet_name'] ?: $payment['payment_channel'] ?: '-') ?>
                                </div>

                            </td>

                            <td class="text-end text-nowrap" data-label="Nominal">

                                <div class="fw-bold text-success">
                                    <?= rupiah($payment['amount']) ?>
                                </div>

                            </td>

                            <td data-label="Status">
                                <?= paymentStatusBadge($payment['status']) ?>
                            </td>

                            <td class="text-end bajama-table-actions" data-label="Aksi">

                                <div class="btn-group">

                                    <a
                                        href="payment_view.php?id=<?= (int) $payment['id'] ?>"
                                        class="btn btn-sm btn-outline-primary"
                                        title="Detail pembayaran"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </a>

                                    <a
                                        href="payment_receipt.php?id=<?= (int) $payment['id'] ?>"
                                        target="_blank"
                                        class="btn btn-sm btn-outline-secondary"
                                        title="Kuitansi"
                                    >
                                        <i class="bi bi-printer"></i>
                                    </a>

                                    <?php if ($canDeletePayments && $payment['status'] === 'SUCCESS'): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Void payment ini? Invoice akan dihitung ulang dan tindakan diaudit.');">
                                            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Void payment">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <?php if ($totalPages > 1): ?>

            <div class="card-footer bg-white">

                <nav>

                    <ul class="pagination justify-content-end mb-0">

                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">

                            <a
                                class="page-link"
                                href="<?= $page > 1 ? h(pageUrl($page - 1, $queryParams)) : '#' ?>"
                            >
                                <i class="bi bi-chevron-left"></i>
                            </a>

                        </li>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        ?>

                        <?php for ($p = $startPage; $p <= $endPage; $p++): ?>

                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">

                                <a
                                    class="page-link"
                                    href="<?= h(pageUrl($p, $queryParams)) ?>"
                                >
                                    <?= $p ?>
                                </a>

                            </li>

                        <?php endfor; ?>

                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">

                            <a
                                class="page-link"
                                href="<?= $page < $totalPages ? h(pageUrl($page + 1, $queryParams)) : '#' ?>"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </a>

                        </li>

                    </ul>

                </nav>

            </div>

        <?php endif; ?>

    </div>

</div>

<?php
$content = ob_get_clean();

require __DIR__ . '/../app/layout/layout.php';
