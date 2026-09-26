<?php

require_once __DIR__ . '/../app/bootstrap.php';
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

/* =========================================================
 * LOAD DATABASE CONFIG FROM .env
 * ========================================================= */

$envFile = dirname(__DIR__) . '/.env';

if (!is_readable($envFile)) {
    http_response_code(500);
    exit('File konfigurasi .env tidak ditemukan.');
}

$env = [];

foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);

    if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
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

$requiredEnv = [
    'DB_HOST',
    'DB_PORT',
    'DB_DATABASE',
    'DB_USERNAME',
    'DB_PASSWORD'
];

foreach ($requiredEnv as $key) {
    if (!array_key_exists($key, $env)) {
        http_response_code(500);
        exit('Konfigurasi database tidak lengkap: ' . $key);
    }
}

$host = $env['DB_HOST'];
$port = $env['DB_PORT'];
$db   = $env['DB_DATABASE'];
$user = $env['DB_USERNAME'];
$pass = $env['DB_PASSWORD'];

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log('BAJAMA service_plan_form DB error: ' . $e->getMessage());
    http_response_code(500);
    exit('Koneksi database gagal.');
}

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

$plan = [
    'id' => 0,
    'name' => '',
    'code' => '',
    'service_type' => 'PPPOE',
    'speed_download' => 10,
    'speed_upload' => 5,
    'price' => '0',
    'billing_cycle' => 'MONTHLY',
    'description' => '',
    'status' => 'ACTIVE',
];

if ($id > 0) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM service_plans
        WHERE id = ?
          AND organization_id = ?
        LIMIT 1
    ");

    $stmt->execute([$id, $organizationId]);

    $found = $stmt->fetch();

    if (!$found) {
        http_response_code(404);
        exit('Paket tidak ditemukan.');
    }

    $plan = $found;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf(
        (string)($_POST['_csrf'] ?? $_POST['csrf'] ?? '')
    );

    $name = trim($_POST['name'] ?? '');
    $code = strtoupper(trim($_POST['code'] ?? ''));

    $serviceType = $_POST['service_type'] ?? 'PPPOE';

    $speedDownload = (int)($_POST['speed_download'] ?? 0);
    $speedUpload   = (int)($_POST['speed_upload'] ?? 0);

    $price = (float)($_POST['price'] ?? 0);

    $billingCycle = $_POST['billing_cycle'] ?? 'MONTHLY';

    $description = trim($_POST['description'] ?? '');

    $status = $_POST['status'] ?? 'ACTIVE';

    if ($name === '') {
        $errors[] = 'Nama paket wajib diisi.';
    }

    if ($code === '') {
        $errors[] = 'Kode paket wajib diisi.';
    }

    if (!in_array($serviceType, ['PPPOE', 'HOTSPOT', 'STATIC', 'FTTH'], true)) {
        $errors[] = 'Tipe layanan tidak valid.';
    }

    if ($speedDownload < 0 || $speedUpload < 0) {
        $errors[] = 'Kecepatan tidak valid.';
    }

    if ($price < 0) {
        $errors[] = 'Harga tidak valid.';
    }

    if (!in_array($billingCycle, ['MONTHLY', 'QUARTERLY', 'YEARLY'], true)) {
        $errors[] = 'Siklus billing tidak valid.';
    }

    if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
        $errors[] = 'Status tidak valid.';
    }

    if (!$errors) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM service_plans
            WHERE organization_id = ?
              AND code = ?
              AND id <> ?
            LIMIT 1
        ");

        $stmt->execute([
            $organizationId,
            $code,
            $id
        ]);

        if ($stmt->fetch()) {
            $errors[] = 'Kode paket sudah digunakan.';
        }
    }

    if (!$errors) {

        if ($id > 0) {

            $stmt = $pdo->prepare("
                UPDATE service_plans
                SET
                    name = ?,
                    code = ?,
                    service_type = ?,
                    speed_download = ?,
                    speed_upload = ?,
                    price = ?,
                    billing_cycle = ?,
                    description = ?,
                    status = ?
                WHERE id = ?
                  AND organization_id = ?
            ");

            $stmt->execute([
                $name,
                $code,
                $serviceType,
                $speedDownload,
                $speedUpload,
                $price,
                $billingCycle,
                $description !== '' ? $description : null,
                $status,
                $id,
                $organizationId
            ]);

            header('Location: service_plans.php?msg=' . urlencode('Paket berhasil diperbarui.'));
            exit;

        } else {

            $stmt = $pdo->prepare("
                INSERT INTO service_plans
                (
                    organization_id,
                    name,
                    code,
                    service_type,
                    speed_download,
                    speed_upload,
                    price,
                    billing_cycle,
                    description,
                    status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $organizationId,
                $name,
                $code,
                $serviceType,
                $speedDownload,
                $speedUpload,
                $price,
                $billingCycle,
                $description !== '' ? $description : null,
                $status
            ]);

            header('Location: service_plans.php?msg=' . urlencode('Paket berhasil dibuat.'));
            exit;
        }
    }

    $plan = array_merge($plan, [
        'name' => $name,
        'code' => $code,
        'service_type' => $serviceType,
        'speed_download' => $speedDownload,
        'speed_upload' => $speedUpload,
        'price' => $price,
        'billing_cycle' => $billingCycle,
        'description' => $description,
        'status' => $status,
    ]);
}

