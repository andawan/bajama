<?php

require_once __DIR__ . '/../app/bootstrap.php';

/* BAJAMA_RBAC_ENFORCED */
\BAJAMA\Core\Auth::requireLogin();
\BAJAMA\Core\License::requireFeature(\db(), 'billing');
\BAJAMA\Core\RBAC::require(\db(), 'billing.view');


if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['organization_id'])
) {
    header('Location: login.php');
    exit;
}

$orgId = (int) $_SESSION['organization_id'];
$paymentId = (int) ($_GET['id'] ?? 0);

if ($paymentId <= 0) {
    http_response_code(400);
    exit('Payment ID tidak valid.');
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rupiahReceipt($amount)
{
    return 'Rp ' . number_format(
        (float) $amount,
        0,
        ',',
        '.'
    );
}

/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

$env = [];
$envFile = __DIR__ . '/../.env';

if (is_file($envFile)) {

    foreach (
        file(
            $envFile,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        ) as $line
    ) {

        $line = trim($line);

        if (
            $line === '' ||
            strpos($line, '#') === 0
        ) {
            continue;
        }

        $parts = explode('=', $line, 2);

        if (count($parts) === 2) {
            $env[trim($parts[0])] =
                trim($parts[1], "\"'");
        }
    }
}

$pdo = new PDO(
    'mysql:host=' .
        ($env['DB_HOST'] ?? '127.0.0.1') .
        ';port=' .
        ($env['DB_PORT'] ?? '3306') .
        ';dbname=' .
        ($env['DB_DATABASE'] ?? 'bajama') .
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
| PAYMENT
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.invoice_id,
        p.amount,
        p.payment_method,
        p.reference_number,
        p.paid_at,
        p.status,
        p.created_at,

        i.invoice_number,
        i.total AS invoice_total,
        i.status AS invoice_status,
          i.router_id,
          r.name AS router_name,

        c.name AS customer_name,
        c.customer_code,
        c.phone AS customer_phone,
        c.email AS customer_email,

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

$paidDate =
    $payment['paid_at'] ?: $payment['created_at'];

$reference =
    $payment['reference_number'] ?: 'PAY-' . $payment['id'];

$logo = trim(
    (string) ($payment['organization_logo'] ?? '')
);

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
        Kuitansi <?= h($reference) ?>
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f1f3f5;
            color: #212529;
            font-family:
                Arial,
                Helvetica,
                sans-serif;
        }

        .toolbar {
            position: sticky;
            top: 0;
            z-index: 1000;

            display: flex;
            justify-content: center;
            gap: 10px;

            padding: 14px;

            background: #212529;
        }

        .toolbar a,
        .toolbar button {
            border: 0;
            border-radius: 8px;

            padding: 10px 18px;

            font-size: 14px;
            font-weight: 600;

            text-decoration: none;
            cursor: pointer;
        }

        .btn-back {
            background: #ffffff;
            color: #212529;
        }

        .btn-print {
            background: #0d6efd;
            color: #ffffff;
        }

        .receipt {
            width: 800px;
            max-width: calc(100% - 30px);

            margin: 30px auto;

            padding: 45px;

            background: #ffffff;

            box-shadow:
                0 8px 30px rgba(0, 0, 0, .08);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;

            gap: 30px;

            padding-bottom: 22px;

            border-bottom: 2px solid #212529;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand img {
            width: auto;
            max-width: 75px;
            max-height: 75px;
            object-fit: contain;
        }

        .brand-name {
            font-size: 24px;
            font-weight: 800;
        }

        .brand-contact {
            margin-top: 4px;
            color: #6c757d;
            font-size: 13px;
        }

        .receipt-title {
            text-align: right;
        }

        .receipt-title h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 800;
        }

        .receipt-number {
            margin-top: 4px;
            color: #6c757d;
            font-size: 13px;
        }

        .success {
            display: inline-block;

            margin-top: 10px;

            padding: 6px 12px;

            border-radius: 20px;

            background: #d1e7dd;
            color: #0f5132;

            font-size: 11px;
            font-weight: 800;
        }

        .section {
            margin-top: 30px;
        }

        .section-title {
            margin-bottom: 10px;

            color: #6c757d;

            font-size: 11px;
            font-weight: 700;

            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .customer {
            padding: 18px;

            border-radius: 10px;

            background: #f8f9fa;
        }

        .customer-name {
            font-size: 18px;
            font-weight: 800;
        }

        .customer-detail {
            margin-top: 4px;
            color: #6c757d;
            font-size: 13px;
        }

        .info-table {
            width: 100%;
            border-collapse: collapse;
        }

        .info-table td {
            padding: 11px 0;

            border-bottom: 1px solid #e9ecef;

            vertical-align: top;
        }

        .info-table td:first-child {
            width: 45%;
            color: #6c757d;
        }

        .amount-box {
            margin-top: 30px;

            padding: 25px;

            border: 2px solid #198754;
            border-radius: 12px;

            text-align: center;
        }

        .amount-label {
            color: #6c757d;
            font-size: 13px;
            font-weight: 600;
        }

        .amount {
            margin-top: 5px;

            color: #198754;

            font-size: 34px;
            font-weight: 800;
        }

        .footer {
            margin-top: 45px;
            padding-top: 18px;

            border-top: 1px solid #dee2e6;

            color: #6c757d;

            font-size: 12px;
            line-height: 1.7;

            text-align: center;
        }

        @media (max-width: 700px) {

            .receipt {
                padding: 25px;
            }

            .header {
                flex-direction: column;
            }

            .receipt-title {
                text-align: left;
            }

        }

        @media print {

            @page {
                size: A4;
                margin: 12mm;
            }

            body {
                background: #ffffff;
            }

            .toolbar {
                display: none !important;
            }

            .receipt {
                width: 100%;
                max-width: none;

                margin: 0;
                padding: 15px;

                box-shadow: none;
            }

        }

    </style>

</head>

<body>

<div class="toolbar">

    <a
        href="payments.php"
        class="btn-back"
    >
        ← Kembali
    </a>

    <button
        type="button"
        class="btn-print"
        onclick="window.print()"
    >
        🖨 Cetak Kuitansi
    </button>

</div>

<div class="receipt">

    <div class="header">

        <div class="brand">

            <?php if ($logo !== ''): ?>

                <img
                    src="<?= h($logo) ?>"
                    alt="Logo"
                >

            <?php endif; ?>

            <div>

                <div class="brand-name">
                    <?= h($payment['organization_name']) ?>
                </div>

                <?php if (!empty($payment['organization_email'])): ?>

                    <div class="brand-contact">
                        <?= h($payment['organization_email']) ?>
                    </div>

                <?php endif; ?>

                <?php if (!empty($payment['organization_phone'])): ?>

                    <div class="brand-contact">
                        <?= h($payment['organization_phone']) ?>
                    </div>

                <?php endif; ?>

            </div>

        </div>

        <div class="receipt-title">

            <h1>KUITANSI</h1>

            <div class="receipt-number">
                <?= h($reference) ?>
            </div>

            <div class="success">
                PEMBAYARAN BERHASIL
            </div>

        </div>

    </div>

    <div class="section">

        <div class="section-title">
            Diterima dari
        </div>

        <div class="customer">

            <div class="customer-name">
                <?= h($payment['customer_name']) ?>
            </div>

            <?php if (!empty($payment['customer_code'])): ?>

                <div class="customer-detail">
                    Kode Customer:
                    <?= h($payment['customer_code']) ?>
                </div>

            <?php endif; ?>

            <?php if (!empty($payment['customer_phone'])): ?>

                <div class="customer-detail">
                    <?= h($payment['customer_phone']) ?>
                </div>

            <?php endif; ?>

            <?php if (!empty($payment['customer_email'])): ?>

                <div class="customer-detail">
                    <?= h($payment['customer_email']) ?>
                </div>

            <?php endif; ?>

        </div>

    </div>

    <div class="section">

        <div class="section-title">
            Detail Pembayaran
        </div>

        <table class="info-table">

            <tr>
                <td>Invoice</td>
                <td>
                    <strong>
                        <?= h($payment['invoice_number']) ?>
                    </strong>
                </td>
            </tr>

            <tr>
                <td>Tanggal Pembayaran</td>
                <td>
                    <?= h(
                        date(
                            'd/m/Y H:i:s',
                            strtotime($paidDate)
                        )
                    ) ?>
                </td>
            </tr>

            <tr>
                <td>Metode Pembayaran</td>
                <td>
                    <?= h($payment['payment_method']) ?>
                </td>
            </tr>

            <tr>
                <td>Nomor Referensi</td>
                <td>
                    <strong>
                        <?= h($reference) ?>
                    </strong>
                </td>
            </tr>

        </table>

    </div>

    <div class="amount-box">

        <div class="amount-label">
            TOTAL PEMBAYARAN
        </div>

        <div class="amount">
            <?= rupiahReceipt($payment['amount']) ?>
        </div>

    </div>

    <div class="footer">

        Kuitansi ini diterbitkan secara elektronik oleh
        <strong><?= h($payment['organization_name']) ?></strong>.

        <br>

        Powered by <strong>BAJAMA</strong> —
        Building Networks Together.

    </div>

</div>

<script>
window.addEventListener('load', function () {
    <?php if (
        isset($_GET['print']) &&
        $_GET['print'] === '1'
    ): ?>
        window.print();
    <?php endif; ?>
});
</script>

</body>
</html>
