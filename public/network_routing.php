<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\License;
use BAJAMA\Core\RBAC;
use BAJAMA\Core\Tenant;
use BAJAMA\Network\MikroTik;
use BAJAMA\Network\RouterOSProvisioningService;

Auth::requireLogin();

$db = db();

License::requireFeature($db, 'mikrotik');
RBAC::require($db, 'network.view');

$organizationId = Tenant::id();
$canManage = RBAC::hasPermission($db, 'network.manage');
$routers = MikroTik::all($db, $organizationId);

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function validIpOrCidr(string $value): bool
{
    $value = trim($value);

    if ($value === '') {
        return false;
    }

    if (strpos($value, '/') !== false) {
        [$ip, $prefix] = array_pad(explode('/', $value, 2), 2, '');

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if ($prefix === '' || !ctype_digit($prefix)) {
            return false;
        }

        $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? 128
            : 32;

        return (int)$prefix >= 0 && (int)$prefix <= $max;
    }

    return filter_var($value, FILTER_VALIDATE_IP) !== false;
}

function validGateway(?string $value): bool
{
    $value = trim((string)$value);

    if ($value === '') {
        return true;
    }

    return validIpOrCidr($value);
}

function redirectRouting(): void
{
    header('Location: network_routing.php');
    exit;
}

$message = '';
$error = '';

/*
 * One-time token untuk mencegah duplicate POST pada form save route.
 * Token dibuat per tampilan form dan hanya boleh diproses sekali.
 */
