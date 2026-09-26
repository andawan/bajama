<?php

require_once __DIR__ . '/../app/bootstrap.php';

/* BAJAMA_RBAC_ENFORCED */
\BAJAMA\Core\Auth::requireLogin();
\BAJAMA\Core\License::requireFeature(\db(), 'billing');
\BAJAMA\Core\RBAC::require(\db(), 'billing.view');

$canDeleteInvoices = \BAJAMA\Core\RBAC::hasPermission(
    \db(),
    'invoices.delete'
);


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
| LOAD ENV
|--------------------------------------------------------------------------
*/
$envFile = dirname(__DIR__) . '/.env';

if (!is_readable($envFile)) {
    http_response_code(500);
    exit('Konfigurasi aplikasi tidak ditemukan.');
}

$env = [];

foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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

/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/
try {

    $pdo = new PDO(
        'mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1') .
        ';port=' . ($env['DB_PORT'] ?? '3306') .
        ';dbname=' . ($env['DB_DATABASE'] ?? '') .
        ';charset=utf8mb4',
        $env['DB_USERNAME'] ?? '',
        $env['DB_PASSWORD'] ?? '',
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

} catch (PDOException $e) {

    error_log('BAJAMA invoices DB error: ' . $e->getMessage());

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
        o.email AS organization_email,
        o.phone AS organization_phone,
        o.currency,
        o.timezone
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canDeleteInvoices || ($_POST['action'] ?? '') !== 'delete') {
        http_response_code(403);
        exit('Akses pembatalan invoice ditolak.');
    }

    verify_csrf((string)($_POST['_csrf'] ?? ''));
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);

    $stmt = $pdo->prepare(
        'UPDATE invoices
         SET status = "CANCELLED"
         WHERE id = ?
           AND organization_id = ?
           AND status NOT IN ("PAID", "CANCELLED")'
    );
    $stmt->execute([$invoiceId, $organizationId]);

    if ($stmt->rowCount() > 0) {
        \BAJAMA\Core\Audit::log(
            $pdo,
            'INVOICE_CANCELLED',
            'billing',
            'invoice',
            $invoiceId
        );
    }

    header('Location: invoices.php?msg=' . urlencode('Invoice dibatalkan dan tercatat di audit.'));
    exit;
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function invoice_h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function invoice_rupiah($value): string
{
    return 'Rp ' . number_format(
        (float)$value,
        0,
        ',',
        '.'
    );
}

function invoiceStatusBadge(string $status): string
{
    switch ($status) {

        case 'PAID':
            return '
                <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-3 py-2">
                    <i class="bi bi-check-circle-fill me-1"></i>
                    PAID
                </span>
            ';

        case 'PARTIAL':
            return '
                <span class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-2">
                    <i class="bi bi-pie-chart-fill me-1"></i>
                    PARTIAL
                </span>
            ';

        case 'OVERDUE':
            return '
                <span class="badge rounded-pill bg-danger-subtle text-danger border border-danger-subtle px-3 py-2">
                    <i class="bi bi-exclamation-circle-fill me-1"></i>
                    OVERDUE
                </span>
            ';

        case 'CANCELLED':
            return '
                <span class="badge rounded-pill bg-secondary-subtle text-secondary border px-3 py-2">
                    <i class="bi bi-x-circle-fill me-1"></i>
                    CANCELLED
                </span>
            ';

        case 'DRAFT':
            return '
                <span class="badge rounded-pill bg-secondary-subtle text-secondary border px-3 py-2">
                    <i class="bi bi-file-earmark me-1"></i>
                    DRAFT
                </span>
            ';

        default:
            return '
                <span class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-2">
                    <i class="bi bi-clock-fill me-1"></i>
                    UNPAID
                </span>
            ';
    }
}

function invoiceStatusIcon(string $status): string
{
    switch ($status) {

        case 'PAID':
            return 'bi-check-circle-fill';

        case 'OVERDUE':
            return 'bi-exclamation-triangle-fill';

        case 'PARTIAL':
            return 'bi-pie-chart-fill';

        case 'CANCELLED':
            return 'bi-x-circle-fill';

        default:
            return 'bi-clock-fill';
    }
}

/*
|--------------------------------------------------------------------------
| FILTER
|--------------------------------------------------------------------------
*/
$search = trim($_GET['q'] ?? '');
$status = strtoupper(trim($_GET['status'] ?? ''));

$allowedStatuses = [
    'DRAFT',
    'UNPAID',
    'PARTIAL',
    'PAID',
    'OVERDUE',
    'CANCELLED'
];

/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/
$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_invoice,

        SUM(
            CASE
                WHEN status IN ('UNPAID','PARTIAL')
                THEN 1
                ELSE 0
            END
        ) AS unpaid_count,

        SUM(
            CASE
                WHEN status = 'OVERDUE'
                THEN 1
                ELSE 0
            END
        ) AS overdue_count,

        SUM(
            CASE
                WHEN status = 'PAID'
                THEN 1
                ELSE 0
            END
        ) AS paid_count,

        COALESCE(
            SUM(
                CASE
                    WHEN status IN ('UNPAID','PARTIAL','OVERDUE')
                    THEN total
                    ELSE 0
                END
            ),
            0
        ) AS outstanding_total,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'PAID'
                    THEN total
                    ELSE 0
                END
            ),
            0
        ) AS paid_total,

        COALESCE(SUM(total), 0) AS grand_total

    FROM invoices
    WHERE organization_id = ?
