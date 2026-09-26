<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Traffic/TrafficPolicyProvisioner.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\Tenant;
use BAJAMA\Core\RBAC;
use BAJAMA\Core\License;
use BAJAMA\Network\MikroTik;
use BAJAMA\Traffic\TrafficPolicyProvisioner;
use BAJAMA\Traffic\TrafficRealtimeService;

Auth::requireLogin();

$db = db();

License::requireFeature($db, 'dashboard');
RBAC::require($db, 'traffic_catalog.view');

$organizationId = Tenant::id();
$canManage = RBAC::hasPermission($db, 'traffic_catalog.manage');

$message = '';
$error = '';
$saved = false;

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
$csrf = csrf_token();

/*
|--------------------------------------------------------------------------
| POST SAVE
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        http_response_code(403);
        exit('Forbidden');
    }

    verify_csrf((string) ($_POST['csrf'] ?? $_POST['_csrf'] ?? ''));

    $routerId = isset($_POST['router_id'])
        ? (int)$_POST['router_id']
        : 0;

        $policies = isset($_POST['policies']) && is_array($_POST['policies'])
            ? $_POST['policies']
            : [];
        $trafficAction = strtoupper(trim((string)($_POST['traffic_action'] ?? 'SAVE')));

        try {
            /*
             * Router harus benar-benar milik tenant.
             */
                        $router = MikroTik::find($db, $organizationId, $routerId);

            if (!$router) {
                throw new RuntimeException(
                    'Router tidak ditemukan atau bukan milik organisasi Anda.'
                );
            }

            $api = MikroTik::connect($router);
            try {
                $routerInterfaces = $api->interfaces();
                $routerRoutes = $api->routes();
            } finally {
                $api->disconnect();
            }

            $allowedInterfaces = [];
            foreach ($routerInterfaces as $interface) {
                $name = trim((string)($interface['name'] ?? ''));
                if ($name !== '' && strtolower((string)($interface['disabled'] ?? 'no')) !== 'yes') {
                    $allowedInterfaces[strtolower($name)] = $name;
                }
            }
            if (!$allowedInterfaces) {
                throw new RuntimeException('Tidak ada interface aktif yang terbaca dari MikroTik.');
            }

            $routeGateways = [];
            foreach ($routerRoutes as $route) {
                if ((string)($route['dst-address'] ?? '') !== '0.0.0.0/0') {
                    continue;
                }
                $gateway = trim((string)($route['gateway'] ?? $route['immediate-gw'] ?? ''));
                $interface = trim((string)($route['interface'] ?? ''));
                if ($interface === '' && strpos($gateway, '%') !== false) {
                    [, $interface] = explode('%', $gateway, 2);
                }
                if ($interface !== '') {
                    $routeGateways[strtolower($interface)] = $gateway;
                }
            }

            /*
             * Kategori aktif.
             */
            $catStmt = $db->prepare(
                'SELECT id
                 FROM traffic_categories
                 WHERE enabled = 1
                 ORDER BY sort_order, id'
            );

            $catStmt->execute();

            $categoryRows = $catStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!$categoryRows) {
                throw new RuntimeException(
                    'Belum ada kategori traffic aktif.'
                );
            }

            $allowedCategories = [];

            foreach ($categoryRows as $categoryId) {
                $allowedCategories[(int)$categoryId] = true;
            }

            $db->beginTransaction();

            /*
             * Simpan setiap kategori.
             */
            $selectExisting = $db->prepare(
                'SELECT id
                 FROM traffic_policies
                 WHERE organization_id = ?
                   AND router_id = ?
                   AND category_id = ?
                 LIMIT 1'
            );

            $insertPolicy = $db->prepare(
                'INSERT INTO traffic_policies
                    (
                        organization_id,
                        router_id,
                        category_id,
                        isp_id,
                        enabled,
                        priority,
                        notes
                    )
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            $updatePolicy = $db->prepare(
                'UPDATE traffic_policies
                 SET
                     isp_id = ?,
                     enabled = ?,
                     priority = ?,
                     notes = ?
                 WHERE id = ?
                   AND organization_id = ?
                   AND router_id = ?
                   AND category_id = ?'
            );

            foreach ($policies as $categoryIdKey => $policy) {
                $categoryId = (int)$categoryIdKey;

                if (!isset($allowedCategories[$categoryId])) {
                    continue;
                }

                if (!is_array($policy)) {
                    continue;
                }

                $interfaceName = trim((string)($policy['interface_name'] ?? ''));
                $interfaceKey = strtolower($interfaceName);
                if (!isset($allowedInterfaces[$interfaceKey])) {
                    throw new RuntimeException('Interface MikroTik tidak valid: ' . $interfaceName);
                }
                $interfaceName = $allowedInterfaces[$interfaceKey];

                $ispStmt = $db->prepare(
                    'SELECT id FROM network_isps
                     WHERE organization_id = ? AND router_id = ? AND interface_name = ?
                     LIMIT 1'
                );
                $ispStmt->execute([$organizationId, $routerId, $interfaceName]);
                $ispId = (int)$ispStmt->fetchColumn();
                if ($ispId <= 0) {
                    $insertInterface = $db->prepare(
                        'INSERT INTO network_isps
                         (organization_id, router_id, name, interface_name, gateway, status, enabled)
                         VALUES (?, ?, ?, ?, ?, "ONLINE", 1)'
                    );
                    $insertInterface->execute([
                        $organizationId,
                        $routerId,
                        'RouterOS ' . $interfaceName,
                        $interfaceName,
                        $routeGateways[$interfaceKey] ?? null,
                    ]);
                    $ispId = (int)$db->lastInsertId();
                }

                $enabled = !empty($policy['enabled']) ? 1 : 0;

                $priority = isset($policy['priority'])
                    ? (int)$policy['priority']
                    : 100;

                if ($priority < 1) {
                    $priority = 1;
                }

                if ($priority > 65535) {
                    $priority = 65535;
                }

                $notes = isset($policy['notes'])
                    ? trim((string)$policy['notes'])
                    : '';

                if (strlen($notes) > 5000) {
                    $notes = substr($notes, 0, 5000);
                }

                $selectExisting->execute([
                    $organizationId,
                    $routerId,
                    $categoryId
                ]);

                $existingId = $selectExisting->fetchColumn();

                if ($existingId) {
                    $updatePolicy->execute([
                        $ispId,
                        $enabled,
                        $priority,
                        $notes !== '' ? $notes : null,
                        (int)$existingId,
                        $organizationId,
                        $routerId,
                        $categoryId
                    ]);
                } else {
                    $insertPolicy->execute([
                        $organizationId,
                        $routerId,
                        $categoryId,
                        $ispId,
                        $enabled,
                        $priority,
                        $notes !== '' ? $notes : null
                    ]);
                }
            }

            $db->commit();
            $saved = true;

            $message = 'Traffic Policy berhasil disimpan.';
            if ($trafficAction === 'SAVE_APPLY') {
                $realtime = (new TrafficRealtimeService())->refreshOrganization(
                    $db,
                    $organizationId
                );
                $result = (new TrafficPolicyProvisioner())->apply(
                    $db,
                    $organizationId,
                    $routerId
                );
                $message .= ' RouterOS diperbarui: ' . (int)$result['created'] . ' rule/address-list dibuat.';
                $message .= ' Realtime: ' . (int)$realtime['success'] . '/' . (int)$realtime['routers'] . ' router dipindai.';
                if (!empty($result['errors'])) {
                    $message .= ' Catatan: ' . implode(' ', $result['errors']);
                }
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            error_log('BAJAMA traffic policy error: ' . $e->getMessage());
            $error = $saved
                ? 'Traffic policy sudah tersimpan, tetapi penerapan ke MikroTik gagal: ' . $e->getMessage()
                : 'Traffic policy tidak dapat disimpan.';
        }
}

