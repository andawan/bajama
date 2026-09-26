<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| BAJAMA Automatic Invoice Generator
|--------------------------------------------------------------------------
| CLI:
|   php scripts/generate_invoices.php
|
| Rules:
| - Hanya subscription ACTIVE
| - Hanya subscription yang sudah jatuh tempo
| - Tidak membuat invoice duplikat
| - Harga invoice diambil dari subscription saat invoice dibuat
| - next_due_date otomatis dimajukan sesuai billing cycle
|--------------------------------------------------------------------------
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$baseDir = dirname(__DIR__);
$envFile = $baseDir . '/.env';

if (!is_readable($envFile)) {
    fwrite(STDERR, "ERROR: .env tidak ditemukan.\n");
    exit(1);
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

$required = [
    'DB_HOST',
    'DB_PORT',
    'DB_DATABASE',
    'DB_USERNAME',
    'DB_PASSWORD'
];

foreach ($required as $key) {
    if (!array_key_exists($key, $env)) {
        fwrite(STDERR, "ERROR: konfigurasi {$key} tidak ditemukan.\n");
        exit(1);
    }
}

try {
    $pdo = new PDO(
        'mysql:host=' . $env['DB_HOST'] .
        ';port=' . $env['DB_PORT'] .
        ';dbname=' . $env['DB_DATABASE'] .
        ';charset=utf8mb4',
        $env['DB_USERNAME'],
        $env['DB_PASSWORD'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "ERROR: koneksi database gagal.\n");
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$today = new DateTimeImmutable('today');

echo "======================================================\n";
echo " BAJAMA AUTOMATIC INVOICE GENERATOR\n";
echo " Tanggal: " . $today->format('Y-m-d') . "\n";
echo "======================================================\n";

$sql = "
    SELECT
        s.id,
        s.organization_id,
        s.customer_id,
        s.router_id,
        s.service_plan_id,
        s.service_type,
        s.service_name,
        s.speed_download,
        s.speed_upload,
        s.username,
        s.billing_cycle,
        s.price,
        s.next_due_date,
        c.name AS customer_name
    FROM subscriptions s
    INNER JOIN customers c
        ON c.id = s.customer_id
       AND c.organization_id = s.organization_id
    WHERE s.status = 'ACTIVE'
      AND s.next_due_date IS NOT NULL
            AND s.next_due_date <= ?
            AND c.status = 'ACTIVE'
    ORDER BY s.next_due_date ASC, s.id ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$today->format('Y-m-d')]);

$subscriptions = $stmt->fetchAll();

echo "Subscription jatuh tempo: " . count($subscriptions) . "\n\n";

$created = 0;
$skipped = 0;
$errors = 0;

foreach ($subscriptions as $subscription) {

    $subscriptionId = (int)$subscription['id'];
    $organizationId = (int)$subscription['organization_id'];
    $customerId = (int)$subscription['customer_id'];

    $dueDate = new DateTimeImmutable($subscription['next_due_date']);

    /*
     * Period invoice:
     * MONTHLY   = 1 bulan
     * QUARTERLY = 3 bulan
     * YEARLY    = 1 tahun
     */
    switch ($subscription['billing_cycle']) {
        case 'QUARTERLY':
            $periodStart = $dueDate->modify('-3 months');
            break;

        case 'YEARLY':
            $periodStart = $dueDate->modify('-1 year');
            break;

        case 'MONTHLY':
        default:
            $periodStart = $dueDate->modify('-1 month');
            break;
    }

    $periodEnd = $dueDate->modify('-1 day');

    /*
     * Cek invoice yang sama.
     * Subscription + period_start + period_end harus unik secara logika.
     */
    $check = $pdo->prepare("
        SELECT id, invoice_number, status
        FROM invoices
        WHERE organization_id = ?
          AND subscription_id = ?
          AND period_start = ?
          AND period_end = ?
        LIMIT 1
    ");

    $check->execute([
        $organizationId,
        $subscriptionId,
        $periodStart->format('Y-m-d'),
        $periodEnd->format('Y-m-d')
    ]);

    $existing = $check->fetch();

    if ($existing) {
        echo "[SKIP] Subscription #{$subscriptionId} "
           . "sudah memiliki invoice {$existing['invoice_number']}\n";

        /*
         * Invoice sudah ada, tetap majukan next_due_date
         * supaya cron tidak memproses subscription ini terus.
         */
        $newDueDate = $dueDate;

        switch ($subscription['billing_cycle']) {
            case 'QUARTERLY':
                $newDueDate = $dueDate->modify('+3 months');
                break;

            case 'YEARLY':
                $newDueDate = $dueDate->modify('+1 year');
                break;

            case 'MONTHLY':
            default:
                $newDueDate = $dueDate->modify('+1 month');
                break;
        }

        $update = $pdo->prepare("
            UPDATE subscriptions
            SET next_due_date = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND organization_id = ?
        ");

        $update->execute([
            $newDueDate->format('Y-m-d'),
            $subscriptionId,
            $organizationId
        ]);

        $skipped++;
        continue;
    }

    /*
     * Invoice number.
     * Contoh:
     * INV-202609-ORG001-SUB000001
     */
    $invoiceNumber = sprintf(
        'INV-%s-ORG%03d-SUB%06d',
        $dueDate->format('Ym'),
        $organizationId,
        $subscriptionId
    );

    /*
     * Pastikan invoice number tidak bentrok.
     */
    $numberCheck = $pdo->prepare("
        SELECT id
        FROM invoices
        WHERE invoice_number = ?
        LIMIT 1
    ");

    $numberCheck->execute([$invoiceNumber]);

    if ($numberCheck->fetch()) {
        $invoiceNumber .= '-' . date('His');
    }

    $price = (float)$subscription['price'];

    $description =
        $subscription['service_name'] .
        ' - ' .
        $subscription['service_type'] .
        ' - ' .
        $subscription['speed_download'] . '/' .
        $subscription['speed_upload'] . ' Mbps';

    try {

        $pdo->beginTransaction();

        /*
         * Buat invoice.
         */
        $insert = $pdo->prepare("
            INSERT INTO invoices (
                organization_id,
                customer_id,
                router_id,
                subscription_id,
                invoice_number,
                issue_date,
                due_date,
                period_start,
                period_end,
                subtotal,
                discount,
                total,
                description,
                status,
                created_at
            ) VALUES (
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
                0,
                ?,
                ?,
                'UNPAID',
                CURRENT_TIMESTAMP
            )
        ");

        $insert->execute([
            $organizationId,
            $customerId,
            (int)$subscription['router_id'],
            $subscriptionId,
            $invoiceNumber,
            $today->format('Y-m-d'),
            $dueDate->format('Y-m-d'),
            $periodStart->format('Y-m-d'),
            $periodEnd->format('Y-m-d'),
            $price,
            $price,
            $description
        ]);

        /*
         * Majukan next_due_date.
         */
        switch ($subscription['billing_cycle']) {
            case 'QUARTERLY':
                $newDueDate = $dueDate->modify('+3 months');
                break;

            case 'YEARLY':
                $newDueDate = $dueDate->modify('+1 year');
                break;

            case 'MONTHLY':
            default:
                $newDueDate = $dueDate->modify('+1 month');
                break;
        }

        $update = $pdo->prepare("
            UPDATE subscriptions
            SET next_due_date = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND organization_id = ?
        ");

        $update->execute([
            $newDueDate->format('Y-m-d'),
            $subscriptionId,
            $organizationId
        ]);

        $pdo->commit();

        echo "[CREATE] {$invoiceNumber}\n";
        echo "         Customer : {$subscription['customer_name']}\n";
        echo "         Service  : {$subscription['service_name']}\n";
        echo "         Period   : "
           . $periodStart->format('Y-m-d')
           . " s/d "
           . $periodEnd->format('Y-m-d') . "\n";
        echo "         Total    : Rp " . number_format($price, 0, ',', '.') . "\n";
        echo "         Due      : " . $dueDate->format('Y-m-d') . "\n";
        echo "         Next Due  : " . $newDueDate->format('Y-m-d') . "\n\n";

        $created++;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        echo "[ERROR] Subscription #{$subscriptionId}: "
           . $e->getMessage() . "\n\n";

        $errors++;
    }
}

echo "======================================================\n";
echo " SELESAI\n";
echo "======================================================\n";
echo "Created : {$created}\n";
echo "Skipped : {$skipped}\n";
echo "Errors  : {$errors}\n";
echo "======================================================\n";

exit($errors > 0 ? 1 : 0);
