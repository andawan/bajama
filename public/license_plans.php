<?php
declare(strict_types=1);

if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\Audit;
use BAJAMA\Core\RBAC;

Auth::requireLogin();
$db = db();
RBAC::require($db, 'license_plans.manage');

function ensureLicensePlanSchema(PDO $db): void
{
    $columnNames = [
        'features' => "ALTER TABLE license_plans ADD COLUMN features JSON NULL AFTER description",
        'max_customers' => "ALTER TABLE license_plans ADD COLUMN max_customers INT UNSIGNED NOT NULL DEFAULT 100 AFTER features",
        'max_routers' => "ALTER TABLE license_plans ADD COLUMN max_routers INT UNSIGNED NOT NULL DEFAULT 5 AFTER max_customers",
        'max_olts' => "ALTER TABLE license_plans ADD COLUMN max_olts INT UNSIGNED NOT NULL DEFAULT 1 AFTER max_routers",
        'max_onus' => "ALTER TABLE license_plans ADD COLUMN max_onus INT UNSIGNED NOT NULL DEFAULT 128 AFTER max_olts",
        'price_monthly' => "ALTER TABLE license_plans ADD COLUMN price_monthly DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER max_onus",
        'active' => "ALTER TABLE license_plans ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER price_monthly",
        'created_at' => "ALTER TABLE license_plans ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER active",
    ];

    foreach ($columnNames as $columnName => $alterSql) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `license_plans` LIKE '{$columnName}'");
            if ($stmt && $stmt->fetch()) {
                continue;
            }
        } catch (Throwable $e) {
            // table may not exist yet; the schema fix should handle missing table creation for new installs.
        }

        try {
            $db->exec($alterSql);
        } catch (Throwable $e) {
            error_log('BAJAMA license plan schema sync warning: ' . $e->getMessage());
        }
    }
}

$featureNames = [
    'dashboard' => 'Dashboard',
    'billing' => 'Billing tenant',
    'mikrotik' => 'MikroTik',
    'pppoe' => 'PPPoE',
    'hotspot' => 'Hotspot',
    'static' => 'Static IP',
    'fiber' => 'Fiber / FTTH',
    'olt' => 'OLT',
    'onu' => 'ONU',
    'noc' => 'NOC',
    'api' => 'API',
];

$featurePricing = [
    'dashboard' => 30000,
    'billing' => 35000,
    'mikrotik' => 50000,
    'pppoe' => 40000,
    'hotspot' => 35000,
    'static' => 30000,
    'fiber' => 45000,
    'olt' => 55000,
    'onu' => 30000,
    'noc' => 60000,
    'api' => 150000,
];

function computePlanPrice(array $features, array $featurePricing): float
{
    $total = 0.0;
    foreach ($featurePricing as $feature => $price) {
        if (!empty($features[$feature])) {
            $total += (float) $price;
        }
    }

    if (!empty($features['api'])) {
        return round($total, 2);
    }

    return round($total + (float) ($featurePricing['api'] ?? 0), 2);
}

ensureLicensePlanSchema($db);
$message = '';
$error = '';
$editId = (int)($_GET['edit_id'] ?? 0);
$editPlan = null;