/*
|--------------------------------------------------------------------------
| ROUTERS
|--------------------------------------------------------------------------
*/
$routerStmt = $db->prepare(
    'SELECT
        id,
        name,
        host,
        port,
        status
     FROM mikrotik_routers
     WHERE organization_id = ?
     ORDER BY name, id'
);

$routerStmt->execute([$organizationId]);

$routers = $routerStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedRouterId = isset($_GET['router_id'])
    ? (int)$_GET['router_id']
    : 0;

if (!$selectedRouterId && $routers) {
    $selectedRouterId = (int)$routers[0]['id'];
}

/*
|--------------------------------------------------------------------------
| INTERFACES ROUTEROS
|--------------------------------------------------------------------------
*/
$routerInterfaces = [];
$interfaceError = '';

if ($selectedRouterId > 0) {
    try {
        $selectedRouterApi = MikroTik::connect(
            MikroTik::find($db, $organizationId, $selectedRouterId) ?? []
        );
        try {
            foreach ($selectedRouterApi->interfaces() as $interface) {
                $name = trim((string)($interface['name'] ?? ''));
                if ($name !== '' && strtolower((string)($interface['disabled'] ?? 'no')) !== 'yes') {
                    $routerInterfaces[] = ['name' => $name, 'type' => (string)($interface['type'] ?? '')];
                }
            }
        } finally {
            $selectedRouterApi->disconnect();
        }
    } catch (Throwable $e) {
        $interfaceError = 'Interface MikroTik tidak dapat dibaca: ' . $e->getMessage();
    }
}

