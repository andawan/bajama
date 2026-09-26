<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Network/RouterOSResourceDiscovery.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\License;
use BAJAMA\Core\RBAC;
use BAJAMA\Core\Tenant;
use BAJAMA\Network\MikroTik;
use BAJAMA\Network\RouterOSResourceDiscovery;

Auth::requireLogin();
$db = db();
License::requireFeature($db, 'mikrotik');
RBAC::require($db, 'network.view');

$organizationId = (int)Tenant::id();
$canManage = RBAC::hasPermission($db, 'network.manage');
$csrf = csrf_token();
$message = trim((string)($_GET['routeros_success'] ?? ''));
$error = trim((string)($_GET['routeros_error'] ?? ''));

$entities = [
    'network_isp' => ['label' => 'WAN / ISP', 'table' => 'network_isps', 'page' => 'network_isp.php'],
    'network_lan' => ['label' => 'LAN / VLAN', 'table' => 'network_lans', 'page' => 'network_lan.php'],
    'network_route' => ['label' => 'Routing', 'table' => 'network_routes', 'page' => 'network_routing.php'],
    'network_firewall' => ['label' => 'Firewall', 'table' => 'network_firewall_rules', 'page' => 'network_firewall.php'],
    'network_static_ip' => ['label' => 'Static IP Customer', 'table' => 'network_static_ips', 'page' => 'network_static_ip.php'],
    'network_hotspot_voucher' => ['label' => 'Hotspot / Voucher', 'table' => 'network_hotspot_vouchers', 'page' => 'network_hotspot.php'],
    'network_load_balance' => ['label' => 'Load Balance', 'table' => 'network_load_balances', 'page' => 'network_loadbalance.php'],
    'network_ftth' => ['label' => 'FTTH', 'table' => 'network_ftth_services', 'page' => 'network_ftth.php'],
    'network_olt' => ['label' => 'OLT Management', 'table' => 'network_olts', 'page' => 'network_olt.php'],
];

$selectedType = (string)($_GET['source_type'] ?? array_key_first($entities));
if (!isset($entities[$selectedType])) {
    $selectedType = array_key_first($entities);
}