");

$summaryStmt->execute([$organizationId]);

$summary = $summaryStmt->fetch();

$totalInvoice     = (int)($summary['total_invoice'] ?? 0);
$unpaidCount      = (int)($summary['unpaid_count'] ?? 0);
$overdueCount     = (int)($summary['overdue_count'] ?? 0);
$paidCount        = (int)($summary['paid_count'] ?? 0);
$outstandingTotal = (float)($summary['outstanding_total'] ?? 0);
$paidTotal        = (float)($summary['paid_total'] ?? 0);
$grandTotal       = (float)($summary['grand_total'] ?? 0);

/*
|--------------------------------------------------------------------------
| CURRENT MONTH SUMMARY
|--------------------------------------------------------------------------
*/
$monthStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(total), 0) AS amount
    FROM invoices
    WHERE organization_id = ?
      AND DATE_FORMAT(issue_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
");

$monthStmt->execute([$organizationId]);

$currentMonth = $monthStmt->fetch();

$currentMonthCount  = (int)($currentMonth['total'] ?? 0);
$currentMonthAmount = (float)($currentMonth['amount'] ?? 0);

/*
|--------------------------------------------------------------------------
| INVOICE LIST
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        i.id,
        i.invoice_number,
        i.issue_date,
        i.due_date,
        i.period_start,
        i.period_end,
        i.subtotal,
        i.discount,
        i.total,
        i.status,
        i.description,
        i.router_id,
        r.name AS router_name,

        c.name AS customer_name,
        c.customer_code,
        c.phone AS customer_phone,
        c.email AS customer_email,

        s.id AS subscription_id,
        s.service_name,
        s.service_type,
        s.username AS service_username,
        s.speed_download,
        s.speed_upload,
        s.billing_cycle

    FROM invoices i

    INNER JOIN customers c
        ON c.id = i.customer_id
       AND c.organization_id = i.organization_id

    LEFT JOIN subscriptions s
        ON s.id = i.subscription_id
       AND s.organization_id = i.organization_id

    LEFT JOIN mikrotik_routers r
        ON r.id = i.router_id
       AND r.organization_id = i.organization_id

    WHERE i.organization_id = ?
";

$params = [$organizationId];

if ($search !== '') {

    $sql .= "
        AND (
            i.invoice_number LIKE ?
            OR c.name LIKE ?
            OR c.customer_code LIKE ?
            OR c.phone LIKE ?
            OR s.service_name LIKE ?
            OR s.username LIKE ?
        )
    ";

    $like = '%' . $search . '%';

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if (in_array($status, $allowedStatuses, true)) {

    $sql .= " AND i.status = ? ";

    $params[] = $status;
}

$sql .= "
    ORDER BY
        CASE
            WHEN i.status = 'OVERDUE' THEN 1
            WHEN i.status = 'UNPAID' THEN 2
            WHEN i.status = 'PARTIAL' THEN 3
            WHEN i.status = 'DRAFT' THEN 4
            WHEN i.status = 'PAID' THEN 5
            ELSE 6
        END,
        i.id DESC
    LIMIT 200
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$invoices = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| CONTENT BUFFER
|--------------------------------------------------------------------------
*/
ob_start();

?>

<style>
/* =========================================================
   BAJAMA INVOICE PAGE
   ========================================================= */

.invoice-page {
    width: 100%;
    max-width: 100%;
}

.invoice-page .page-title {
    font-size: 28px;
    font-weight: 800;
    letter-spacing: -0.5px;
}

.invoice-page .page-subtitle {
    color: #64748b;
    font-size: 14px;
}

/* Summary cards */

.invoice-summary-card {
    position: relative;
    overflow: hidden;
    min-height: 135px;
    border: 1px solid #e2e8f0 !important;
    border-radius: 16px !important;
    background: #fff;
    transition: transform .2s ease, box-shadow .2s ease;
}

.invoice-summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 30px rgba(15,23,42,.08) !important;
}

.invoice-summary-card .summary-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 21px;
}

.invoice-summary-card .summary-label {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .6px;
    color: #64748b;
}

.invoice-summary-card .summary-value {
    font-size: 24px;
    font-weight: 800;
    color: #0f172a;
    margin-top: 5px;
}

.invoice-summary-card .summary-footer {
    margin-top: 8px;
    font-size: 12px;
    color: #94a3b8;
}

/* Main card */

.invoice-main-card {
    border: 1px solid #e2e8f0 !important;
    border-radius: 16px !important;
    overflow: hidden;
}

.invoice-toolbar {
    padding: 20px;
    background: #fff;
    border-bottom: 1px solid #e2e8f0;
}

.invoice-toolbar-title {
    font-size: 15px;
    font-weight: 750;
}

.invoice-toolbar-subtitle {
    color: #94a3b8;
    font-size: 12px;
}

/* Table */