if (empty($_SESSION['network_routing_save_token'])) {
    $_SESSION['network_routing_save_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf(
            (string)($_POST['_csrf'] ?? $_POST['csrf'] ?? '')
        );

        if (!$canManage) {
            throw new RuntimeException('Anda tidak memiliki izin untuk mengubah routing.');
        }

        $action = (string)($_POST['action'] ?? '');

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            $routerId = (int)($_POST['router_id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('ID route tidak valid.');
            }

            (new RouterOSProvisioningService($db, (int)$organizationId))
                ->remove('network_route', $id);

            $stmt = $db->prepare(
                'DELETE FROM network_routes
                 WHERE id = :id AND organization_id = :organization_id'
            );

            $stmt->execute([
                ':id' => $id,
                ':organization_id' => $organizationId,
            ]);

            redirectRouting();
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('ID route tidak valid.');
            }

            $current = $db->prepare(
                'SELECT enabled FROM network_routes WHERE id=? AND organization_id=? LIMIT 1'
            );
            $current->execute([$id, $organizationId]);
            $currentEnabled = (int)$current->fetchColumn();

            (new RouterOSProvisioningService($db, (int)$organizationId)
                )->setEnabled('network_route', $id, !$currentEnabled);

            $stmt = $db->prepare(
                'UPDATE network_routes
                 SET enabled = CASE WHEN enabled = 1 THEN 0 ELSE 1 END
                 WHERE id = :id AND organization_id = :organization_id'
            );

            $stmt->execute([
                ':id' => $id,
                ':organization_id' => $organizationId,
            ]);

            redirectRouting();
        }

        if ($action === 'save') {
            $saveToken = (string)($_POST['_save_token'] ?? '');
            $sessionSaveToken = (string)($_SESSION['network_routing_save_token'] ?? '');

            if (
                $saveToken === '' ||
                $sessionSaveToken === '' ||
                !hash_equals($sessionSaveToken, $saveToken)
            ) {
                redirectRouting();
            }

            $id = (int)($_POST['id'] ?? 0);

            $routerId = (int)($_POST['router_id'] ?? 0);

            $name = trim((string)($_POST['name'] ?? ''));

            if ($routerId <= 0) {
                throw new RuntimeException('Router MikroTik wajib dipilih.');
            }

            $router = MikroTik::find($db, $organizationId, $routerId);

            if (!$router) {
                throw new RuntimeException(
                    'Router MikroTik tidak ditemukan atau bukan milik organisasi ini.'
                );
            }
            $routeType = strtoupper(trim((string)($_POST['route_type'] ?? 'STATIC')));
            $destination = trim((string)($_POST['destination'] ?? ''));
            $gateway = trim((string)($_POST['gateway'] ?? ''));
            $interfaceName = trim((string)($_POST['interface_name'] ?? ''));
            $routingTable = trim((string)($_POST['routing_table'] ?? 'main'));
            $distance = (int)($_POST['distance'] ?? 1);
            $scope = (int)($_POST['scope'] ?? 30);
            $targetScope = (int)($_POST['target_scope'] ?? 10);
            $checkGateway = strtoupper(trim((string)($_POST['check_gateway'] ?? 'NONE')));
            $comment = trim((string)($_POST['comment'] ?? ''));

            if ($name === '') {
                throw new RuntimeException('Nama route wajib diisi.');
            }

            if (!in_array($routeType, ['DEFAULT', 'STATIC', 'RECURSIVE'], true)) {
                throw new RuntimeException('Jenis route tidak valid.');
            }

            if ($routeType === 'DEFAULT') {
                $destination = '0.0.0.0/0';
            }

            if (!validIpOrCidr($destination)) {
                throw new RuntimeException('Destination harus berupa IP atau CIDR yang valid.');
            }

            if (!validGateway($gateway)) {
                throw new RuntimeException('Gateway tidak valid.');
            }

            if ($routingTable === '') {
                $routingTable = 'main';
            }

            if ($distance < 1 || $distance > 255) {
                throw new RuntimeException('Distance harus 1 sampai 255.');
            }

            if ($scope < 0 || $scope > 255) {
                throw new RuntimeException('Scope harus 0 sampai 255.');
            }

            if ($targetScope < 0 || $targetScope > 255) {
                throw new RuntimeException('Target Scope harus 0 sampai 255.');
            }

            if (!in_array($checkGateway, ['NONE', 'PING', 'ARP'], true)) {
                throw new RuntimeException('Check Gateway tidak valid.');
            }

            if ($id > 0) {
                $stmt = $db->prepare(
                    'UPDATE network_routes
                     SET
                        router_id = :router_id,
                        name = :name,
                        route_type = :route_type,
                        destination = :destination,
                        gateway = :gateway,
                        interface_name = :interface_name,
                        routing_table = :routing_table,
                        distance = :distance,
                        scope = :scope,
                        target_scope = :target_scope,
                        check_gateway = :check_gateway,
                        comment = :comment
                     WHERE id = :id
                       AND organization_id = :organization_id'
                );

                $stmt->execute([
                    ':id' => $id,
                    ':organization_id' => $organizationId,
                    ':router_id' => $routerId,
                    ':name' => $name,
                    ':route_type' => $routeType,
                    ':destination' => $destination,
                    ':gateway' => $gateway !== '' ? $gateway : null,
                    ':interface_name' => $interfaceName !== '' ? $interfaceName : null,
                    ':routing_table' => $routingTable,
                    ':distance' => $distance,
                    ':scope' => $scope,
                    ':target_scope' => $targetScope,
                    ':check_gateway' => $checkGateway,
                    ':comment' => $comment !== '' ? $comment : null,
                ]);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO network_routes
                    (
                        organization_id,
                        router_id,
                        name,
                        route_type,
                        destination,
                        gateway,
                        interface_name,
                        routing_table,
                        distance,
                        scope,
                        target_scope,
                        check_gateway,
                        enabled,
                        comment
                    )
                    VALUES
                    (
                        :organization_id,
                        :router_id,
                        :name,
                        :route_type,
                        :destination,
                        :gateway,
                        :interface_name,
                        :routing_table,
                        :distance,
                        :scope,
                        :target_scope,
                        :check_gateway,
                        1,
                        :comment
                    )'
                );

                $stmt->execute([
                    ':organization_id' => $organizationId,
                    ':router_id' => $routerId,
                    ':name' => $name,
                    ':route_type' => $routeType,
                    ':destination' => $destination,
                    ':gateway' => $gateway !== '' ? $gateway : null,
                    ':interface_name' => $interfaceName !== '' ? $interfaceName : null,
                    ':routing_table' => $routingTable,
                    ':distance' => $distance,
                    ':scope' => $scope,
                    ':target_scope' => $targetScope,
                    ':check_gateway' => $checkGateway,
                    ':comment' => $comment !== '' ? $comment : null,
                ]);
            }

            /*
             * Simpan hanya ke BAJAMA. Provisioning RouterOS harus dilakukan
             * melalui tombol Apply yang eksplisit pada daftar route.
             * Konsumsi token agar POST duplikat tidak menulis ulang data.
             */
            unset($_SESSION['network_routing_save_token']);

            redirectRouting();
        }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$editRoute = null;
$editRouterId = 0;

if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];

    if ($editId > 0) {
        $stmt = $db->prepare(
            'SELECT *
             FROM network_routes
             WHERE id = :id
               AND organization_id = :organization_id
             LIMIT 1'
        );

        $stmt->execute([
            ':id' => $editId,
            ':organization_id' => $organizationId,
        ]);

        $editRoute = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

if ($editRoute) {
    $editRouterId = (int)($editRoute['router_id'] ?? 0);
}
    }
}

$stmt = $db->prepare(
    'SELECT *
     FROM network_routes
     WHERE organization_id = :organization_id
     ORDER BY
        CASE route_type
            WHEN "DEFAULT" THEN 1
            WHEN "RECURSIVE" THEN 2
            ELSE 3
        END,
        destination ASC,
        id DESC'
);

$stmt->execute([
    ':organization_id' => $organizationId,
]);

$routes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalRoutes = count($routes);
$enabledRoutes = count(array_filter(
    $routes,
    static fn(array $r): bool => (int)$r['enabled'] === 1
));
$disabledRoutes = $totalRoutes - $enabledRoutes;

