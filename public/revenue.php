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
| DATABASE
|--------------------------------------------------------------------------
*/

$envFile = dirname(__DIR__) . '/.env';
$env = [];

if (is_readable($envFile)) {
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

    error_log('BAJAMA revenue DB error: ' . $e->getMessage());

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

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function revenue_rupiah($value): string
{
    return 'Rp ' . number_format(
        (float)$value,
        0,
        ',',
        '.'
    );
}

function revenue_h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function revenue_method($method): string
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

/*
|--------------------------------------------------------------------------
| DATE RANGE
|--------------------------------------------------------------------------
*/

$today = date('Y-m-d');
$startDate = date('Y-m-d', strtotime('-29 days'));
$endDate = $today;

/*
|--------------------------------------------------------------------------
| REVENUE SUMMARY
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        COALESCE(
            SUM(
                CASE
                    WHEN status = 'SUCCESS'
                    THEN amount
                    ELSE 0
                END
            ),
            0
        ) AS revenue_total,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'SUCCESS'
                     AND DATE(paid_at) = CURDATE()
                    THEN amount
                    ELSE 0
                END
            ),
            0
        ) AS revenue_today,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'SUCCESS'
                     AND YEAR(paid_at) = YEAR(CURDATE())
                     AND MONTH(paid_at) = MONTH(CURDATE())
                    THEN amount
                    ELSE 0
                END
            ),
            0
        ) AS revenue_month,

        COUNT(
            CASE
                WHEN status = 'SUCCESS'
                THEN id
            END
        ) AS success_transactions,

        COUNT(
            CASE
                WHEN status = 'PENDING'
                THEN id
            END
        ) AS pending_transactions,

        COUNT(
            CASE
                WHEN status = 'FAILED'
                THEN id
            END
        ) AS failed_transactions

    FROM payments
    WHERE organization_id = ?
");

$stmt->execute([$organizationId]);

$summary = $stmt->fetch();

$revenueTotal = (float)($summary['revenue_total'] ?? 0);
$revenueToday = (float)($summary['revenue_today'] ?? 0);
$revenueMonth = (float)($summary['revenue_month'] ?? 0);
$successTransactions = (int)($summary['success_transactions'] ?? 0);
$pendingTransactions = (int)($summary['pending_transactions'] ?? 0);
$failedTransactions = (int)($summary['failed_transactions'] ?? 0);

$averageTransaction = $successTransactions > 0
    ? $revenueTotal / $successTransactions
    : 0;

/*
|--------------------------------------------------------------------------
| LAST 30 DAYS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        DATE(paid_at) AS payment_date,
        COALESCE(SUM(amount), 0) AS amount,
        COUNT(*) AS transactions
    FROM payments
    WHERE organization_id = ?
      AND status = 'SUCCESS'
      AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
      AND paid_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
    GROUP BY DATE(paid_at)
    ORDER BY payment_date ASC
");

$stmt->execute([$organizationId]);

$dailyRows = $stmt->fetchAll();

$dailyMap = [];

foreach ($dailyRows as $row) {
    $dailyMap[$row['payment_date']] = [
        'amount' => (float)$row['amount'],
        'transactions' => (int)$row['transactions']
    ];
}

$chartLabels = [];
$chartValues = [];
$chartTransactions = [];

for ($i = 29; $i >= 0; $i--) {

    $date = date(
        'Y-m-d',
        strtotime('-' . $i . ' days')
    );

    $chartLabels[] = date(
        'd/m',
        strtotime($date)
    );

    $chartValues[] = $dailyMap[$date]['amount'] ?? 0;

    $chartTransactions[] =
        $dailyMap[$date]['transactions'] ?? 0;
}

/*
|--------------------------------------------------------------------------
| PAYMENT METHOD
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        payment_method,
        COALESCE(SUM(amount), 0) AS amount,
        COUNT(*) AS transactions
    FROM payments
    WHERE organization_id = ?
      AND status = 'SUCCESS'
    GROUP BY payment_method
    ORDER BY amount DESC
");

$stmt->execute([$organizationId]);

$methods = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| MONTHLY REVENUE
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        DATE_FORMAT(paid_at, '%Y-%m') AS month_key,
        DATE_FORMAT(paid_at, '%b %Y') AS month_label,
        COALESCE(SUM(amount), 0) AS amount,
        COUNT(*) AS transactions
    FROM payments
    WHERE organization_id = ?
      AND status = 'SUCCESS'
      AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
    GROUP BY
        DATE_FORMAT(paid_at, '%Y-%m'),
        DATE_FORMAT(paid_at, '%b %Y')
    ORDER BY month_key DESC
    LIMIT 6
");

$stmt->execute([$organizationId]);

$monthlyRows = $stmt->fetchAll();

ob_start();

?>

<style>
.revenue-page {
    width: 100%;
}

.revenue-title {
    font-size: 28px;
    font-weight: 800;
    letter-spacing: -.5px;
}

.revenue-subtitle {
    color: #64748b;
    font-size: 14px;
}

.revenue-card {
    border: 1px solid #e2e8f0 !important;
    border-radius: 16px !important;
    overflow: hidden;
}

.revenue-stat {
    min-height: 145px;
}

.revenue-icon {
    width: 50px;
    height: 50px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 21px;
}

.revenue-label {
    color: #64748b;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .6px;
    text-transform: uppercase;
}

.revenue-value {
    color: #0f172a;
    font-size: 25px;
    font-weight: 850;
    margin-top: 5px;
}

.revenue-small {
    color: #94a3b8;
    font-size: 12px;
    margin-top: 7px;
}

.revenue-section-title {
    color: #0f172a;
    font-size: 15px;
    font-weight: 800;
}

.revenue-chart {
    height: 330px;
}

.revenue-method-row {
    padding: 14px 0;
    border-bottom: 1px solid #f1f5f9;
}

.revenue-method-row:last-child {
    border-bottom: 0;
}

.revenue-progress {
    height: 7px;
    border-radius: 99px;
}

.revenue-table th {
    color: #64748b;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .4px;
}

.revenue-table td {
    vertical-align: middle;
}

@media (max-width: 575px) {
    .revenue-title {
        font-size: 23px;
    }

    .revenue-value {
        font-size: 20px;
    }
}
</style>

<div class="revenue-page">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

        <div>

            <div class="revenue-title">
                <i class="bi bi-graph-up-arrow me-2"></i>
                Revenue Dashboard
            </div>

            <div class="revenue-subtitle mt-1">
                Ringkasan pendapatan aktual berdasarkan pembayaran SUCCESS.
            </div>

        </div>

        <div class="d-flex gap-2">

            <a
                href="payment_reports.php"
                class="btn btn-primary"
            >
                <i class="bi bi-file-earmark-bar-graph me-1"></i>
                Payment Reports
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

            <div class="card revenue-card revenue-stat shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <div class="revenue-label">
                                Total Revenue
                            </div>

                            <div class="revenue-value">
                                <?= revenue_rupiah($revenueTotal) ?>
                            </div>

                            <div class="revenue-small">
                                Semua pembayaran SUCCESS
                            </div>

                        </div>

                        <div class="revenue-icon bg-success-subtle text-success">
                            <i class="bi bi-cash-stack"></i>
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card revenue-card revenue-stat shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <div class="revenue-label">
                                Revenue Hari Ini
                            </div>

                            <div class="revenue-value">
                                <?= revenue_rupiah($revenueToday) ?>
                            </div>

                            <div class="revenue-small">
                                <?= date('d/m/Y') ?>
                            </div>

                        </div>

                        <div class="revenue-icon bg-primary-subtle text-primary">
                            <i class="bi bi-calendar-day"></i>
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card revenue-card revenue-stat shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <div class="revenue-label">
                                Revenue Bulan Ini
                            </div>

                            <div class="revenue-value">
                                <?= revenue_rupiah($revenueMonth) ?>
                            </div>

                            <div class="revenue-small">
                                <?= date('F Y') ?>
                            </div>

                        </div>

                        <div class="revenue-icon bg-info-subtle text-info">
                            <i class="bi bi-calendar-month"></i>
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-12 col-sm-6 col-xl-3">

            <div class="card revenue-card revenue-stat shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between">

                        <div>

                            <div class="revenue-label">
                                Transaksi SUCCESS
                            </div>

                            <div class="revenue-value">
                                <?= number_format($successTransactions) ?>
                            </div>

                            <div class="revenue-small">
                                Rata-rata <?= revenue_rupiah($averageTransaction) ?>
                            </div>

                        </div>

                        <div class="revenue-icon bg-warning-subtle text-warning-emphasis">
                            <i class="bi bi-receipt-cutoff"></i>
                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <div class="row g-3 mb-4">

        <div class="col-12 col-xl-8">

            <div class="card revenue-card shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between align-items-center mb-3">

                        <div>

                            <div class="revenue-section-title">
                                Revenue 30 Hari
                            </div>

                            <div class="small text-secondary">
                                Pendapatan aktual berdasarkan paid_at.
                            </div>

                        </div>

                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                            SUCCESS
                        </span>

                    </div>

                    <div class="revenue-chart">
                        <canvas id="revenueChart"></canvas>
                    </div>

                </div>

            </div>

        </div>

        <div class="col-12 col-xl-4">

            <div class="card revenue-card shadow-sm h-100">

                <div class="card-body">

                    <div class="revenue-section-title mb-1">
                        Payment Method
                    </div>

                    <div class="small text-secondary mb-3">
                        Distribusi pembayaran SUCCESS.
                    </div>

                    <?php if (!$methods): ?>

                        <div class="text-center text-secondary py-5">
                            <i class="bi bi-credit-card fs-2 d-block mb-2"></i>
                            Belum ada pembayaran.
                        </div>

                    <?php else: ?>

                        <?php foreach ($methods as $method): ?>

                            <?php
                            $methodAmount = (float)$method['amount'];

                            $percentage = $revenueTotal > 0
                                ? ($methodAmount / $revenueTotal) * 100
                                : 0;
                            ?>

                            <div class="revenue-method-row">

                                <div class="d-flex justify-content-between mb-1">

                                    <strong>
                                        <?= revenue_h(
                                            revenue_method($method['payment_method'])
                                        ) ?>
                                    </strong>

                                    <strong>
                                        <?= revenue_rupiah($methodAmount) ?>
                                    </strong>

                                </div>

                                <div class="progress revenue-progress mb-1">

                                    <div
                                        class="progress-bar bg-primary"
                                        style="width: <?= min(100, $percentage) ?>%"
                                    ></div>

                                </div>

                                <div class="d-flex justify-content-between">

                                    <span class="small text-secondary">
                                        <?= number_format((int)$method['transactions']) ?>
                                        transaksi
                                    </span>

                                    <span class="small text-secondary">
                                        <?= number_format($percentage, 1) ?>%
                                    </span>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

    <div class="row g-3 mb-4">

        <div class="col-12 col-lg-4">

            <div class="card revenue-card shadow-sm">

                <div class="card-body">

                    <div class="revenue-section-title mb-3">
                        Payment Monitoring
                    </div>

                    <div class="d-flex justify-content-between py-2 border-bottom">

                        <span class="text-secondary">
                            SUCCESS
                        </span>

                        <strong class="text-success">
                            <?= number_format($successTransactions) ?>
                        </strong>

                    </div>

                    <div class="d-flex justify-content-between py-2 border-bottom">

                        <span class="text-secondary">
                            PENDING
                        </span>

                        <strong class="text-warning">
                            <?= number_format($pendingTransactions) ?>
                        </strong>

                    </div>

                    <div class="d-flex justify-content-between py-2">

                        <span class="text-secondary">
                            FAILED
                        </span>

                        <strong class="text-danger">
                            <?= number_format($failedTransactions) ?>
                        </strong>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-12 col-lg-8">

            <div class="card revenue-card shadow-sm">

                <div class="card-body">

                    <div class="d-flex justify-content-between align-items-center mb-3">

                        <div>

                            <div class="revenue-section-title">
                                Revenue Bulanan
                            </div>

                            <div class="small text-secondary">
                                6 bulan terakhir.
                            </div>

                        </div>

                    </div>

                    <div class="table-responsive bajama-table-responsive revenue-table-responsive">
                        <table class="table revenue-table mb-0 bajama-table" data-responsive-table="true">

                            <thead>

                                <tr>
                                    <th data-label="Bulan">Bulan</th>
                                    <th data-label="Transaksi">Transaksi</th>
                                    <th class="text-end" data-label="Revenue">Revenue</th>
                                </tr>

                            </thead>

                            <tbody>

                            <?php if (!$monthlyRows): ?>

                                <tr>
                                    <td colspan="3" class="text-center text-secondary py-4">
                                        Belum ada data revenue.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php foreach ($monthlyRows as $month): ?>

                                    <tr class="bajama-table-row">

                                        <td data-label="Bulan">
                                            <strong>
                                                <?= revenue_h($month['month_label']) ?>
                                            </strong>
                                        </td>

                                        <td data-label="Transaksi">
                                            <?= number_format(
                                                (int)$month['transactions']
                                            ) ?>
                                        </td>

                                        <td class="text-end fw-bold" data-label="Revenue">
                                            <?= revenue_rupiah($month['amount']) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>

(function () {

    const labels = <?= json_encode($chartLabels) ?>;

    const values = <?= json_encode($chartValues) ?>;

    const transactions = <?= json_encode($chartTransactions) ?>;

    const canvas = document.getElementById('revenueChart');

    if (!canvas || typeof Chart === 'undefined') {
        return;
    }

    new Chart(
        canvas,
        {
            type: 'line',

            data: {
                labels: labels,

                datasets: [
                    {
                        label: 'Revenue',
                        data: values,
                        borderWidth: 3,
                        tension: 0.35,
                        fill: true
                    }
                ]
            },

            options: {
                responsive: true,
                maintainAspectRatio: false,

                interaction: {
                    intersect: false,
                    mode: 'index'
                },

                plugins: {
                    legend: {
                        display: false
                    },

                    tooltip: {
                        callbacks: {
                            label: function (context) {

                                const value =
                                    Number(context.raw || 0);

                                const index =
                                    context.dataIndex;

                                const trx =
                                    transactions[index] || 0;

                                return [
                                    'Revenue: Rp ' +
                                    value.toLocaleString('id-ID'),

                                    'Transaksi: ' +
                                    trx
                                ];
                            }
                        }
                    }
                },

                scales: {
                    y: {
                        beginAtZero: true,

                        ticks: {
                            callback: function (value) {
                                return 'Rp ' +
                                    Number(value)
                                        .toLocaleString('id-ID');
                            }
                        }
                    }
                }
            }
        }
    );

})();

</script>

<?php

$content = ob_get_clean();

$pageTitle = 'Revenue Dashboard';

require __DIR__ . '/../app/layout/layout.php';