$interfaceNames = [];
foreach ($routerInterfaces as $interface) {
    $interfaceNames[strtolower($interface['name'])] = $interface['name'];
}

/*
|--------------------------------------------------------------------------
| CATEGORIES
|--------------------------------------------------------------------------
*/
$catStmt = $db->query(
    'SELECT
        id,
        name,
        slug,
        description,
        icon,
        sort_order
     FROM traffic_categories
     WHERE enabled = 1
     ORDER BY sort_order, id'
);

$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| EXISTING POLICIES
|--------------------------------------------------------------------------
*/
$existingPolicies = [];

if ($selectedRouterId > 0) {
    $policyStmt = $db->prepare(
        'SELECT
            tp.id,
            tp.category_id,
            tp.isp_id,
            tp.enabled,
            tp.priority,
            tp.notes
         FROM traffic_policies tp
         INNER JOIN mikrotik_routers r
             ON r.id = tp.router_id
            AND r.organization_id = tp.organization_id
         WHERE tp.organization_id = ?
           AND tp.router_id = ?
         ORDER BY tp.category_id'
    );

    $policyStmt->execute([
        $organizationId,
        $selectedRouterId
    ]);

    foreach ($policyStmt->fetchAll(PDO::FETCH_ASSOC) as $policy) {
        $existingPolicies[(int)$policy['category_id']] = $policy;
    }
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/
$totalCategories = count($categories);
$configuredCount = count($existingPolicies);

$enabledCount = 0;

foreach ($existingPolicies as $policy) {
    if ((int)$policy['enabled'] === 1) {
        $enabledCount++;
    }
}

$selectedRouter = null;

foreach ($routers as $router) {
    if ((int)$router['id'] === $selectedRouterId) {
        $selectedRouter = $router;
        break;
    }
}

function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function categoryIcon(?string $icon, string $slug): string
{
    if ($icon) {
        return $icon;
    }

    $icons = [
        'streaming' => 'bi-play-circle-fill',
        'social'    => 'bi-people-fill',
        'chat'      => 'bi-chat-dots-fill',
        'game'      => 'bi-controller',
        'banking'   => 'bi-bank2',
        'e-wallet'  => 'bi-wallet2',
        'browser'   => 'bi-globe2'
    ];

    return isset($icons[$slug])
        ? $icons[$slug]
        : 'bi-diagram-3-fill';
}

$pageTitle = 'Traffic Policy - BAJAMA';

ob_start();

?>
<style>
:root {
    --tp-radius: 18px;
}

body {
    background:
        radial-gradient(
            circle at top right,
            rgba(13,110,253,.08),
            transparent 30%
        ),
        #f6f8fb;
}

.page-wrap {
    max-width: 1500px;
    margin: 0 auto;
}

.hero {
    border: 0;
    border-radius: 24px;
    background:
        linear-gradient(
            135deg,
            #111827,
            #1e3a8a
        );
    color: #fff;
    overflow: hidden;
}

.hero .icon-box {
    width: 58px;
    height: 58px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 16px;
    background: rgba(255,255,255,.12);
    font-size: 27px;
}

.stat-card {
    border: 0;
    border-radius: var(--tp-radius);
    box-shadow: 0 8px 30px rgba(15,23,42,.06);
}

.policy-card {
    border: 0;
    border-radius: 20px;
    box-shadow: 0 8px 30px rgba(15,23,42,.07);
    transition: transform .15s ease, box-shadow .15s ease;
}

.policy-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 34px rgba(15,23,42,.10);
}

