<?php

require_once __DIR__ . '/../app/bootstrap.php';
/* BAJAMA_RBAC_ENFORCED */
\BAJAMA\Core\Auth::requireLogin();
\BAJAMA\Core\License::requireFeature(\db(), 'billing');
\BAJAMA\Core\RBAC::require(\db(), 'customers.manage');


if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (function_exists('requireLogin')) {
    requireLogin();
} elseif (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Customer';

$userId = (int)($_SESSION['user_id'] ?? 0);

$customerId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$error = '';
$success = '';

$data = [
    'customer_code' => '',
    'name' => '',
    'email' => '',
    'phone' => '',
    'address' => '',
    'status' => 'ACTIVE'
];


try {

    $env = [];

    foreach (
        file(
            dirname(__DIR__) . '/.env',
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

        [$k, $v] = explode('=', $line, 2);

        $env[trim($k)] = trim($v);
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


    /*
    |--------------------------------------------------------------------------
    | LOAD
    |--------------------------------------------------------------------------
    */

    if ($customerId > 0) {

        $stmt = $pdo->prepare("
            SELECT
                customer_code,
                name,
                email,
                phone,
                address,
                status
            FROM customers
            WHERE id = ?
              AND organization_id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $customerId,
            $organizationId
        ]);

        $found = $stmt->fetch();

        if (!$found) {
            throw new RuntimeException(
                'Pelanggan tidak ditemukan.'
            );
        }

        $data = $found;
    }


    /*
    |--------------------------------------------------------------------------
    | SAVE
    |--------------------------------------------------------------------------
    */

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        verify_csrf(
        (string)($_POST['_csrf'] ?? $_POST['csrf'] ?? '')
    );

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $status = $_POST['status'] ?? 'ACTIVE';

        if ($name === '') {
            throw new RuntimeException(
                'Nama pelanggan wajib diisi.'
            );
        }

        if (
            !in_array(
                $status,
                ['ACTIVE', 'INACTIVE', 'BLOCKED'],
                true
            )
        ) {
            $status = 'ACTIVE';
        }


        if ($customerId > 0) {

            $stmt = $pdo->prepare("
                UPDATE customers
                SET
                    name = ?,
                    email = ?,
                    phone = ?,
                    address = ?,
                    status = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND organization_id = ?
            ");

            $stmt->execute([
                $name,
                $email ?: null,
                $phone ?: null,
                $address ?: null,
                $status,
                $customerId,
                $organizationId
            ]);

        } else {

            /*
            |--------------------------------------------------------------------------
            | CUSTOMER CODE
            |--------------------------------------------------------------------------
            */

            do {

                $code =
                    'CUS-' .
                    date('ymd') .
                    '-' .
                    strtoupper(
                        substr(
                            bin2hex(random_bytes(3)),
                            0,
                            6
                        )
                    );

                $stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM customers
                    WHERE organization_id = ?
                      AND customer_code = ?
                ");

                $stmt->execute([
                    $organizationId,
                    $code
                ]);

            } while ((int)$stmt->fetchColumn() > 0);

/*
|--------------------------------------------------------------------------
| LICENSE LIMIT CHECK
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM customers
    WHERE organization_id = ?
");

$stmt->execute([
    $organizationId
]);

$currentCustomers = (int)$stmt->fetchColumn();

\BAJAMA\Core\License::requireLimit(
    $pdo,
    'customers',
    $currentCustomers
);

            $stmt = $pdo->prepare("
                INSERT INTO customers
                (
                    organization_id,
                    customer_code,
                    name,
                    email,
                    phone,
                    address,
                    status,
                    created_at,
                    updated_at
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
                    NOW(),
                    NOW()
                )
            ");

            $stmt->execute([
                $organizationId,
                $code,
                $name,
                $email ?: null,
                $phone ?: null,
                $address ?: null,
                $status
            ]);

            $customerId = (int)$pdo->lastInsertId();
        }


        header('Location: customers.php');
        exit;
    }

} catch (Throwable $e) {
    error_log('BAJAMA customer form error: ' . $e->getMessage());
    $error = 'Pelanggan tidak dapat disimpan.';
}

ob_start();

?>

<div class="d-flex justify-content-between align-items-center mb-4">

    <div>

        <h2 class="fw-bold mb-1">

            <?= $customerId > 0
                ? 'Perbarui Pelanggan'
                : 'Tambahkan Pelanggan' ?>

        </h2>

        <div class="text-muted small">
            Data pelanggan ISP.
        </div>

    </div>


    <a href="customers.php" class="btn btn-outline-secondary">

        <i class="bi bi-arrow-left me-1"></i>

        Kembali

    </a>

</div>


<?php if ($error): ?>

<div class="alert alert-danger">

    <i class="bi bi-exclamation-triangle me-2"></i>

    <?= htmlspecialchars($error) ?>

</div>

<?php endif; ?>


<div class="dashboard-card">

    <div class="dashboard-card-body">

        <form method="post">

            <input
                type="hidden"
                name="_csrf"
                value="<?= htmlspecialchars(csrf_token()) ?>">

            <input
                type="hidden"
                name="id"
                value="<?= (int)$customerId ?>">


            <?php if ($customerId > 0): ?>

                <div class="mb-3">

                    <label class="form-label">
                        Kode Pelanggan
                    </label>

                    <input
                        class="form-control"
                        value="<?= htmlspecialchars($data['customer_code']) ?>"
                        readonly>

                </div>

            <?php endif; ?>


            <div class="row g-3">

                <div class="col-md-6">

                    <label class="form-label">
                        Nama Pelanggan *
                    </label>

                    <input
                        type="text"
                        name="name"
                        class="form-control"
                        value="<?= htmlspecialchars($data['name']) ?>"
                        required>

                </div>


                <div class="col-md-6">

                    <label class="form-label">
                        Telephone atau Whatsapp
                    </label>

                    <input
                        type="text"
                        name="phone"
                        class="form-control"
                        value="<?= htmlspecialchars($data['phone'] ?? '') ?>">

                </div>


                <div class="col-md-6">

                    <label class="form-label">
                        Email
                    </label>

                    <input
                        type="email"
                        name="email"
                        class="form-control"
                        value="<?= htmlspecialchars($data['email'] ?? '') ?>">

                </div>


                <div class="col-md-6">

                    <label class="form-label">
                        Status
                    </label>

                    <select
                        name="status"
                        class="form-select">

                        <?php foreach (
                            ['ACTIVE', 'INACTIVE', 'BLOCKED']
                            as $status
                        ): ?>

                            <option
                                value="<?= $status ?>"
                                <?= $data['status'] === $status
                                    ? 'selected'
                                    : '' ?>>

                                <?= $status ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-12">

                    <label class="form-label">
                        Alamat
                    </label>

                    <textarea
                        name="address"
                        class="form-control"
                        rows="4"><?= htmlspecialchars($data['address'] ?? '') ?></textarea>

                </div>

            </div>


            <div class="mt-4 d-flex gap-2">

                <button class="btn btn-primary">

                    <i class="bi bi-check-lg me-1"></i>

                    Simpan Pelanggan

                </button>


                <a
                    href="customers.php"
                    class="btn btn-outline-secondary">

                    Batalkan

                </a>

            </div>

        </form>

    </div>

</div>

<?php

$content = ob_get_clean();

require __DIR__ . '/../app/layout/layout.php';
