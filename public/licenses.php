<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

\BAJAMA\Core\Auth::requireLogin();
\BAJAMA\Core\RBAC::require(db(), 'license.manage');

$db = db();
$orgId = (int) \BAJAMA\Core\Tenant::id();
$currentRoles = \BAJAMA\Core\RBAC::roles($db);
$isSuperAdmin = in_array('SUPER_ADMIN', $currentRoles, true);
$message = '';
$error = '';
$editLicenseId = (int) ($_GET['edit_id'] ?? 0);
$editLicense = null;

if ($editLicenseId > 0) {
    $stmt = $db->prepare('SELECT * FROM licenses WHERE id = ?' . (!$isSuperAdmin ? ' AND organization_id = ?' : ''));
    $params = [$editLicenseId];
    if (!$isSuperAdmin) {
        $params[] = $orgId;
    }
    $stmt->execute($params);
    $editLicense = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf((string) ($_POST['_csrf'] ?? ''));
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_license') {
            $id = (int) ($_POST['id'] ?? 0);
            $targetOrgId = $isSuperAdmin ? (int) ($_POST['organization_id'] ?? $orgId) : $orgId;
            $planId = (int) ($_POST['plan_id'] ?? 0);
            $licenseKey = trim((string) ($_POST['license_key'] ?? ''));
            $status = strtoupper(trim((string) ($_POST['status'] ?? 'TRIAL')));
            $expiresAt = trim((string) ($_POST['expires_at'] ?? ''));

            if ($targetOrgId <= 0 || $planId <= 0 || $licenseKey === '' || !in_array($status, ['TRIAL', 'ACTIVE', 'SUSPENDED', 'EXPIRED', 'CANCELLED'], true)) {
                throw new RuntimeException('Data license tidak valid.');
            }
            if ($expiresAt === '') {
                $expiresAt = date('d-m-Y H:i:s', strtotime('+30 days'));
            }

            $planStmt = $db->prepare('SELECT id, active, features FROM license_plans WHERE id=? LIMIT 1');
            $planStmt->execute([$planId]);
            $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
            if (!$plan) {
                throw new RuntimeException('License plan tidak ditemukan.');
            }
            if (!$id && !(int) $plan['active']) {
                throw new RuntimeException('License plan yang tidak aktif tidak dapat dipakai untuk license baru.');
            }

            $duplicateStmt = $db->prepare('SELECT id FROM licenses WHERE license_key = ?' . ($id > 0 ? ' AND id <> ?' : '') . ' LIMIT 1');
            $duplicateParams = [$licenseKey];
            if ($id > 0) {
                $duplicateParams[] = $id;
            }
            $duplicateStmt->execute($duplicateParams);
            if ($duplicateStmt->fetchColumn()) {
                throw new RuntimeException('License key sudah digunakan. Gunakan key yang unik.');
            }

            $planFeatures = json_decode((string) ($plan['features'] ?? ''), true);
            if (!is_array($planFeatures)) {
                $planFeatures = [];
            }

            $db->beginTransaction();

            if ($id > 0) {
                $query = 'UPDATE licenses SET organization_id=?, plan_id=?, license_key=?, status=?, expires_at=?, features=? WHERE id=?' . (!$isSuperAdmin ? ' AND organization_id=?' : '');
                $params = [$targetOrgId, $planId, $licenseKey, $status, $expiresAt, json_encode($planFeatures, JSON_UNESCAPED_UNICODE), $id];
                if (!$isSuperAdmin) {
                    $params[] = $orgId;
                }
                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $targetId = $id;
            } else {
                $stmt = $db->prepare('INSERT INTO licenses (organization_id, plan_id, license_key, status, issued_at, expires_at, features) VALUES (?, ?, ?, ?, NOW(), ?, ?)');
                $stmt->execute([
                    $targetOrgId,
                    $planId,
                    $licenseKey,
                    $status,
                    $expiresAt,
                    json_encode($planFeatures, JSON_UNESCAPED_UNICODE),
                ]);
                $targetId = (int) $db->lastInsertId();
            }

            \BAJAMA\Core\Audit::log($db, $id > 0 ? 'LICENSE_UPDATED' : 'LICENSE_CREATED', 'admin', 'license', $targetId, ['organization_id' => $targetOrgId, 'status' => $status]);
            $db->commit();
            $message = 'License berhasil disimpan.';
        }

        if ($action === 'delete_license') {
            $licenseId = (int) ($_POST['license_id'] ?? 0);
            if ($licenseId <= 0) {
                throw new RuntimeException('License tidak valid.');
            }

            $db->beginTransaction();
            $deleteSql = 'DELETE FROM licenses WHERE id=?';
            $deleteParams = [$licenseId];
            if (!$isSuperAdmin) {
                $deleteSql .= ' AND organization_id=?';
                $deleteParams[] = $orgId;
            }
            $stmt = $db->prepare($deleteSql);
            $stmt->execute($deleteParams);
            if ($stmt->rowCount() <= 0) {
                throw new RuntimeException('License tidak dapat dihapus.');
            }
            \BAJAMA\Core\Audit::log($db, 'LICENSE_DELETED', 'admin', 'license', $licenseId, ['organization_id' => $orgId]);
            $db->commit();
            $message = 'License berhasil dihapus.';
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('BAJAMA license error: ' . $e->getMessage());
        $error = 'Perubahan license gagal disimpan.';
    }

    header('Location: licenses.php');
    exit;
}