.invoice-table-wrap {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.invoice-table {
    width: 100%;
    min-width: 1150px;
    margin: 0;
}

.invoice-table thead th {
    background: #f8fafc;
    color: #64748b;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .5px;
    font-weight: 800;
    white-space: nowrap;
    border-bottom: 1px solid #e2e8f0;
    padding: 15px 14px;
}

.invoice-table tbody td {
    padding: 16px 14px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}

.invoice-table tbody tr {
    transition: background .15s ease;
}

.invoice-table tbody tr:hover {
    background: #f8fafc;
}

.invoice-number {
    font-weight: 800;
    color: #0f172a;
    font-size: 13px;
}

.invoice-date {
    color: #94a3b8;
    font-size: 11px;
    margin-top: 3px;
}

.customer-name {
    font-weight: 700;
    color: #1e293b;
}

.customer-code {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 3px;
}

.service-name {
    font-weight: 650;
    color: #334155;
}

.service-meta {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 3px;
}

.period-text {
    font-size: 12px;
    color: #475569;
    white-space: nowrap;
}

.period-separator {
    color: #94a3b8;
    margin: 0 5px;
}

.invoice-total {
    font-size: 14px;
    font-weight: 800;
    color: #0f172a;
    white-space: nowrap;
}

.due-date {
    font-size: 12px;
    font-weight: 650;
    color: #475569;
    white-space: nowrap;
}

.invoice-actions {
    display: flex;
    gap: 5px;
    white-space: nowrap;
}

.invoice-actions .btn {
    width: 34px;
    height: 34px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
}

/* Empty */

.invoice-empty {
    padding: 70px 20px;
    text-align: center;
}

.invoice-empty-icon {
    width: 70px;
    height: 70px;
    margin: 0 auto 16px;
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f1f5f9;
    color: #94a3b8;
    font-size: 30px;
}

/* Bottom info */

.invoice-info-card {
    border: 1px solid #e2e8f0 !important;
    border-radius: 16px !important;
    background: #fff;
}

.invoice-info-card .info-title {
    font-weight: 800;
    color: #0f172a;
}

.invoice-info-card .info-text {
    font-size: 13px;
    color: #64748b;
    line-height: 1.7;
}

/* Modal */

.invoice-modal-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #94a3b8;
    font-weight: 800;
}

.invoice-modal-value {
    font-weight: 700;
    color: #1e293b;
}

.invoice-detail-total {
    border-radius: 14px;
    padding: 18px;
    background: #f8fafc;
}

.invoice-detail-total .amount {
    font-size: 24px;
    font-weight: 850;
    color: #0f172a;
}

/* Responsive */

@media (max-width: 991px) {

    .invoice-page .page-title {
        font-size: 23px;
    }

    .invoice-summary-card .summary-value {
        font-size: 20px;
    }

    .invoice-toolbar {
        padding: 15px;
    }

}

@media (max-width: 575px) {

    .invoice-summary-card {
        min-height: 115px;
    }

}

/* =========================================================
   BILLING WORKSPACE — responsive desktop + mobile treatment
   Scoped to invoices so legacy pages keep their current theme.
   ========================================================= */
.invoice-page {
    --billing-ink: #172554;
    --billing-muted: #64748b;
    --billing-line: #e6ebf3;
    --billing-accent: #4f46e5;
    --billing-mint: #10b981;
}

.invoice-page > .invoice-heading {
    padding: 22px 24px;
    margin-bottom: 20px !important;
    border: 1px solid var(--billing-line);
    border-radius: 20px;
    background:
        radial-gradient(circle at 100% 0, rgba(99, 102, 241, .11), transparent 34%),
        #fff;
    box-shadow: 0 8px 24px rgba(23, 37, 84, .04);
}

.invoice-heading .page-title {
    color: var(--billing-ink);
    font-size: clamp(22px, 2vw, 29px);
    letter-spacing: -.7px;
}

.invoice-heading .page-title i {
    color: var(--billing-accent);
}

.invoice-heading .page-subtitle {
    color: var(--billing-muted);
}

.invoice-heading .btn {
    min-height: 42px;
    border-radius: 11px;
    font-weight: 650;
}

.invoice-summary-card,
.invoice-main-card,
.invoice-info-card {
    border-color: var(--billing-line) !important;
    border-radius: 18px !important;
    box-shadow: 0 8px 24px rgba(23, 37, 84, .045) !important;
}

.invoice-summary-card {
    min-height: 142px;
}

.invoice-summary-card .summary-icon {
    border-radius: 15px;
}

.invoice-summary-card .summary-label {
    color: #718096;
    letter-spacing: .08em;
}

.invoice-summary-card .summary-value {
    color: var(--billing-ink);
    letter-spacing: -.6px;
}

.invoice-main-card {
    overflow: hidden;
}