.category-icon {
    width: 48px;
    height: 48px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 14px;
    background: rgba(13,110,253,.10);
    color: #0d6efd;
    font-size: 22px;
}

.isp-option {
    border: 1px solid #dee2e6;
    border-radius: 14px;
    padding: 12px;
    cursor: pointer;
    transition: .15s ease;
    height: 100%;
}

.isp-option:hover {
    border-color: #86b7fe;
    background: #f8fbff;
}

.isp-option.selected {
    border-color: #0d6efd;
    background: rgba(13,110,253,.06);
    box-shadow: inset 0 0 0 1px rgba(13,110,253,.15);
}

.policy-status {
    min-width: 90px;
}

.sticky-save {
    position: sticky;
    bottom: 16px;
    z-index: 20;
}

.save-bar {
    border: 0;
    border-radius: 18px;
    box-shadow: 0 12px 40px rgba(15,23,42,.15);
    backdrop-filter: blur(12px);
    background: rgba(255,255,255,.94);
}

@media (max-width: 767.98px) {
    .hero {
        border-radius: 18px;
    }

    .policy-card {
        border-radius: 16px;
    }
}
</style>

<style>
/* BAJAMA CONTENT SAFETY */
.content-area {
    min-width: 0;
    max-width: 100%;
    overflow-x: hidden;
}

.content-area > .container-fluid {
    width: 100%;
    max-width: 100%;
    min-width: 0;
    box-sizing: border-box;
}
</style>