$orgs = $db->query('SELECT id, name FROM organizations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$plans = $db->query('SELECT * FROM license_plans ORDER BY active DESC, name')->fetchAll(PDO::FETCH_ASSOC);

$licensesSql = 'SELECT l.*, p.name AS plan_name, p.code AS plan_code, p.price_monthly AS plan_price, p.features AS plan_features, o.name AS organization_name FROM licenses l LEFT JOIN license_plans p ON p.id = l.plan_id LEFT JOIN organizations o ON o.id = l.organization_id WHERE 1 = 1';
$params = [];
if (!$isSuperAdmin) {
    $licensesSql .= ' AND l.organization_id = ?';
    $params[] = $orgId;
}
$licensesSql .= ' ORDER BY l.id DESC';
$licensesStmt = $db->prepare($licensesSql);
$licensesStmt->execute($params);
$licenses = $licensesStmt->fetchAll(PDO::FETCH_ASSOC);

function lic_h($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function license_feature_labels($value): array {
  $labels = ['dashboard' => 'Dashboard', 'billing' => 'Billing tenant', 'mikrotik' => 'MikroTik', 'pppoe' => 'PPPoE', 'hotspot' => 'Hotspot', 'static' => 'Static IP', 'fiber' => 'Fiber / FTTH', 'olt' => 'OLT', 'onu' => 'ONU', 'noc' => 'NOC', 'api' => 'API'];
  $decoded = is_string($value) ? json_decode($value, true) : $value;
  if (!is_array($decoded)) return [];
  $features = [];
  foreach ($decoded as $key => $enabled) {
    if ($enabled === true || $enabled === 1 || $enabled === '1' || strtolower((string)$enabled) === 'true') {
      $features[] = $labels[$key] ?? ucwords(str_replace(['_', '-'], ' ', (string)$key));
    }
  }
  if (!in_array('Dashboard', $features, true)) {
    array_unshift($features, 'Dashboard');
  }
  return $features;
}

$pageTitle = 'License Management';
ob_start();
?>
<div class="container-fluid py-4">
  <div class="d-flex justify-content-between mb-4">
    <div>
      <h1>License Management</h1>
      <p class="text-muted">Administrasi license per organisasi, hanya untuk superadmin platform.</p>
    </div>
    <a class="btn btn-outline-secondary" href="dashboard.php">Dashboard</a>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-success"><?= lic_h($message) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= lic_h($error) ?></div>
  <?php endif; ?>

  <div class="card mb-4">
    <div class="card-body">
      <h2 class="h5 mb-3"><?= $editLicense ? 'Edit License' : 'Tambah License' ?></h2>
      <form method="post" class="row g-3">
        <input type="hidden" name="_csrf" value="<?= lic_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_license">
        <?php if ($editLicense): ?>
          <input type="hidden" name="id" value="<?= (int) $editLicense['id'] ?>">
        <?php endif; ?>

        <?php if ($isSuperAdmin): ?>
          <div class="col-md-3">
            <label class="form-label">Organisasi</label>
            <select class="form-select" name="organization_id" required>
              <?php foreach ($orgs as $org): ?>
                <option value="<?= (int) $org['id'] ?>" <?= (($editLicense['organization_id'] ?? $orgId) == (int) $org['id']) ? 'selected' : '' ?>><?= lic_h($org['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>

        <div class="col-md-3">
          <label class="form-label">Plan</label>
          <select class="form-select" name="plan_id" required>
            <?php foreach ($plans as $plan): ?>
              <option value="<?= (int) $plan['id'] ?>" <?= (($editLicense['plan_id'] ?? 0) == (int) $plan['id']) ? 'selected' : '' ?>><?= lic_h($plan['name']) ?> (<?= lic_h($plan['code']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">License Key</label>
          <input class="form-control" name="license_key" value="<?= lic_h($editLicense['license_key'] ?? '') ?>" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">Status</label>
          <select class="form-select" name="status">
            <?php foreach (['TRIAL','ACTIVE','SUSPENDED','EXPIRED','CANCELLED'] as $status): ?>
              <option value="<?= $status ?>" <?= (($editLicense['status'] ?? 'TRIAL') === $status) ? 'selected' : '' ?>><?= $status ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Expired</label>
          <input type="datetime-local" class="form-control" name="expires_at" value="<?= lic_h(!empty($editLicense['expires_at']) ? date('d-m-Y\TH:i', strtotime($editLicense['expires_at'])) : date('d-m-Y\TH:i', strtotime('+30 days'))) ?>">
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button class="btn btn-primary w-100" type="submit"><?= $editLicense ? 'Update' : 'Simpan' ?></button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header">License aktif / terdaftar</div>
    <div class="table-responsive">
      <table class="table mb-0">
        <thead>
          <tr>
            <?php if ($isSuperAdmin): ?><th>Organization</th><?php endif; ?>
            <th>Plan</th>
            <th>Harga & fitur</th>
            <th>Key</th>
            <th>Status</th>
            <th>Expires</th>
            <th class="text-end">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($licenses as $license): ?>
            <tr>
              <?php if ($isSuperAdmin): ?><td><?= lic_h($license['organization_name'] ?? '-') ?></td><?php endif; ?>
              <td><?= lic_h($license['plan_name'] ?? $license['plan_code']) ?></td>
              <td><strong>Rp <?= number_format((float)($license['plan_price'] ?? 0), 0, ',', '.') ?></strong><div class="small text-muted">/ bulan</div><?php $licenseFeatures = license_feature_labels($license['plan_features'] ?? ''); ?><?php if ($licenseFeatures): ?><div class="small mt-1"><?= lic_h(implode(', ', $licenseFeatures)) ?></div><?php else: ?><div class="small text-muted">Fitur belum tersedia</div><?php endif; ?></td>
              <td><code><?= lic_h(substr($license['license_key'], 0, 12)) ?>...</code></td>
              <td><?= lic_h($license['status']) ?></td>
              <td><?= lic_h($license['expires_at']) ?></td>
              <td class="text-end">
                <div class="btn-group btn-group-sm">
                  <a href="licenses.php?edit_id=<?= (int) $license['id'] ?>" class="btn btn-outline-primary">Edit</a>
                  <form method="post" onsubmit="return confirm('Hapus license ini?');" style="display:inline;">
                    <input type="hidden" name="_csrf" value="<?= lic_h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_license">
                    <input type="hidden" name="license_id" value="<?= (int) $license['id'] ?>">
                    <button class="btn btn-outline-danger" type="submit">Hapus</button>
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
<?php
$content = ob_get_clean();
require __DIR__ . '/../app/layout/layout.php';
