<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\License;
use BAJAMA\Core\RBAC;
use BAJAMA\Core\Tenant;

Auth::requireLogin();
$db = db();
License::requireFeature($db, 'mikrotik');
RBAC::require($db, 'network.view');
$organizationId = Tenant::id();
$canManage = RBAC::hasPermission($db, 'network.manage');
$message = '';
$error = '';
$editId = (int) ($_GET['edit'] ?? 0);
$edit = null;

if ($editId > 0) {
    $stmt = $db->prepare('SELECT * FROM network_olts WHERE id=? AND organization_id=?');
    $stmt->execute([$editId, $organizationId]);
    $edit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf((string) ($_POST['_csrf'] ?? $_POST['csrf'] ?? ''));
        if (!$canManage) {
            throw new RuntimeException('Anda tidak memiliki izin mengelola OLT.');
        }
        $action = (string) ($_POST['action'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'delete' || $action === 'toggle') {
            $sql = $action === 'delete'
                ? 'DELETE FROM network_olts WHERE id=? AND organization_id=?'
                : 'UPDATE network_olts SET enabled=CASE WHEN enabled=1 THEN 0 ELSE 1 END, status=CASE WHEN enabled=1 THEN "DISABLED" ELSE "ACTIVE" END WHERE id=? AND organization_id=?';
            $db->prepare($sql)->execute([$id, $organizationId]);
            $message = $action === 'delete' ? 'OLT berhasil dihapus.' : 'Status OLT berhasil diubah.';
        } elseif ($action === 'save') {
            $routerId = (int) ($_POST['router_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $vendor = trim((string) ($_POST['vendor'] ?? ''));
            $managementIp = trim((string) ($_POST['management_ip'] ?? ''));
            $status = strtoupper(trim((string) ($_POST['status'] ?? 'ACTIVE')));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            if ($routerId <= 0 || $name === '' || !in_array($status, ['ACTIVE', 'OFFLINE', 'DISABLED'], true)) {
                throw new RuntimeException('Router, nama OLT, dan status wajib valid.');
            }
            if ($managementIp !== '' && !filter_var($managementIp, FILTER_VALIDATE_IP)) {
                throw new RuntimeException('IP management OLT tidak valid.');
            }
            $check = $db->prepare('SELECT id FROM mikrotik_routers WHERE id=? AND organization_id=?');
            $check->execute([$routerId, $organizationId]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('Router MikroTik bukan milik organisasi ini.');
            }
            $params = [$routerId, $name, $vendor ?: null, $managementIp ?: null, $status, $notes ?: null];
            if ($id > 0) {
                $db->prepare('UPDATE network_olts SET router_id=?,name=?,vendor=?,management_ip=?,status=?,notes=? WHERE id=? AND organization_id=?')->execute([...$params, $id, $organizationId]);
            } else {
                $db->prepare('INSERT INTO network_olts (organization_id,router_id,name,vendor,management_ip,status,notes) VALUES (?,?,?,?,?,?,?)')->execute([$organizationId, ...$params]);
            }
            $message = 'Data OLT berhasil disimpan.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$routerStmt = $db->prepare('SELECT id,name,host FROM mikrotik_routers WHERE organization_id=? ORDER BY name');
$routerStmt->execute([$organizationId]);
$routers = $routerStmt->fetchAll(PDO::FETCH_ASSOC);
$oltStmt = $db->prepare('SELECT o.*,r.name router_name FROM network_olts o JOIN mikrotik_routers r ON r.id=o.router_id AND r.organization_id=o.organization_id WHERE o.organization_id=? ORDER BY o.name');
$oltStmt->execute([$organizationId]);
$olts = $oltStmt->fetchAll(PDO::FETCH_ASSOC);

function olt_h($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
$pageTitle = 'OLT Management';
ob_start();
?>
<div class="container-fluid py-4">
  <div class="mb-4"><h1>OLT Management</h1><p class="text-muted mb-0">Daftarkan OLT secara jelas berdasarkan router MikroTik pengelola, vendor, IP management, dan status operasional.</p></div>
  <?php if ($message): ?><div class="alert alert-success"><?= olt_h($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= olt_h($error) ?></div><?php endif; ?>
  <div class="card mb-4"><div class="card-body"><h2 class="h5"><?= $edit ? 'Edit OLT' : 'Tambah OLT' ?></h2><form method="post" class="row g-3"><input type="hidden" name="_csrf" value="<?= olt_h(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>"><div class="col-md-3"><label class="form-label">Router MikroTik</label><select class="form-select" name="router_id" required><option value="">Pilih router</option><?php foreach ($routers as $router): ?><option value="<?= (int) $router['id'] ?>" <?= ((int) ($edit['router_id'] ?? 0) === (int) $router['id']) ? 'selected' : '' ?>><?= olt_h($router['name'].' - '.$router['host']) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Nama OLT</label><input class="form-control" name="name" value="<?= olt_h($edit['name'] ?? '') ?>" placeholder="OLT POP Utama" required></div><div class="col-md-2"><label class="form-label">Vendor</label><input class="form-control" name="vendor" value="<?= olt_h($edit['vendor'] ?? '') ?>" placeholder="Huawei, ZTE..."></div><div class="col-md-2"><label class="form-label">IP Management</label><input class="form-control" name="management_ip" value="<?= olt_h($edit['management_ip'] ?? '') ?>" placeholder="10.0.0.2"></div><div class="col-md-2"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach (['ACTIVE','OFFLINE','DISABLED'] as $status): ?><option <?= $status === ($edit['status'] ?? 'ACTIVE') ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select></div><div class="col-md-10"><label class="form-label">Catatan</label><input class="form-control" name="notes" value="<?= olt_h($edit['notes'] ?? '') ?>"></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" <?= $canManage ? '' : 'disabled' ?>>Simpan OLT</button></div></form></div></div>
  <div class="card"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>OLT</th><th>Vendor</th><th>Router MikroTik</th><th>IP Management</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php foreach ($olts as $olt): ?><tr><td><?= olt_h($olt['name']) ?></td><td><?= olt_h($olt['vendor'] ?: '-') ?></td><td><?= olt_h($olt['router_name']) ?></td><td><?= olt_h($olt['management_ip'] ?: '-') ?></td><td><?= $olt['enabled'] ? 'ENABLED' : 'DISABLED' ?> / <?= olt_h($olt['status']) ?></td><td><a class="btn btn-sm btn-outline-primary" href="network_olt.php?edit=<?= (int) $olt['id'] ?>">Edit</a><form method="post" class="d-inline"><input type="hidden" name="_csrf" value="<?= olt_h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $olt['id'] ?>"><button name="action" value="toggle" class="btn btn-sm btn-outline-warning">Enable/Disable</button><button name="action" value="delete" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus OLT?')">Hapus</button></form></td></tr><?php endforeach; ?></tbody></table></div></div>
</div>
<?php $content = ob_get_clean(); require __DIR__ . '/../app/layout/layout.php';
