<?php

require_once __DIR__ . '/../app/bootstrap.php';

/* BAJAMA_RBAC_ENFORCED */
\BAJAMA\Core\Auth::requireLogin();
\BAJAMA\Core\License::requireFeature(\db(), 'billing');
\BAJAMA\Core\RBAC::require(\db(), 'billing.view');


if (function_exists('requireLogin')) {
    requireLogin();
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| ENV / DATABASE
|--------------------------------------------------------------------------
*/

$envFile = dirname(__DIR__) . '/.env';
$env = [];

if (is_readable($envFile)) {

    foreach (
        file(
            $envFile,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        ) as $line
    ) {

        $line = trim($line);

        if (
            $line === '' ||
            strpos($line, '#') === 0 ||
            strpos($line, '=') === false
        ) {
            continue;
        }

        list($key, $value) = explode('=', $line, 2);

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
}

try {

    $pdo = new PDO(
        'mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1') .
        ';port=' . ($env['DB_PORT'] ?? '3306') .
        ';dbname=' . ($env['DB_DATABASE'] ?? '') .
        ';charset=utf8mb4',
        $env['DB_USERNAME'] ?? '',
        $env['DB_PASSWORD'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

} catch (PDOException $e) {

    error_log('BAJAMA payment report DB error: ' . $e->getMessage());

    http_response_code(500);
    exit('Koneksi database gagal.');

}

/*
|--------------------------------------------------------------------------
| ORGANIZATION
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        u.organization_id,
        o.name AS organization_name,
        o.currency
    FROM users u
    INNER JOIN organizations o
        ON o.id = u.organization_id
    WHERE u.id = ?
    LIMIT 1
");

$stmt->execute([$userId]);

$organization = $stmt->fetch();

if (!$organization) {
    http_response_code(403);
    exit('Organization tidak ditemukan.');
}

$organizationId = (int)$organization['organization_id'];

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function report_h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function report_rupiah($value): string
{
    return 'Rp ' . number_format(
        (float)$value,
        0,
        ',',
        '.'
    );
}

function report_method($method): string
{
    switch (strtoupper((string)$method)) {

        case 'TRANSFER':
            return 'Transfer Bank';

        case 'QRIS':
            return 'QRIS';

        case 'CASH':
            return 'Cash';

        case 'E-WALLET':
            return 'E-Wallet';

        case 'PAYMENT_GATEWAY':
            return 'Payment Gateway';

        default:
            return ucwords(
                strtolower(
                    str_replace('_', ' ', (string)$method)
                )
            );
    }
}

function report_status_badge($status): string
{
    switch (strtoupper((string)$status)) {

        case 'SUCCESS':
            return '<span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-3 py-2"><i class="bi bi-check-circle-fill me-1"></i>SUCCESS</span>';

        case 'PENDING':
            return '<span class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-2"><i class="bi bi-clock-fill me-1"></i>PENDING</span>';

        case 'FAILED':
            return '<span class="badge rounded-pill bg-danger-subtle text-danger border border-danger-subtle px-3 py-2"><i class="bi bi-x-circle-fill me-1"></i>FAILED</span>';

        case 'REFUNDED':
            return '<span class="badge rounded-pill bg-secondary-subtle text-secondary border px-3 py-2"><i class="bi bi-arrow-counterclockwise me-1"></i>REFUNDED</span>';

        default:
            return report_h($status);
    }
}

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$status = strtoupper(trim($_GET['status'] ?? ''));
$method = strtoupper(trim($_GET['method'] ?? ''));
$search = trim($_GET['q'] ?? '');

if ($dateFrom === '') {
    $dateFrom = date('Y-m-01');
}

if ($dateTo === '') {
    $dateTo = date('Y-m-d');
}

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

/*
|--------------------------------------------------------------------------
| WHERE
|--------------------------------------------------------------------------
*/

$where = [
    'p.organization_id = ?',
    'DATE(COALESCE(p.paid_at, p.created_at)) BETWEEN ? AND ?'
];

$params = [
    $organizationId,
    $dateFrom,
    $dateTo
];

if ($status !== '') {

    $where[] = 'p.status = ?';
    $params[] = $status;
}

if ($method !== '') {

    $where[] = 'p.payment_method = ?';
    $params[] = $method;
}

if ($search !== '') {

    $where[] = "
        (
            p.reference_number LIKE ?
            OR p.invoice_id IN (
                SELECT id
                FROM invoices
                WHERE organization_id = ?
                  AND invoice_number LIKE ?
            )
            OR p.invoice_id IN (
                SELECT i.id
                FROM invoices i
                INNER JOIN customers c
                    ON c.id = i.customer_id
                   AND c.organization_id = i.organization_id
                WHERE i.organization_id = ?
                  AND (
                      c.name LIKE ?
                      OR c.customer_code LIKE ?
                  )
            )
        )
    ";

    $like = '%' . $search . '%';

    $params[] = $like;
    $params[] = $organizationId;
    $params[] = $like;
    $params[] = $organizationId;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode("\n AND ", $where);

/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

$summaryStmt = $pdo->prepare("
    SELECT

        COUNT(*) AS transactions,

        COALESCE(
            SUM(
                CASE
                    WHEN p.status = 'SUCCESS'
                    THEN p.amount
                    ELSE 0
                END
            ),
            0
        ) AS success_total,

        COALESCE(
            SUM(
                CASE
                    WHEN p.status = 'PENDING'
                    THEN p.amount
                    ELSE 0
                END
            ),
            0
        ) AS pending_total,

        COALESCE(
            SUM(
                CASE
                    WHEN p.status = 'REFUNDED'
                    THEN p.amount
                    ELSE 0
                END
            ),
            0
        ) AS refunded_total,

        COUNT(
            CASE
                WHEN p.status = 'SUCCESS'
                THEN 1
            END
        ) AS success_count

    FROM payments p

    WHERE {$whereSql}
");

$summaryStmt->execute($params);

$summary = $summaryStmt->fetch();

$reportTransactions = (int)($summary['transactions'] ?? 0);
$successTotal = (float)($summary['success_total'] ?? 0);
$pendingTotal = (float)($summary['pending_total'] ?? 0);
$refundedTotal = (float)($summary['refunded_total'] ?? 0);
$successCount = (int)($summary['success_count'] ?? 0);

$averagePayment = $successCount > 0
    ? $successTotal / $successCount
    : 0;

/*
|--------------------------------------------------------------------------
| CSV EXPORT
|--------------------------------------------------------------------------
*/

if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    $exportStmt = $pdo->prepare("
        SELECT
            p.paid_at,
            p.created_at,
            i.invoice_number,
            c.customer_code,
            c.name AS customer_name,
            p.payment_method,
            p.reference_number,
            p.amount,
            p.status

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
    ");

    $exportStmt->execute($params);

    $filename =
        'bajama-payment-report-' .
        $dateFrom .
        '-to-' .
        $dateTo .
        '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="' .
        $filename .
        '"'
    );

    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    fputcsv(
        $output,
        [
            'Tanggal',
            'Invoice',
            'Kode Pelanggan',
            'Pelanggan',
            'Metode',
            'Referensi',
            'Jumlah',
            'Status'
        ]
    );

    while ($row = $exportStmt->fetch()) {

        fputcsv(
            $output,
            [
                $row['paid_at'] ?: $row['created_at'],
                $row['invoice_number'],
                $row['customer_code'],
                $row['customer_name'],
                report_method($row['payment_method']),
                $row['reference_number'],
                $row['amount'],
                $row['status']
            ]
        );
    }

    fclose($output);
    exit;
}

/*
|--------------------------------------------------------------------------
| DATA
|--------------------------------------------------------------------------
*/

$listStmt = $pdo->prepare("
    SELECT
        p.id,
        p.paid_at,
        p.created_at,
        p.amount,
        p.payment_method,
        p.reference_number,
        p.status,

        i.invoice_number,

        c.customer_code,
        c.name AS customer_name

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

    LIMIT 500
");

$listStmt->execute($params);

$payments = $listStmt->fetchAll();

ob_start();

?>

<style>

.payment-report-page {
    width: 100%;
}

.payment-report-title {
    font-size: 28px;
    font-weight: 800;
    letter-spacing: -.5px;
}

.payment-report-subtitle {
    color: #64748b;
    font-size: 14px;
}

.payment-report-card {
    border: 1px solid #e2e8f0 !important;
    border-radius: 16px !important;
    overflow: hidden;
}

.payment-report-stat {
    min-height: 135px;
}

.payment-report-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 21px;
}

.payment-report-label {
    color: #64748b;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .5px;
    text-transform: uppercase;
}

.payment-report-value {
    color: #0f172a;
    font-size: 23px;
    font-weight: 850;
    margin-top: 5px;
}

.payment-report-table th {
    background: #f8fafc;
    color: #64748b;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .4px;
    white-space: nowrap;
}

.payment-report-table td {
    vertical-align: middle;
    white-space: nowrap;
}

.payment-reference {
    max-width: 180px;
    overflow: hidden;
    text-overflow: ellipsis;
}

@media (max-width: 575px) {

    .payment-report-title {
        font-size: 23px;
    }

    .payment-report-value {
        font-size: 19px;
    }

}

</style>

<div class="payment-report-page">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

        <div>

            <div class="payment-report-title">
                <i class="bi bi-file-earmark-bar-graph me-2"></i>
                Payment Reports
            </div>

            <div class="payment-report-subtitle mt-1">
                Laporan seluruh transaksi pembayaran organisasi.
            </div>

        </div>

        <div class="d-flex gap-2">

            <a
                href="revenue.php"
                class="btn btn-primary"
            >
                <i class="bi bi-graph-up-arrow me-1"></i>
                Revenue Dashboard
            </a>

            <a
                href="payments.php"
                class="btn btn-outline-secondary"
            >
                <i class="bi bi-credit-card me-1"></i>
                Payments
            </a>

        </div>

    </div>

    <div class="row g-3 mb-4">

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card payment-report-card payment-report-stat shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <div class="payment-report-label">
                                Revenue
                            </div>

                            <div class="payment-report-value text-success">
                                <?= report_rupiah($successTotal) ?>
                            </div>

                            <div class="small text-secondary mt-1">
                                SUCCESS
                            </div>

                        </div>

                        <div class="payment-report-icon bg-success-subtle text-success">
                            <i class="bi bi-cash-stack"></i>
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card payment-report-card payment-report-stat shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <div class="payment-report-label">
                                Transactions
                            </div>

                            <div class="payment-report-value">
                                <?= number_format($reportTransactions) ?>
                            </div>

                            <div class="small text-secondary mt-1">
                                Semua status
                            </div>

                        </div>

                        <div class="payment-report-icon bg-primary-subtle text-primary">
                            <i class="bi bi-receipt"></i>
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card payment-report-card payment-report-stat shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <div class="payment-report-label">
                                Pending
                            </div>

                            <div class="payment-report-value text-warning-emphasis">
                                <?= report_rupiah($pendingTotal) ?>
                            </div>

                            <div class="small text-secondary mt-1">
                                Menunggu pembayaran
                            </div>

                        </div>

                        <div class="payment-report-icon bg-warning-subtle text-warning-emphasis">
                            <i class="bi bi-hourglass-split"></i>
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card payment-report-card payment-report-stat shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <div class="payment-report-label">
                                Average Payment
                            </div>

                            <div class="payment-report-value">
                                <?= report_rupiah($averagePayment) ?>
                            </div>

                            <div class="small text-secondary mt-1">
                                Per transaksi SUCCESS
                            </div>

                        </div>

                        <div class="payment-report-icon bg-info-subtle text-info">
                            <i class="bi bi-calculator"></i>
                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <div class="card payment-report-card shadow-sm mb-4">

        <div class="card-body">

            <form method="get">

                <div class="row g-2">

                    <div class="col-12 col-md-2">

                        <label class="form-label small fw-bold">
                            Dari
                        </label>

                        <input
                            type="date"
                            name="date_from"
                            class="form-control"
                            value="<?= report_h($dateFrom) ?>"
                        >

                    </div>

                    <div class="col-12 col-md-2">

                        <label class="form-label small fw-bold">
                            Sampai
                        </label>

                        <input
                            type="date"
                            name="date_to"
                            class="form-control"
                            value="<?= report_h($dateTo) ?>"
                        >

                    </div>

                    <div class="col-12 col-md-2">

                        <label class="form-label small fw-bold">
                            Status
                        </label>

                        <select
                            name="status"
                            class="form-select"
                        >

                            <option value="">
                                Semua Status
                            </option>

                            <?php foreach (
                                array_slice($allowedStatuses, 1)
                                as $item
                            ): ?>

                                <option
                                    value="<?= report_h($item) ?>"
                                    <?= $status === $item ? 'selected' : '' ?>
                                >
                                    <?= report_h($item) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-12 col-md-2">

                        <label class="form-label small fw-bold">
                            Metode
                        </label>

                        <select
                            name="method"
                            class="form-select"
                        >

                            <option value="">
                                Semua Metode
                            </option>

                            <?php foreach (
                                array_slice($allowedMethods, 1)
                                as $item
                            ): ?>

                                <option
                                    value="<?= report_h($item) ?>"
                                    <?= $method === $item ? 'selected' : '' ?>
                                >
                                    <?= report_h(
                                        report_method($item)
                                    ) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-12 col-md-3">

                        <label class="form-label small fw-bold">
                            Cari
                        </label>

                        <input
                            type="text"
                            name="q"
                            class="form-control"
                            placeholder="Invoice, pelanggan, referensi..."
                            value="<?= report_h($search) ?>"
                        >

                    </div>

                    <div class="col-12 col-md-1 d-flex align-items-end">

                        <button
                            type="submit"
                            class="btn btn-primary w-100"
                            title="Filter"
                        >
                            <i class="bi bi-funnel-fill"></i>
                        </button>

                    </div>

                </div>

                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">

                    <div class="small text-secondary">

                        Periode:
                        <strong>
                            <?= report_h($dateFrom) ?>
                        </strong>
                        —
                        <strong>
                            <?= report_h($dateTo) ?>
                        </strong>

                    </div>

                    <div class="d-flex gap-2">

                        <a
                            href="payment_reports.php"
                            class="btn btn-sm btn-outline-secondary"
                        >
                            <i class="bi bi-arrow-counterclockwise me-1"></i>
                            Reset
                        </a>

                        <a
                            href="?<?= http_build_query(
                                array_merge(
                                    $_GET,
                                    ['export' => 'csv']
                                )
                            ) ?>"
                            class="btn btn-sm btn-success"
                        >
                            <i class="bi bi-filetype-csv me-1"></i>
                            Export CSV
                        </a>

                    </div>

                </div>

            </form>

        </div>

    </div>

    <div class="card payment-report-card shadow-sm">

        <div class="card-body p-0">

            <div class="table-responsive bajama-table-responsive payment-reports-table-responsive">

                <table class="table payment-report-table table-hover mb-0 bajama-table" data-responsive-table="true">

                    <thead>

                        <tr>

                            <th data-label="Tanggal">Tanggal</th>

                            <th data-label="Invoice">Invoice</th>

                            <th data-label="Pelanggan">Pelanggan</th>

                            <th data-label="Metode">Metode</th>

                            <th data-label="Referensi">Referensi</th>

                            <th class="text-end" data-label="Jumlah">Jumlah</th>

                            <th data-label="Status">Status</th>

                            <th data-label="Aksi">Aksi</th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if (!$payments): ?>

                        <tr>

                            <td
                                colspan="8"
                                class="text-center py-5 text-secondary"
                            >

                                <i class="bi bi-receipt fs-1 d-block mb-2"></i>

                                Tidak ada pembayaran pada periode/filter ini.

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($payments as $payment): ?>

                            <tr class="bajama-table-row">

                                <td data-label="Tanggal">

                                    <strong>
                                        <?= report_h(
                                            date(
                                                'd/m/Y',
                                                strtotime(
                                                    $payment['paid_at'] ?: $payment['created_at']
                                                )
                                            )
                                        ) ?>
                                    </strong>

                                    <div class="small text-secondary">

                                        <?= report_h(
                                            date(
                                                'H:i',
                                                strtotime(
                                                    $payment['paid_at'] ?: $payment['created_at']
                                                )
                                            )
                                        ) ?>

                                    </div>

                                </td>

                                <td data-label="Invoice">

                                    <a
                                        href="invoice_view.php?invoice=<?= urlencode($payment['invoice_number']) ?>"
                                        class="fw-bold text-decoration-none"
                                    >
                                        <?= report_h(
                                            $payment['invoice_number']
                                        ) ?>
                                    </a>

                                </td>

                                <td data-label="Pelanggan">

                                    <div class="fw-bold">
                                        <?= report_h(
                                            $payment['customer_name']
                                        ) ?>
                                    </div>

                                    <div class="small text-secondary">
                                        <?= report_h(
                                            $payment['customer_code']
                                        ) ?>
                                    </div>

                                </td>

                                <td data-label="Metode">
                                    <?= report_h(
                                        report_method(
                                            $payment['payment_method']
                                        )
                                    ) ?>
                                </td>

                                <td data-label="Referensi">

                                    <div class="payment-reference">

                                        <?= report_h(
                                            $payment['reference_number'] ?: '-'
                                        ) ?>

                                    </div>

                                </td>

                                <td class="text-end" data-label="Jumlah">

                                    <strong>
                                        <?= report_rupiah(
                                            $payment['amount']
                                        ) ?>
                                    </strong>

                                </td>

                                <td data-label="Status">
                                    <?= report_status_badge(
                                        $payment['status']
                                    ) ?>
                                </td>

                                <td class="bajama-table-actions" data-label="Aksi">

                                    <a
                                        href="payment_view.php?id=<?= (int)$payment['id'] ?>"
                                        class="btn btn-sm btn-light border"
                                        title="Detail"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </a>

                                    <a
                                        href="payment_receipt.php?id=<?= (int)$payment['id'] ?>"
                                        class="btn btn-sm btn-light border"
                                        title="Kwitansi"
                                    >
                                        <i class="bi bi-printer"></i>
                                    </a>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

        <div class="px-3 py-3 border-top bg-light">

            <div class="d-flex flex-wrap justify-content-between gap-2 small text-secondary">

                <div>
                    Menampilkan
                    <strong><?= number_format(count($payments)) ?></strong>
                    transaksi.
                </div>

                <div>
                    Revenue:
                    <strong class="text-success">
                        <?= report_rupiah($successTotal) ?>
                    </strong>
                </div>

            </div>

        </div>

    </div>

</div>

<?php

$content = ob_get_clean();

$pageTitle = 'Payment Reports';

require __DIR__ . '/../app/layout/layout.php';