ob_start();
?>

<style>
.routing-page {
    padding: 24px;
}

.routing-hero {
    background: linear-gradient(135deg, #111827, #1f2937);
    color: #fff;
    border-radius: 18px;
    padding: 24px;
    margin-bottom: 20px;
    box-shadow: 0 12px 30px rgba(15,23,42,.12);
}

.routing-hero h2 {
    margin: 0;
    font-weight: 700;
}

.routing-hero p {
    margin: 6px 0 0;
    color: #cbd5e1;
}

.routing-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}

.routing-stat {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 18px;
}

.routing-stat small {
    color: #6b7280;
}

.routing-stat strong {
    display: block;
    font-size: 25px;
    margin-top: 4px;
}

.routing-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    overflow: hidden;
}

.routing-card-header {
    padding: 16px 18px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.routing-table th {
    white-space: nowrap;
    font-size: 12px;
    color: #64748b;
    text-transform: uppercase;
}

.routing-table td {
    vertical-align: middle;
}

.route-name {
    font-weight: 700;
}

.route-destination {
    font-family: monospace;
    font-weight: 600;
}

.route-meta {
    font-size: 12px;
    color: #64748b;
}

.route-badge {
    font-size: 10px;
    padding: 5px 8px;
    border-radius: 999px;
}

.route-enabled {
    background: #dcfce7;
    color: #166534;
}

.route-disabled {
    background: #fee2e2;
    color: #991b1b;
}

.route-type {
    background: #e0e7ff;
    color: #3730a3;
}

.routing-empty {
    padding: 60px 20px;
    text-align: center;
    color: #64748b;
}

.routing-empty i {
    font-size: 44px;
    display: block;
    margin-bottom: 12px;
}


.routing-modal-dialog {
    max-width: 1100px;
}

#routeModal .modal-content {
    border: 0;
    border-radius: 16px;
    overflow: hidden;
}

#routeModal .modal-header {
    background: #111827;
    color: #fff;
    border-bottom: 1px solid #273244;
    padding: 16px 20px;
}

#routeModal .modal-header .btn-close {
    filter: invert(1);
}

#routeModal .modal-body {
    max-height: calc(100vh - 190px);
    overflow-y: auto;
    padding: 22px;
}

#routeModal .modal-footer {
    background: #f8fafc;
    border-top: 1px solid #e5e7eb;
    padding: 14px 20px;
}

#routeModal .form-label {
    font-weight: 600;
    color: #334155;
}

#routeModal .form-control,
#routeModal .form-select {
    min-height: 42px;
    border-radius: 9px;
}

#routeModal .form-control:focus,
#routeModal .form-select:focus {
    box-shadow: 0 0 0 .2rem rgba(13,110,253,.12);
}

.routing-form-section {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 18px;
    margin-bottom: 18px;
    background: #fff;
}

.routing-form-section-title {
    font-size: 14px;
    font-weight: 700;
    color: #111827;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.routing-form-section-title i {
    color: #2563eb;
}

.routing-help {
    border-radius: 10px;
    padding: 12px 14px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1e3a8a;
    font-size: 13px;
}

@media (max-width: 768px) {
    .routing-modal-dialog {
        margin: 8px;
    }

    #routeModal .modal-body {
        max-height: calc(100vh - 150px);
        padding: 15px;
    }

    #routeModal .modal-footer {
        padding: 12px 15px;
    }

    .routing-form-section {
        padding: 14px;
    }
}

@media (max-width: 900px) {
    .routing-page {
        padding: 15px;
        padding-top: 75px;
    }

    .routing-stats {
        grid-template-columns: 1fr;
    }

    .routing-card-header {
        align-items: stretch;
        flex-direction: column;
    }
}

@media (max-width: 700px) {
    .routing-table thead {
        display: none;
    }

    .routing-table,
    .routing-table tbody,
    .routing-table tr,
    .routing-table td {
        display: block;
        width: 100%;
    }

    .routing-table tr {
        border-bottom: 1px solid #e5e7eb;
        padding: 12px;
    }

    .routing-table td {
        border: 0;
        padding: 5px 8px;
    }

    .routing-table td::before {
        content: attr(data-label);
        display: block;
        font-size: 10px;
        color: #94a3b8;
        text-transform: uppercase;
        margin-bottom: 2px;
    }
}
</style>

<style>
/* =========================================================
   ROUTING NETWORK MENU
   ========================================================= */

