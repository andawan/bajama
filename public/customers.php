<?php

require_once __DIR__ . '/../app/bootstrap.php';

/* BAJAMA_RBAC_ACTION_ENFORCED */
\BAJAMA\Core\Auth::requireLogin();
\BAJAMA\Core\License::requireFeature(\db(), 'billing');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    \BAJAMA\Core\RBAC::require(\db(), 'customers.manage');
} else {
    \BAJAMA\Core\RBAC::require(\db(), 'customers.view');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (function_exists('requireLogin')) {
    requireLogin();
} elseif (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Customers';

$userId = (int)($_SESSION['user_id'] ?? 0);

$customers = [];
$error = '';
$success = '';

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


    /*
    |--------------------------------------------------------------------------
    | CURRENT ORGANIZATION
    |--------------------------------------------------------------------------
    */

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
    | DELETE / DEACTIVATE
    |--------------------------------------------------------------------------
    */

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['action'])
    ) {

        verify_csrf(
        (string)($_POST['_csrf'] ?? $_POST['csrf'] ?? '')
    );

        $customerId = (int)($_POST['customer_id'] ?? 0);

        if ($_POST['action'] === 'permanent_delete' && $customerId > 0) {
            $customerStmt = $pdo->prepare(
                'SELECT customer_code, name
                 FROM customers
                 WHERE id = ? AND organization_id = ?
                 LIMIT 1'
            );
            $customerStmt->execute([$customerId, $organizationId]);
            $customer = $customerStmt->fetch();

            if (!$customer) {
                throw new RuntimeException('Pelanggan tidak ditemukan.');
            }

            $historyStmt = $pdo->prepare(
                'SELECT
                    (SELECT COUNT(*) FROM subscriptions WHERE customer_id = ? AND organization_id = ?) AS subscriptions_count,
                    (SELECT COUNT(*) FROM invoices WHERE customer_id = ? AND organization_id = ?) AS invoices_count'
            );
            $historyStmt->execute([$customerId, $organizationId, $customerId, $organizationId]);
            $history = $historyStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ((int)($history['subscriptions_count'] ?? 0) > 0 || (int)($history['invoices_count'] ?? 0) > 0) {
                throw new RuntimeException('Pelanggan memiliki histori subscription atau invoice. Gunakan nonaktifkan, bukan hapus permanen.');
            }

            $pdo->beginTransaction();
            try {
                \BAJAMA\Core\Audit::log(
                    $pdo,
                    'CUSTOMER_PERMANENT_DELETE',
                    'billing',
                    'customer',
                    $customerId,
                    [
                        'customer_code' => $customer['customer_code'],
                        'name' => $customer['name'],
                    ]
                );

                $delete = $pdo->prepare(
                    'DELETE FROM customers
                     WHERE id = ? AND organization_id = ?'
                );
                $delete->execute([$customerId, $organizationId]);

                if ($delete->rowCount() !== 1) {
                    throw new RuntimeException('Pelanggan gagal dihapus.');
                }

                $pdo->commit();
            } catch (Throwable $deleteError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $deleteError;
            }

            $success = 'Pelanggan dan data relasinya berhasil dihapus permanen.';
        }

        if (
            in_array((string)$_POST['action'], ['deactivate', 'delete'], true) &&
            $customerId > 0
        ) {

            $stmt = $pdo->prepare("
                UPDATE customers
                SET status = 'INACTIVE',
                    updated_at = NOW()
                WHERE id = ?
                  AND organization_id = ?
            ");

            $stmt->execute([
                $customerId,
                $organizationId
            ]);

            $success = $_POST['action'] === 'delete'
                ? 'Pelanggan berhasil dihapus dari daftar aktif.'
                : 'Pelanggan berhasil dinonaktifkan.';

        }

        if (
            $_POST['action'] === 'activate' &&
            $customerId > 0
        ) {

            $stmt = $pdo->prepare("
                UPDATE customers
                SET status = 'ACTIVE',
                    updated_at = NOW()
                WHERE id = ?
                  AND organization_id = ?
            ");

            $stmt->execute([
                $customerId,
                $organizationId
            ]);

            $success = 'Pelanggan berhasil diaktifkan.';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | SEARCH
    |--------------------------------------------------------------------------
    */

    $search = trim($_GET['q'] ?? '');

    if ($search !== '') {

        $stmt = $pdo->prepare("
            SELECT
                id,
                customer_code,
                name,
                email,
                phone,
                address,
                status,
                created_at
            FROM customers
            WHERE organization_id = ?
              AND (
                    customer_code LIKE ?
                    OR name LIKE ?
                    OR email LIKE ?
                    OR phone LIKE ?
              )
            ORDER BY id DESC
        ");

        $like = '%' . $search . '%';

        $stmt->execute([
            $organizationId,
            $like,
            $like,
            $like,
            $like
        ]);

    } else {

        $stmt = $pdo->prepare("
            SELECT
                id,
                customer_code,
                name,
                email,
                phone,
                address,
                status,
                created_at
            FROM customers
            WHERE organization_id = ?
            ORDER BY id DESC
        ");

        $stmt->execute([$organizationId]);
    }

    $customers = $stmt->fetchAll();

} catch (Throwable $e) {
    error_log('BAJAMA customers error: ' . $e->getMessage());
    $error = 'Data pelanggan tidak dapat dimuat.';
}

ob_start();

?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Controll pelanggan</h1>
            <p class="text-muted mb-0">Kelola status aktif dan data pelanggan anda.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="customer_form.php" class="btn btn-primary">
                <i class="bi bi-person-plus-fill me-1"></i>
                Tambah Pelanggan
            </a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success border-0 shadow-sm">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger border-0 shadow-sm">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-3">
            <span>Daftar pelanggan</span>
            <span class="badge bg-light text-dark"><?= number_format(count($customers)) ?> pelanggan</span>
        </div>

        <div class="card-body">
            <form method="get" class="row g-2 mb-3">
                <div class="col-md-5">
                    <input type="search" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Cari nama, email, nomor HP, atau kode pelanggan...">
                </div>
                <div class="col-auto">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i> Cari</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Nama&Id</th>
                            <th>Kontak & Email</th>
                            <th>Status</th>
                            <th>Dibuat</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$customers): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">Belum ada pelanggan yang terdaftar.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($customers as $customer): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars((string)($customer['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <small class="text-muted"><?= htmlspecialchars((string)($customer['customer_code'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($customer['phone'])): ?>
                                        <div><i class="bi bi-telephone me-1"></i><?= htmlspecialchars((string)$customer['phone'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($customer['email'])): ?>
                                        <div class="small text-muted"><?= htmlspecialchars((string)$customer['email'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                        $statusClass = [
                                            'ACTIVE' => 'success',
                                            'INACTIVE' => 'secondary',
                                            'BLOCKED' => 'danger'
                                        ][$customer['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge text-bg-<?= $statusClass ?>"><?= htmlspecialchars((string)($customer['status'] ?? 'UNKNOWN'), ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td class="small text-muted"><?= htmlspecialchars(date('d M Y', strtotime((string)($customer['created_at'] ?? time()))), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-end">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <a href="customer_form.php?id=<?= (int)$customer['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit pelanggan"><i class="bi bi-pencil"></i></a>

                                        <?php if (($customer['status'] ?? '') === 'ACTIVE'): ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Nonaktifkan pelanggan ini?')">
                                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="deactivate">
                                                <input type="hidden" name="customer_id" value="<?= (int)$customer['id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-person-dash"></i></button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="activate">
                                                <input type="hidden" name="customer_id" value="<?= (int)$customer['id'] ?>">
                                                <button class="btn btn-sm btn-outline-success"><i class="bi bi-person-check"></i></button>
                                            </form>
                                        <?php endif; ?>

                                        <form method="post" class="d-inline" onsubmit="return confirm('Hapus pelanggan ini secara permanen?')">
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="permanent_delete">
                                            <input type="hidden" name="customer_id" value="<?= (int)$customer['id'] ?>">
                                            <button class="btn btn-sm btn-danger"><i class="bi bi-trash3-fill"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../app/layout/layout.php';