<div class="container-fluid py-4 px-3 px-lg-4">
<div class="page-wrap">

    <!-- HERO -->
    <div class="hero p-4 p-lg-5 mb-4 shadow-sm">
        <div class="d-flex flex-column flex-lg-row gap-4 align-items-lg-center justify-content-between">

            <div class="d-flex gap-3 align-items-start">
                <div class="icon-box">
                    <i class="bi bi-signpost-split-fill"></i>
                </div>

                <div>
                    <div class="text-white-50 small fw-semibold mb-1">
                        BAJAMA · TRAFFIC INTELLIGENCE
                    </div>

                    <h1 class="h2 fw-bold mb-2">
                        Traffic Policy
                    </h1>

                    <p class="mb-0 text-white-50">
                        Atur interface RouterOS untuk setiap kategori traffic
                        tanpa mengubah konfigurasi MikroTik.
                    </p>
                </div>
            </div>

            <div>
                <a
                    href="traffic_catalog.php"
                    class="btn btn-light"
                >
                    <i class="bi bi-database me-1"></i>
                    Traffic Catalog
                </a>
            </div>

        </div>
    </div>

    <!-- ALERT -->
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?= h($message) ?>
            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?= h($error) ?>
            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>
        </div>
    <?php endif; ?>

    <?php if (!$routers): ?>

        <div class="card policy-card">
            <div class="card-body text-center py-5">
                <div class="display-5 text-muted mb-3">
                    <i class="bi bi-router"></i>
                </div>

                <h4 class="fw-bold">
                    Belum ada MikroTik
                </h4>

                <p class="text-muted mb-0">
                    Organisasi ini belum memiliki router MikroTik
                    yang dapat digunakan untuk Traffic Policy.
                </p>
            </div>
        </div>

    <?php elseif (!$routerInterfaces): ?>

        <div class="card policy-card">
            <div class="card-body text-center py-5">
                <div class="display-5 text-muted mb-3">
                    <i class="bi bi-diagram-3"></i>
                </div>

                <h4 class="fw-bold">
                    Interface MikroTik belum tersedia
                </h4>

                <p class="text-muted mb-0">
                    Pastikan RouterOS dapat diakses dan memiliki interface aktif.
                    <?= h($interfaceError) ?>
                </p>
            </div>
        </div>

    <?php else: ?>

        <!-- ROUTER SELECTOR -->
        <div class="card stat-card mb-4">
            <div class="card-body p-4">

                <div class="row g-3 align-items-end">

                    <div class="col-lg-7">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-router me-1"></i>
                            MikroTik Router
                        </label>

                        <select
                            id="routerSelector"
                            class="form-select form-select-lg"
                        >
                            <?php foreach ($routers as $router): ?>
                                <option
                                    value="<?= (int)$router['id'] ?>"
                                    <?= (int)$router['id'] === $selectedRouterId ? 'selected' : '' ?>
                                >
                                    <?= h($router['name']) ?>
                                    — <?= h($router['host']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-5">

                        <div class="d-flex gap-2 flex-wrap">

                            <div class="badge text-bg-light border p-2">
                                <i class="bi bi-list-check me-1"></i>
                                <?= $totalCategories ?> kategori
                            </div>

                            <div class="badge text-bg-light border p-2">
                                <i class="bi bi-check-circle me-1"></i>
                                <?= $configuredCount ?> configured
                            </div>

                            <div class="badge text-bg-light border p-2">
                                <i class="bi bi-lightning-charge me-1"></i>
                                <?= $enabledCount ?> aktif
                            </div>

                        </div>

                    </div>

                </div>

                <?php if ($selectedRouter): ?>
                    <div class="mt-3 small text-muted">
                        Router:
                        <strong><?= h($selectedRouter['name']) ?></strong>
                        · <?= h($selectedRouter['host']) ?>
                        · Status:
                        <strong><?= h($selectedRouter['status']) ?></strong>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- POLICY FORM -->
        <form method="post">

            <input
                type="hidden"
                name="csrf"
                value="<?= h($csrf) ?>"
            >

            <input
                type="hidden"
                name="router_id"
                value="<?= (int)$selectedRouterId ?>"
            >

            <div class="row g-4">

                <?php foreach ($categories as $category): ?>

                    <?php
                    $categoryId = (int)$category['id'];

                    $policy = isset($existingPolicies[$categoryId])
                        ? $existingPolicies[$categoryId]
                        : null;

                    $currentInterface = '';
                    if ($policy && !empty($policy['isp_id'])) {
                        $currentInterfaceStmt = $db->prepare(
                            'SELECT interface_name FROM network_isps WHERE id = ? LIMIT 1'
                        );
                        $currentInterfaceStmt->execute([(int)$policy['isp_id']]);
                        $currentInterface = (string)($currentInterfaceStmt->fetchColumn() ?: '');
                    }

                    $enabled = $policy
                        ? (int)$policy['enabled'] === 1
                        : false;

                    $priority = $policy
                        ? (int)$policy['priority']
                        : 100;

                    $notes = $policy
                        ? (string)$policy['notes']
                        : '';
                    ?>

                    <div class="col-12 col-xl-6">

                        <div class="card policy-card h-100">

                            <div class="card-body p-4">

                                <div class="d-flex justify-content-between align-items-start mb-4">

                                    <div class="d-flex gap-3 align-items-center">

                                        <div class="category-icon">
                                            <i class="bi <?= h(categoryIcon(
                                                $category['icon'] ?? null,
                                                (string)$category['slug']
                                            )) ?>"></i>
                                        </div>

                                        <div>
                                            <h5 class="fw-bold mb-1">
                                                <?= h($category['name']) ?>
                                            </h5>

                                            <div class="small text-muted">
                                                <?= h($category['slug']) ?>
                                            </div>
                                        </div>

                                    </div>

                                    <span class="badge rounded-pill policy-status <?= $policy
                                        ? ($enabled ? 'text-bg-success' : 'text-bg-secondary')
                                        : 'text-bg-light border text-dark'
                                    ?>">
                                        <?= $policy
                                            ? ($enabled ? 'Aktif' : 'Nonaktif')
                                            : 'Belum diatur'
                                        ?>
                                    </span>

                                </div>

                                <?php if (!empty($category['description'])): ?>
                                    <p class="small text-muted mb-3">
                                        <?= h($category['description']) ?>
                                    </p>
                                <?php endif; ?>

                                <div class="mb-3">

                                    <div class="small fw-semibold mb-2">
                                        Interface RouterOS
                                    </div>

                                    <div class="row g-2">

                                        <?php foreach ($routerInterfaces as $interface): ?>

                                            <?php
                                            $interfaceName = (string)$interface['name'];
                                            $isSelected = strcasecmp($currentInterface, $interfaceName) === 0;
                                            ?>

                                            <div class="col-12 col-md-6">

                                                <label class="isp-option d-block <?= $isSelected ? 'selected' : '' ?>">

                                                    <div class="d-flex align-items-start gap-2">

                                                        <input
                                                            class="form-check-input mt-1 isp-radio"
                                                            type="radio"
                                                            name="policies[<?= $categoryId ?>][interface_name]"
                                                            value="<?= h($interfaceName) ?>"
                                                            <?= $isSelected ? 'checked' : '' ?>
                                                            required
                                                        >

                                                        <div class="flex-grow-1">

                                                            <div class="fw-semibold">
                                                                <?= h($interfaceName) ?>
                                                            </div>

                                                            <div class="small text-muted">
                                                                <?= h($interface['type'] ?: 'RouterOS interface') ?>
                                                            </div>

                                                            <div class="small text-muted">
                                                                Sumber: MikroTik RouterOS
                                                            </div>

                                                        </div>

                                                    </div>

                                                </label>

                                            </div>

                                        <?php endforeach; ?>

                                    </div>

                                </div>

                                <div class="row g-3">

                                    <div class="col-sm-6">

                                        <label class="form-label small fw-semibold">
                                            Priority
                                        </label>

                                        <input
                                            type="number"
                                            class="form-control"
                                            name="policies[<?= $categoryId ?>][priority]"
                                            min="1"
                                            max="65535"
                                            value="<?= $priority ?>"
                                        >

                                    </div>

                                    <div class="col-sm-6">

                                        <label class="form-label small fw-semibold d-block">
                                            Status
                                        </label>

                                        <div class="form-check form-switch pt-1">

                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                role="switch"
                                                name="policies[<?= $categoryId ?>][enabled]"
                                                value="1"
                                                <?= $enabled ? 'checked' : '' ?>
                                            >

                                            <label class="form-check-label">
                                                Policy aktif
                                            </label>

                                        </div>

                                    </div>

                                </div>

                                <div class="mt-3">

                                    <label class="form-label small fw-semibold">
                                        Catatan
                                    </label>

                                    <textarea
                                        class="form-control"
                                        name="policies[<?= $categoryId ?>][notes]"
                                        rows="2"
                                        maxlength="5000"
                                        placeholder="Catatan policy..."
                                    ><?= h($notes) ?></textarea>

                                </div>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

            <?php if ($canManage): ?>

                <div class="sticky-save mt-4">

                    <div class="save-bar p-3">

                        <div class="d-flex flex-column flex-md-row gap-3 align-items-md-center justify-content-between">

                            <div class="small text-muted">
                                <i class="bi bi-shield-check me-1"></i>
                                Domain/IP masuk ke address-list, lalu mangle
                                <code>prerouting</code> memberi routing-mark ke interface RouterOS.
                            </div>

                            <div class="d-flex gap-2 flex-wrap">
                                <button type="submit" name="traffic_action" value="SAVE" class="btn btn-outline-primary btn-lg px-4">
                                    <i class="bi bi-save2 me-2"></i>
                                    Simpan Policy
                                </button>
                                <button type="submit" name="traffic_action" value="SAVE_APPLY" class="btn btn-primary btn-lg px-4" onclick="return confirm('Simpan dan terapkan policy ke MikroTik sekarang? Rule BAJAMA sebelumnya akan disinkronkan ulang.');">
                                    <i class="bi bi-router me-2"></i>
                                    Simpan &amp; Terapkan ke MikroTik
                                </button>
                            </div>

                        </div>

                    </div>

                </div>

            <?php endif; ?>

        </form>

    <?php endif; ?>

</div>
</div>

<script>
(function () {
    const selector = document.getElementById('routerSelector');

    if (!selector) {
        return;
    }

    selector.addEventListener('change', function () {
        const id = this.value;

        const url = new URL(window.location.href);

        url.searchParams.set('router_id', id);

        window.location.href = url.toString();
    });

    document.querySelectorAll('.isp-option').forEach(function (option) {
        option.addEventListener('click', function () {

            const radio = this.querySelector('input[type="radio"]');

            if (!radio) {
                return;
            }

            document.querySelectorAll(
                'input[name="' + radio.name + '"]'
            ).forEach(function (input) {
                const wrapper = input.closest('.isp-option');

                if (wrapper) {
                    wrapper.classList.remove('selected');
                }
            });

            radio.checked = true;
            this.classList.add('selected');
        });
    });
})();
</script>

<?php
$content = ob_get_clean();

require __DIR__ . '/../app/layout/layout.php';
