<?php

require_once __DIR__ . '/../app/bootstrap.php';

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

function sp_db()
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $envFile = __DIR__ . '/../.env';
    $env = [];

    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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
    }

    $host = $env['DB_HOST'] ?? '127.0.0.1';
    $port = $env['DB_PORT'] ?? '3306';
    $db   = $env['DB_DATABASE'] ?? 'bajama';
    $user = $env['DB_USERNAME'] ?? 'bajama';
    $pass = $env['DB_PASSWORD'] ?? '';

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    return $pdo;
}

$pdo = sp_db();

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
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'toggle' && $id > 0) {

        $stmt = $pdo->prepare("
            UPDATE service_plans
            SET status = CASE
                WHEN status = 'ACTIVE' THEN 'INACTIVE'
                ELSE 'ACTIVE'
            END
            WHERE id = ?
              AND organization_id = ?
        ");

        $stmt->execute([$id, $organizationId]);

        header('Location: service_plans.php?msg=' . urlencode('Status paket berhasil diubah.'));
        exit;
    }

    if ($action === 'delete' && $id > 0) {

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM subscriptions
            WHERE organization_id = ?
                            AND service_plan_id = ?
        ");

        $stmt->execute([
            $organizationId,
                        $id
        ]);

        $used = (int)$stmt->fetchColumn();

        if ($used > 0) {
            header('Location: service_plans.php?error=' . urlencode(
                'Paket tidak dapat dihapus karena sudah digunakan oleh subscription.'
            ));
            exit;
        }

        $stmt = $pdo->prepare("
            DELETE FROM service_plans
            WHERE id = ?
              AND organization_id = ?
        ");

        $stmt->execute([$id, $organizationId]);

        header('Location: service_plans.php?msg=' . urlencode('Paket berhasil dihapus.'));
        exit;
    }
}

$search = trim($_GET['q'] ?? '');

$sql = "
    SELECT
        id,
        name,
        code,
        service_type,
        speed_download,
        speed_upload,
        price,
        billing_cycle,
        description,
        status,
        created_at
    FROM service_plans
    WHERE organization_id = ?
";

$params = [$organizationId];

if ($search !== '') {
    $sql .= "
        AND (
            name LIKE ?
            OR code LIKE ?
            OR service_type LIKE ?
        )
    ";

    $like = '%' . $search . '%';

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY status DESC, id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$plans = $stmt->fetchAll();

$msg   = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';

$title = 'Paket Internet';

ob_start();
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h2 class="fw-bold mb-1">Paket Internet</h2>
        <div class="text-muted">
            Kelola paket layanan internet untuk customer dan subscription.
        </div>
    </div>

    <a href="service_plan_form.php" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>
        Tambah Paket
    </a>
</div>

<?php if ($msg !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle me-2"></i>
        <?= htmlspecialchars($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
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
                    placeholder="Cari nama paket, kode, atau tipe layanan..."
                >
            </div>

            <div class="col-md-2">
                <button class="btn btn-outline-primary w-100">
                    <i class="bi bi-search me-1"></i>
                    Cari
                </button>
            </div>

            <div class="col-md-2">
                <a href="service_plans.php" class="btn btn-outline-secondary w-100">
                    Reset
                </a>
            </div>
        </form>

        <div class="bajama-table-responsive service-plans-table-responsive">
            <table class="table table-hover align-middle bajama-table" data-responsive-table="true">
                <thead>
                    <tr>
                        <th data-label="Paket">Paket</th>
                        <th data-label="Layanan">Layanan</th>
                        <th data-label="Kecepatan">Kecepatan</th>
                        <th data-label="Harga">Harga</th>
                        <th data-label="Siklus">Siklus</th>
                        <th data-label="Status">Status</th>
                        <th class="text-end" data-label="Aksi">Aksi</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$plans): ?>

                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-box-seam fs-1 d-block mb-2"></i>
                            Belum ada paket internet.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($plans as $plan): ?>

                        <tr class="bajama-table-row">

                            <td data-label="Paket">
                                <div class="fw-semibold">
                                    <?= htmlspecialchars($plan['name']) ?>
                                </div>

                                <small class="text-muted">
                                    <?= htmlspecialchars($plan['code']) ?>
                                </small>
                            </td>

                            <td data-label="Layanan">
                                <span class="badge text-bg-light border">
                                    <?= htmlspecialchars($plan['service_type']) ?>
                                </span>
                            </td>

                            <td data-label="Kecepatan">
                                <div class="fw-semibold">
                                    <?= number_format((int)$plan['speed_download']) ?> Mbps
                                </div>

                                <small class="text-muted">
                                    Upload <?= number_format((int)$plan['speed_upload']) ?> Mbps
                                </small>
                            </td>

                            <td data-label="Harga">
                                <div class="fw-semibold">
                                    Rp <?= number_format((float)$plan['price'], 0, ',', '.') ?>
                                </div>
                            </td>

                            <td data-label="Siklus">
                                <?= htmlspecialchars($plan['billing_cycle']) ?>
                            </td>

                            <td data-label="Status">
                                <?php if ($plan['status'] === 'ACTIVE'): ?>
                                    <span class="badge text-bg-success">ACTIVE</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">INACTIVE</span>
                                <?php endif; ?>
                            </td>

                            <td class="text-end bajama-table-actions" data-label="Aksi">

                                <a
                                    href="service_plan_form.php?id=<?= (int)$plan['id'] ?>"
                                    class="btn btn-sm btn-outline-primary"
                                >
                                    <i class="bi bi-pencil"></i>
                                </a>

                                <form
                                    method="post"
                                    class="d-inline"
                                    onsubmit="return confirm('Ubah status paket ini?')"
                                >
                                    <input
                                        type="hidden"
                                        name="_csrf"
                                        value="<?= htmlspecialchars(csrf_token()) ?>">

                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$plan['id'] ?>">

                                    <button class="btn btn-sm btn-outline-warning">
                                        <i class="bi bi-power"></i>
                                    </button>
                                </form>

                                <form
                                    method="post"
                                    class="d-inline"
                                    onsubmit="return confirm('Hapus paket ini?')"
                                >
                                    <input
                                        type="hidden"
                                        name="_csrf"
                                        value="<?= htmlspecialchars(csrf_token()) ?>">

                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$plan['id'] ?>">

                                    <button class="btn btn-sm btn-outline-danger">
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
