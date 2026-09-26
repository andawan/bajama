<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Traffic/TrafficCatalog.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\RBAC;
use BAJAMA\Core\License;
use BAJAMA\Traffic\TrafficCatalog;

Auth::requireLogin();

$db = db();

License::requireFeature($db, 'dashboard');
RBAC::require($db, 'traffic_catalog.view');

$canManage = RBAC::hasPermission($db, 'traffic_catalog.manage');

$catalog = new TrafficCatalog($db);

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectCatalog(): void
{
    $query = $_SERVER['QUERY_STRING'] ?? '';
    $url = 'traffic_catalog.php' . ($query !== '' ? '?' . $query : '');
    header('Location: ' . $url);
    exit;
}

$message = '';
$error = '';

/*
|--------------------------------------------------------------------------
| ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {

    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

    try {

        if ($action === 'save') {

            $data = [
                'id' => isset($_POST['id']) && $_POST['id'] !== ''
                    ? (int)$_POST['id']
                    : null,

                'category_slug' => trim((string)($_POST['category_slug'] ?? '')),
                'name' => trim((string)($_POST['name'] ?? '')),
                'entry_type' => strtoupper(trim((string)($_POST['entry_type'] ?? 'DOMAIN'))),
                'value' => trim((string)($_POST['value'] ?? '')),
                'port' => isset($_POST['port']) && $_POST['port'] !== ''
                    ? (int)$_POST['port']
                    : 0,
                'protocol' => strtoupper(trim((string)($_POST['protocol'] ?? 'ANY'))),
                'source_type' => 'OWNER',
                'confidence' => isset($_POST['confidence']) && $_POST['confidence'] !== ''
                    ? (float)$_POST['confidence']
                    : 100,
                'description' => trim((string)($_POST['description'] ?? '')),
            ];

            if ($data['category_slug'] === '') {
                throw new RuntimeException('Kategori wajib dipilih.');
            }

            if ($data['name'] === '') {
                throw new RuntimeException('Nama intelligence wajib diisi.');
            }

            if ($data['value'] === '') {
                throw new RuntimeException('Value/domain/IP/port wajib diisi.');
            }

            $result = $catalog->upsert($data);

            $message = 'Data berhasil diproses: ' . strtoupper((string)$result['action'])
                . ' — ID #' . (int)$result['id'];

        }

        if ($action === 'toggle') {

            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('ID catalog tidak valid.');
            }

            $stmt = $db->prepare(
                "UPDATE traffic_catalog
                 SET status = CASE
                     WHEN status = 'ACTIVE' THEN 'INACTIVE'
                     ELSE 'ACTIVE'
                 END,
                 updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );

            $stmt->execute([$id]);

            $message = 'Status catalog berhasil diperbarui.';
        }

    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$q = trim((string)($_GET['q'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$entryType = strtoupper(trim((string)($_GET['entry_type'] ?? '')));
$protocol = strtoupper(trim((string)($_GET['protocol'] ?? '')));
$sourceType = strtoupper(trim((string)($_GET['source_type'] ?? '')));
$status = strtoupper(trim((string)($_GET['status'] ?? 'ACTIVE')));

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| CATEGORIES
|--------------------------------------------------------------------------
*/

$categories = $db->query(
    "SELECT id, name, slug, icon
     FROM traffic_categories
     WHERE enabled = 1
     ORDER BY sort_order ASC, name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

$stats = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'domains' => 0,
    'ips' => 0,
    'ports' => 0,
];

$row = $db->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'ACTIVE') AS active,
        SUM(status = 'INACTIVE') AS inactive,
        SUM(entry_type IN ('DOMAIN','HOST')) AS domains,
        SUM(entry_type IN ('IP','CIDR')) AS ips,
        SUM(entry_type = 'PORT') AS ports
     FROM traffic_catalog"
)->fetch(PDO::FETCH_ASSOC);

if ($row) {
    $stats['total'] = (int)$row['total'];
    $stats['active'] = (int)$row['active'];
    $stats['inactive'] = (int)$row['inactive'];
    $stats['domains'] = (int)$row['domains'];
    $stats['ips'] = (int)$row['ips'];
    $stats['ports'] = (int)$row['ports'];
}

/*
|--------------------------------------------------------------------------
| CATEGORY STATISTICS
|--------------------------------------------------------------------------
*/