$rows = [];
$definition = $entities[$selectedType];
$labelColumns = [
    'network_isp' => 'name',
    'network_lan' => 'name',
    'network_route' => 'name',
    'network_firewall' => 'comment',
    'network_static_ip' => 'ip_address',
    'network_hotspot_voucher' => 'username',
    'network_load_balance' => 'name',
    'network_ftth' => 'onu_serial',
    'network_olt' => 'name',
];
$labelColumn = $labelColumns[$selectedType] ?? 'id';
$stmt = $db->prepare(
    "SELECT id, router_id, {$labelColumn} AS entity_name, status, enabled
     FROM {$definition['table']}
     WHERE organization_id=?
     ORDER BY id DESC"
);
$stmt->execute([$organizationId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$routerStmt = $db->prepare('SELECT id, name, host FROM mikrotik_routers WHERE organization_id=? ORDER BY name');
$routerStmt->execute([$organizationId]);
$routers = $routerStmt->fetchAll(PDO::FETCH_ASSOC);
$selectedRouterId = (int)($_GET['router_id'] ?? 0);
$routerosDiscovery = null;
$discoveryError = '';
if ($selectedRouterId > 0) {
    try {
        $router = MikroTik::find($db, $organizationId, $selectedRouterId);
        if (!$router) throw new RuntimeException('Router bukan milik organisasi ini.');
        $api = MikroTik::connect($router);
        try {
            $routerosDiscovery = (new RouterOSResourceDiscovery())->list($api, $selectedType);
        } finally {
            $api->disconnect();
        }
    } catch (Throwable $e) {
        $discoveryError = $e->getMessage();
    }
}

function ros_control_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$pageTitle = 'RouterOS Control Center';
ob_start();
?>
<style>
.ros-control { max-width: 1380px; }
.ros-control .hero { background: linear-gradient(135deg,#111827,#1d4ed8); color:#fff; border-radius:20px; }
.ros-control .action-form { display:inline-flex; gap:.35rem; margin:.15rem; }
.ros-control .table td, .ros-control .table th { vertical-align:middle; white-space:nowrap; }
@media (max-width: 767.98px) {
    .ros-control .action-form { display:flex; margin:.25rem 0; }
    .ros-control .action-form .btn { flex:1; }
    .ros-control .table thead { display:none; }
    .ros-control .table, .ros-control .table tbody, .ros-control .table tr, .ros-control .table td { display:block; width:100%; }
    .ros-control .table tr { border:1px solid #e5e7eb; border-radius:14px; margin-bottom:12px; padding:10px; }
    .ros-control .table td { border:0; padding:.35rem .5rem; white-space:normal; }
    .ros-control .table td::before { content:attr(data-label); display:block; color:#64748b; font-size:.72rem; font-weight:700; text-transform:uppercase; }
}
</style>
<div class="container-fluid py-4 ros-control">
    <div class="hero p-4 p-lg-5 mb-4">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
            <div>
                <div class="text-uppercase small opacity-75 fw-semibold">WinBox-style control</div>
                <h1 class="h2 mb-2">RouterOS Control Center</h1>
                <p class="mb-0 opacity-75">Terapkan, matikan, hidupkan, atau hapus konfigurasi BAJAMA di router MikroTik secara tenant-scoped.</p>
            </div>
            <i class="bi bi-router display-4 opacity-50"></i>
        </div>
    </div>
    <?php if ($message): ?><div class="alert alert-success">Aksi RouterOS <strong><?= ros_control_h($message) ?></strong> berhasil.</div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= ros_control_h($error) ?></div><?php endif; ?>
    <?php if (!$canManage): ?><div class="alert alert-warning">Akun ini hanya memiliki akses baca. Tombol perubahan disembunyikan.</div><?php endif; ?>
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-12 col-md-5 col-lg-3">
                    <label class="form-label fw-semibold" for="source_type">Modul</label>
                    <select class="form-select" id="source_type" name="source_type" onchange="this.form.submit()">
                        <?php foreach ($entities as $key => $item): ?>
                            <option value="<?= ros_control_h($key) ?>" <?= $selectedType === $key ? 'selected' : '' ?>><?= ros_control_h($item['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-5 col-lg-3">
                    <label class="form-label fw-semibold" for="router_id">Lihat RouterOS user</label>
                    <select class="form-select" id="router_id" name="router_id" onchange="this.form.submit()">
                        <option value="0">Pilih router</option>
                        <?php foreach ($routers as $router): ?><option value="<?= (int)$router['id'] ?>" <?= $selectedRouterId === (int)$router['id'] ? 'selected' : '' ?>><?= ros_control_h($router['name']) ?> — <?= ros_control_h($router['host']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-auto"><a class="btn btn-outline-secondary" href="<?= ros_control_h($definition['page']) ?>"><i class="bi bi-pencil-square me-1"></i>Buka halaman modul</a></div>
            </form>
        </div>
    </div>
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3"><strong>Konfigurasi aktual RouterOS</strong><span class="text-muted small ms-2">Read-only discovery</span></div>
        <div class="card-body">
            <?php if ($discoveryError): ?><div class="alert alert-warning mb-0"><?= ros_control_h($discoveryError) ?></div>
            <?php elseif (!$selectedRouterId): ?><div class="text-muted">Pilih router untuk melihat WAN/LAN/route/firewall yang sudah ada di MikroTik.</div>
            <?php elseif (!$routerosDiscovery): ?><div class="text-muted">Modul ini belum memiliki discovery adapter.</div>
            <?php else: ?><div class="small text-muted mb-2">Path: <code><?= ros_control_h($routerosDiscovery['path']) ?></code> · <?= count($routerosDiscovery['rows']) ?> resource</div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>.id</th><th>Nama/Address</th><th>Interface/Gateway</th><th>Comment</th></tr></thead><tbody><?php foreach (array_slice($routerosDiscovery['rows'], 0, 100) as $resource): ?><tr><td><?= ros_control_h($resource['.id'] ?? '-') ?></td><td><?= ros_control_h($resource['name'] ?? $resource['address'] ?? $resource['dst-address'] ?? '-') ?></td><td><?= ros_control_h($resource['interface'] ?? $resource['gateway'] ?? $resource['action'] ?? '-') ?></td><td><?= ros_control_h($resource['comment'] ?? '-') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        </div>
    </div>
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3"><strong><?= ros_control_h($definition['label']) ?></strong><span class="text-muted small ms-2"><?= count($rows) ?> entitas</span></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th>ID</th><th>Nama</th><th>Router ID</th><th>Status</th><th>Local</th><th class="text-end">Operasi RouterOS</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td data-label="ID"><?= (int)$row['id'] ?></td>
                        <td data-label="Nama"><?= ros_control_h($row['entity_name'] ?? ('#' . $row['id'])) ?></td>
                        <td data-label="Router ID"><?= (int)($row['router_id'] ?? 0) ?></td>
                        <td data-label="Status"><span class="badge text-bg-<?= strtoupper((string)($row['status'] ?? 'UNKNOWN')) === 'ONLINE' ? 'success' : 'secondary' ?>"><?= ros_control_h($row['status'] ?? 'UNKNOWN') ?></span></td>
                        <td data-label="Local"><?= !empty($row['enabled']) ? 'ENABLED' : 'DISABLED' ?></td>
                        <td data-label="Operasi RouterOS" class="text-lg-end">
                            <?php if ($canManage): ?>
                                <?php foreach (['apply' => 'Apply', 'enable' => 'Hidupkan', 'disable' => 'Matikan', 'remove' => 'Hapus'] as $action => $label): ?>
                                    <form class="action-form" method="post" action="network_routeros_action.php" onsubmit="return <?= $action === 'remove' ? "confirm('Hapus objek ini dari RouterOS?')" : 'true' ?>;">
                                        <input type="hidden" name="_csrf" value="<?= ros_control_h($csrf) ?>">
                                        <input type="hidden" name="source_type" value="<?= ros_control_h($selectedType) ?>">
                                        <input type="hidden" name="source_id" value="<?= (int)$row['id'] ?>">
                                        <input type="hidden" name="routeros_action" value="<?= $action ?>">
                                        <input type="hidden" name="return_to" value="network_routeros_control.php?source_type=<?= rawurlencode($selectedType) ?>">
                                        <button class="btn btn-sm btn-outline-<?= $action === 'remove' ? 'danger' : ($action === 'apply' ? 'primary' : 'secondary') ?>" type="submit"><?= $label ?></button>
                                    </form>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-muted">Read-only</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-5">Belum ada data pada modul ini.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="alert alert-info mt-4 small mb-0"><i class="bi bi-info-circle me-1"></i>Load Balance, FTTH, dan OLT memerlukan konfigurasi tambahan/adapter vendor. Sistem akan menolak operasi yang belum memiliki mapping aman, bukan mengirim perintah RouterOS yang keliru.</div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../app/layout/layout.php';
