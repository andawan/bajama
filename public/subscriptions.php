<?php

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Network\RouterOSProvisioningService;

/* BAJAMA_RBAC_ACTION_ENFORCED */
\BAJAMA\Core\Auth::requireLogin();
\BAJAMA\Core\License::requireFeature(\db(), 'billing');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    \BAJAMA\Core\RBAC::require(\db(), 'billing.manage');
} else {
    \BAJAMA\Core\RBAC::require(\db(), 'billing.view');
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf(
        (string)($_POST['_csrf'] ?? $_POST['csrf'] ?? '')
    );

    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    /*
     * DELETE SUBSCRIPTION
     * Hanya subscription milik organization aktif yang boleh dihapus.
     */
    if ($action === 'delete' && $id > 0) {

        try {

            $service = new RouterOSProvisioningService($pdo, $organizationId);
            $lookup = $pdo->prepare('SELECT service_type FROM subscriptions WHERE id=? AND organization_id=? LIMIT 1');
            $lookup->execute([$id, $organizationId]);
            if ((string)$lookup->fetchColumn() === 'HOTSPOT') {
                $service->remove('subscription_hotspot', $id);
            }

            $stmt = $pdo->prepare("
                DELETE FROM subscriptions
                WHERE id = ?
                  AND organization_id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $id,
                $organizationId
            ]);

            header('Location: subscriptions.php');
            exit;

        } catch (Throwable $e) {

            http_response_code(409);

            exit(
                'Subscription tidak dapat dihapus karena masih digunakan oleh data terkait.'
            );
        }
    }

    if ($action === 'status' && $id > 0) {

        $stmt = $pdo->prepare("
            SELECT status
            FROM subscriptions
            WHERE id = ?
              AND organization_id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $id,
            $organizationId
        ]);

        $current = $stmt->fetchColumn();

        if ($current !== false) {

            $newStatus =
                $current === 'ACTIVE'
                ? 'SUSPENDED'
                : 'ACTIVE';

            $stmt = $pdo->prepare("
                UPDATE subscriptions
                SET status = ?
                WHERE id = ?
                  AND organization_id = ?
            ");

            $stmt->execute([
                $newStatus,
                $id,
                $organizationId
            ]);

            $lookup = $pdo->prepare('SELECT service_type FROM subscriptions WHERE id=? AND organization_id=? LIMIT 1');
            $lookup->execute([$id, $organizationId]);
            if ((string)$lookup->fetchColumn() === 'HOTSPOT') {
                (new RouterOSProvisioningService($pdo, $organizationId))
                    ->setEnabled('subscription_hotspot', $id, $newStatus === 'ACTIVE');
            }
        }

        header(
            'Location: subscriptions.php?msg=' .
            urlencode('Status subscription berhasil diperbarui.')
        );

        exit;
    }
}

$search = trim($_GET['q'] ?? '');

$sql = "
    SELECT
        s.id,
        s.service_type,
        s.service_name,
        s.username,
        s.status,
        s.billing_cycle,
        s.price,
        s.next_due_date,
        s.speed_download,
        s.speed_upload,

        c.customer_code,
        c.name AS customer_name,

        p.code AS plan_code,
        p.name AS plan_name

    FROM subscriptions s

    INNER JOIN customers c
        ON c.id = s.customer_id
       AND c.organization_id = s.organization_id

    LEFT JOIN service_plans p
        ON p.id = s.service_plan_id
       AND p.organization_id = s.organization_id

    WHERE s.organization_id = ?
";

$params = [$organizationId];