$categoryStats = $db->query(
    "SELECT
        c.name,
        c.slug,
        c.icon,
        COUNT(tc.id) AS total
     FROM traffic_categories c
     LEFT JOIN traffic_catalog tc
        ON tc.category_id = c.id
        AND tc.status = 'ACTIVE'
     WHERE c.enabled = 1
     GROUP BY c.id, c.name, c.slug, c.icon
     ORDER BY c.sort_order ASC, c.name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| QUERY
|--------------------------------------------------------------------------
*/

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(
        tc.name LIKE ?
        OR tc.value LIKE ?
        OR tc.normalized_value LIKE ?
        OR tc.description LIKE ?
    )";

    $like = '%' . $q . '%';

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($category !== '') {
    $where[] = "c.slug = ?";
    $params[] = $category;
}

if ($entryType !== '') {
    $where[] = "tc.entry_type = ?";
    $params[] = $entryType;
}

if ($protocol !== '') {
    $where[] = "tc.protocol = ?";
    $params[] = $protocol;
}

if ($sourceType !== '') {
    $where[] = "tc.source_type = ?";
    $params[] = $sourceType;
}

if ($status !== '') {
    $where[] = "tc.status = ?";
    $params[] = $status;
}

$whereSql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

$countSql =
    "SELECT COUNT(*)
     FROM traffic_catalog tc
     INNER JOIN traffic_categories c
        ON c.id = tc.category_id
     $whereSql";

$countStmt = $db->prepare($countSql);
$countStmt->execute($params);

$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$sql =
    "SELECT
        tc.id,
        tc.name,
        c.name AS category_name,
        c.slug AS category_slug,
        c.icon,
        tc.entry_type,
        tc.value,
        tc.normalized_value,
        tc.port,
        tc.protocol,
        tc.source_type,
        tc.source_organization_id,
        tc.confidence,
        tc.status,
        tc.description,
        tc.metadata,
        tc.first_seen_at,
        tc.last_seen_at,
        tc.updated_at
     FROM traffic_catalog tc
     INNER JOIN traffic_categories c
        ON c.id = tc.category_id
     $whereSql
     ORDER BY tc.last_seen_at DESC, tc.id DESC
     LIMIT $perPage OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| CENTRAL LOOKUP V3 INTELLIGENCE
|--------------------------------------------------------------------------
*/
foreach ($rows as &$catalogRow) {
    $catalogRow['v3'] = [];

    $rawMetadata = trim((string)($catalogRow['metadata'] ?? ''));

    if ($rawMetadata === '') {
        continue;
    }

    $decodedMetadata = json_decode($rawMetadata, true);

    if (!is_array($decodedMetadata)) {
        continue;
    }

    $catalogRow['v3'] = [
        'central_lookup'       => !empty($decodedMetadata['central_lookup']),
        'intelligence_version' => $decodedMetadata['intelligence_version'] ?? null,
        'lookup_id'            => $decodedMetadata['lookup_id'] ?? null,
        'lookup_confidence'    => $decodedMetadata['lookup_confidence'] ?? null,
        'provider'             => $decodedMetadata['provider'] ?? null,
        'application'          => $decodedMetadata['application'] ?? null,
        'service'              => $decodedMetadata['service'] ?? null,
        'classification'      => $decodedMetadata['classification'] ?? null,
        'business_category'    => $decodedMetadata['business_category'] ?? null,
        'business_category_name' =>
            $decodedMetadata['business_category_name'] ?? null,
        'normalized_domain'    => $decodedMetadata['normalized_domain'] ?? null,
        'lookup_evidence'      => $decodedMetadata['lookup_evidence'] ?? [],
    ];
}
unset($catalogRow);

$queryParams = $_GET;
unset($queryParams['page']);

function pageUrl(int $page, array $queryParams): string
{
    $queryParams['page'] = $page;
    return 'traffic_catalog.php?' . http_build_query($queryParams);
}

function v3Value(array $row, string $key, string $fallback = '—'): string
{
    $value = $row['v3'][$key] ?? null;

    if ($value === null || $value === '') {
        return $fallback;
    }

    if (is_array($value)) {
        return $fallback;
    }

    return (string)$value;
}

function v3HasIntelligence(array $row): bool
{
    return !empty($row['v3']['central_lookup'])
        && (int)($row['v3']['intelligence_version'] ?? 0) >= 3;
}

function v3Evidence(array $row): array
{
    $evidence = $row['v3']['lookup_evidence'] ?? [];

    return is_array($evidence) ? $evidence : [];
}

$pageTitle = 'Global Traffic Catalog';

ob_start();

?>


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


        body {
            background: #f5f7fb;
        }

        .page-header {
            background:
                linear-gradient(135deg, #111827 0%, #1f2937 55%, #374151 100%);
            color: #fff;
            border-radius: 20px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 12px 30px rgba(0,0,0,.12);
        }

        .stat-card {
            border: 0;
            border-radius: 18px;
            box-shadow: 0 8px 24px rgba(15,23,42,.06);
            height: 100%;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            background: #f1f5f9;
        }

        .catalog-card {
            border: 0;
            border-radius: 18px;
            box-shadow: 0 8px 28px rgba(15,23,42,.06);
        }

        .table > :not(caption) > * > * {
            padding: .85rem .75rem;
            vertical-align: middle;
        }

        .traffic-value {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: .86rem;
            word-break: break-word;
        }

        .category-pill {
            border-radius: 999px;
            font-size: .76rem;
            padding: .35rem .65rem;
            background: #eef2ff;
            color: #3730a3;
            white-space: nowrap;
        }

        .confidence-bar {
            width: 70px;
            height: 6px;
            background: #e5e7eb;
            border-radius: 999px;
            overflow: hidden;
        }

        .confidence-bar span {
            display: block;
            height: 100%;
            background: currentColor;
        }

        .category-card {
            border: 0;
            border-radius: 16px;
            transition: .2s ease;
        }

        .category-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(15,23,42,.09);
        }

        .empty-state {
            padding: 70px 20px;
            text-align: center;
            color: #64748b;
        }

        @media (max-width: 767.98px) {
            .page-header {
                padding: 22px;
            }

            .desktop-only {
                display: none;
            }

            .table-responsive {
                border-radius: 14px;
            }
        }
    

        /* =====================================================
           CENTRAL LOOKUP V3
           ===================================================== */

        .v3-intelligence {
            margin-top: 10px;
            padding: 12px;
            border: 1px solid rgba(59,130,246,.14);
            border-radius: 12px;
            background: linear-gradient(180deg,#f8fbff 0%,#f1f5f9 100%);
        }

        .v3-intelligence-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }

        .v3-title {
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #2563eb;
        }

        .v3-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border-radius: 999px;
            padding: 4px 8px;
            font-size: .68rem;
            font-weight: 800;
            background: #dbeafe;
            color: #1d4ed8;
            white-space: nowrap;
        }

        .v3-grid {
            display: grid;
            grid-template-columns: repeat(2,minmax(0,1fr));
            gap: 8px 14px;
        }

        .v3-field {
            min-width: 0;
        }

        .v3-label {
            display: block;
            margin-bottom: 2px;
            color: #64748b;
            font-size: .64rem;
            line-height: 1.25;
            font-weight: 800;
            letter-spacing: .05em;
            text-transform: uppercase;
        }

        .v3-value {
            display: block;
            color: #0f172a;
            font-size: .78rem;
            line-height: 1.35;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .v3-value.muted {
            color: #94a3b8;
            font-weight: 500;
        }

        .v3-category {
            display: inline-flex;
            align-items: center;
            max-width: 100%;
            border-radius: 999px;
            padding: 4px 8px;
            background: #ecfdf5;
            color: #047857;
            font-size: .68rem;
            font-weight: 800;
            overflow-wrap: anywhere;
        }

        .v3-confidence {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .v3-confidence-bar {
            flex: 1;
            min-width: 45px;
            max-width: 100px;
            height: 6px;
            overflow: hidden;
            border-radius: 999px;
            background: #e2e8f0;
        }

        .v3-confidence-bar span {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: #2563eb;
        }

        .v3-evidence {
            margin-top: 10px;
            padding-top: 9px;
            border-top: 1px dashed rgba(100,116,139,.25);
        }

        .v3-evidence-list {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 5px;
        }

        .v3-evidence-item {
            max-width: 100%;
            padding: 3px 7px;
            border-radius: 7px;
            background: #fff;
            border: 1px solid #e2e8f0;
            color: #475569;
            font-size: .64rem;
            line-height: 1.3;
            overflow-wrap: anywhere;
        }

        .v3-no-intelligence {
            margin-top: 10px;
            padding: 8px 10px;
            border-radius: 9px;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            color: #64748b;
            font-size: .72rem;
        }

        .catalog-intelligence-name {
            font-size: .9rem;
            line-height: 1.35;
        }

        .catalog-hostname {
            margin-top: 4px;
            color: #64748b;
            font-family: ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;
            font-size: .72rem;
            line-height: 1.4;
            overflow-wrap: anywhere;
        }

        .catalog-source-stack {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .catalog-table-row {
            vertical-align: top;
        }

        @media (max-width: 767.98px) {

            .v3-grid {
                grid-template-columns: 1fr;
                gap: 9px;
            }

            .v3-intelligence {
                margin-top: 12px;
                padding: 11px;
            }

            .v3-intelligence-header {
                align-items: flex-start;
            }

            .catalog-intelligence-name {
                font-size: .95rem;
            }

        }

        @media (min-width: 768px) {

            .catalog-table-wrap {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .catalog-table {
                min-width: 1120px;
            }

        }

</style>

<div class="container-fluid py-4 px-3 px-lg-4">

    <div class="page-header">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
            <div>
                <div class="text-uppercase small opacity-75 fw-semibold mb-2">
                    BAJAMA Intelligence
                </div>

                <h1 class="h3 fw-bold mb-2">
                    <i class="bi bi-globe2 me-2"></i>
                    Global Traffic Catalog
                </h1>

                <p class="mb-0 opacity-75">
                    Pusat intelligence traffic global untuk domain, IP, CIDR,
                    port dan protocol.
                </p>
            </div>

            <?php if ($canManage): ?>
                <div class="d-flex align-items-start">
                    <button
                        class="btn btn-light fw-semibold"
                        data-bs-toggle="modal"
                        data-bs-target="#catalogModal"
                        onclick="newCatalog()"
                    >
                        <i class="bi bi-plus-lg me-1"></i>
                        Tambah Intelligence
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?= e($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?= e($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- STATISTICS -->

    <div class="row g-3 mb-4">

        <div class="col-6 col-xl-2">
            <div class="card stat-card">
                <div class="card-body">
                    <div class="stat-icon mb-3">
                        <i class="bi bi-database"></i>
                    </div>
                    <div class="text-muted small">Total Intelligence</div>
                    <div class="fs-3 fw-bold"><?= number_format($stats['total']) ?></div>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-2">
            <div class="card stat-card">
                <div class="card-body">
                    <div class="stat-icon mb-3">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="text-muted small">Active</div>
                    <div class="fs-3 fw-bold text-success">
                        <?= number_format($stats['active']) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-2">
            <div class="card stat-card">
                <div class="card-body">
                    <div class="stat-icon mb-3">
                        <i class="bi bi-globe"></i>
                    </div>
                    <div class="text-muted small">Domain / Host</div>
                    <div class="fs-3 fw-bold">
                        <?= number_format($stats['domains']) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-2">
            <div class="card stat-card">
                <div class="card-body">
                    <div class="stat-icon mb-3">
                        <i class="bi bi-hdd-network"></i>
                    </div>
                    <div class="text-muted small">IP / CIDR</div>
                    <div class="fs-3 fw-bold">
                        <?= number_format($stats['ips']) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-2">
            <div class="card stat-card">
                <div class="card-body">
                    <div class="stat-icon mb-3">
                        <i class="bi bi-ethernet"></i>
                    </div>
                    <div class="text-muted small">Port</div>
                    <div class="fs-3 fw-bold">
                        <?= number_format($stats['ports']) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-2">
            <div class="card stat-card">
                <div class="card-body">
                    <div class="stat-icon mb-3">
                        <i class="bi bi-pause-circle"></i>
                    </div>
                    <div class="text-muted small">Inactive</div>
                    <div class="fs-3 fw-bold text-secondary">
                        <?= number_format($stats['inactive']) ?>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- CATEGORY OVERVIEW -->

    <div class="row g-3 mb-4">

        <?php foreach ($categoryStats as $cat): ?>

            <div class="col-6 col-md-4 col-lg-3 col-xl-auto flex-xl-grow-1">

                <a
                    href="<?= e('traffic_catalog.php?' . http_build_query([
                        'category' => $cat['slug'],
                        'status' => 'ACTIVE'
                    ])) ?>"
                    class="text-decoration-none text-dark"
                >

                    <div class="card category-card h-100">
                        <div class="card-body">

                            <div class="d-flex align-items-center gap-3">

                                <div class="fs-4">
                                    <i class="bi <?= e($cat['icon'] ?: 'bi-diagram-3') ?>"></i>
                                </div>

                                <div class="flex-grow-1">
                                    <div class="small fw-semibold">
                                        <?= e($cat['name']) ?>
                                    </div>

                                    <div class="fw-bold">
                                        <?= number_format((int)$cat['total']) ?>
                                    </div>
                                </div>

                            </div>

                        </div>
                    </div>

                </a>

            </div>

        <?php endforeach; ?>

    </div>

    <!-- FILTER -->

    <div class="card catalog-card mb-4">

        <div class="card-body">

            <form method="get">

                <div class="row g-2">

                    <div class="col-12 col-lg-4">

                        <label class="form-label small fw-semibold">
                            Search
                        </label>

                        <div class="input-group">

                            <span class="input-group-text">
                                <i class="bi bi-search"></i>
                            </span>

                            <input
                                type="text"
                                name="q"
                                class="form-control"
                                placeholder="Domain, IP, CIDR, nama..."
                                value="<?= e($q) ?>"
                            >

                        </div>

                    </div>

                    <div class="col-6 col-md-3 col-lg-2">

                        <label class="form-label small fw-semibold">
                            Category
                        </label>

                        <select name="category" class="form-select">

                            <option value="">Semua</option>

                            <?php foreach ($categories as $cat): ?>

                                <option
                                    value="<?= e($cat['slug']) ?>"
                                    <?= $category === $cat['slug'] ? 'selected' : '' ?>
                                >
                                    <?= e($cat['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-6 col-md-3 col-lg-2">

                        <label class="form-label small fw-semibold">
                            Type
                        </label>

                        <select name="entry_type" class="form-select">

                            <option value="">Semua</option>
                            <option value="DOMAIN" <?= $entryType === 'DOMAIN' ? 'selected' : '' ?>>DOMAIN</option>
                            <option value="HOST" <?= $entryType === 'HOST' ? 'selected' : '' ?>>HOST</option>
                            <option value="IP" <?= $entryType === 'IP' ? 'selected' : '' ?>>IP</option>
                            <option value="CIDR" <?= $entryType === 'CIDR' ? 'selected' : '' ?>>CIDR</option>
                            <option value="PORT" <?= $entryType === 'PORT' ? 'selected' : '' ?>>PORT</option>
                            <option value="PROTOCOL" <?= $entryType === 'PROTOCOL' ? 'selected' : '' ?>>PROTOCOL</option>

                        </select>

                    </div>

                    <div class="col-6 col-md-3 col-lg-1">

                        <label class="form-label small fw-semibold">
                            Proto
                        </label>

                        <select name="protocol" class="form-select">

                            <option value="">All</option>
                            <option value="ANY" <?= $protocol === 'ANY' ? 'selected' : '' ?>>ANY</option>
                            <option value="TCP" <?= $protocol === 'TCP' ? 'selected' : '' ?>>TCP</option>
                            <option value="UDP" <?= $protocol === 'UDP' ? 'selected' : '' ?>>UDP</option>
                            <option value="ICMP" <?= $protocol === 'ICMP' ? 'selected' : '' ?>>ICMP</option>

                        </select>

                    </div>

                    <div class="col-6 col-md-3 col-lg-1">

                        <label class="form-label small fw-semibold">
                            Source
                        </label>

                        <select name="source_type" class="form-select">

                            <option value="">All</option>
                            <option value="SYSTEM" <?= $sourceType === 'SYSTEM' ? 'selected' : '' ?>>SYSTEM</option>
                            <option value="OWNER" <?= $sourceType === 'OWNER' ? 'selected' : '' ?>>OWNER</option>
                            <option value="CUSTOMER" <?= $sourceType === 'CUSTOMER' ? 'selected' : '' ?>>CUSTOMER</option>
                            <option value="IMPORT" <?= $sourceType === 'IMPORT' ? 'selected' : '' ?>>IMPORT</option>
                            <option value="DISCOVERED" <?= $sourceType === 'DISCOVERED' ? 'selected' : '' ?>>DISCOVERED</option>

                        </select>

                    </div>

                    <div class="col-6 col-md-3 col-lg-1">

                        <label class="form-label small fw-semibold">
                            Status
                        </label>

                        <select name="status" class="form-select">

                            <option value="">All</option>
                            <option value="ACTIVE" <?= $status === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option>
                            <option value="INACTIVE" <?= $status === 'INACTIVE' ? 'selected' : '' ?>>INACTIVE</option>

                        </select>

                    </div>

                    <div class="col-6 col-md-3 col-lg-1 d-flex align-items-end">

                        <button class="btn btn-dark w-100">
                            <i class="bi bi-funnel me-1"></i>
                            Filter
                        </button>

                    </div>

                </div>

            </form>

        </div>

    </div>

    <!-- TABLE -->

    <div class="card catalog-card">

        <div class="card-header bg-white border-0 pt-4 px-4">

            <div class="d-flex justify-content-between align-items-center gap-3">

                <div>
                    <h2 class="h5 fw-bold mb-1">
                        Traffic Intelligence
                    </h2>

                    <div class="text-muted small">
                        Menampilkan <?= number_format(count($rows)) ?>
                        dari <?= number_format($totalRows) ?> record
                    </div>
                </div>

                <div class="text-muted small desktop-only">
                    Page <?= $page ?> / <?= $totalPages ?>
                </div>

            </div>

        </div>

        <div class="card-body p-0">

            <?php if (!$rows): ?>

                <div class="empty-state">

                    <i class="bi bi-search fs-1 d-block mb-3"></i>

                    <h5 class="fw-bold">
                        Tidak ada intelligence ditemukan
                    </h5>

                    <p class="mb-0">
                        Coba ubah kata pencarian atau filter.
                    </p>

                </div>

            <?php else: ?>

                <div class="catalog-table-wrap">

                    <table class="table table-hover mb-0 catalog-table bajama-table">

                        <thead class="table-light">

                            <tr>
                                <th>ID</th>
                                <th>Category</th>
                                <th>Intelligence</th>
                                <th>Type</th>
                                <th>Port / Protocol</th>
                                <th>Source</th>
                                <th>Confidence</th>
                                <th>Status</th>
                                <?php if ($canManage): ?>
                                    <th class="text-end">Action</th>
                                <?php endif; ?>
                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($rows as $row): ?>

                            <?php
                            $hasV3 = v3HasIntelligence($row);
                            $evidence = v3Evidence($row);
                            $lookupConfidence = $row['v3']['lookup_confidence'] ?? $row['confidence'];
                            $lookupConfidence = min(100, max(0, (float)$lookupConfidence));
                            ?>

                            <tr class="catalog-table-row bajama-table-row">

                                <td data-label="ID" class="text-muted">
                                    #<?= (int)$row['id'] ?>
                                </td>

                                <td data-label="Category">

                                    <span class="category-pill">
                                        <i class="bi <?= e($row['icon'] ?: 'bi-diagram-3') ?> me-1"></i>
                                        <?= e($row['category_name']) ?>
                                    </span>

                                    <?php if ($hasV3): ?>
                                        <div class="mt-2">
                                            <span class="v3-badge">
                                                <i class="bi bi-stars"></i>
                                                LOOKUP V3
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                </td>

                                <td data-label="Intelligence" style="min-width:320px">

                                    <div class="fw-bold catalog-intelligence-name">
                                        <?= e($row['name']) ?>
                                    </div>

                                    <div class="catalog-hostname">
                                        <?= e($row['normalized_value']) ?>
                                    </div>

                                    <?php if ($hasV3): ?>

                                        <div class="v3-intelligence">

                                            <div class="v3-intelligence-header">

                                                <div class="v3-title">
                                                    <i class="bi bi-cpu me-1"></i>
                                                    Central Lookup Intelligence
                                                </div>

                                                <span class="v3-badge">
                                                    V<?= (int)$row['v3']['intelligence_version'] ?>
                                                </span>

                                            </div>

                                            <div class="v3-grid">

                                                <div class="v3-field">
                                                    <span class="v3-label">Provider</span>
                                                    <span class="v3-value <?= v3Value($row,'provider') === '—' ? 'muted' : '' ?>">
                                                        <?= e(v3Value($row,'provider')) ?>
                                                    </span>
                                                </div>

                                                <div class="v3-field">
                                                    <span class="v3-label">Application</span>
                                                    <span class="v3-value <?= v3Value($row,'application') === '—' ? 'muted' : '' ?>">
                                                        <?= e(v3Value($row,'application')) ?>
                                                    </span>
                                                </div>

                                                <div class="v3-field">
                                                    <span class="v3-label">Service</span>
                                                    <span class="v3-value <?= v3Value($row,'service') === '—' ? 'muted' : '' ?>">
                                                        <?= e(v3Value($row,'service')) ?>
                                                    </span>
                                                </div>

                                                <div class="v3-field">
                                                    <span class="v3-label">Technical Class</span>
                                                    <span class="v3-value">
                                                        <?= e(v3Value($row,'classification','APPLICATION')) ?>
                                                    </span>
                                                </div>

                                                <div class="v3-field">
                                                    <span class="v3-label">Business Category</span>
                                                    <span class="v3-category">
                                                        <?= e(v3Value(
                                                            $row,
                                                            'business_category_name',
                                                            v3Value($row,'business_category','Browser / General')
                                                        )) ?>
                                                    </span>
                                                </div>

                                                <div class="v3-field">
                                                    <span class="v3-label">Lookup Confidence</span>

                                                    <div class="v3-confidence">

                                                        <div class="v3-confidence-bar">
                                                            <span style="width:<?= $lookupConfidence ?>%"></span>
                                                        </div>

                                                        <span class="v3-value">
                                                            <?= number_format($lookupConfidence, 0) ?>%
                                                        </span>

                                                    </div>

                                                </div>

                                                <div class="v3-field">
                                                    <span class="v3-label">Normalized Domain</span>
                                                    <span class="v3-value">
                                                        <?= e(v3Value(
                                                            $row,
                                                            'normalized_domain',
                                                            $row['normalized_value']
                                                        )) ?>
                                                    </span>
                                                </div>

                                                <div class="v3-field">
                                                    <span class="v3-label">Lookup ID</span>
                                                    <span class="v3-value">
                                                        #<?= e(v3Value($row,'lookup_id','—')) ?>
                                                    </span>
                                                </div>

                                            </div>

                                            <?php if ($evidence): ?>

                                                <div class="v3-evidence">

                                                    <span class="v3-label">
                                                        <i class="bi bi-shield-check me-1"></i>
                                                        Evidence
                                                    </span>

                                                    <div class="v3-evidence-list">

                                                        <?php foreach ($evidence as $evidenceKey => $evidenceValue): ?>

                                                            <?php
                                                            if (is_array($evidenceValue)) {
                                                                $evidenceText = implode(', ', array_map(
                                                                    static function ($item): string {
                                                                        return is_scalar($item)
                                                                            ? (string)$item
                                                                            : json_encode($item);
                                                                    },
                                                                    $evidenceValue
                                                                ));
                                                            } elseif (is_scalar($evidenceValue)) {
                                                                $evidenceText = (string)$evidenceValue;
                                                            } else {
                                                                $evidenceText = json_encode($evidenceValue);
                                                            }

                                                            if ($evidenceText === '') {
                                                                continue;
                                                            }
                                                            ?>

                                                            <span class="v3-evidence-item">
                                                                <strong><?= e((string)$evidenceKey) ?>:</strong>
                                                                <?= e($evidenceText) ?>
                                                            </span>

                                                        <?php endforeach; ?>

                                                    </div>

                                                </div>

                                            <?php endif; ?>

                                        </div>

                                    <?php else: ?>

                                        <div class="v3-no-intelligence">
                                            <i class="bi bi-info-circle me-1"></i>
                                            Belum memiliki hasil Central Lookup V3.
                                        </div>

                                    <?php endif; ?>

                                </td>

                                <td data-label="Type">

                                    <span class="badge text-bg-light border">
                                        <?= e($row['entry_type']) ?>
                                    </span>

                                </td>

                                <td data-label="Port / Protocol">

                                    <div class="catalog-source-stack">

                                        <?php if ((int)$row['port'] > 0): ?>

                                            <span class="badge text-bg-dark">
                                                :<?= (int)$row['port'] ?>
                                            </span>

                                        <?php endif; ?>

                                        <span class="badge text-bg-light border">
                                            <?= e($row['protocol']) ?>
                                        </span>

                                    </div>

                                </td>

                                <td data-label="Source">

                                    <span class="badge text-bg-secondary">
                                        <?= e($row['source_type']) ?>
                                    </span>

                                </td>

                                <td data-label="Confidence">

                                    <div class="d-flex align-items-center gap-2">

                                        <div class="confidence-bar">
                                            <span
                                                style="width:<?= min(100, max(0, (float)$row['confidence'])) ?>%"
                                            ></span>
                                        </div>

                                        <small>
                                            <?= number_format((float)$row['confidence'], 0) ?>%
                                        </small>

                                    </div>

                                    <?php if ($hasV3 && $row['v3']['lookup_confidence'] !== null): ?>

                                        <div class="small text-primary mt-1">
                                            Lookup:
                                            <?= number_format($lookupConfidence, 0) ?>%
                                        </div>

                                    <?php endif; ?>

                                </td>

                                <td data-label="Status">

                                    <?php if ($row['status'] === 'ACTIVE'): ?>

                                        <span class="badge text-bg-success">
                                            ACTIVE
                                        </span>

                                    <?php else: ?>

                                        <span class="badge text-bg-secondary">
                                            INACTIVE
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <?php if ($canManage): ?>

                                    <td data-label="Action" class="text-end bajama-table-actions">

                                        <div class="btn-group btn-group-sm">

                                            <button
                                                type="button"
                                                class="btn btn-outline-primary"
                                                onclick='editCatalog(<?= json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                                data-bs-toggle="modal"
                                                data-bs-target="#catalogModal"
                                                title="Edit"
                                            >
                                                <i class="bi bi-pencil"></i>
                                            </button>

                                            <form method="post" class="d-inline">

                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">

                                                <button
                                                    type="submit"
                                                    class="btn btn-outline-secondary"
                                                    title="Toggle status"
                                                    onclick="return confirm('Ubah status intelligence ini?')"
                                                >
                                                    <i class="bi bi-power"></i>
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

                </div>

            <?php endif; ?>

        </div>

        <?php if ($totalPages > 1): ?>

            <div class="card-footer bg-white border-0 py-3">

                <nav>

                    <ul class="pagination justify-content-center mb-0">

                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a
                                class="page-link"
                                href="<?= $page > 1 ? e(pageUrl($page - 1, $queryParams)) : '#' ?>"
                            >
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>

                        <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        ?>

                        <?php for ($p = $start; $p <= $end; $p++): ?>

                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">

                                <a
                                    class="page-link"
                                    href="<?= e(pageUrl($p, $queryParams)) ?>"
                                >
                                    <?= $p ?>
                                </a>

                            </li>

                        <?php endfor; ?>

                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">

                            <a
                                class="page-link"
                                href="<?= $page < $totalPages ? e(pageUrl($page + 1, $queryParams)) : '#' ?>"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </a>

                        </li>

                    </ul>

                </nav>

            </div>

        <?php endif; ?>

    </div>

</div>

<?php if ($canManage): ?>

<!-- ADD / EDIT MODAL -->

<div
    class="modal fade"
    id="catalogModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content border-0 shadow-lg">

            <form method="post">

                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="catalog_id">

                <div class="modal-header">

                    <div>
                        <h5 class="modal-title fw-bold" id="modalTitle">
                            Tambah Intelligence
                        </h5>

                        <div class="small text-muted">
                            Data akan melewati TrafficCatalog engine
                            untuk normalisasi dan deduplikasi.
                        </div>
                    </div>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                    ></button>

                </div>

                <div class="modal-body">

                    <div class="row g-3">

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Category
                            </label>

                            <select
                                name="category_slug"
                                id="catalog_category"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Pilih kategori
                                </option>

                                <?php foreach ($categories as $cat): ?>

                                    <option value="<?= e($cat['slug']) ?>">
                                        <?= e($cat['name']) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Name
                            </label>

                            <input
                                type="text"
                                name="name"
                                id="catalog_name"
                                class="form-control"
                                placeholder="Contoh: YouTube"
                                required
                            >

                        </div>

                        <div class="col-md-4">

                            <label class="form-label fw-semibold">
                                Entry Type
                            </label>

                            <select
                                name="entry_type"
                                id="catalog_entry_type"
                                class="form-select"
                            >
                                <option value="DOMAIN">DOMAIN</option>
                                <option value="HOST">HOST</option>
                                <option value="IP">IP</option>
                                <option value="CIDR">CIDR</option>
                                <option value="PORT">PORT</option>
                                <option value="PROTOCOL">PROTOCOL</option>
                            </select>

                        </div>

                        <div class="col-md-5">

                            <label class="form-label fw-semibold">
                                Value
                            </label>

                            <input
                                type="text"
                                name="value"
                                id="catalog_value"
                                class="form-control"
                                placeholder="youtube.com / 1.2.3.4 / 443"
                                required
                            >

                        </div>

                        <div class="col-md-3">

                            <label class="form-label fw-semibold">
                                Port
                            </label>

                            <input
                                type="number"
                                name="port"
                                id="catalog_port"
                                class="form-control"
                                min="0"
                                max="65535"
                                value="0"
                            >

                        </div>

                        <div class="col-md-4">

                            <label class="form-label fw-semibold">
                                Protocol
                            </label>

                            <select
                                name="protocol"
                                id="catalog_protocol"
                                class="form-select"
                            >
                                <option value="ANY">ANY</option>
                                <option value="TCP">TCP</option>
                                <option value="UDP">UDP</option>
                                <option value="ICMP">ICMP</option>
                            </select>

                        </div>

                        <div class="col-md-4">

                            <label class="form-label fw-semibold">
                                Confidence
                            </label>

                            <div class="input-group">

                                <input
                                    type="number"
                                    name="confidence"
                                    id="catalog_confidence"
                                    class="form-control"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    value="100"
                                >

                                <span class="input-group-text">%</span>

                            </div>

                        </div>

                        <div class="col-md-4">

                            <label class="form-label fw-semibold">
                                Source
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                value="OWNER"
                                disabled
                            >

                        </div>

                        <div class="col-12">

                            <label class="form-label fw-semibold">
                                Description
                            </label>

                            <textarea
                                name="description"
                                id="catalog_description"
                                class="form-control"
                                rows="3"
                                placeholder="Catatan intelligence..."
                            ></textarea>

                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal"
                    >
                        Batal
                    </button>

                    <button
                        type="submit"
                        class="btn btn-dark"
                    >
                        <i class="bi bi-check2-circle me-1"></i>
                        Simpan Intelligence
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<?php endif; ?>

<?php if ($canManage): ?>

<script>

function newCatalog() {

    document.getElementById('modalTitle').textContent =
        'Tambah Intelligence';

    document.getElementById('catalog_id').value = '';
    document.getElementById('catalog_category').value = '';
    document.getElementById('catalog_name').value = '';
    document.getElementById('catalog_entry_type').value = 'DOMAIN';
    document.getElementById('catalog_value').value = '';
    document.getElementById('catalog_port').value = '0';
    document.getElementById('catalog_protocol').value = 'ANY';
    document.getElementById('catalog_confidence').value = '100';
    document.getElementById('catalog_description').value = '';
}

function editCatalog(row) {

    document.getElementById('modalTitle').textContent =
        'Edit Intelligence #' + row.id;

    document.getElementById('catalog_id').value = row.id || '';
    document.getElementById('catalog_category').value = row.category_slug || '';
    document.getElementById('catalog_name').value = row.name || '';
    document.getElementById('catalog_entry_type').value = row.entry_type || 'DOMAIN';
    document.getElementById('catalog_value').value = row.value || '';
    document.getElementById('catalog_port').value = row.port || '0';
    document.getElementById('catalog_protocol').value = row.protocol || 'ANY';
    document.getElementById('catalog_confidence').value = row.confidence || '100';
    document.getElementById('catalog_description').value = row.description || '';
}

</script>

<?php endif; ?>

<?php

$content = ob_get_clean();

require __DIR__ . '/../app/layout/layout.php';
