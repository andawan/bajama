<?php

require_once __DIR__ . '/../app/bootstrap.php';
use BAJAMA\Network\ProvisioningService;
use BAJAMA\Network\RouterOSProvisioningService;
/* BAJAMA_RBAC_ENFORCED */
\BAJAMA\Core\Auth::requireLogin();
\BAJAMA\Core\License::requireFeature(\db(), 'billing');
\BAJAMA\Core\RBAC::require(\db(), 'billing.manage');


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

$env = [];

foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);

    if ($line === '' || strpos($line, '#') === 0) {
        continue;
    }

    $parts = explode('=', $line, 2);

    if (count($parts) === 2) {
        $key = trim($parts[0]);
        $value = trim($parts[1]);

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

$pdo = new PDO(
    "mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_DATABASE']};charset=utf8mb4",
    $env['DB_USERNAME'],
    $env['DB_PASSWORD'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);

$stmt = $pdo->prepare("
    SELECT organization_id
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$userId]);

$organizationId = (int)$stmt->fetchColumn();

if ($organizationId <= 0) {
    http_response_code(403);
    exit('Organization tidak ditemukan.');
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$subscription = [
    'id' => 0,
    'customer_id' => 0,
    'router_id' => 0,
    'service_plan_id' => 0,
    'service_type' => 'PPPOE',
    'service_name' => '',
    'username' => '',
    'password_encrypted' => '',
    'hotspot_address' => '',
    'hotspot_mac_address' => '',
    'status' => 'ACTIVE',
    'billing_cycle' => 'MONTHLY',
    'price' => '0',
    'next_due_date' => '',
    'speed_download' => 0,
    'speed_upload' => 0,
];

if ($id > 0) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM subscriptions
        WHERE id = ?
          AND organization_id = ?
        LIMIT 1
    ");

    $stmt->execute([$id, $organizationId]);

    $found = $stmt->fetch();

    if (!$found) {
        http_response_code(404);
        exit('Subscription tidak ditemukan.');
    }

    $subscription = array_merge($subscription, $found);
}

$stmt = $pdo->prepare("
    SELECT
        id,
        customer_code,
        name,
        phone
    FROM customers
    WHERE organization_id = ?
      AND status <> 'BLOCKED'
    ORDER BY name ASC
");

$stmt->execute([$organizationId]);

$customers = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        code,
        service_type,
        speed_download,
        speed_upload,
        price,
        billing_cycle
    FROM service_plans
    WHERE organization_id = ?
      AND status = 'ACTIVE'
    ORDER BY name ASC
");

$stmt->execute([$organizationId]);

$plans = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT id, name, host, status
     FROM mikrotik_routers
     WHERE organization_id = ?
     ORDER BY name ASC'
);
$stmt->execute([$organizationId]);
$routers = $stmt->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf(
        (string)($_POST['_csrf'] ?? $_POST['csrf'] ?? '')
    );

    $customerId = (int)($_POST['customer_id'] ?? 0);
    $routerId   = (int)($_POST['router_id'] ?? 0);
    $planId     = (int)($_POST['service_plan_id'] ?? 0);
    $username   = trim($_POST['username'] ?? '');
    $password   = trim((string)($_POST['password'] ?? ''));
    $hotspotAddress = trim((string)($_POST['hotspot_address'] ?? ''));
    $hotspotMac = strtoupper(trim((string)($_POST['hotspot_mac_address'] ?? '')));
    $status     = $_POST['status'] ?? 'ACTIVE';
    $nextDue    = trim($_POST['next_due_date'] ?? '');

    if ($customerId <= 0) {
        $errors[] = 'Customer wajib dipilih.';
    }

    if ($planId <= 0) {
        $errors[] = 'Paket internet wajib dipilih.';
    }

    if ($routerId <= 0) {
        $errors[] = 'Router MikroTik wajib dipilih agar billing tidak tertukar.';
    }

    $routerStmt = $pdo->prepare(
        'SELECT id
         FROM mikrotik_routers
         WHERE id = ?
           AND organization_id = ?
           AND status <> "DISABLED"
         LIMIT 1'
    );
    $routerStmt->execute([$routerId, $organizationId]);

    if (!$routerStmt->fetchColumn()) {
        $errors[] = 'Router MikroTik tidak valid untuk organization ini.';
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM customers
        WHERE id = ?
          AND organization_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $customerId,
        $organizationId
    ]);

    if (!$stmt->fetch()) {
        $errors[] = 'Customer tidak valid.';
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM service_plans
        WHERE id = ?
          AND organization_id = ?
          AND status = 'ACTIVE'
        LIMIT 1
    ");

    $stmt->execute([
        $planId,
        $organizationId
    ]);

    $plan = $stmt->fetch();

    if (!$plan) {
        $errors[] = 'Paket internet tidak valid atau sudah tidak aktif.';
    }

    if (!in_array($status, [
        'ACTIVE',
        'GRACE_PERIOD',
        'SUSPENDED',
        'TERMINATED'
    ], true)) {
        $errors[] = 'Status subscription tidak valid.';
    }

    if ($nextDue !== '') {

        $date = DateTime::createFromFormat('Y-m-d', $nextDue);

        if (!$date || $date->format('Y-m-d') !== $nextDue) {
            $errors[] = 'Tanggal jatuh tempo tidak valid.';
        }
    } else {
        $nextDue = null;
    }

    if (!$errors) {

        $serviceType = $plan['service_type'];
        $serviceName = $plan['name'];
        $billingCycle = $plan['billing_cycle'];
        $price = $plan['price'];
        $speedDownload = (int)$plan['speed_download'];
        $speedUpload = (int)$plan['speed_upload'];

        if ($serviceType === 'HOTSPOT') {
            if ($username === '') {
                $errors[] = 'Username Hotspot wajib diisi.';
            }
            if ($password === '' && $id <= 0) {
                $password = $username;
            }
            if ($hotspotAddress !== '' && filter_var($hotspotAddress, FILTER_VALIDATE_IP) === false) {
                $errors[] = 'IP binding Hotspot tidak valid.';
            }
            if ($hotspotMac !== '' && !preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $hotspotMac)) {
                $errors[] = 'MAC address Hotspot tidak valid.';
            }
        }

        if (!$errors && $id > 0) {

            $passwordSql = $serviceType === 'HOTSPOT'
                ? ProvisioningService::encryptPassword($username)
                : ($password !== ''
                ? ProvisioningService::encryptPassword($password)
                : (string)($subscription['password_encrypted'] ?? ''));

            $stmt = $pdo->prepare("
                UPDATE subscriptions
                SET
                    customer_id = ?,
                    router_id = ?,
                    service_plan_id = ?,
                    service_type = ?,
                    service_name = ?,
                    speed_download = ?,
                    speed_upload = ?,
                    username = ?,
                    password_encrypted = ?,
                    hotspot_address = ?,
                    hotspot_mac_address = ?,
                    status = ?,
                    billing_cycle = ?,
                    price = ?,
                    next_due_date = ?
                WHERE id = ?
                  AND organization_id = ?
            ");

            $stmt->execute([
                $customerId,
                $routerId,
                $planId,
                $serviceType,
                $serviceName,
                $speedDownload,
                $speedUpload,
                $username !== '' ? $username : null,
                $passwordSql !== '' ? $passwordSql : null,
                $hotspotAddress !== '' ? $hotspotAddress : null,
                $hotspotMac !== '' ? $hotspotMac : null,
                $status,
                $billingCycle,
                $price,
                $nextDue,
                $id,
                $organizationId
            ]);

            header(
                'Location: subscriptions.php?msg=' .
                urlencode('Subscription berhasil diperbarui.')
            );

            exit;

        } elseif (!$errors) {

            $passwordSql = $serviceType === 'HOTSPOT'
                ? ProvisioningService::encryptPassword($username)
                : ($password !== ''
                ? ProvisioningService::encryptPassword($password)
                : null);

            $stmt = $pdo->prepare("
                INSERT INTO subscriptions
                (
                    organization_id,
                    customer_id,
                    router_id,
                    service_plan_id,
                    service_type,
                    service_name,
                    speed_download,
                    speed_upload,
                    username,
                    password_encrypted,
                    hotspot_address,
                    hotspot_mac_address,
                    status,
                    billing_cycle,
                    price,
                    next_due_date
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $organizationId,
                $customerId,
                $routerId,
                $planId,
                $serviceType,
                $serviceName,
                $speedDownload,
                $speedUpload,
                $username !== '' ? $username : null,
                $passwordSql,
                $hotspotAddress !== '' ? $hotspotAddress : null,
                $hotspotMac !== '' ? $hotspotMac : null,
                $status,
                $billingCycle,
                $price,
                $nextDue
            ]);

            $id = (int)$pdo->lastInsertId();

            header(
                'Location: subscriptions.php?msg=' .
                urlencode('Subscription berhasil dibuat.')
            );

            exit;
        }
    }

    $subscription['customer_id'] = $customerId;
    $subscription['router_id'] = $routerId;
    $subscription['service_plan_id'] = $planId;
    $subscription['username'] = $username;
    $subscription['status'] = $status;
    $subscription['next_due_date'] = $nextDue;
}

