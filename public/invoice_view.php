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

    error_log('BAJAMA invoice view DB error: ' . $e->getMessage());

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
        o.name,
        o.email,
        o.phone,
        o.logo,
        o.timezone,
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
| INVOICE ID
|--------------------------------------------------------------------------
*/
$invoiceId = (int)($_GET['id'] ?? 0);
$invoiceNumber = trim($_GET['invoice'] ?? '');

if ($invoiceId <= 0 && $invoiceNumber === '') {
    http_response_code(400);
    exit('Invoice tidak valid.');
}

/*
|--------------------------------------------------------------------------
| LOAD INVOICE
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
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

        c.id AS customer_id,
        c.customer_code,
        c.name AS customer_name,
        c.email AS customer_email,
        c.phone AS customer_phone,
        c.address AS customer_address,

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

    WHERE i.id = ?
      AND i.organization_id = ?

    LIMIT 1
");

if ($invoiceId > 0) {

    $stmt->execute([
        $invoiceId,
        $organizationId
    ]);

} else {

    $stmt = $pdo->prepare("
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

            c.id AS customer_id,
            c.customer_code,
            c.name AS customer_name,
            c.email AS customer_email,
            c.phone AS customer_phone,
            c.address AS customer_address,

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

        WHERE i.invoice_number = ?
          AND i.organization_id = ?

        LIMIT 1
    ");

    $stmt->execute([
        $invoiceNumber,
        $organizationId
    ]);
}

$invoice = $stmt->fetch();

if (!$invoice) {
    http_response_code(404);
    exit('Invoice tidak ditemukan.');
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function rupiah($value): string
{
    return 'Rp ' . number_format(
        (float)$value,
        0,
        ',',
        '.'
    );
}

function statusLabel($status): string
{
    switch ($status) {

        case 'PAID':
            return 'LUNAS';

        case 'PARTIAL':
            return 'SEBAGIAN';

        case 'OVERDUE':
            return 'JATUH TEMPO';

        case 'CANCELLED':
            return 'DIBATALKAN';

        case 'DRAFT':
            return 'DRAFT';

        default:
            return 'BELUM DIBAYAR';
    }
}

function formatDateId($date): string
{
    if (!$date) {
        return '-';
    }

    $timestamp = strtotime($date);

    if (!$timestamp) {
        return $date;
    }

    $months = [
        1  => 'Januari',
        2  => 'Februari',
        3  => 'Maret',
        4  => 'April',
        5  => 'Mei',
        6  => 'Juni',
        7  => 'Juli',
        8  => 'Agustus',
        9  => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember'
    ];

    return date('d', $timestamp) . ' ' .
        $months[(int)date('n', $timestamp)] . ' ' .
        date('Y', $timestamp);
}

$organizationName = $organization['name'] ?: 'BAJAMA';

$currency = $organization['currency'] ?: 'IDR';

$pageTitle = 'Invoice ' . $invoice['invoice_number'];

$subtotal = (float)$invoice['subtotal'];
$discount = (float)$invoice['discount'];
$total    = (float)$invoice['total'];

$serviceName = $invoice['service_name'] ?: 'Layanan Internet';
$routerName = $invoice['router_name'] ?: 'Router belum dipetakan';

$speedDownload = (int)$invoice['speed_download'];
$speedUpload   = (int)$invoice['speed_upload'];

$serviceSpeed = '-';

if ($speedDownload > 0 || $speedUpload > 0) {

    $serviceSpeed =
        number_format($speedDownload) .
        ' Mbps / ' .
        number_format($speedUpload) .
        ' Mbps';

}

/*
|--------------------------------------------------------------------------
| PRINT VIEW
|--------------------------------------------------------------------------
*/
?>
<!doctype html>
<html lang="id">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        <?= h($invoice['invoice_number']) ?> -
        <?= h($organizationName) ?>
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
        }

        body {
            background: #eef2f7;
            color: #172033;
            font-family:
                Arial,
                Helvetica,
                sans-serif;
            font-size: 13px;
            line-height: 1.5;
        }

        .screen-toolbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: #0f172a;
            padding: 12px 18px;
            box-shadow: 0 4px 15px rgba(0,0,0,.15);
        }

        .toolbar-inner {
            width: 210mm;
            max-width: 100%;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .toolbar-title {
            color: #fff;
            font-weight: 700;
        }

        .toolbar-actions {
            display: flex;
            gap: 8px;
        }

        .toolbar-btn {
            border: 0;
            border-radius: 7px;
            padding: 9px 14px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .toolbar-btn-primary {
            background: #2563eb;
            color: #fff;
        }

        .toolbar-btn-light {
            background: #fff;
            color: #0f172a;
        }

        .invoice-wrapper {
            padding: 30px 15px;
        }

        .invoice-paper {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 18mm;
            background: #fff;
            box-shadow: 0 10px 40px rgba(15,23,42,.12);
        }

        /* Header */

        .invoice-header {
            display: flex;
            justify-content: space-between;
            gap: 30px;
            padding-bottom: 22px;
            border-bottom: 2px solid #172033;
        }

        .company {
            flex: 1;
        }

        .company-logo {
            max-width: 150px;
            max-height: 65px;
            object-fit: contain;
            margin-bottom: 8px;
        }

        .company-name {
            font-size: 25px;
            font-weight: 900;
            letter-spacing: -.5px;
            color: #0f172a;
        }

        .company-tagline {
            margin-top: 2px;
            color: #64748b;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .5px;
        }

        .company-contact {
            margin-top: 9px;
            color: #64748b;
            font-size: 11px;
        }

        .invoice-heading {
            text-align: right;
            min-width: 220px;
        }

        .invoice-title {
            font-size: 29px;
            font-weight: 900;
            letter-spacing: 1px;
            color: #2563eb;
        }

        .invoice-number {
            margin-top: 5px;
            font-size: 12px;
            font-weight: 700;
            color: #475569;
        }

        .invoice-status {
            display: inline-block;
            margin-top: 12px;
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .5px;
        }

        .status-paid {
            background: #dcfce7;
            color: #166534;
        }

        .status-unpaid {
            background: #fef3c7;
            color: #92400e;
        }

        .status-overdue {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-partial {
            background: #fef3c7;
            color: #92400e;
        }

        .status-cancelled,
        .status-draft {
            background: #e2e8f0;
            color: #475569;
        }

        /* Meta */

        .invoice-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 35px;
            padding: 25px 0;
        }

        .meta-label {
            color: #94a3b8;
            font-size: 9px;
            font-weight: 900;
            letter-spacing: .8px;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .meta-value {
            color: #1e293b;
            font-weight: 700;
        }

        .meta-small {
            color: #64748b;
            font-size: 11px;
            margin-top: 2px;
        }

        /* Service */

        .section-title {
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .8px;
            color: #475569;
            margin-bottom: 10px;
        }

        .service-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }

        .service-table th {
            padding: 11px 12px;
            background: #f1f5f9;
            border-top: 1px solid #dbe3ed;
            border-bottom: 1px solid #dbe3ed;
            text-align: left;
            font-size: 10px;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .service-table td {
            padding: 15px 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        .service-name {
            font-weight: 800;
            color: #0f172a;
        }

        .service-detail {
            color: #64748b;
            font-size: 11px;
            margin-top: 3px;
        }

        .text-right {
            text-align: right;
        }

        /* Totals */

        .totals-area {
            display: flex;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .totals {
            width: 310px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 7px 0;
            color: #64748b;
        }

        .total-row strong {
            color: #334155;
        }

        .grand-total {
            margin-top: 8px;
            padding: 14px 0;
            border-top: 2px solid #172033;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .grand-total-label {
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .grand-total-value {
            font-size: 22px;
            font-weight: 900;
            color: #2563eb;
        }

        /* Notes */

        .invoice-notes {
            margin-top: 35px;
            padding: 15px;
            border-radius: 8px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }

        .notes-title {
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: #475569;
            margin-bottom: 5px;
        }

        .notes-text {
            color: #64748b;
            font-size: 11px;
        }

        /* Footer */

        .invoice-footer {
            margin-top: 55px;
            padding-top: 18px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            gap: 30px;
            color: #94a3b8;
            font-size: 9px;
        }

        .footer-right {
            text-align: right;
        }

        /* Mobile */

        @media (max-width: 900px) {

            .invoice-wrapper {
                padding: 15px 0;
            }

            .invoice-paper {
                width: 100%;
                min-height: auto;
                padding: 25px;
                box-shadow: none;
            }

            .toolbar-inner {
                width: 100%;
            }

        }

        @media (max-width: 600px) {

            .toolbar-inner {
                flex-direction: column;
                align-items: stretch;
            }

            .toolbar-actions {
                display: grid;
                grid-template-columns: 1fr 1fr;
            }

            .invoice-header {
                flex-direction: column;
            }

            .invoice-heading {
                text-align: left;
            }

            .invoice-meta {
                grid-template-columns: 1fr;
                gap: 18px;
            }

            .totals-area {
                justify-content: stretch;
            }

            .totals {
                width: 100%;
            }

            .invoice-footer {
                flex-direction: column;
            }

            .footer-right {
                text-align: left;
            }

        }

        /* PRINT */

        @media print {

            @page {
                size: A4;
                margin: 0;
            }

            html,
            body {
                width: 210mm;
                min-height: 297mm;
                background: #fff !important;
            }

            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .screen-toolbar {
                display: none !important;
            }

            .invoice-wrapper {
                padding: 0 !important;
            }

            .invoice-paper {
                width: 210mm !important;
                min-height: 297mm !important;
                margin: 0 !important;
                padding: 18mm !important;
                box-shadow: none !important;
            }

            .invoice-table,
            .service-table {
                page-break-inside: avoid;
            }

            .invoice-header,
            .invoice-meta,
            .invoice-notes,
            .invoice-footer {
                page-break-inside: avoid;
            }

        }

    </style>

</head>

<body>

<!-- =========================================================
     SCREEN TOOLBAR
     ========================================================= -->

<div class="screen-toolbar">

    <div class="toolbar-inner">

        <div class="toolbar-title">
            BAJAMA Invoice
        </div>

        <div class="toolbar-actions">

            <a
                href="invoices.php"
                class="toolbar-btn toolbar-btn-light"
            >
                ← Kembali
            </a>

            <button
                type="button"
                class="toolbar-btn toolbar-btn-primary"
                onclick="window.print()"
            >
                🖨 Print Invoice
            </button>

        </div>

    </div>

</div>

<!-- =========================================================
     INVOICE PAPER
     ========================================================= -->

<div class="invoice-wrapper">

    <main class="invoice-paper">

        <!-- HEADER -->

        <header class="invoice-header">

            <div class="company">

                <?php if (!empty($organization['logo'])): ?>

                    <?php
                    $logo = trim($organization['logo']);

                    if (
                        strpos($logo, 'http://') === 0 ||
                        strpos($logo, 'https://') === 0
                    ) {
                        $logoUrl = $logo;
                    } else {
                        $logoUrl = $logo;
                    }
                    ?>

                    <img
                        src="<?= h($logoUrl) ?>"
                        alt="<?= h($organizationName) ?>"
                        class="company-logo"
                    >

                <?php endif; ?>

                <div class="company-name">
                    <?= h($organizationName) ?>
                </div>

                <div class="company-tagline">
                    Building Networks Together
                </div>

                <div class="company-contact">

                    <?php if (!empty($organization['email'])): ?>
                        <?= h($organization['email']) ?>
                    <?php endif; ?>

                    <?php if (!empty($organization['phone'])): ?>

                        <?php if (!empty($organization['email'])): ?>
                            &nbsp; • &nbsp;
                        <?php endif; ?>

                        <?= h($organization['phone']) ?>

                    <?php endif; ?>

                </div>

            </div>

            <div class="invoice-heading">

                <div class="invoice-title">
                    INVOICE
                </div>

                <div class="invoice-number">
                    <?= h($invoice['invoice_number']) ?>
                </div>

                <?php
                $statusClass = 'status-unpaid';

                if ($invoice['status'] === 'PAID') {
                    $statusClass = 'status-paid';
                } elseif ($invoice['status'] === 'OVERDUE') {
                    $statusClass = 'status-overdue';
                } elseif ($invoice['status'] === 'PARTIAL') {
                    $statusClass = 'status-partial';
                } elseif (
                    $invoice['status'] === 'CANCELLED'
                ) {
                    $statusClass = 'status-cancelled';
                } elseif (
                    $invoice['status'] === 'DRAFT'
                ) {
                    $statusClass = 'status-draft';
                }
                ?>

                <div class="invoice-status <?= $statusClass ?>">
                    <?= h(statusLabel($invoice['status'])) ?>
                </div>

            </div>

        </header>

        <!-- META -->

        <section class="invoice-meta">

            <div>

                <div class="meta-label">
                    Ditagihkan Kepada
                </div>

                <div class="meta-value">
                    <?= h($invoice['customer_name']) ?>
                </div>

                <div class="meta-small">
                    <?= h($invoice['customer_code']) ?>
                </div>

                <?php if (!empty($invoice['customer_phone'])): ?>

                    <div class="meta-small">
                        <?= h($invoice['customer_phone']) ?>
                    </div>

                <?php endif; ?>

                <?php if (!empty($invoice['customer_email'])): ?>

                    <div class="meta-small">
                        <?= h($invoice['customer_email']) ?>
                    </div>

                <?php endif; ?>

                <?php if (!empty($invoice['customer_address'])): ?>

                    <div class="meta-small">
                        <?= nl2br(h($invoice['customer_address'])) ?>
                    </div>

                <?php endif; ?>

            </div>

            <div>

                <div class="meta-label">
                    Informasi Invoice
                </div>

                <div class="meta-small">
                    Tanggal Invoice:
                    <strong>
                        <?= h(formatDateId($invoice['issue_date'])) ?>
                    </strong>
                </div>

                <div class="meta-small">
                    Jatuh Tempo:
                    <strong>
                        <?= h(formatDateId($invoice['due_date'])) ?>
                    </strong>
                </div>

                <div class="meta-small">
                    Periode:
                    <strong>
                        <?= h(formatDateId($invoice['period_start'])) ?>
                        -
                        <?= h(formatDateId($invoice['period_end'])) ?>
                    </strong>
                </div>

            </div>

        </section>

        <!-- SERVICE -->

        <section>

            <div class="section-title">
                Detail Layanan
            </div>

            <table class="service-table">

                <thead>

                    <tr>

                        <th>
                            Deskripsi
                        </th>

                        <th style="width:130px;">
                            Periode
                        </th>

                        <th
                            style="width:150px;"
                            class="text-right"
                        >
                            Harga
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <tr>

                        <td>

                            <div class="service-name">
                                <?= h($serviceName) ?>
                            </div>

                            <div class="service-detail">

                                  Router: <?= h($routerName) ?>
                                  &nbsp; • &nbsp;

                                Tipe:
                                <?= h(
                                    $invoice['service_type'] ?: '-'
                                ) ?>

                                <?php if (!empty($invoice['service_username'])): ?>

                                    &nbsp; • &nbsp;

                                    Username:
                                    <?= h(
                                        $invoice['service_username']
                                    ) ?>

                                <?php endif; ?>

                            </div>

                            <div class="service-detail">

                                Kecepatan:
                                <?= h($serviceSpeed) ?>

                            </div>

                        </td>

                        <td>

                            <?= h(
                                strtoupper(
                                    $invoice['billing_cycle'] ?: 'MONTHLY'
                                )
                            ) ?>

                        </td>

                        <td class="text-right">

                            <?= rupiah($subtotal) ?>

                        </td>

                    </tr>

                </tbody>

            </table>

        </section>

        <!-- TOTAL -->

        <section class="totals-area">

            <div class="totals">

                <div class="total-row">

                    <span>
                        Subtotal
                    </span>

                    <strong>
                        <?= rupiah($subtotal) ?>
                    </strong>

                </div>

                <div class="total-row">

                    <span>
                        Diskon
                    </span>

                    <strong>
                        <?= rupiah($discount) ?>
                    </strong>

                </div>

                <div class="grand-total">

                    <div class="grand-total-label">
                        Total
                    </div>

                    <div class="grand-total-value">
                        <?= rupiah($total) ?>
                    </div>

                </div>

            </div>

        </section>

        <!-- NOTES -->

        <section class="invoice-notes">

            <div class="notes-title">
                Catatan
            </div>

            <div class="notes-text">

                <?php if (!empty($invoice['description'])): ?>

                    <?= nl2br(h($invoice['description'])) ?>

                <?php else: ?>

                    Terima kasih telah menggunakan layanan
                    <?= h($organizationName) ?>.

                    Mohon melakukan pembayaran sebelum tanggal
                    jatuh tempo yang tercantum pada invoice.

                <?php endif; ?>

            </div>

        </section>

        <!-- FOOTER -->

        <footer class="invoice-footer">

            <div>

                <strong>
                    <?= h($organizationName) ?>
                </strong>

                <br>

                Building Networks Together

            </div>

            <div class="footer-right">

                Invoice:
                <?= h($invoice['invoice_number']) ?>

                <br>

                Generated by BAJAMA

            </div>

        </footer>

    </main>

</div>

<script>

window.addEventListener('load', function () {

    /*
     * Print otomatis jika URL menggunakan ?print=1
     */
    const params = new URLSearchParams(
        window.location.search
    );

    if (params.get('print') === '1') {

        setTimeout(function () {
            window.print();
        }, 500);

    }

});

</script>

</body>
</html>