if ($search !== '') {

    $sql .= "
        AND (
            c.name LIKE ?
            OR c.customer_code LIKE ?
            OR s.service_name LIKE ?
            OR s.username LIKE ?
            OR p.code LIKE ?
        )
    ";

    $like = '%' . $search . '%';

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= "
    ORDER BY s.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$subscriptions = $stmt->fetchAll();

$msg = $_GET['msg'] ?? '';

$title = 'Subscription';

ob_start();
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

    <div>
        <h2 class="fw-bold mb-1">
            Subscription
        </h2>

        <div class="text-muted">
            Kelola layanan internet customer.
        </div>
    </div>

    <a href="subscription_form.php" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>
        Tambah Subscription
    </a>

</div>

<?php if ($msg !== ''): ?>

    <div class="alert alert-success alert-dismissible fade show">

        <i class="bi bi-check-circle me-2"></i>

        <?= htmlspecialchars($msg) ?>

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert"
        ></button>

    </div>

<?php endif; ?>

<div class="card border-0 shadow-sm">

    <div class="card-body">

        <form method="get" class="row g-2 mb-4">

            <div class="col-md-8">

                <input
                    type="text"
                    name="q"
                    class="form-control"
                    value="<?= htmlspecialchars($search) ?>"
                    placeholder="Cari customer, paket, username..."
                >

            </div>

            <div class="col-md-2">

                <button class="btn btn-outline-primary w-100">
                    <i class="bi bi-search me-1"></i>
                    Cari
                </button>

            </div>

            <div class="col-md-2">

                <a
                    href="subscriptions.php"
                    class="btn btn-outline-secondary w-100"
                >
                    Reset
                </a>

            </div>

        </form>

        <div class="bajama-table-responsive subscriptions-table-responsive">

            <table class="table table-hover align-middle bajama-table" data-responsive-table="true">

                <thead>

                    <tr>

                        <th data-label="Customer">Customer</th>
                        <th data-label="Paket">Paket</th>
                        <th data-label="Layanan">Layanan</th>
                        <th data-label="Kecepatan">Kecepatan</th>
                        <th data-label="Harga">Harga</th>
                        <th data-label="Jatuh Tempo">Jatuh Tempo</th>
                        <th data-label="Status">Status</th>
                        <th class="text-end" data-label="Aksi">Aksi</th>

                    </tr>

                </thead>

                <tbody>

                <?php if (!$subscriptions): ?>

                    <tr>

                        <td
                            colspan="8"
                            class="text-center text-muted py-5"
                        >

                            <i class="bi bi-reception-4 fs-1 d-block mb-2"></i>

                            Belum ada subscription.

                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($subscriptions as $sub): ?>

                        <tr class="bajama-table-row">

                            <td data-label="Customer">

                                <div class="fw-semibold">
                                    <?= htmlspecialchars($sub['customer_name']) ?>
                                </div>

                                <small class="text-muted">
                                    <?= htmlspecialchars($sub['customer_code']) ?>
                                </small>

                            </td>

                            <td data-label="Paket">

                                <div class="fw-semibold">

                                    <?= htmlspecialchars(
                                        $sub['plan_name']
                                        ?: $sub['service_name']
                                    ) ?>

                                </div>

                                <?php if (!empty($sub['plan_code'])): ?>

                                    <small class="text-muted">
                                        <?= htmlspecialchars($sub['plan_code']) ?>
                                    </small>

                                <?php endif; ?>

                            </td>

                            <td data-label="Layanan">

                                <span class="badge text-bg-light border">

                                    <?= htmlspecialchars(
                                        $sub['service_type']
                                    ) ?>

                                </span>

                            </td>

                            <td data-label="Kecepatan">

                                <?= number_format(
                                    (int)$sub['speed_download']
                                ) ?>

                                /

                                <?= number_format(
                                    (int)$sub['speed_upload']
                                ) ?>

                                Mbps

                            </td>

                            <td data-label="Harga">

                                Rp
                                <?= number_format(
                                    (float)$sub['price'],
                                    0,
                                    ',',
                                    '.'
                                ) ?>

                            </td>

                            <td data-label="Jatuh Tempo">

                                <?= !empty($sub['next_due_date'])
                                    ? htmlspecialchars($sub['next_due_date'])
                                    : '-' ?>

                            </td>

                            <td data-label="Status">

                                <?php

                                $badge = [
                                    'ACTIVE' => 'success',
                                    'GRACE_PERIOD' => 'warning',
                                    'SUSPENDED' => 'danger',
                                    'TERMINATED' => 'secondary',
                                ];

                                $class = $badge[$sub['status']] ?? 'secondary';

                                ?>

                                <span class="badge text-bg-<?= $class ?>">

                                    <?= htmlspecialchars($sub['status']) ?>

                                </span>

                            </td>

                            <td class="text-end bajama-table-actions" data-label="Aksi">

                                <a
                                    href="subscription_form.php?id=<?= (int)$sub['id'] ?>"
                                    class="btn btn-sm btn-outline-primary"
                                    title="Edit"
                                >
                                    <i class="bi bi-pencil"></i>
                                </a>

                                <form
                                    method="post"
                                    class="d-inline"
                                    onsubmit="return confirm('Ubah status subscription ini?')"
                                >

                                    <input
                                        type="hidden"
                                        name="_csrf"
                                        value="<?= htmlspecialchars(csrf_token()) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="status"
                                    >

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int)$sub['id'] ?>"
                                    >

                                    <button
                                        class="btn btn-sm btn-outline-warning"
                                        title="Ubah status"
                                    >
                                        <i class="bi bi-power"></i>
                                    </button>

                                </form>

                                <form
                                    method="post"
                                    class="d-inline"
                                    onsubmit="return confirm('Hapus subscription ini? Data subscription yang dihapus tidak dapat dikembalikan.')"
                                >

                                    <input
                                        type="hidden"
                                        name="_csrf"
                                        value="<?= htmlspecialchars(csrf_token()) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="delete"
                                    >

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int)$sub['id'] ?>"
                                    >

                                    <button
                                        class="btn btn-sm btn-outline-danger"
                                        title="Hapus subscription"
                                    >
                                        <i class="bi bi-trash"></i>
                                    </button>

                                </form>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<?php
$content = ob_get_clean();

require __DIR__ . '/../app/layout/layout.php';
