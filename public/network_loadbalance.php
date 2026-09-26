<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Network/LoadBalanceProvisioner.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\License;
use BAJAMA\Core\RBAC;
use BAJAMA\Core\Tenant;
use BAJAMA\Network\LoadBalanceProvisioner;

Auth::requireLogin();
$db = db();
License::requireFeature($db, 'mikrotik');
RBAC::require($db, 'network.view');
$organizationId = (int)Tenant::id();
$canManage = RBAC::hasPermission($db, 'network.manage');
$message = '';
$error = '';

function lb_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf((string)($_POST['_csrf'] ?? $_POST['csrf'] ?? ''));
        if (!$canManage) throw new RuntimeException('Anda tidak memiliki izin mengelola load balance.');
        $id = (int)($_POST['id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        $service = new LoadBalanceProvisioner($db, $organizationId);
        if ($action === 'apply') {
            $result = $service->apply($id);
            $message = 'Mode ' . $result['mode'] . ' diterapkan. ' . (int)$result['created'] . ' resource BAJAMA dibuat; route manual tidak disentuh.';
        } elseif ($action === 'remove') {
            $message = $service->remove($id) . ' resource BAJAMA dihapus. Route manual tetap aman.';
        } elseif ($action === 'toggle') {
            $stmt = $db->prepare('SELECT enabled FROM network_load_balances WHERE id=? AND organization_id=? LIMIT 1');
            $stmt->execute([$id, $organizationId]);
            $enabled = (int)$stmt->fetchColumn() === 1;
            $db->prepare('UPDATE network_load_balances SET enabled=? WHERE id=? AND organization_id=?')->execute([$enabled ? 0 : 1, $id, $organizationId]);
            if ($enabled) $service->remove($id); else $service->apply($id);
            $message = 'Status load balance dan resource BAJAMA berhasil disinkronkan.';
        } elseif ($action === 'delete') {
            $service->remove($id);
            $db->prepare('DELETE FROM network_load_balances WHERE id=? AND organization_id=?')->execute([$id, $organizationId]);
            $message = 'Konfigurasi load balance dihapus; konfigurasi WAN, LAN, dan route manual tidak disentuh.';
        } elseif ($action === 'save') {
            $routerId = (int)($_POST['router_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $mode = strtoupper(trim((string)($_POST['mode'] ?? 'PCC')));
            $algorithm = trim((string)($_POST['algorithm'] ?? 'both-addresses-and-ports'));
            $health = isset($_POST['health_check']) ? 1 : 0;
            $comment = trim((string)($_POST['comment'] ?? ''));
            if ($routerId <= 0 || $name === '' || !in_array($mode, ['PCC','ECMP','NTH','FAILOVER'], true)) throw new RuntimeException('Data load balance tidak valid.');
            $check = $db->prepare('SELECT id FROM mikrotik_routers WHERE id=? AND organization_id=?');
            $check->execute([$routerId, $organizationId]);
            if (!$check->fetchColumn()) throw new RuntimeException('Router bukan milik organisasi ini.');
            if ($id > 0) {
                $db->prepare('UPDATE network_load_balances SET router_id=?,name=?,mode=?,algorithm=?,health_check=?,comment=? WHERE id=? AND organization_id=?')->execute([$routerId,$name,$mode,$algorithm,$health,$comment ?: null,$id,$organizationId]);
            } else {
                $db->prepare('INSERT INTO network_load_balances (organization_id,router_id,name,mode,algorithm,health_check,comment) VALUES (?,?,?,?,?,?,?)')->execute([$organizationId,$routerId,$name,$mode,$algorithm,$health,$comment ?: null]);
            }
            $message = 'Konfigurasi disimpan. Tekan Apply untuk menerapkan ke MikroTik.';
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$routers = $db->query('SELECT id,name FROM mikrotik_routers WHERE organization_id=' . $organizationId . ' ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0) { $stmt = $db->prepare('SELECT * FROM network_load_balances WHERE id=? AND organization_id=?'); $stmt->execute([$editId,$organizationId]); $edit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null; }
$items = $db->query('SELECT lb.*, r.name AS router_name FROM network_load_balances lb INNER JOIN mikrotik_routers r ON r.id=lb.router_id AND r.organization_id=lb.organization_id WHERE lb.organization_id=' . $organizationId . ' ORDER BY lb.name')->fetchAll(PDO::FETCH_ASSOC);
$pageTitle = 'Load Balance';
ob_start();
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4"><div><h1 class="fw-bold mb-1">Load Balance</h1><p class="text-muted mb-0">PCC, ECMP, NTH, dan Failover per router dan ISP aktif.</p></div></div>
    <?php if ($message): ?><div class="alert alert-success"><?=lb_h($message)?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?=lb_h($error)?></div><?php endif; ?>
    <div class="alert alert-info"><strong>Keamanan Apply:</strong> BAJAMA hanya mengelola route/mangle dengan marker miliknya sendiri. Route WAN, LAN, dan routing manual tidak dinonaktifkan atau dihapus.</div>
    <div class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5"><?= $edit ? 'Edit Load Balance' : 'Tambah Load Balance' ?></h2><form method="post" class="row g-3"><input type="hidden" name="_csrf" value="<?=lb_h(csrf_token())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>"><div class="col-md-3"><label class="form-label">Router</label><select name="router_id" class="form-select" required><?php foreach($routers as $router):?><option value="<?=$router['id']?>" <?=((int)($edit['router_id']??0)==(int)$router['id']?'selected':'')?>><?=lb_h($router['name'])?></option><?php endforeach;?></select></div><div class="col-md-3"><label class="form-label">Nama</label><input name="name" class="form-control" value="<?=lb_h($edit['name']??'')?>" required></div><div class="col-md-2"><label class="form-label">Mode</label><select name="mode" class="form-select"><?php foreach(['PCC','ECMP','NTH','FAILOVER'] as $mode):?><option value="<?=$mode?>" <?=($mode===($edit['mode']??'PCC')?'selected':'')?>><?=$mode?></option><?php endforeach;?></select></div><div class="col-md-3"><label class="form-label">Algorithm PCC</label><input name="algorithm" class="form-control" value="<?=lb_h($edit['algorithm']??'both-addresses-and-ports')?>"></div><div class="col-md-1 form-check mt-5"><input type="checkbox" name="health_check" class="form-check-input" <?=((int)($edit['health_check']??1)?'checked':'')?>><label class="form-check-label">Health</label></div><div class="col-md-10"><label class="form-label">Catatan</label><input name="comment" class="form-control" value="<?=lb_h($edit['comment']??'')?>"></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" <?= $canManage?'':'disabled' ?>>Simpan</button></div></form></div></div>
    <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Nama</th><th>Router</th><th>Mode</th><th>Health</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php if(!$items):?><tr><td colspan="6" class="text-center text-muted py-4">Belum ada konfigurasi load balance.</td></tr><?php endif;?><?php foreach($items as $item):?><tr><td><strong><?=lb_h($item['name'])?></strong><div class="small text-muted"><?=lb_h($item['comment']??'')?></div></td><td><?=lb_h($item['router_name'])?></td><td><span class="badge text-bg-primary"><?=$item['mode']?></span></td><td><?=$item['health_check']?'ON':'OFF'?></td><td><?=$item['enabled']?'ENABLED':'DISABLED'?></td><td><div class="d-flex gap-1 flex-wrap"><a class="btn btn-sm btn-outline-primary" href="network_loadbalance.php?edit=<?=$item['id']?>">Edit</a><?php if($canManage && (int)$item['enabled']===1):?><form method="post" class="d-inline" onsubmit="return confirm('Terapkan load balance ke MikroTik?');"><input type="hidden" name="_csrf" value="<?=lb_h(csrf_token())?>"><input type="hidden" name="action" value="apply"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-sm btn-primary">Apply</button></form><?php endif;?><form method="post" class="d-inline"><input type="hidden" name="_csrf" value="<?=lb_h(csrf_token())?>"><input type="hidden" name="id" value="<?=$item['id']?>"><button name="action" value="toggle" class="btn btn-sm btn-outline-warning">Enable/Disable</button><button name="action" value="delete" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus load balance dan resource BAJAMA-nya?');">Hapus</button></form></div></td></tr><?php endforeach;?></tbody></table></div></div>
</div>
<?php $content=ob_get_clean(); require __DIR__.'/../app/layout/layout.php';