.invoice-toolbar {
    padding: 22px 24px;
    background: linear-gradient(180deg, #fff, #fbfcff);
    border-bottom-color: var(--billing-line);
}

.invoice-toolbar-title {
    color: var(--billing-ink);
    font-size: 16px;
}

.invoice-toolbar .form-control,
.invoice-toolbar .form-select,
.invoice-toolbar .input-group-text,
.invoice-toolbar .btn {
    min-height: 43px;
    border-color: #dfe5ef;
    border-radius: 11px;
}

.invoice-toolbar .input-group-text {
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
}

.invoice-toolbar .input-group .form-control {
    border-top-left-radius: 0;
    border-bottom-left-radius: 0;
}

.invoice-table thead th {
    padding-top: 14px;
    padding-bottom: 14px;
    background: #f6f8fc;
    color: #64748b;
}

.invoice-table tbody td {
    padding-top: 17px;
    padding-bottom: 17px;
    border-bottom-color: #edf0f5;
}

.invoice-table tbody tr:hover {
    background: #f8faff;
}

.invoice-number,
.invoice-total {
    color: var(--billing-ink);
}

.invoice-actions .btn {
    width: 37px;
    height: 37px;
    border-radius: 10px;
}

.invoice-main-card > .border-top.bg-light {
    background: #fbfcff !important;
}

@media (max-width: 767.98px) {
    .invoice-page > .invoice-heading {
        padding: 18px;
        border-radius: 17px;
    }

    .invoice-page > .invoice-heading > .d-flex {
        align-items: flex-start !important;
    }

    .invoice-heading .page-subtitle {
        max-width: 34ch;
        line-height: 1.5;
    }

    .invoice-heading > div:last-child {
        width: 100%;
    }

    .invoice-heading > div:last-child .btn {
        flex: 1 1 auto;
        justify-content: center;
    }

    .invoice-summary-card {
        min-height: 124px;
        border-radius: 16px !important;
    }

    .invoice-summary-card .summary-value {
        font-size: clamp(17px, 5vw, 21px);
        overflow-wrap: anywhere;
    }

    .invoice-toolbar {
        padding: 17px;
    }

    .invoice-toolbar .form-control,
    .invoice-toolbar .form-select,
    .invoice-toolbar .btn {
        min-height: 45px;
    }

    /* Mobile: show the task-critical fields as a compact invoice card. */
    .invoice-page .invoice-table {
        min-width: 0;
    }

    .invoice-table tbody tr.bajama-table-row {
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) auto;
        grid-template-areas:
            "invoice status"
            "customer customer"
            "due total"
            "actions actions";
        gap: 12px 14px;
        margin: 0 12px 12px;
        padding: 16px;
        border: 1px solid var(--billing-line);
        border-radius: 16px;
        box-shadow: 0 5px 16px rgba(23, 37, 84, .055);
    }

    .invoice-table tbody tr.bajama-table-row > td {
        min-width: 0;
        padding: 0 !important;
        margin: 0 !important;
    }

    .invoice-table tbody tr.bajama-table-row > td::before {
        display: block;
        margin: 0 0 4px;
        color: #8390a5;
        font-size: 9px;
        letter-spacing: .08em;
    }

    .invoice-table tbody tr.bajama-table-row > td[data-label="Invoice"] {
        grid-area: invoice;
    }

    .invoice-table tbody tr.bajama-table-row > td[data-label="Invoice"]::before,
    .invoice-table tbody tr.bajama-table-row > td[data-label="Pelanggan"]::before,
    .invoice-table tbody tr.bajama-table-row > td[data-label="Status"]::before {
        display: none;
    }

    .invoice-table tbody tr.bajama-table-row > td[data-label="Pelanggan"] {
        grid-area: customer;
        padding-top: 11px !important;
        border-top: 1px solid #edf0f5;
    }

    .invoice-table tbody tr.bajama-table-row > td[data-label="Layanan"],
    .invoice-table tbody tr.bajama-table-row > td[data-label="Periode"] {
        display: none !important;
    }

    .invoice-table tbody tr.bajama-table-row > td[data-label="Jatuh Tempo"] {
        grid-area: due;
        align-self: end;
    }

    .invoice-table tbody tr.bajama-table-row > td[data-label="Total"] {
        grid-area: total;
        text-align: right !important;
        align-self: end;
    }

    .invoice-table tbody tr.bajama-table-row > td[data-label="Status"] {
        grid-area: status;
        justify-self: end;
        align-self: start;
    }

    .invoice-table tbody tr.bajama-table-row > td[data-label="Aksi"] {
        grid-area: actions;
        padding-top: 12px !important;
        border-top: 1px solid #edf0f5;
    }

    .invoice-table tbody tr.bajama-table-row .invoice-total {
        font-size: 15px;
    }

    .invoice-table tbody tr.bajama-table-row .invoice-actions {
        justify-content: flex-end;
    }

    .invoice-table tbody tr.bajama-table-row .invoice-actions .btn {
        width: 42px;
        height: 42px;
    }

    .invoice-main-card > .border-top.bg-light {
        padding: 13px 16px !important;
    }
}

@media (max-width: 380px) {
    .invoice-page > .invoice-heading {
        padding: 15px;
    }

    .invoice-summary-card .card-body {
        padding: 14px;
    }

    .invoice-summary-card .summary-icon {
        width: 39px;
        height: 39px;
        font-size: 17px;
    }
}
</style>