.bajama-network-routing-page .routing-heading {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

</style>


<div class="routing-page bajama-network-routing-page">

    <div class="routing-hero">
        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
            <div class="routing-heading">
                <div>
                    <h2>
                        <i class="bi bi-signpost-split me-2"></i>
                        Routing
                    </h2>
                    <p>
                        Static Route, Default Route dan Recursive Route BAJAMA.
                    </p>
                </div>
            </div>

            <?php if ($canManage): ?>
                <button
                    type="button"
                    class="btn btn-light"
                    data-bs-toggle="modal"
                    data-bs-target="#routeModal"
                    onclick="newRoute()">
                    <i class="bi bi-plus-lg me-1"></i>
                    Tambah Route
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <div class="routing-stats">
        <div class="routing-stat">
            <small>Total Route</small>
            <strong><?= $totalRoutes ?></strong>
        </div>

        <div class="routing-stat">
            <small>Aktif</small>
            <strong><?= $enabledRoutes ?></strong>
        </div>

        <div class="routing-stat">
            <small>Nonaktif</small>
            <strong><?= $disabledRoutes ?></strong>
        </div>
    </div>

    <div class="routing-card">

        <div class="routing-card-header">
            <div>
                <strong>
                    <i class="bi bi-signpost-2 me-1"></i>
                    Routing Table
                </strong>
                <div class="route-meta">
                    Konfigurasi tersimpan di BAJAMA.
                </div>
            </div>

            <span class="badge text-bg-secondary">
                <?= $totalRoutes ?> route
            </span>
        </div>

        <?php if (!$routes): ?>

            <div class="routing-empty">
                <i class="bi bi-signpost-split"></i>
                <strong>Belum ada route</strong>
                <div class="mt-1">
                    Tambahkan Default, Static atau Recursive Route.
                </div>
            </div>

        <?php else: ?>

            <div class="table-responsive">
                <table class="table table-hover mb-0 routing-table">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Type</th>
                            <th>Destination</th>
                            <th>Gateway</th>
                            <th>Interface</th>
                            <th>Table</th>
                            <th>Distance</th>
                            <th>Status</th>
                            <?php if ($canManage): ?>
                                <th>Aksi</th>
                            <?php endif; ?>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($routes as $route): ?>

                        <tr>

                            <td data-label="Nama">
                                <div class="route-name">
                                    <?= h($route['name']) ?>
                                </div>

                                <?php if (!empty($route['comment'])): ?>
                                    <div class="route-meta">
                                        <?= h($route['comment']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td data-label="Type">
                                <span class="route-badge route-type">
                                    <?= h($route['route_type']) ?>
                                </span>
                            </td>

                            <td data-label="Destination">
                                <span class="route-destination">
                                    <?= h($route['destination']) ?>
                                </span>
                            </td>

                            <td data-label="Gateway">
                                <?= $route['gateway']
                                    ? '<code>' . h($route['gateway']) . '</code>'
                                    : '<span class="text-muted">-</span>' ?>
                            </td>

                            <td data-label="Interface">
                                <?= $route['interface_name']
                                    ? h($route['interface_name'])
                                    : '<span class="text-muted">-</span>' ?>
                            </td>

                            <td data-label="Table">
                                <code><?= h($route['routing_table']) ?></code>
                            </td>

                            <td data-label="Distance">
                                <?= (int)$route['distance'] ?>
                            </td>

                            <td data-label="Status">

                                <?php if ((int)$route['enabled'] === 1): ?>

                                    <span class="route-badge route-enabled">
                                        ENABLED
                                    </span>

                                <?php else: ?>

                                    <span class="route-badge route-disabled">
                                        DISABLED
                                    </span>

                                <?php endif; ?>

                            </td>

                            <?php if ($canManage): ?>

                                <td data-label="Aksi">

                                    <div class="routing-actions d-flex flex-wrap gap-1">

                                        <a
                                            href="?edit=<?= (int)$route['id'] ?>"
                                            class="btn btn-sm btn-outline-primary"
                                            title="Edit">
                                            <i class="bi bi-pencil"></i>
                                            <span class="d-none d-xl-inline">Edit</span>
                                        </a>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-info"
                                            title="Test Gateway"
                                            data-route-id="<?= (int)$route['id'] ?>"
                                            data-router-id="<?= (int)($route['router_id'] ?? 0) ?>"
                                            data-csrf="<?= h(csrf_token()) ?>"
                                            onclick="routingApiAction(this, 'test_gateway')">
                                            <i class="bi bi-activity"></i>
                                            <span class="d-none d-xl-inline">Test</span>
                                        </button>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-success"
                                            title="Apply ke MikroTik"
                                            data-route-id="<?= (int)$route['id'] ?>"
                                            data-router-id="<?= (int)($route['router_id'] ?? 0) ?>"
                                            data-csrf="<?= h(csrf_token()) ?>"
                                            onclick="routingApiAction(this, 'apply')">
                                            <i class="bi bi-cloud-arrow-up"></i>
                                            <span class="d-none d-xl-inline">Apply</span>
                                        </button>

                                        <?php if (!empty($route['mikrotik_id'])): ?>

                                            <?php if ((int)$route['enabled'] === 1): ?>

                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-warning"
                                                    title="Disable di MikroTik"
                                                    data-route-id="<?= (int)$route['id'] ?>"
                                                    data-router-id="<?= (int)($route['router_id'] ?? 0) ?>"
                                                    data-csrf="<?= h(csrf_token()) ?>"
                                                    onclick="routingApiAction(this, 'disable')">
                                                    <i class="bi bi-pause-circle"></i>
                                                    <span class="d-none d-xl-inline">Disable</span>
                                                </button>

                                            <?php else: ?>

                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-success"
                                                    title="Enable di MikroTik"
                                                    data-route-id="<?= (int)$route['id'] ?>"
                                                    data-router-id="<?= (int)($route['router_id'] ?? 0) ?>"
                                                    data-csrf="<?= h(csrf_token()) ?>"
                                                    onclick="routingApiAction(this, 'enable')">
                                                    <i class="bi bi-play-circle"></i>
                                                    <span class="d-none d-xl-inline">Enable</span>
                                                </button>

                                            <?php endif; ?>

                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-danger"
                                                title="Remove dari MikroTik"
                                                data-route-id="<?= (int)$route['id'] ?>"
                                                data-router-id="<?= (int)($route['router_id'] ?? 0) ?>"
                                                data-csrf="<?= h(csrf_token()) ?>"
                                                onclick="routingApiAction(this, 'remove')">
                                                <i class="bi bi-cloud-arrow-down"></i>
                                                <span class="d-none d-xl-inline">Remove</span>
                                            </button>

                                        <?php endif; ?>

                                        <form
                                            method="post"
                                            class="d-inline">

                                            <input
                                                type="hidden"
                                                name="_csrf"
                                                value="<?= h(csrf_token()) ?>">

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="toggle">

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int)$route['id'] ?>">

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-outline-secondary"
                                                title="Aktif/nonaktif di BAJAMA">
                                                <i class="bi bi-power"></i>
                                                <span class="d-none d-xl-inline">Local</span>
                                            </button>

                                        </form>

                                        <form
                                            method="post"
                                            class="d-inline"
                                            onsubmit="return confirm('Hapus route ini dari BAJAMA?\n\nCatatan: gunakan Remove terlebih dahulu jika route masih terpasang di MikroTik.')">

                                            <input
                                                type="hidden"
                                                name="_csrf"
                                                value="<?= h(csrf_token()) ?>">

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete">

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int)$route['id'] ?>">

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-outline-danger"
                                                title="Hapus dari BAJAMA">
                                                <i class="bi bi-trash"></i>
                                                <span class="d-none d-xl-inline">Delete</span>
                                            </button>

                                        </form>

                                    </div>

                                </td>

                            <?php endif; ?>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>
                </table>
            </div>

        <?php endif; ?>

    </div>