$title = $id > 0 ? 'Edit Subscription' : 'Tambah Subscription';

ob_start();
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

    <div>
        <h2 class="fw-bold mb-1">
            <?= $id > 0 ? 'Edit Subscription' : 'Tambah Subscription' ?>
        </h2>

        <div class="text-muted">
            Hubungkan customer dengan paket layanan internet.
        </div>
    </div>

    <a href="subscriptions.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>
        Kembali
    </a>

</div>

<?php if ($errors): ?>

    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>

<?php endif; ?>

<?php if (!$customers): ?>

    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>

        Belum ada customer.

        <a href="customer_form.php" class="alert-link">
            Tambahkan customer terlebih dahulu.
        </a>
    </div>

<?php elseif (!$plans): ?>

    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>

        Belum ada paket internet aktif.

        <a href="service_plan_form.php" class="alert-link">
            Buat paket internet terlebih dahulu.
        </a>
    </div>

<?php endif; ?>

<form method="post">

    <input
        type="hidden"
        name="_csrf"
        value="<?= htmlspecialchars(csrf_token()) ?>"
    >

    <input
        type="hidden"
        name="id"
        value="<?= (int)$subscription['id'] ?>"
    >

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white fw-semibold">
            Customer & Paket
        </div>

        <div class="card-body">

            <div class="row g-3">

                <div class="col-md-6">

                    <label class="form-label">
                        Customer
                    </label>

                    <select
                        name="customer_id"
                        class="form-select"
                        required
                    >

                        <option value="">
                            -- Pilih Customer --
                        </option>

                        <?php foreach ($customers as $customer): ?>

                            <option
                                value="<?= (int)$customer['id'] ?>"
                                <?= (int)$subscription['customer_id'] === (int)$customer['id'] ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($customer['name']) ?>
                                —
                                <?= htmlspecialchars($customer['customer_code']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-6">

                    <label class="form-label">
                        Paket Internet
                    </label>

                    <select
                        name="service_plan_id"
                        id="service_plan_id"
                        class="form-select"
                        required
                    >

                        <option value="">
                            -- Pilih Paket --
                        </option>

                        <?php foreach ($plans as $plan): ?>

                            <option
                                value="<?= (int)$plan['id'] ?>"
                                data-type="<?= htmlspecialchars($plan['service_type']) ?>"
                                data-download="<?= (int)$plan['speed_download'] ?>"
                                data-upload="<?= (int)$plan['speed_upload'] ?>"
                                data-price="<?= htmlspecialchars($plan['price']) ?>"
                                data-cycle="<?= htmlspecialchars($plan['billing_cycle']) ?>"
                                <?= (int)$subscription['service_plan_id'] === (int)$plan['id'] ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($plan['name']) ?>
                                —
                                <?= number_format((float)$plan['price'], 0, ',', '.') ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-6">
                    <label class="form-label">Router MikroTik</label>
                    <select name="router_id" class="form-select" required>
                        <option value="">-- Pilih Router --</option>
                        <?php foreach ($routers as $router): ?>
                            <option
                                value="<?= (int)$router['id'] ?>"
                                <?= (int)$subscription['router_id'] === (int)$router['id'] ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($router['name']) ?>
                                — <?= htmlspecialchars($router['host']) ?>
                                (<?= htmlspecialchars($router['status']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Service, invoice, dan provisioning akan terikat ke router ini.</div>
                </div>

            </div>

        </div>

    </div>

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white fw-semibold">
            Detail Layanan
        </div>

        <div class="card-body">

            <div class="row g-3">

                <div class="col-md-4">

                    <label class="form-label">
                        Jenis Layanan
                    </label>

                    <input
                        type="text"
                        id="service_type_view"
                        class="form-control"
                        readonly
                        value="<?= htmlspecialchars($subscription['service_type']) ?>"
                    >

                </div>

                <div class="col-md-4">

                    <label class="form-label">
                        Download
                    </label>

                    <div class="input-group">

                        <input
                            type="text"
                            id="download_view"
                            class="form-control"
                            readonly
                            value="<?= (int)$subscription['speed_download'] ?>"
                        >

                        <span class="input-group-text">
                            Mbps
                        </span>

                    </div>

                </div>

                <div class="col-md-4">

                    <label class="form-label">
                        Upload
                    </label>

                    <div class="input-group">

                        <input
                            type="text"
                            id="upload_view"
                            class="form-control"
                            readonly
                            value="<?= (int)$subscription['speed_upload'] ?>"
                        >

                        <span class="input-group-text">
                            Mbps
                        </span>

                    </div>

                </div>

                <div class="col-md-4">

                    <label class="form-label">
                        Harga
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            Rp
                        </span>

                        <input
                            type="text"
                            id="price_view"
                            class="form-control"
                            readonly
                            value="<?= number_format((float)$subscription['price'], 0, ',', '.') ?>"
                        >

                    </div>

                </div>

                <div class="col-md-4">

                    <label class="form-label">
                        Siklus Billing
                    </label>

                    <input
                        type="text"
                        id="cycle_view"
                        class="form-control"
                        readonly
                        value="<?= htmlspecialchars($subscription['billing_cycle']) ?>"
                    >

                </div>

                <div class="col-md-4">

                    <label class="form-label">
                        Username PPPoE / Hotspot
                    </label>

                    <input
                        type="text"
                        name="username"
                        class="form-control"
                        maxlength="150"
                        value="<?= htmlspecialchars($subscription['username'] ?? '') ?>"
                        placeholder="username pelanggan"
                    >

                </div>

                <div class="col-md-4 hotspot-credential-field">
                    <label class="form-label">Password Hotspot</label>
                    <input
                        type="password"
                        name="password"
                        class="form-control"
                        maxlength="150"
                        placeholder="password user MikroTik"
                        autocomplete="new-password"
                    >
                    <div class="form-text">Dipakai sama persis untuk user Hotspot MikroTik.</div>
                </div>

                <div class="col-md-4 hotspot-credential-field">
                    <label class="form-label">IP Binding (opsional)</label>
                    <input
                        type="text"
                        name="hotspot_address"
                        class="form-control"
                        value="<?= htmlspecialchars($subscription['hotspot_address'] ?? '') ?>"
                        placeholder="192.168.88.10"
                    >
                </div>

                <div class="col-md-4 hotspot-credential-field">
                    <label class="form-label">MAC Binding (opsional)</label>
                    <input
                        type="text"
                        name="hotspot_mac_address"
                        class="form-control"
                        value="<?= htmlspecialchars($subscription['hotspot_mac_address'] ?? '') ?>"
                        placeholder="AA:BB:CC:DD:EE:FF"
                    >
                </div>

                <div class="col-md-6">

                    <label class="form-label">
                        Status
                    </label>

                    <select name="status" class="form-select">

                        <?php
                        $statuses = [
                            'ACTIVE' => 'ACTIVE',
                            'GRACE_PERIOD' => 'GRACE PERIOD',
                            'SUSPENDED' => 'SUSPENDED',
                            'TERMINATED' => 'TERMINATED',
                        ];
                        ?>

                        <?php foreach ($statuses as $value => $label): ?>

                            <option
                                value="<?= $value ?>"
                                <?= $subscription['status'] === $value ? 'selected' : '' ?>
                            >
                                <?= $label ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-6">

                    <label class="form-label">
                        Jatuh Tempo
                    </label>

                    <input
                        type="date"
                        name="next_due_date"
                        class="form-control"
                        value="<?= htmlspecialchars($subscription['next_due_date'] ?? '') ?>"
                    >

                </div>

            </div>

        </div>

    </div>

    <div class="d-flex justify-content-end gap-2">

        <a href="subscriptions.php" class="btn btn-outline-secondary">
            Batal
        </a>

        <button
            type="submit"
            class="btn btn-primary"
            <?= (!$customers || !$plans) ? 'disabled' : '' ?>
        >
            <i class="bi bi-check-lg me-1"></i>
            Simpan Subscription
        </button>

    </div>

</form>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const select = document.getElementById('service_plan_id');

    const typeView = document.getElementById('service_type_view');
    const downloadView = document.getElementById('download_view');
    const uploadView = document.getElementById('upload_view');
    const priceView = document.getElementById('price_view');
    const cycleView = document.getElementById('cycle_view');

    function updatePlanInfo() {

        const option = select.options[select.selectedIndex];

        if (!option || !option.value) {
            return;
        }

        typeView.value = option.dataset.type || '';
        downloadView.value = option.dataset.download || '0';
        uploadView.value = option.dataset.upload || '0';

        const price = Number(option.dataset.price || 0);

        priceView.value = new Intl.NumberFormat('id-ID').format(price);

        cycleView.value = option.dataset.cycle || '';
    }

    select.addEventListener('change', updatePlanInfo);

    updatePlanInfo();
});
</script>

<?php
$content = ob_get_clean();

require __DIR__ . '/../app/layout/layout.php';