<div class="invoice-page">

    <!-- =====================================================
         HEADER
         ===================================================== -->

    <div class="invoice-heading d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

        <div>
            <div class="page-title">
                <i class="bi bi-receipt-cutoff me-2"></i>
                Invoices
            </div>

            <div class="page-subtitle mt-1">
                Kelola invoice pelanggan dan tagihan subscription BAJAMA.
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2">

            <a
                href="subscriptions.php"
                class="btn btn-outline-secondary"
            >
                <i class="bi bi-box-arrow-up-right me-1"></i>
                Subscriptions
            </a>

            <a
                href="customers.php"
                class="btn btn-primary"
            >
                <i class="bi bi-people-fill me-1"></i>
                Customers
            </a>

        </div>

    </div>

    <!-- =====================================================
         SUMMARY
         ===================================================== -->

    <div class="row g-3 mb-4">

        <!-- Total -->

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card invoice-summary-card shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between align-items-start">

                        <div>

                            <div class="summary-label">
                                Total Invoice
                            </div>

                            <div class="summary-value">
                                <?= number_format($totalInvoice) ?>
                            </div>

                        </div>

                        <div class="summary-icon bg-primary-subtle text-primary">
                            <i class="bi bi-receipt"></i>
                        </div>

                    </div>

                    <div class="summary-footer">
                        Semua invoice organisasi
                    </div>

                </div>

            </div>

        </div>

        <!-- Outstanding -->

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card invoice-summary-card shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between align-items-start">

                        <div>

                            <div class="summary-label">
                                Outstanding
                            </div>

                            <div class="summary-value">
                                <?= invoice_rupiah($outstandingTotal) ?>
                            </div>

                        </div>

                        <div class="summary-icon bg-warning-subtle text-warning-emphasis">
                            <i class="bi bi-clock-history"></i>
                        </div>

                    </div>

                    <div class="summary-footer">
                        <?= number_format($unpaidCount) ?> invoice belum lunas
                    </div>

                </div>

            </div>

        </div>

        <!-- Overdue -->

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card invoice-summary-card shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between align-items-start">

                        <div>

                            <div class="summary-label">
                                Overdue
                            </div>

                            <div class="summary-value text-danger">
                                <?= number_format($overdueCount) ?>
                            </div>

                        </div>

                        <div class="summary-icon bg-danger-subtle text-danger">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                        </div>

                    </div>

                    <div class="summary-footer">
                        Invoice melewati jatuh tempo
                    </div>

                </div>

            </div>

        </div>

        <!-- Paid -->

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card invoice-summary-card shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between align-items-start">

                        <div>

                            <div class="summary-label">
                                Paid
                            </div>

                            <div class="summary-value text-success">
                                <?= invoice_rupiah($paidTotal) ?>
                            </div>

                        </div>

                        <div class="summary-icon bg-success-subtle text-success">
                            <i class="bi bi-check-circle-fill"></i>
                        </div>

                    </div>

                    <div class="summary-footer">
                        <?= number_format($paidCount) ?> invoice sudah lunas
                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- =====================================================
         FILTER / TABLE
         ===================================================== -->

    <div class="card invoice-main-card shadow-sm">

        <!-- Toolbar -->

        <div class="invoice-toolbar">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">

                <div>

                    <div class="invoice-toolbar-title">
                        Daftar Invoice
                    </div>

                    <div class="invoice-toolbar-subtitle">
                        Menampilkan maksimal 200 invoice terbaru.
                    </div>

                </div>

                <div class="small text-secondary">

                    <i class="bi bi-calendar3 me-1"></i>

                    Bulan ini:
                    <strong>
                        <?= number_format($currentMonthCount) ?>
                    </strong>
                    invoice

                    <span class="mx-1">�</span>

                    <strong>
                        <?= invoice_rupiah($currentMonthAmount) ?>
                    </strong>

                </div>

            </div>

            <form
                method="get"
                action="invoices.php"
                class="row g-2"
            >

                <div class="col-12 col-lg-5">

                    <div class="input-group">

                        <span class="input-group-text bg-white">
                            <i class="bi bi-search"></i>
                        </span>

                        <input
                            type="text"
                            name="q"
                            class="form-control"
                            placeholder="Cari invoice, pelanggan, kode, layanan..."
                            value="<?= invoice_h($search) ?>"
                        >

                    </div>

                </div>

                <div class="col-12 col-sm-6 col-lg-3">

                    <select
                        name="status"
                        class="form-select"
                    >

                        <option value="">
                            Semua Status
                        </option>

                        <?php foreach ($allowedStatuses as $item): ?>

                            <option
                                value="<?= invoice_h($item) ?>"
                                <?= $status === $item ? 'selected' : '' ?>
                            >
                                <?= invoice_h($item) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-6 col-sm-3 col-lg-2">

                    <button
                        type="submit"
                        class="btn btn-primary w-100"
                    >
                        <i class="bi bi-funnel-fill me-1"></i>
                        Filter
                    </button>

                </div>

                <div class="col-6 col-sm-3 col-lg-2">

                    <a
                        href="invoices.php"
                        class="btn btn-outline-secondary w-100"
                    >
                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                        Reset
                    </a>

                </div>

            </form>

        </div>

        <!-- Table -->

        <div class="invoice-table-wrap bajama-table-responsive invoices-table-responsive">

            <table class="table invoice-table align-middle bajama-table" data-responsive-table="true">

                <thead>

                    <tr>

                        <th style="width:190px;" data-label="Invoice">
                            Invoice
                        </th>

                        <th style="width:220px;" data-label="Pelanggan">
                            Pelanggan
                        </th>

                        <th style="width:210px;" data-label="Layanan">
                            Layanan
                        </th>

                        <th style="width:210px;" data-label="Periode">
                            Periode
                        </th>

                        <th style="width:130px;" data-label="Jatuh Tempo">
                            Jatuh Tempo
                        </th>

                        <th style="width:150px;" data-label="Total">
                            Total
                        </th>

                        <th style="width:125px;" data-label="Status">
                            Status
                        </th>

                        <th style="width:80px;" data-label="Aksi">
                            Aksi
                        </th>

                    </tr>

                </thead>

                <tbody>

                <?php if (!$invoices): ?>

                    <tr>

                        <td colspan="8">

                            <div class="invoice-empty">

                                <div class="invoice-empty-icon">
                                    <i class="bi bi-receipt"></i>
                                </div>

                                <h5 class="fw-bold mb-2">
                                    Belum ada invoice
                                </h5>

                                <div class="text-secondary small mb-3">
                                    Tidak ada invoice yang sesuai dengan filter saat ini.
                                </div>

                                <?php if ($search !== '' || $status !== ''): ?>

                                    <a
                                        href="invoices.php"
                                        class="btn btn-outline-primary btn-sm"
                                    >
                                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                                        Hapus Filter
                                    </a>

                                <?php endif; ?>

                            </div>

                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($invoices as $invoice): ?>

                        <?php

                        $invoiceJson = htmlspecialchars(
                            json_encode(
                                [
                                    'invoice_number'  => $invoice['invoice_number'],
                                    'issue_date'      => $invoice['issue_date'],
                                    'due_date'        => $invoice['due_date'],
                                    'period_start'    => $invoice['period_start'],
                                    'period_end'      => $invoice['period_end'],
                                    'subtotal'        => $invoice['subtotal'],
                                    'discount'        => $invoice['discount'],
                                    'total'           => $invoice['total'],
                                    'status'          => $invoice['status'],
                                    'description'     => $invoice['description'],
                                    'customer_name'   => $invoice['customer_name'],
                                    'customer_code'   => $invoice['customer_code'],
                                    'customer_phone'  => $invoice['customer_phone'],
                                    'customer_email'  => $invoice['customer_email'],
                                    'service_name'    => $invoice['service_name'],
                                    'service_type'    => $invoice['service_type'],
                                    'service_username'=> $invoice['service_username'],
                                      'router_name'     => $invoice['router_name'],
                                      'router_id'       => $invoice['router_id'],
                                    'speed_download' => $invoice['speed_download'],
                                    'speed_upload'   => $invoice['speed_upload'],
                                    'billing_cycle'  => $invoice['billing_cycle'],
                                ],
                                JSON_UNESCAPED_UNICODE
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        );

                        ?>

                        <tr class="bajama-table-row">

                            <!-- Invoice -->

                            <td data-label="Invoice">

                                <div class="invoice-number">
                                    <?= invoice_h($invoice['invoice_number']) ?>
                                </div>

                                <div class="invoice-date">
                                    <i class="bi bi-calendar3 me-1"></i>
                                    <?= invoice_h($invoice['issue_date']) ?>
                                </div>

                            </td>

                            <!-- Customer -->

                            <td data-label="Pelanggan">

                                <div class="customer-name">
                                    <?= invoice_h($invoice['customer_name']) ?>
                                </div>

                                <div class="customer-code">
                                    <?= invoice_h($invoice['customer_code']) ?>
                                </div>

                            </td>

                            <!-- Service -->

                            <td data-label="Layanan">

                                <div class="service-name">

                                    <?= invoice_h(
                                        $invoice['service_name'] ?: '-'
                                    ) ?>

                                </div>

                                <div class="service-meta">

                                    <?php if (!empty($invoice['service_type'])): ?>

                                        <span>
                                            <?= invoice_h($invoice['service_type']) ?>
                                        </span>

                                    <?php endif; ?>

                                    <?php if (!empty($invoice['service_username'])): ?>

                                        <span class="ms-1">
                                            � <?= invoice_h($invoice['service_username']) ?>
                                        </span>

                                    <?php endif; ?>

                                      <span class="ms-1 <?= empty($invoice['router_id']) ? 'text-danger' : 'text-muted' ?>">
                                          <i class="bi bi-router me-1"></i>
                                          <?= invoice_h($invoice['router_name'] ?: 'Router belum dipetakan') ?>
                                      </span>

                                </div>

                            </td>

                            <!-- Period -->

                            <td data-label="Periode">

                                <div class="period-text">

                                    <?= invoice_h(
                                        $invoice['period_start'] ?: '-'
                                    ) ?>

                                    <span class="period-separator">
                                        ?
                                    </span>

                                    <?= invoice_h(
                                        $invoice['period_end'] ?: '-'
                                    ) ?>

                                </div>

                                <?php if (!empty($invoice['billing_cycle'])): ?>

                                    <div class="service-meta">

                                        <i class="bi bi-repeat me-1"></i>

                                        <?= invoice_h(
                                            $invoice['billing_cycle']
                                        ) ?>

                                    </div>

                                <?php endif; ?>

                            </td>

                            <!-- Due -->

                            <td data-label="Jatuh Tempo">

                                <div class="due-date">

                                    <i class="bi bi-calendar-event me-1"></i>

                                    <?= invoice_h(
                                        $invoice['due_date']
                                    ) ?>

                                </div>

                            </td>

                            <!-- Total -->

                            <td data-label="Total">

                                <div class="invoice-total">
                                    <?= invoice_rupiah($invoice['total']) ?>
                                </div>

                                <?php if ((float)$invoice['discount'] > 0): ?>

                                    <div class="service-meta">
                                        Diskon:
                                        <?= invoice_rupiah($invoice['discount']) ?>
                                    </div>

                                <?php endif; ?>

                            </td>

                            <!-- Status -->

                            <td data-label="Status">

                                <?= invoiceStatusBadge(
                                    $invoice['status']
                                ) ?>

                            </td>

                            <!-- Actions -->

<!-- Actions -->

<td class="bajama-table-actions" data-label="Aksi">

    <div class="invoice-actions">

        <?php if (
            in_array(
                $invoice['status'],
                ['UNPAID', 'PARTIAL'],
                true
            )
        ): ?>

            <a
                href="payment_form.php?invoice=<?= urlencode($invoice['invoice_number']) ?>"
                class="btn btn-success"
                title="Bayar Invoice"
            >
                <i class="bi bi-credit-card-fill"></i>
            </a>

        <?php endif; ?>

        <button
            type="button"
            class="btn btn-light border"
            title="Lihat Detail"
            data-invoice="<?= $invoiceJson ?>"
            onclick="showInvoiceDetail(this)"
        >
            <i class="bi bi-eye"></i>
        </button>

        <button
            type="button"
            class="btn btn-light border"
            title="Cetak"
            onclick="printInvoice('<?= invoice_h($invoice['invoice_number']) ?>')"
        >
            <i class="bi bi-printer"></i>
        </button>

          <?php if ($canDeleteInvoices && !in_array($invoice['status'], ['PAID', 'CANCELLED'], true)): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Batalkan invoice ini? Tindakan akan dicatat di audit.');">
                  <input type="hidden" name="_csrf" value="<?= invoice_h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>">
                  <button type="submit" class="btn btn-outline-danger" title="Batalkan invoice">
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

        <!-- Table footer -->

        <div class="px-3 py-3 border-top bg-light">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">

                <div class="small text-secondary">

                    <i class="bi bi-info-circle me-1"></i>

                    Menampilkan
                    <strong><?= number_format(count($invoices)) ?></strong>
                    invoice.

                </div>

                <div class="small text-secondary">

                    Total invoice:
                    <strong class="text-dark">
                        <?= invoice_rupiah($grandTotal) ?>
                    </strong>

                </div>

            </div>

        </div>

    </div>

    <!-- =====================================================
         INFORMATION
         ===================================================== -->

    <div class="row g-3 mt-1">

        <div class="col-12 col-lg-8">

            <div class="card invoice-info-card shadow-sm">

                <div class="card-body">

                    <div class="d-flex gap-3">

                        <div class="text-primary fs-4">
                            <i class="bi bi-robot"></i>
                        </div>

                        <div>

                            <div class="info-title mb-1">
                                Automatic Billing
                            </div>

                            <div class="info-text">
                                Invoice dibuat otomatis berdasarkan subscription
                                pelanggan yang sudah mencapai tanggal jatuh tempo.
                                Sistem menggunakan harga dan periode subscription
                                sebagai dasar pembuatan invoice.
                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-12 col-lg-4">

            <div class="card invoice-info-card shadow-sm">

                <div class="card-body">

                    <div class="info-title mb-2">
                        <?= invoice_h($organization['organization_name']) ?>
                    </div>

                    <div class="info-text">

                        <div>
                            <i class="bi bi-clock me-1"></i>
                            <?= invoice_h(
                                $organization['timezone'] ?: 'Asia/Makassar'
                            ) ?>
                        </div>

                        <div class="mt-1">
                            <i class="bi bi-currency-exchange me-1"></i>
                            <?= invoice_h(
                                $organization['currency'] ?: 'IDR'
                            ) ?>
                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

<!-- =========================================================
     DETAIL MODAL
     ========================================================= -->

<div
    class="modal fade"
    id="invoiceDetailModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content border-0 shadow-lg">

            <div class="modal-header">

                <div>

                    <div class="modal-title fw-bold">
                        Invoice Detail
                    </div>

                    <div
                        class="small text-secondary"
                        id="modalInvoiceNumber"
                    >
                    </div>

                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <div class="modal-body">

                <div class="row g-4">

                    <div class="col-md-7">

                        <div class="invoice-modal-label mb-1">
                            Customer
                        </div>

                        <div
                            class="invoice-modal-value mb-3"
                            id="modalCustomer"
                        >
                        </div>

                        <div class="row g-3">

                            <div class="col-6">

                                <div class="invoice-modal-label">
                                    Customer Code
                                </div>

                                <div
                                    class="invoice-modal-value"
                                    id="modalCustomerCode"
                                >
                                </div>

                            </div>

                            <div class="col-6">

                                <div class="invoice-modal-label">
                                    Service Type
                                </div>

                                <div
                                    class="invoice-modal-value"
                                    id="modalServiceType"
                                >
                                </div>

                            </div>

                            <div class="col-12">

                                <div class="invoice-modal-label">
                                    Service
                                </div>

                                <div
                                    class="invoice-modal-value"
                                    id="modalService"
                                >
                                </div>

                            </div>

                            <div class="col-12">

                                <div class="invoice-modal-label">
                                    Username
                                </div>

                                <div
                                    class="invoice-modal-value"
                                    id="modalUsername"
                                >
                                </div>

                            </div>

                        </div>

                    </div>

                    <div class="col-md-5">

                        <div class="invoice-detail-total">

                            <div class="invoice-modal-label mb-1">
                                Total Invoice
                            </div>

                            <div
                                class="amount mb-2"
                                id="modalTotal"
                            >
                            </div>

                            <div id="modalStatus"></div>

                        </div>

                    </div>

                </div>

                <hr class="my-4">

                <div class="row g-3">

                    <div class="col-md-4">

                        <div class="invoice-modal-label">
                            Issue Date
                        </div>

                        <div
                            class="invoice-modal-value"
                            id="modalIssueDate"
                        >
                        </div>

                    </div>

                    <div class="col-md-4">

                        <div class="invoice-modal-label">
                            Due Date
                        </div>

                        <div
                            class="invoice-modal-value"
                            id="modalDueDate"
                        >
                        </div>

                    </div>

                    <div class="col-md-4">

                        <div class="invoice-modal-label">
                            Billing Cycle
                        </div>

                        <div
                            class="invoice-modal-value"
                            id="modalBillingCycle"
                        >
                        </div>

                    </div>

                    <div class="col-md-6">

                        <div class="invoice-modal-label">
                            Period Start
                        </div>

                        <div
                            class="invoice-modal-value"
                            id="modalPeriodStart"
                        >
                        </div>

                    </div>

                    <div class="col-md-6">

                        <div class="invoice-modal-label">
                            Period End
                        </div>

                        <div
                            class="invoice-modal-value"
                            id="modalPeriodEnd"
                        >
                        </div>

                    </div>

                </div>

                <hr class="my-4">

                <div>

                    <div class="invoice-modal-label mb-1">
                        Description
                    </div>

                    <div
                        class="text-secondary"
                        id="modalDescription"
                    >
                    </div>

                </div>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    data-bs-dismiss="modal"
                >
                    Tutup
                </button>

                <button
                    type="button"
                    class="btn btn-primary"
                    onclick="printInvoiceFromModal()"
                >
                    <i class="bi bi-printer me-1"></i>
                    Cetak Invoice
                </button>

            </div>

        </div>

    </div>

</div>

<script>

let currentInvoice = null;

function rupiah(value)
{
    const number = Number(value || 0);

    return 'Rp ' + number.toLocaleString(
        'id-ID',
        {
            maximumFractionDigits: 0
        }
    );
}

function safeValue(value)
{
    if (
        value === null ||
        value === undefined ||
        value === ''
    ) {
        return '-';
    }

    return value;
}

function showInvoiceDetail(button)
{
    try {

        currentInvoice = JSON.parse(
            button.getAttribute('data-invoice')
        );

        document.getElementById('modalInvoiceNumber').textContent =
            safeValue(currentInvoice.invoice_number);

        document.getElementById('modalCustomer').textContent =
            safeValue(currentInvoice.customer_name);

        document.getElementById('modalCustomerCode').textContent =
            safeValue(currentInvoice.customer_code);

        document.getElementById('modalServiceType').textContent =
            safeValue(currentInvoice.service_type);

        document.getElementById('modalService').textContent =
            safeValue(currentInvoice.service_name);

        document.getElementById('modalUsername').textContent =
            safeValue(currentInvoice.service_username);

        document.getElementById('modalIssueDate').textContent =
            safeValue(currentInvoice.issue_date);

        document.getElementById('modalDueDate').textContent =
            safeValue(currentInvoice.due_date);

        document.getElementById('modalBillingCycle').textContent =
            safeValue(currentInvoice.billing_cycle);

        document.getElementById('modalPeriodStart').textContent =
            safeValue(currentInvoice.period_start);

        document.getElementById('modalPeriodEnd').textContent =
            safeValue(currentInvoice.period_end);

        document.getElementById('modalTotal').textContent =
            rupiah(currentInvoice.total);

        document.getElementById('modalDescription').textContent =
            safeValue(currentInvoice.description);

        document.getElementById('modalStatus').innerHTML =
            getStatusBadge(currentInvoice.status);

        const modalElement =
            document.getElementById('invoiceDetailModal');

        const modal =
            bootstrap.Modal.getOrCreateInstance(modalElement);

        modal.show();

    } catch (error) {

        console.error(error);

        alert('Detail invoice tidak dapat dibuka.');

    }
}

function getStatusBadge(status)
{
    switch (status) {

        case 'PAID':
            return '<span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-3 py-2"><i class="bi bi-check-circle-fill me-1"></i>PAID</span>';

        case 'PARTIAL':
            return '<span class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-2"><i class="bi bi-pie-chart-fill me-1"></i>PARTIAL</span>';

        case 'OVERDUE':
            return '<span class="badge rounded-pill bg-danger-subtle text-danger border border-danger-subtle px-3 py-2"><i class="bi bi-exclamation-circle-fill me-1"></i>OVERDUE</span>';

        case 'CANCELLED':
            return '<span class="badge rounded-pill bg-secondary-subtle text-secondary border px-3 py-2"><i class="bi bi-x-circle-fill me-1"></i>CANCELLED</span>';

        case 'DRAFT':
            return '<span class="badge rounded-pill bg-secondary-subtle text-secondary border px-3 py-2"><i class="bi bi-file-earmark me-1"></i>DRAFT</span>';

        default:
            return '<span class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-2"><i class="bi bi-clock-fill me-1"></i>UNPAID</span>';
    }
}

function printInvoice(invoiceNumber)
{
    if (!invoiceNumber) {
        alert("Nomor invoice tidak ditemukan.");
        return;
    }

    window.open(
        "invoice_view.php?invoice=" + encodeURIComponent(invoiceNumber) + "&print=1",
        "_blank"
    );
}

function printInvoiceFromModal()
{
    if (
        currentInvoice &&
        currentInvoice.invoice_number
    ) {
        printInvoice(
            currentInvoice.invoice_number
        );
    }
}

</script>

<?php

/*
|--------------------------------------------------------------------------
| SEND CONTENT INTO BAJAMA LAYOUT
|--------------------------------------------------------------------------
*/
$content = ob_get_clean();

$pageTitle = 'Invoices';

require __DIR__ . '/../app/layout/layout.php';