</div>

<?php if ($canManage): ?>

<div class="modal fade" id="routeModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-xl modal-dialog-scrollable routing-modal-dialog">

        <div class="modal-content">

            <form method="post">

                <input
    type="hidden"
    name="_csrf"
    value="<?= h(csrf_token()) ?>">

                <input
                    type="hidden"
                    name="_save_token"
                    value="<?= h((string)($_SESSION['network_routing_save_token'] ?? '')) ?>">

                <input type="hidden" name="action" value="save">
                <input
                    type="hidden"
                    name="id"
                    id="route_id"
                    value="<?= (int)($editRoute['id'] ?? 0) ?>">

                <div class="modal-header">

                    <h5 class="modal-title">
                        <i class="bi bi-signpost-split me-2"></i>
                        <?= $editRoute ? 'Edit Route' : 'Tambah Route' ?>
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal">
                    </button>

                </div>

                <div class="modal-body">

                    <div class="routing-form-section">

                        <div class="routing-form-section-title">
                            <i class="bi bi-signpost-split"></i>
                            Informasi Route
                        </div>

                        <div class="row g-3">

                        <div class="col-md-12">
                            <label class="form-label">
                                Router MikroTik
                            </label>

                            <select
                                name="router_id"
                                id="router_id"
                                class="form-select"
                                required>

                                <option value="">
                                    -- Pilih Router MikroTik --
                                </option>

                                <?php foreach ($routers as $router): ?>
                                    <?php
                                    $routerOptionId = (int)($router['id'] ?? 0);
                                    $routerOptionName = (string)(
                                        $router['name'] ?? ('Router #' . $routerOptionId)
                                    );
                                    $routerOptionHost = (string)($router['host'] ?? '');
                                    ?>

                                    <option
                                        value="<?= $routerOptionId ?>"
                                        <?= $editRouterId === $routerOptionId ? 'selected' : '' ?>>

                                        <?= h($routerOptionName) ?>

                                        <?php if ($routerOptionHost !== ''): ?>
                                            — <?= h($routerOptionHost) ?>
                                        <?php endif; ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                            <div class="form-text">
                                Route ini akan dikaitkan dengan router MikroTik yang dipilih.
                            </div>
                        </div>

                        <div class="col-md-7">
                            <label class="form-label">
                                Nama Route
                            </label>

                            <input
                                type="text"
                                name="name"
                                class="form-control"
                                maxlength="150"
                                required
                                value="<?= h($editRoute['name'] ?? '') ?>"
                                placeholder="Contoh: DEFAULT ISP1">
                        </div>

                        <div class="col-md-5">
                            <label class="form-label">
                                Route Type
                            </label>

                            <select
                                name="route_type"
                                id="route_type"
                                class="form-select"
                                onchange="routeTypeChanged()">

                                <?php
                                $currentType = $editRoute['route_type'] ?? 'STATIC';
                                ?>

                                <option
                                    value="DEFAULT"
                                    <?= $currentType === 'DEFAULT' ? 'selected' : '' ?>>
                                    DEFAULT
                                </option>

                                <option
                                    value="STATIC"
                                    <?= $currentType === 'STATIC' ? 'selected' : '' ?>>
                                    STATIC
                                </option>

                                <option
                                    value="RECURSIVE"
                                    <?= $currentType === 'RECURSIVE' ? 'selected' : '' ?>>
                                    RECURSIVE
                                </option>

                            </select>
                        </div>

                        <div class="col-md-7">

                            <label class="form-label">
                                Destination
                            </label>

                            <input
                                type="text"
                                name="destination"
                                id="destination"
                                class="form-control"
                                required
                                value="<?= h($editRoute['destination'] ?? '') ?>"
                                placeholder="0.0.0.0/0">

                            <div class="form-text">
                                Contoh: 0.0.0.0/0, 192.168.10.0/24
                            </div>

                        </div>

                        <div class="col-md-5">

                            <label class="form-label">
                                Gateway / Next Hop
                            </label>

                            <input
                                type="text"
                                name="gateway"
                                class="form-control"
                                value="<?= h($editRoute['gateway'] ?? '') ?>"
                                placeholder="192.168.1.1">

                        </div>

                        <div class="col-md-6">

                            <label class="form-label">
                                Interface
                            </label>

                            <select
                                name="interface_name"
                                id="route_interface_name"
                                class="form-select"
                                data-current="<?= h($editRoute['interface_name'] ?? '') ?>">
                                <option value="">Pilih router terlebih dahulu</option>
                            </select>
                            <div class="form-text">Nama interface dibaca otomatis dari RouterOS.</div>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label">
                                Routing Table
                            </label>

                            <input
                                type="text"
                                name="routing_table"
                                class="form-control"
                                value="<?= h($editRoute['routing_table'] ?? 'main') ?>"
                                placeholder="main">

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                Distance
                            </label>

                            <input
                                type="number"
                                name="distance"
                                class="form-control"
                                min="1"
                                max="255"
                                value="<?= (int)($editRoute['distance'] ?? 1) ?>">

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                Scope
                            </label>

                            <input
                                type="number"
                                name="scope"
                                class="form-control"
                                min="0"
                                max="255"
                                value="<?= (int)($editRoute['scope'] ?? 30) ?>">

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                Target Scope
                            </label>

                            <input
                                type="number"
                                name="target_scope"
                                class="form-control"
                                min="0"
                                max="255"
                                value="<?= (int)($editRoute['target_scope'] ?? 10) ?>">

                        </div>

                        <div class="col-md-5">

                            <label class="form-label">
                                Check Gateway
                            </label>

                            <?php
                            $currentCheck = $editRoute['check_gateway'] ?? 'NONE';
                            ?>

                            <select
                                name="check_gateway"
                                class="form-select">

                                <option
                                    value="NONE"
                                    <?= $currentCheck === 'NONE' ? 'selected' : '' ?>>
                                    NONE
                                </option>

                                <option
                                    value="PING"
                                    <?= $currentCheck === 'PING' ? 'selected' : '' ?>>
                                    PING
                                </option>

                                <option
                                    value="ARP"
                                    <?= $currentCheck === 'ARP' ? 'selected' : '' ?>>
                                    ARP
                                </option>

                            </select>

                        </div>

                        <div class="col-md-7">

                            <label class="form-label">
                                Comment
                            </label>

                            <input
                                type="text"
                                name="comment"
                                class="form-control"
                                maxlength="1000"
                                value="<?= h($editRoute['comment'] ?? '') ?>"
                                placeholder="Keterangan route">

                        </div>

                    </div>

                    <div class="alert alert-info mt-4 mb-0">

                        <i class="bi bi-info-circle me-1"></i>

                        Route ini baru disimpan di BAJAMA.
                        <strong>Tidak ada perubahan otomatis ke MikroTik.</strong>

                    </div>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal">
                        Batal
                    </button>

                    <button
                        type="submit"
                        class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>
                        Simpan Route
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<style>
.routing-actions {
    min-width: 220px;
}