if ($editId > 0) {
    $stmt = $db->prepare('SELECT * FROM license_plans WHERE id=? LIMIT 1');
    $stmt->execute([$editId]);
    $editPlan = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf((string)($_POST['_csrf'] ?? ''));
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'save_plan') {
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $maxCustomers = max(0, (int)($_POST['max_customers'] ?? 0));
            $maxRouters = max(0, (int)($_POST['max_routers'] ?? 0));
            $maxOlts = max(0, (int)($_POST['max_olts'] ?? 0));
            $maxOnus = max(0, (int)($_POST['max_onus'] ?? 0));
            $priceMonthly = max(0, (float)($_POST['price_monthly'] ?? 0));
            $active = isset($_POST['active']) ? 1 : 0;
            $features = [];
            foreach ($featureNames as $feature => $_label) {
                $features[$feature] = isset($_POST['features'][$feature]);
            }
            $features['dashboard'] = true;
            $features['api'] = true;
            $priceMonthly = computePlanPrice($features, $featurePricing);

            if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,49}$/', $code) || $name === '') {
                throw new RuntimeException('Kode dan nama plan wajib valid.');
            }

            $duplicate = $db->prepare('SELECT id FROM license_plans WHERE code=? AND id<>? LIMIT 1');
            $duplicate->execute([$code, $id]);
            if ($duplicate->fetchColumn()) {
                throw new RuntimeException('Kode plan sudah digunakan.');
            }

            if ($id > 0) {
                $stmt = $db->prepare(
                    'UPDATE license_plans
                     SET code=?, name=?, description=?, features=?, max_customers=?, max_routers=?, max_olts=?, max_onus=?, price_monthly=?, active=?
                     WHERE id=?'
                );
                $stmt->execute([$code, $name, $description ?: null, json_encode($features), $maxCustomers, $maxRouters, $maxOlts, $maxOnus, $priceMonthly, $active, $id]);
                Audit::log($db, 'LICENSE_PLAN_UPDATED', 'admin', 'license_plan', $id, ['code' => $code]);
                $message = 'License plan berhasil diperbarui.';
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO license_plans (code, name, description, features, max_customers, max_routers, max_olts, max_onus, price_monthly, active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$code, $name, $description ?: null, json_encode($features), $maxCustomers, $maxRouters, $maxOlts, $maxOnus, $priceMonthly, $active]);
                $newId = (int)$db->lastInsertId();
                Audit::log($db, 'LICENSE_PLAN_CREATED', 'admin', 'license_plan', $newId, ['code' => $code]);
                $message = 'License plan berhasil dibuat.';
            }
        } elseif ($action === 'delete_plan') {
            if ($id <= 0) {
                throw new RuntimeException('Plan tidak valid.');
            }
            $used = $db->prepare('SELECT COUNT(*) FROM licenses WHERE plan_id=?');
            $used->execute([$id]);
            if ((int)$used->fetchColumn() > 0) {
                throw new RuntimeException('Plan masih dipakai oleh license. Nonaktifkan plan, jangan hapus.');
            }
            $stmt = $db->prepare('DELETE FROM license_plans WHERE id=?');
            $stmt->execute([$id]);
            Audit::log($db, 'LICENSE_PLAN_DELETED', 'admin', 'license_plan', $id);
            $message = 'License plan berhasil dihapus.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$plans = $db->query('SELECT * FROM license_plans ORDER BY active DESC, name')->fetchAll(PDO::FETCH_ASSOC);
function plan_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function plan_features(array $plan): array
{
    $decoded = json_decode((string)($plan['features'] ?? ''), true);
    if (!is_array($decoded)) {
        $decoded = [];
    }
    $decoded['dashboard'] = true;
    return $decoded;
}
function plan_feature_labels(array $features, array $featureNames): array
{
    $labels = [];
    foreach ($features as $feature => $enabled) {
        if ($enabled === true || $enabled === 1 || $enabled === '1' || strtolower((string)$enabled) === 'true') {
            $labels[] = $featureNames[$feature] ?? ucwords(str_replace(['_', '-'], ' ', (string)$feature));
        }
    }
    return $labels;
}

$pageTitle = 'License Plans';
ob_start();
?>
<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1>License Plans</h1><p class="text-muted mb-0">Master paket, fitur, batas resource, dan harga lisensi platform.</p></div>
    <a href="licenses.php" class="btn btn-outline-secondary">Kelola License</a>
  </div>
  <?php if ($message): ?><div class="alert alert-success"><?= plan_h($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= plan_h($error) ?></div><?php endif; ?>

  <div class="card mb-4"><div class="card-body">
    <h2 class="h5 mb-3"><?= $editPlan ? 'Edit License Plan' : 'Tambah License Plan' ?></h2>
    <?php $currentFeatures = $editPlan ? plan_features($editPlan) : []; ?>
    <form method="post" class="row g-3">
      <input type="hidden" name="_csrf" value="<?= plan_h(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_plan">
      <input type="hidden" name="id" value="<?= (int)($editPlan['id'] ?? 0) ?>">
      <div class="col-md-2"><label class="form-label">Code</label><input class="form-control" name="code" value="<?= plan_h($editPlan['code'] ?? '') ?>" placeholder="PRO" required></div>
      <div class="col-md-3"><label class="form-label">Nama</label><input class="form-control" name="name" value="<?= plan_h($editPlan['name'] ?? '') ?>" required></div>
      <div class="col-md-7"><label class="form-label">Deskripsi</label><input class="form-control" name="description" value="<?= plan_h($editPlan['description'] ?? '') ?>"></div>
      <div class="col-md-3"><label class="form-label">Max Customers <small>(0 unlimited)</small></label><input type="number" min="0" class="form-control" name="max_customers" value="<?= (int)($editPlan['max_customers'] ?? 100) ?>"></div>
      <div class="col-md-3"><label class="form-label">Max Routers</label><input type="number" min="0" class="form-control" name="max_routers" value="<?= (int)($editPlan['max_routers'] ?? 5) ?>"></div>
      <div class="col-md-3"><label class="form-label">Max OLT</label><input type="number" min="0" class="form-control" name="max_olts" value="<?= (int)($editPlan['max_olts'] ?? 1) ?>"></div>
      <div class="col-md-3"><label class="form-label">Max ONU</label><input type="number" min="0" class="form-control" name="max_onus" value="<?= (int)($editPlan['max_onus'] ?? 128) ?>"></div>
      <div class="col-md-3"><label class="form-label">Harga / bulan</label><input type="number" min="0" step="0.01" class="form-control" name="price_monthly" id="price_monthly" value="<?= plan_h((string)($editPlan['price_monthly'] ?? computePlanPrice($currentFeatures, $featurePricing))) ?>" readonly></div>
    <div class="col-md-9"><label class="form-label d-block">Fitur dan harga komponen</label><div class="d-flex flex-wrap gap-3"><?php foreach ($featureNames as $feature => $label): $isForced = $feature === 'api'; ?><label class="form-check"><input class="form-check-input feature-checkbox" type="checkbox" name="features[<?= plan_h($feature) ?>]" data-feature="<?= plan_h($feature) ?>" <?= $isForced || !empty($currentFeatures[$feature]) ? 'checked' : '' ?> <?= $isForced ? 'disabled' : '' ?>> <?= plan_h($label) ?> <small class="text-muted">(Rp <?= number_format((float)$featurePricing[$feature], 0, ',', '.') ?>)</small></label><?php endforeach; ?></div></div>
      <div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="active" <?= (!$editPlan || (int)$editPlan['active'] === 1) ? 'checked' : '' ?>> Plan aktif</label></div>
      <div class="col-12"><button class="btn btn-primary" type="submit"><?= $editPlan ? 'Update Plan' : 'Simpan Plan' ?></button><?php if ($editPlan): ?> <a class="btn btn-outline-secondary" href="license_plans.php">Batal</a><?php endif; ?></div>
    </form>
  </div></div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const featureInputs = document.querySelectorAll('.feature-checkbox');
      const priceInput = document.getElementById('price_monthly');
      const pricingMap = <?= json_encode($featurePricing, JSON_UNESCAPED_UNICODE) ?>;

      function recalculatePrice() {
        if (!priceInput) return;
        let total = 0;
        featureInputs.forEach(function (input) {
          const feature = input.dataset.feature || '';
          if (input.checked && pricingMap[feature]) {
            total += Number(pricingMap[feature]);
          }
        });
        if (total > 0) {
          priceInput.value = total.toFixed(2);
        }
      }

      featureInputs.forEach(function (input) {
        input.addEventListener('change', function () {
          if (input.dataset.feature === 'api') {
            input.checked = true;
            return;
          }
          recalculatePrice();
        });
      });

      recalculatePrice();
    });
  </script>

  <div class="card"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Code</th><th>Plan</th><th>Limits</th><th>Harga</th><th>Fitur</th><th>Status</th><th class="text-end">Aksi</th></tr></thead><tbody>
    <?php foreach ($plans as $plan): $features = plan_features($plan); $featureLabels = plan_feature_labels($features, $featureNames); ?>
        <tr><td><code><?= plan_h($plan['code']) ?></code></td><td><strong><?= plan_h($plan['name']) ?></strong><div class="small text-muted"><?= plan_h($plan['description'] ?? '') ?></div></td><td><?= (int)$plan['max_customers'] ?> customer / <?= (int)$plan['max_routers'] ?> router / <?= (int)$plan['max_olts'] ?> OLT / <?= (int)$plan['max_onus'] ?> ONU</td><td><strong>Rp <?= number_format((float)$plan['price_monthly'], 0, ',', '.') ?></strong><div class="small text-muted">per bulan</div></td><td><?php if ($featureLabels): ?><ul class="mb-0 ps-3"><?php foreach ($featureLabels as $featureLabel): ?><li><?= plan_h($featureLabel) ?></li><?php endforeach; ?></ul><?php else: ?><span class="text-muted">Belum ada fitur</span><?php endif; ?></td><td><?= (int)$plan['active'] ? 'ACTIVE' : 'INACTIVE' ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="license_plans.php?edit_id=<?= (int)$plan['id'] ?>">Edit</a><form class="d-inline" method="post" onsubmit="return confirm('Hapus plan ini?');"><input type="hidden" name="_csrf" value="<?= plan_h(csrf_token()) ?>"><input type="hidden" name="action" value="delete_plan"><input type="hidden" name="id" value="<?= (int)$plan['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Hapus</button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
</div>
<?php $content = ob_get_clean(); require __DIR__ . '/../app/layout/layout.php';