$title = $id > 0 ? 'Edit Paket Internet' : 'Tambah Paket Internet';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">

    <div>
        <h2 class="fw-bold mb-1">
            <?= $id > 0 ? 'Edit Paket Internet' : 'Tambah Paket Internet' ?>
        </h2>

        <div class="text-muted">
            Tentukan paket layanan yang dapat digunakan pada subscription.
        </div>
    </div>

    <a href="service_plans.php" class="btn btn-outline-secondary">
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

<form method="post">

    <input
        type="hidden"
        name="_csrf"
        value="<?= htmlspecialchars(csrf_token()) ?>">

    <input type="hidden" name="id" value="<?= (int)$plan['id'] ?>">

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-header bg-white fw-semibold">
            Informasi Paket
        </div>

        <div class="card-body">

            <div class="row g-3">

                <div class="col-md-6">

                    <label class="form-label">
                        Nama Paket
                    </label>

                    <input
                        type="text"
                        name="name"
                        class="form-control"
                        required
                        maxlength="150"
                        value="<?= htmlspecialchars($plan['name']) ?>"
                        placeholder="Contoh: BAJAMA Home 20 Mbps"
                    >

                </div>

                <div class="col-md-6">

                    <label class="form-label">
                        Kode Paket
                    </label>

                    <input
                        type="text"
                        name="code"
                        class="form-control text-uppercase"
                        required
                        maxlength="80"
                        value="<?= htmlspecialchars($plan['code']) ?>"
                        placeholder="Contoh: HOME20"
                    >

                    <div class="form-text">
                        Kode harus unik untuk organisasi Anda.
                    </div>

                </div>

                <div class="col-md-4">

                    <label class="form-label">
                        Jenis Layanan
                    </label>

                    <select name="service_type" class="form-select">

                        <?php
                        $types = [
                            'PPPOE'   => 'PPPoE',
                            'HOTSPOT' => 'Hotspot',
                            'STATIC'  => 'Static IP',
                            'FTTH'    => 'FTTH',
                        ];
                        ?>

                        <?php foreach ($types as $value => $label): ?>

                            <option
                                value="<?= $value ?>"
                                <?= $plan['service_type'] === $value ? 'selected' : '' ?>
                            >
                                <?= $label ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-4">

                    <label class="form-label">
                        Download (Mbps)
                    </label>

                    <input
                        type="number"
                        name="speed_download"
                        class="form-control"
                        min="0"
                        value="<?= (int)$plan['speed_download'] ?>"
                    >

                </div>

                <div class="col-md-4">

                    <label class="form-label">
                        Upload (Mbps)
                    </label>

                    <input
                        type="number"
                        name="speed_upload"
                        class="form-control"
                        min="0"
                        value="<?= (int)$plan['speed_upload'] ?>"
                    >

                </div>

                <div class="col-md-6">

                    <label class="form-label">
                        Harga
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            Rp
                        </span>

                        <input
                            type="number"
                            name="price"
                            class="form-control"
                            min="0"
                            step="0.01"
                            value="<?= htmlspecialchars((string)$plan['price']) ?>"
                        >

                    </div>

                </div>

                <div class="col-md-6">

                    <label class="form-label">
                        Siklus Billing
                    </label>

                    <select name="billing_cycle" class="form-select">

                        <?php
                        $cycles = [
                            'MONTHLY'   => 'Bulanan',
                            'QUARTERLY' => '3 Bulanan',
                            'YEARLY'    => 'Tahunan',
                        ];
                        ?>

                        <?php foreach ($cycles as $value => $label): ?>

                            <option
                                value="<?= $value ?>"
                                <?= $plan['billing_cycle'] === $value ? 'selected' : '' ?>
                            >
                                <?= $label ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-6">

                    <label class="form-label">
                        Status
                    </label>

                    <select name="status" class="form-select">

                        <option
                            value="ACTIVE"
                            <?= $plan['status'] === 'ACTIVE' ? 'selected' : '' ?>
                        >
                            ACTIVE
                        </option>

                        <option
                            value="INACTIVE"
                            <?= $plan['status'] === 'INACTIVE' ? 'selected' : '' ?>
                        >
                            INACTIVE
                        </option>

                    </select>

                </div>

                <div class="col-12">

                    <label class="form-label">
                        Deskripsi
                    </label>

                    <textarea
                        name="description"
                        class="form-control"
                        rows="4"
                        placeholder="Keterangan paket..."
                    ><?= htmlspecialchars($plan['description'] ?? '') ?></textarea>

                </div>

            </div>

        </div>

    </div>

    <div class="d-flex justify-content-end gap-2">

        <a href="service_plans.php" class="btn btn-outline-secondary">
            Batal
        </a>

        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i>
            Simpan Paket
        </button>

    </div>

</form>

<?php
$content = ob_get_clean();

require __DIR__ . '/../app/layout/layout.php';