.routing-actions .btn {
    white-space: nowrap;
}

@media (max-width: 767.98px) {
    .routing-actions {
        width: 100%;
        min-width: 0;
    }

    .routing-actions .btn {
        flex: 0 0 auto;
    }
}
</style>

<script>

function routingToast(message, type = 'info', allowHtml = false) {
    let container = document.getElementById('routingApiToastContainer');

    if (!container) {
        container = document.createElement('div');
        container.id = 'routingApiToastContainer';
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        container.style.zIndex = '1090';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');

    const bgMap = {
        success: 'text-bg-success',
        danger: 'text-bg-danger',
        warning: 'text-bg-warning',
        info: 'text-bg-info'
    };

    toast.className =
        'toast align-items-center border-0 ' +
        (bgMap[type] || bgMap.info);

    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');

    const safeMessage = allowHtml
        ? String(message)
        : routingEscapeHtml(String(message));

    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">${safeMessage}</div>
            <button type="button"
                    class="btn-close btn-close-white me-2 m-auto"
                    data-bs-dismiss="toast"></button>
        </div>
    `;

    container.appendChild(toast);

    if (window.bootstrap) {
        const instance = bootstrap.Toast.getOrCreateInstance(toast, {
            delay: 5000
        });

        instance.show();

        toast.addEventListener('hidden.bs.toast', function () {
            toast.remove();
        });
    } else {
        toast.classList.add('show');
        setTimeout(() => toast.remove(), 5000);
    }
}

function routingEscapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = String(value ?? '');
    return div.innerHTML;
}

function routingActionLabel(action) {
    const labels = {
        test_gateway: 'Test Gateway',
        apply: 'Apply',
        enable: 'Enable',
        disable: 'Disable',
        remove: 'Remove'
    };

    return labels[action] || action;
}

async function routingApiAction(button, action) {
    if (!button) {
        return;
    }

    const routeId = button.dataset.routeId || '';
    const routerId = button.dataset.routerId || '';
    const csrf = button.dataset.csrf || '';

    if (!routeId) {
        routingToast('Route ID tidak ditemukan.', 'danger');
        return;
    }

    if (!csrf) {
        routingToast('CSRF token tidak ditemukan. Silakan reload halaman.', 'danger');
        return;
    }

    if (action === 'apply') {
        if (!confirm(
            'Apply route ini ke MikroTik sekarang?\\n\\n' +
            'Perubahan akan dikirim ke router.'
        )) {
            return;
        }
    }

    if (action === 'remove') {
        if (!confirm(
            'Remove route ini dari MikroTik?\\n\\n' +
            'Route akan dihapus dari RouterOS.'
        )) {
            return;
        }
    }

    if (action === 'disable') {
        if (!confirm('Disable route ini di MikroTik?')) {
            return;
        }
    }

    if (action === 'enable') {
        if (!confirm('Enable route ini di MikroTik?')) {
            return;
        }
    }

    const originalHtml = button.innerHTML;
    const buttons = button.closest('.routing-actions')
        ? button.closest('.routing-actions').querySelectorAll('button')
        : [button];

    buttons.forEach(function (item) {
        item.disabled = true;
    });

    button.innerHTML =
        '<span class="spinner-border spinner-border-sm me-1" ' +
        'role="status" aria-hidden="true"></span>' +
        'Proses...';

    try {
        const body = new URLSearchParams();

        body.append('action', action);
        body.append('route_id', routeId);
        body.append('router_id', routerId);
        body.append('_csrf', csrf);

        const response = await fetch('network_routing_api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        });

        let data = null;

        try {
            data = await response.json();
        } catch (jsonError) {
            throw new Error(
                'Server mengembalikan response yang bukan JSON.'
            );
        }

        if (!response.ok || !data || data.ok !== true) {
            throw new Error(
                data && data.error
                    ? data.error
                    : 'Gagal menjalankan ' + routingActionLabel(action) + '.'
            );
        }

        let message = routingActionLabel(action) + ' berhasil.';

        if (action === 'test_gateway') {

            /*
             * Backend sekarang mengirim identity sebagai string.
             * Tetap dibuat kompatibel jika server lama mengirim array.
             */
            let identity = data.identity || data.router_name || '-';

            if (Array.isArray(identity)) {
                const first = identity[0];

                if (first && typeof first === 'object') {
                    identity =
                        first.name ||
                        first.identity ||
                        data.router_name ||
                        '-';
                } else {
                    identity = String(first || '-');
                }
            } else if (
                typeof identity === 'object' &&
                identity !== null
            ) {
                identity =
                    identity.name ||
                    identity.identity ||
                    data.router_name ||
                    '-';
            }

            const ping = data.ping || {};

            const sent = Number(ping.sent || 0);
            const received = Number(ping.received || 0);

            const loss = sent > 0
                ? Math.max(0, sent - received)
                : Number(ping.loss || 0);

            const lossPercent = sent > 0
                ? Math.round((loss / sent) * 100)
                : 0;

            const times = Array.isArray(ping.times)
                ? ping.times
                    .map(Number)
                    .filter(function (v) {
                        return Number.isFinite(v);
                    })
                : [];

            let min = '-';
            let avg = '-';
            let max = '-';

            if (times.length > 0) {
                min = Math.min.apply(null, times).toFixed(2);
                max = Math.max.apply(null, times).toFixed(2);

                const total = times.reduce(
                    function (sum, value) {
                        return sum + value;
                    },
                    0
                );

                avg = (total / times.length).toFixed(2);
            }

            const reachable =
                ping.reachable === true ||
                received > 0;

            const detail =
                '<strong>Test Gateway berhasil</strong><br>' +
                '<div class="mt-2">' +

                '<div><strong>Router:</strong> ' +
                    routingEscapeHtml(String(identity)) +
                '</div>' +

                '<div><strong>Gateway:</strong> ' +
                    routingEscapeHtml(
                        String(data.gateway || '-')
                    ) +
                '</div>' +

                '<div><strong>Status:</strong> ' +
                    (
                        reachable
                            ? 'REACHABLE'
                            : 'UNREACHABLE'
                    ) +
                '</div>' +

                '<div><strong>Packet:</strong> ' +
                    received + '/' + sent +
                    ' received</div>' +

                '<div><strong>Packet Loss:</strong> ' +
                    lossPercent +
                    '%</div>' +

                '<div><strong>Latency:</strong> ' +
                    'min ' + min +
                    ' ms, avg ' + avg +
                    ' ms, max ' + max +
                    ' ms' +
                '</div>' +

                '</div>';

            routingToast(
                detail,
                reachable ? 'success' : 'warning',
                true
            );

            button.innerHTML = originalHtml;

            buttons.forEach(function (item) {
                item.disabled = false;
            });

            return;
        }

        button.innerHTML = originalHtml;

        buttons.forEach(function (item) {
            item.disabled = false;
        });

        routingToast(message, 'success');

        setTimeout(function () {
            window.location.reload();
        }, 700);

    } catch (error) {
        console.error('Routing API error:', error);

        routingToast(
            error && error.message
                ? error.message
                : 'Terjadi kesalahan saat menghubungi API routing.',
            'danger'
        );

        button.innerHTML = originalHtml;

        buttons.forEach(function (item) {
            item.disabled = false;
        });
    }
}

function routeTypeChanged() {
    const type = document.getElementById('route_type');
    const destination = document.getElementById('destination');

    if (!type || !destination) {
        return;
    }

    if (type.value === 'DEFAULT') {
        destination.value = '0.0.0.0/0';
    }
}

async function loadRouteInterfaces(routerId, selected = '') {
    const select = document.getElementById('route_interface_name');
    if (!select) return;
    select.innerHTML = '<option value="">Memuat interface...</option>';
    if (!routerId) {
        select.innerHTML = '<option value="">Pilih router terlebih dahulu</option>';
        return;
    }
    try {
        const response = await fetch('mikrotik_api.php?action=interfaces&router_id=' + encodeURIComponent(routerId), { credentials: 'same-origin' });
        const payload = await response.json();
        const interfaces = payload && payload.ok && payload.data ? payload.data : [];
        select.innerHTML = '<option value="">Pilih interface</option>';
        interfaces.forEach(function (item) {
            const name = item.name || '';
            if (!name) return;
            select.add(new Option(name + (item.type ? ' (' + item.type + ')' : ''), name, false, name === selected));
        });
        if (selected && !Array.from(select.options).some(option => option.value === selected)) {
            select.add(new Option(selected + ' (tersimpan)', selected, true, true));
        }
    } catch (error) {
        select.innerHTML = '<option value="">Interface gagal dibaca</option>';
    }
}

function newRoute() {
    const form = document.querySelector('#routeModal form');

    if (!form) {
        return;
    }

    form.reset();

    document.getElementById('route_id').value = '0';
    document.getElementById('route_type').value = 'STATIC';
    document.getElementById('destination').value = '';
    loadRouteInterfaces(document.getElementById('router_id')?.value || '');

    const modalElement = document.getElementById('routeModal');

    if (modalElement && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(modalElement).show();
    }
}

document.addEventListener('DOMContentLoaded', function () {

    const modalElement = document.getElementById('routeModal');
    const routerSelect = document.getElementById('router_id');
    if (routerSelect) {
        routerSelect.addEventListener('change', function () {
            loadRouteInterfaces(this.value);
        });
        loadRouteInterfaces(routerSelect.value, document.getElementById('route_interface_name')?.dataset.current || '');
    }

    <?php if ($editRoute): ?>

    if (modalElement && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(modalElement).show();
    }

    <?php endif; ?>

});
</script>

<?php endif; ?>

<?php
$content = ob_get_clean();

$networkLayout = false;
$networkActive = 'routing';

require __DIR__ . '/../app/layout/layout.php';
