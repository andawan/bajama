<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
\BAJAMA\Core\Auth::requireLogin();
\BAJAMA\Core\RBAC::require(db(), 'audit.view');
$db=db(); $orgId=(int)\BAJAMA\Core\Tenant::id(); $roles=\BAJAMA\Core\RBAC::roles($db); $isSuperAdmin=in_array('SUPER_ADMIN',$roles,true); $search=trim((string)($_GET['q']??'')); $where=$isSuperAdmin?'1=1':'a.organization_id=?'; $params=$isSuperAdmin?[]:[$orgId]; if($search!==''){ $where.=' AND (a.action LIKE ? OR a.module LIKE ? OR u.username LIKE ?)'; $like='%'.$search.'%'; array_push($params,$like,$like,$like); } $stmt=$db->prepare("SELECT a.id,a.action,a.module,a.target_type,a.target_id,a.details,a.ip_address,a.created_at,u.username,o.name AS organization_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id LEFT JOIN organizations o ON o.id=a.organization_id WHERE $where ORDER BY a.id DESC LIMIT 250"); $stmt->execute($params); $logs=$stmt->fetchAll(PDO::FETCH_ASSOC); function aud_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<?php $pageTitle = 'Audit Logs'; ob_start(); ?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Laporan / Audit</h1>
            <p class="text-muted mb-0">Jejak perubahan user, billing, license, dan network.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="dashboard.php" class="btn btn-primary">Kembali ke Dashboard</a>
        </div>
    </div>

    <form class="row g-2 mb-3" method="get">
        <div class="col-md-5">
            <input class="form-control" name="q" value="<?= aud_h($search) ?>" placeholder="Cari action, module, username">
        </div>
        <div class="col-auto">
            <button class="btn btn-primary" type="submit">Search</button>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-dark text-white">Audit trail</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <?php if ($isSuperAdmin): ?><th>Organisasi</th><?php endif; ?>
                        <th>Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Target</th>
                        <th>IP</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <?php if ($isSuperAdmin): ?><td><?= aud_h($log['organization_name'] ?: 'PLATFORM') ?></td><?php endif; ?>
                            <td><?= aud_h($log['created_at']) ?></td>
                            <td><?= aud_h($log['username'] ?: 'SYSTEM') ?></td>
                            <td><span class="badge text-bg-primary"><?= aud_h($log['action']) ?></span></td>
                            <td><?= aud_h($log['module'].' / '.$log['target_type'].' #'.$log['target_id']) ?></td>
                            <td><?= aud_h($log['ip_address']) ?></td>
                            <td><code><?= aud_h($log['details']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); require __DIR__ . '/../app/layout/layout.php'; ?>
