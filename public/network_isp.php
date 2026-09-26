<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Network/LoadBalanceProvisioner.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\License;
use BAJAMA\Core\RBAC;
use BAJAMA\Core\Tenant;
use BAJAMA\Network\RouterOSProvisioningService;
use BAJAMA\Network\LoadBalanceProvisioner;

Auth::requireLogin();

$db = db();

License::requireFeature($db, 'mikrotik');
RBAC::require($db, 'network.view');

$organizationId = Tenant::id();
$canManage = RBAC::hasPermission($db, 'network.manage');

function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function redirectNetwork(string $type, string $message): void
{
    header(
        'Location: network_isp.php?' .
        http_build_query([
            $type === 'error' ? 'error' : 'msg' => $message
        ])
    );
    exit;
}

function validIpOrEmpty(string $value): bool
{
    return $value === '' || filter_var(
        $value,
        FILTER_VALIDATE_IP
    ) !== false;
}

function validSubnet(string $value): bool
{
    if ($value === '') {
        return true;
    }

    if (strpos($value, '/') !== false) {
        [$ip, $prefix] = explode('/', $value, 2);

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP
        ) !== false
        && ctype_digit($prefix)
        && (int)$prefix >= 0
        && (int)$prefix <= (
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                ? 128
                : 32
        );
    }

    return filter_var(
        $value,
        FILTER_VALIDATE_IP
    ) !== false;
}

$csrf = csrf_token();
$message = trim((string)($_GET['msg'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));
$routerStmt = $db->prepare('SELECT id, name, host FROM mikrotik_routers WHERE organization_id=? ORDER BY name');
$routerStmt->execute([$organizationId]);
$routers = $routerStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$canManage) {
        http_response_code(403);
        exit('Akses ditolak.');
    }

    verify_csrf(
        (string)($_POST['_csrf'] ?? $_POST['csrf'] ?? '')
    );

    $action = (string)($_POST['action'] ?? '');

    try {

        /*
         * ADD
         */
        if ($action === 'add') {

            $routerId = (int)($_POST['router_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $interface = trim(
                (string)($_POST['interface_name'] ?? '')
            );
            $gateway = trim(
                (string)($_POST['gateway'] ?? '')
            );
            $ipAddress = trim(
                (string)($_POST['ip_address'] ?? '')
            );
            $subnet = trim(
                (string)($_POST['subnet'] ?? '')
            );
            $publicIp = trim(
                (string)($_POST['public_ip'] ?? '')
            );
            $dnsPrimary = trim(
                (string)($_POST['dns_primary'] ?? '')
            );
            $dnsSecondary = trim(
                (string)($_POST['dns_secondary'] ?? '')
            );

            $download = trim(
                (string)($_POST['bandwidth_download'] ?? '')
            );
            $upload = trim(
                (string)($_POST['bandwidth_upload'] ?? '')
            );

            $routingMode = strtoupper(
                trim((string)($_POST['routing_mode'] ?? 'STATIC'))
            );

            $distance = max(
                1,
                (int)($_POST['distance'] ?? 1)
            );

            $checkGateway = strtoupper(
                trim((string)($_POST['check_gateway'] ?? 'PING'))
            );

            if ($routerId <= 0 || $name === '') {
                throw new RuntimeException(
                    'Router MikroTik dan nama ISP wajib diisi.'
                );
            }

            $routerCheck = $db->prepare('SELECT id FROM mikrotik_routers WHERE id=? AND organization_id=?');
            $routerCheck->execute([$routerId, $organizationId]);
            if (!$routerCheck->fetchColumn()) {
                throw new RuntimeException('Router MikroTik bukan milik organisasi ini.');
            }

            if ($interface === '') {
                throw new RuntimeException(
                    'Interface wajib diisi.'
                );
            }

            if (!validIpOrEmpty($gateway)) {
                throw new RuntimeException(
                    'Gateway tidak valid.'
                );
            }

            if (!validIpOrEmpty($ipAddress)) {
                throw new RuntimeException(
                    'IP address tidak valid.'
                );
            }

            if (!validSubnet($subnet)) {
                throw new RuntimeException(
                    'Subnet tidak valid.'
                );
            }

            if (!validIpOrEmpty($publicIp)) {
                throw new RuntimeException(
                    'Public IP tidak valid.'
                );
            }

            if (!validIpOrEmpty($dnsPrimary)) {
                throw new RuntimeException(
                    'DNS primary tidak valid.'
                );
            }

            if (!validIpOrEmpty($dnsSecondary)) {
                throw new RuntimeException(
                    'DNS secondary tidak valid.'
                );
            }

            if (!in_array(
                $routingMode,
                ['STATIC', 'ECMP', 'PCC', 'FAILOVER'],
                true
            )) {
                throw new RuntimeException(
                    'Routing mode tidak valid.'
                );
            }

            if (!in_array(
                $checkGateway,
                ['NONE', 'PING', 'ARP'],
                true
            )) {
                throw new RuntimeException(
                    'Gateway check tidak valid.'
                );
            }

            $downloadValue =
                $download === ''
                    ? null
                    : max(0, (int)$download);

            $uploadValue =
                $upload === ''
                    ? null
                    : max(0, (int)$upload);

            $stmt = $db->prepare(
                'INSERT INTO network_isps (
                    organization_id,
                    router_id,
                    name,
                    interface_name,
                    gateway,
                    ip_address,
                    subnet,
                    public_ip,
                    dns_primary,
                    dns_secondary,
                    bandwidth_download,
                    bandwidth_upload,
                    routing_mode,
                    distance,
                    check_gateway,
                    status,
                    enabled
                ) VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    "UNKNOWN",
                    1
                )'
            );

            $stmt->execute([
                $organizationId,
                $routerId,
                $name,
                $interface,
                $gateway,
                $ipAddress !== '' ? $ipAddress : null,
                $subnet !== '' ? $subnet : null,
                $publicIp !== '' ? $publicIp : null,
                $dnsPrimary !== '' ? $dnsPrimary : null,
                $dnsSecondary !== '' ? $dnsSecondary : null,
                $downloadValue,
                $uploadValue,
                $routingMode,
                $distance,
                $checkGateway
            ]);

            redirectNetwork(
                'msg',
                'ISP berhasil disimpan di BAJAMA. Tekan Apply untuk mengirim ke MikroTik.'
            );
        }

        /*
         * EDIT
         */
        if ($action === 'edit') {

            $id = (int)($_POST['id'] ?? 0);
            $routerId = (int)($_POST['router_id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException(
                    'ID ISP tidak valid.'
                );
            }

            $name = trim((string)($_POST['name'] ?? ''));
            $interface = trim(
                (string)($_POST['interface_name'] ?? '')
            );
            $gateway = trim(
                (string)($_POST['gateway'] ?? '')
            );
            $ipAddress = trim(
                (string)($_POST['ip_address'] ?? '')
            );
            $subnet = trim(
                (string)($_POST['subnet'] ?? '')
            );
            $publicIp = trim(
                (string)($_POST['public_ip'] ?? '')
            );
            $dnsPrimary = trim(
                (string)($_POST['dns_primary'] ?? '')
            );
            $dnsSecondary = trim(
                (string)($_POST['dns_secondary'] ?? '')
            );

            $download = trim(
                (string)($_POST['bandwidth_download'] ?? '')
            );
            $upload = trim(
                (string)($_POST['bandwidth_upload'] ?? '')
            );

            $routingMode = strtoupper(
                trim((string)($_POST['routing_mode'] ?? 'STATIC'))
            );

            $distance = max(
                1,
                (int)($_POST['distance'] ?? 1)
            );

            $checkGateway = strtoupper(
                trim((string)($_POST['check_gateway'] ?? 'PING'))
            );

            if ($name === '') {
                throw new RuntimeException(
                    'Nama ISP wajib diisi.'
                );
            }

            if ($interface === '') {
                throw new RuntimeException(
                    'Interface wajib diisi.'
                );
            }

            if (!validIpOrEmpty($gateway)) {
                throw new RuntimeException(
                    'Gateway tidak valid.'
                );
            }

            if (!validIpOrEmpty($ipAddress)) {
                throw new RuntimeException(
                    'IP address tidak valid.'
                );
            }

            if (!validSubnet($subnet)) {
                throw new RuntimeException(
                    'Subnet tidak valid.'
                );
            }

            if (!validIpOrEmpty($publicIp)) {
                throw new RuntimeException(
                    'Public IP tidak valid.'
                );
            }

            if (!validIpOrEmpty($dnsPrimary)) {
                throw new RuntimeException(
                    'DNS primary tidak valid.'
                );
            }

            if (!validIpOrEmpty($dnsSecondary)) {
                throw new RuntimeException(
                    'DNS secondary tidak valid.'
                );
            }

            if (!in_array(
                $routingMode,
                ['STATIC', 'ECMP', 'PCC', 'FAILOVER'],
                true
            )) {
                throw new RuntimeException(
                    'Routing mode tidak valid.'
                );
            }

            if (!in_array(
                $checkGateway,
                ['NONE', 'PING', 'ARP'],
                true
            )) {
                throw new RuntimeException(
                    'Gateway check tidak valid.'
                );
            }

            $downloadValue =
                $download === ''
                    ? null
                    : max(0, (int)$download);

            $uploadValue =
                $upload === ''
                    ? null
                    : max(0, (int)$upload);

            $routerCheck = $db->prepare('SELECT id FROM mikrotik_routers WHERE id=? AND organization_id=?');
            $routerCheck->execute([$routerId, $organizationId]);
            if (!$routerCheck->fetchColumn()) {
                throw new RuntimeException('Router MikroTik bukan milik organisasi ini.');
            }

            $stmt = $db->prepare(
                'UPDATE network_isps
                 SET
                    router_id=?,
                    name=?,
                    interface_name=?,
                    gateway=?,
                    ip_address=?,
                    subnet=?,
                    public_ip=?,
                    dns_primary=?,
                    dns_secondary=?,
                    bandwidth_download=?,
                    bandwidth_upload=?,
                    routing_mode=?,
                    distance=?,
                    check_gateway=?,
                    status="UNKNOWN",
                    last_error=NULL
                 WHERE id=?
                   AND organization_id=?'
            );

            $stmt->execute([
                $routerId,
                $name,
                $interface,
                $gateway,
                $ipAddress !== '' ? $ipAddress : null,
                $subnet !== '' ? $subnet : null,
                $publicIp !== '' ? $publicIp : null,
                $dnsPrimary !== '' ? $dnsPrimary : null,
                $dnsSecondary !== '' ? $dnsSecondary : null,
                $downloadValue,
                $uploadValue,
                $routingMode,
                $distance,
                $checkGateway,
                $id,
                $organizationId
            ]);

            redirectNetwork(
                'msg',
                'ISP berhasil diperbarui di BAJAMA. Tekan Apply untuk mengirim ke MikroTik.'
            );
        }

        if ($action === 'apply') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('ID ISP tidak valid.');
            }

            (new RouterOSProvisioningService($db, (int)$organizationId)
                )->apply('network_isp', $id);

            redirectNetwork('msg', 'ISP berhasil di-Apply ke MikroTik.');
        }

        /*
         * ENABLE
         */
        if ($action === 'enable') {

            $id = (int)($_POST['id'] ?? 0);

            $stmt = $db->prepare(
                'UPDATE network_isps
                 SET enabled=1,
                     status="UNKNOWN",
                     last_error=NULL
                 WHERE id=?
                   AND organization_id=?'
            );

            $stmt->execute([
                $id,
                $organizationId
            ]);

            (new RouterOSProvisioningService($db, (int)$organizationId)
                )->setEnabled('network_isp', $id, true);

            redirectNetwork(
                'msg',
                'ISP berhasil diaktifkan.'
            );
        }

        /*
         * DISABLE
         */
        if ($action === 'disable') {

            $id = (int)($_POST['id'] ?? 0);

            $stmt = $db->prepare(
                'UPDATE network_isps
                 SET enabled=0,
                     status="DISABLED"
                 WHERE id=?
                   AND organization_id=?'
            );

            $stmt->execute([
                $id,
                $organizationId
            ]);

            (new RouterOSProvisioningService($db, (int)$organizationId)
                )->setEnabled('network_isp', $id, false);

            redirectNetwork(
                'msg',
                'ISP berhasil dinonaktifkan.'
            );
        }

        /*
         * DELETE
         */
        if ($action === 'delete') {

            $id = (int)($_POST['id'] ?? 0);

            (new RouterOSProvisioningService($db, (int)$organizationId)
                )->remove('network_isp', $id);

            $stmt = $db->prepare(
                'DELETE FROM network_isps
                 WHERE id=?
                   AND organization_id=?'
            );

            $stmt->execute([
                $id,
                $organizationId
            ]);

            redirectNetwork(
                'msg',
                'ISP berhasil dihapus.'
            );
        }

        /*
         * TEST GATEWAY
         */
        if ($action === 'test') {

            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('ID ISP tidak valid.');
            }
            $wanCheck = (new LoadBalanceProvisioner($db, (int)$organizationId))->testWan($id);
            $status = $wanCheck['online'] ? 'ONLINE' : 'OFFLINE';
            $latency = $wanCheck['latency'];
            $errorText = $wanCheck['online'] ? null : 'Gateway tidak merespons dari MikroTik.';
            $stmt = $db->prepare('UPDATE network_isps SET status=?, last_checked_at=NOW(), last_latency_ms=?, last_error=? WHERE id=? AND organization_id=?');
            $stmt->execute([$status, $latency, $errorText, $id, $organizationId]);
            if ($wanCheck['online']) {
                redirectNetwork('msg', 'Gateway diuji dari MikroTik: ONLINE — ' . ($latency ?? '-') . ' ms.');
            }
            redirectNetwork('error', 'Gateway diuji dari MikroTik: OFFLINE.');
        }

        if ($action === 'test-legacy-disabled') {

            $id = (int)($_POST['id'] ?? 0);

            $stmt = $db->prepare(
                'SELECT *
                 FROM network_isps
                 WHERE id=?
                   AND organization_id=?
                 LIMIT 1'
            );

            $stmt->execute([
                $id,
                $organizationId
            ]);

            $isp = $stmt->fetch();

            if (!$isp) {
                throw new RuntimeException(
                    'ISP tidak ditemukan.'
                );
            }

            if (!(int)$isp['enabled']) {
                throw new RuntimeException(
                    'ISP sedang disabled.'
                );
            }

            $gateway = trim(
                (string)$isp['gateway']
            );

            if (!filter_var(
                $gateway,
                FILTER_VALIDATE_IP
            )) {
                throw new RuntimeException(
                    'Gateway ISP tidak valid.'
                );
            }

            $start = microtime(true);

            $command =
                'ping -c 1 -W 2 ' .
                escapeshellarg($gateway);

            $output = [];
            $returnCode = 1;

            exec(
                $command . ' 2>&1',
                $output,
                $returnCode
            );

            $elapsed =
                round(
                    (microtime(true) - $start) * 1000,
                    2
                );

            if ($returnCode === 0) {

                $latency = null;

                foreach ($output as $line) {
                    if (preg_match(
                        '/time[=<]([0-9.]+)\s*ms/i',
                        $line,
                        $match
                    )) {
                        $latency = (float)$match[1];
                        break;
                    }
                }

                if ($latency === null) {
                    $latency = $elapsed;
                }

                $stmt = $db->prepare(
                    'UPDATE network_isps
                     SET
                        status="ONLINE",
                        last_checked_at=NOW(),
                        last_latency_ms=?,
                        last_error=NULL
                     WHERE id=?
                       AND organization_id=?'
                );

                $stmt->execute([
                    $latency,
                    $id,
                    $organizationId
                ]);

                redirectNetwork(
                    'msg',
                    'Gateway ' . $gateway .
                    ' ONLINE — ' .
                    $latency . ' ms.'
                );
            }

            $errorText = implode(
                ' ',
                array_slice($output, -3)
            );

            $stmt = $db->prepare(
                'UPDATE network_isps
                 SET
                    status="OFFLINE",
                    last_checked_at=NOW(),
                    last_latency_ms=NULL,
                    last_error=?
                 WHERE id=?
                   AND organization_id=?'
            );

            $stmt->execute([
                substr(
                    $errorText !== ''
                        ? $errorText
                        : 'Gateway tidak merespons.',
                    0,
                    1000
                ),
                $id,
                $organizationId
            ]);

            redirectNetwork(
                'error',
                'Gateway ' .
                $gateway .
                ' OFFLINE.'
            );
        }

        throw new RuntimeException(
            'Action tidak dikenal.'
        );

    } catch (Throwable $e) {

        redirectNetwork(
            'error',
            $e->getMessage()
        );
    }
}

$stmt = $db->prepare(
    'SELECT *
     FROM network_isps
     WHERE organization_id=?
     ORDER BY id ASC'
);

$stmt->execute([
    $organizationId
]);

$isps = $stmt->fetchAll();

$total = count($isps);
$online = 0;
$offline = 0;
$disabled = 0;

foreach ($isps as $isp) {

    if ($isp['status'] === 'ONLINE') {
        $online++;
    }

    if ($isp['status'] === 'OFFLINE') {
        $offline++;
    }

    if ($isp['status'] === 'DISABLED') {
        $disabled++;
    }
}

function statusBadge(string $status): string
{
    switch ($status) {

        case 'ONLINE':
            return
                '<span class="badge rounded-pill text-bg-success">' .
                '<i class="bi bi-check-circle-fill me-1"></i>ONLINE' .
                '</span>';

        case 'OFFLINE':
            return
                '<span class="badge rounded-pill text-bg-danger">' .
                '<i class="bi bi-x-circle-fill me-1"></i>OFFLINE' .
                '</span>';

        case 'DISABLED':
            return
                '<span class="badge rounded-pill text-bg-secondary">' .
                '<i class="bi bi-pause-circle-fill me-1"></i>DISABLED' .
                '</span>';

        case 'ERROR':
            return
                '<span class="badge rounded-pill text-bg-warning">' .
                '<i class="bi bi-exclamation-triangle-fill me-1"></i>ERROR' .
                '</span>';

        default:
            return
                '<span class="badge rounded-pill text-bg-light text-dark border">' .
                '<i class="bi bi-question-circle me-1"></i>UNKNOWN' .
                '</span>';
    }
}

function e($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width, initial-scale=1">

<title>Network ISP / WAN - BAJAMA</title>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
    rel="stylesheet">

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.css"
    rel="stylesheet">

<link href="assets/css/bajama.css" rel="stylesheet">

<style>
:root {
    --sidebar: #111827;
    --sidebar-hover: #1f2937;
    --page: #f3f4f6;
    --card: #ffffff;
    --border: #e5e7eb;
    --text: #111827;
    --muted: #6b7280;
}

body {
    background: var(--page);
    color: var(--text);
    font-size: .92rem;
}

.sidebar {
    width: 250px;
    min-height: 100vh;
    position: fixed;
    left: 0;
    top: 0;
    background: var(--sidebar);
    color: #fff;
    z-index: 1030;
}

.sidebar-brand {
    height: 70px;
    display: flex;
    align-items: center;
    padding: 0 22px;
    border-bottom: 1px solid rgba(255,255,255,.08);
}

.sidebar-brand strong {
    font-size: 1.15rem;
    letter-spacing: .5px;
}

.sidebar-menu {
    padding: 18px 12px;
}

.sidebar-menu a {
    color: #cbd5e1;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 13px;
    border-radius: 9px;
    margin-bottom: 5px;
}

.sidebar-menu a:hover,
.sidebar-menu a.active {
    background: var(--sidebar-hover);
    color: #fff;
}

.sidebar-menu i {
    width: 20px;
    text-align: center;
}

.main {
    margin-left: 250px;
    min-height: 100vh;
}

.topbar {
    background: #fff;
    border-bottom: 1px solid var(--border);
    min-height: 70px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 25px;
}

.content {
    padding: 25px;
}

.stat-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 18px;
    height: 100%;
}

.stat-icon {
    width: 45px;
    height: 45px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #eef2ff;
    color: #4f46e5;
    font-size: 1.25rem;
}

.stat-number {
    font-size: 1.55rem;
    font-weight: 700;
}

.stat-label {
    color: var(--muted);
    font-size: .82rem;
}

.network-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
}

.network-card-header {
    padding: 18px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.table > :not(caption) > * > * {
    padding: .85rem .75rem;
    vertical-align: middle;
}

.isp-name {
    font-weight: 650;
}

.isp-interface {
    font-family: monospace;
    font-size: .85rem;
    color: #4b5563;
}

.gateway {
    font-family: monospace;
    font-weight: 600;
}

.small-muted {
    color: var(--muted);
    font-size: .78rem;
}

@media (max-width: 991.98px) {

    .sidebar {
        transform: translateX(-100%);
        transition: transform .2s ease;
    }

    .sidebar.show {
        transform: translateX(0);
    }

    .main {
        margin-left: 0;
    }

    .mobile-menu {
        display: inline-flex !important;
    }

    .content {
        padding: 16px;
    }

    .topbar {
        padding: 12px 16px;
    }
}

.mobile-menu {
    display: none;
}

/* BAJAMA Network - ISP/WAN */
body.bajama-network-isp-page .bajama-network-mobile-button {
    display: none !important;
}

body.bajama-network-isp-page .mobile-menu {
    display: inline-flex !important;
}

</style>
</head>

<body class="bajama-network-isp-page">

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
require_once __DIR__ . '/../app/layout/sidebar.php';
?>

<main class="main">

    <?php
    $pageTitle = 'ISP / WAN';
    $userName = $_SESSION['username'] ?? 'User';
    $displayRole = 'OWNER';
    require __DIR__ . '/../app/layout/header.php';
    ?>

    <header class="legacy-network-topbar d-none">

        <div class="d-flex align-items-center gap-3">

            <button
                class="btn btn-outline-secondary mobile-menu"
                type="button"
                id="mobileMenu"
                onclick="document.getElementById('sidebarToggle')?.click()"
                aria-label="Buka menu Network">
                <i class="bi bi-list"></i>
            </button>

            <div>
                <div class="fw-bold">
                    Network / ISP & WAN
                </div>

                <div class="small text-muted">
                    Manajemen koneksi Internet
                </div>
            </div>

        </div>

        <div class="d-flex gap-2">

            <a
                href="core.php"
                class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-house me-1"></i>
                Dashboard
            </a>

            <button
                type="button"
                class="btn btn-outline-primary btn-sm"
                onclick="location.reload()">
                <i class="bi bi-arrow-clockwise me-1"></i>
                Refresh
            </button>

        </div>

    </header>

    <section class="content">

        <?php if ($message !== ''): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i>
                <?= e($message) ?>
                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?= e($error) ?>
                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="stat-number">
                                <?= $total ?>
                            </div>
                            <div class="stat-label">
                                Total ISP
                            </div>
                        </div>

                        <div class="stat-icon">
                            <i class="bi bi-globe2"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="stat-number text-success">
                                <?= $online ?>
                            </div>
                            <div class="stat-label">
                                Online
                            </div>
                        </div>

                        <div class="stat-icon">
                            <i class="bi bi-check-circle"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="stat-number text-danger">
                                <?= $offline ?>
                            </div>
                            <div class="stat-label">
                                Offline
                            </div>
                        </div>

                        <div class="stat-icon">
                            <i class="bi bi-wifi-off"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="stat-number text-secondary">
                                <?= $disabled ?>
                            </div>
                            <div class="stat-label">
                                Disabled
                            </div>
                        </div>

                        <div class="stat-icon">
                            <i class="bi bi-pause-circle"></i>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <div class="network-card">

            <div class="network-card-header">

                <div>
                    <h5 class="mb-1">
                        ISP / WAN Connections
                    </h5>

                    <div class="small-muted">
                        Semua koneksi Internet organisasi ini.
                    </div>
                </div>

                <?php if ($canManage): ?>

                    <button
                        class="btn btn-primary"
                        data-bs-toggle="modal"
                        data-bs-target="#ispModal"
                        onclick="openAdd()">

                        <i class="bi bi-plus-lg me-1"></i>
                        Add ISP

                    </button>

                <?php endif; ?>

            </div>

            <div class="table-responsive">

                <table class="table table-hover mb-0">

                    <thead class="table-light">

                        <tr>
                            <th>ISP</th>
                            <th>Interface</th>
                            <th>Gateway</th>
                            <th>Routing</th>
                            <th>Status</th>
                            <th>Latency</th>
                            <th class="text-end">Action</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php if (!$isps): ?>

                        <tr>
                            <td colspan="7"
                                class="text-center py-5">

                                <i class="bi bi-globe2 fs-1 text-muted"></i>

                                <div class="fw-semibold mt-2">
                                    Belum ada ISP
                                </div>

                                <div class="text-muted">
                                    Tambahkan koneksi ISP pertama Anda.
                                </div>

                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($isps as $isp): ?>

                            <tr>

                                <td>

                                    <div class="isp-name">
                                        <?= e($isp['name']) ?>
                                    </div>

                                    <div class="small-muted">
                                        <?= e(
                                            $isp['ip_address']
                                                ?: 'IP belum diisi'
                                        ) ?>

                                        <?php if (
                                            $isp['subnet']
                                        ): ?>
                                            /
                                            <?= e($isp['subnet']) ?>
                                        <?php endif; ?>
                                    </div>

                                </td>

                                <td>

                                    <div class="isp-interface">
                                        <?= e(
                                            $isp['interface_name']
                                        ) ?>
                                    </div>

                                </td>

                                <td>

                                    <span class="gateway">
                                        <?= e(
                                            $isp['gateway']
                                        ) ?>
                                    </span>

                                </td>

                                <td>

                                    <span class="badge text-bg-light border">
                                        <?= e(
                                            $isp['routing_mode']
                                        ) ?>
                                    </span>

                                    <div class="small-muted mt-1">
                                        Distance:
                                        <?= e(
                                            $isp['distance']
                                        ) ?>
                                    </div>

                                </td>

                                <td>

                                    <?= statusBadge(
                                        (string)$isp['status']
                                    ) ?>

                                    <?php if (
                                        $isp['last_error']
                                    ): ?>

                                        <div
                                            class="small text-danger mt-1"
                                            title="<?= e(
                                                $isp['last_error']
                                            ) ?>">

                                            <?= e(
                                                mb_strimwidth(
                                                    (string)$isp['last_error'],
                                                    0,
                                                    45,
                                                    '...'
                                                )
                                            ) ?>

                                        </div>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <?php if (
                                        $isp['last_latency_ms']
                                        !== null
                                    ): ?>

                                        <strong>
                                            <?= e(
                                                $isp['last_latency_ms']
                                            ) ?>
                                        </strong>
                                        ms

                                    <?php else: ?>

                                        <span class="text-muted">
                                            —
                                        </span>

                                    <?php endif; ?>

                                    <?php if (
                                        $isp['last_checked_at']
                                    ): ?>

                                        <div class="small-muted">
                                            <?= e(
                                                $isp['last_checked_at']
                                            ) ?>
                                        </div>

                                    <?php endif; ?>

                                </td>

                                <td class="text-end">

                                    <div
                                        class="btn-group btn-group-sm">

                                        <?php if ($canManage): ?>

                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                                <input type="hidden" name="action" value="apply">
                                                <input type="hidden" name="id" value="<?= (int)$isp['id'] ?>">
                                                <button class="btn btn-outline-primary" title="Apply ke MikroTik" onclick="return confirm('Apply WAN ini ke MikroTik sekarang?')">
                                                    <i class="bi bi-cloud-arrow-up"></i>
                                                </button>
                                            </form>

                                            <form method="post"
                                                  class="d-inline">

                                                <input
                                                    type="hidden"
                                                    name="_csrf"
                                                    value="<?= e($csrf) ?>">

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="test">

                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= (int)$isp['id'] ?>">

                                                <button
                                                    class="btn btn-outline-success"
                                                    title="Test gateway">

                                                    <i class="bi bi-lightning-charge"></i>

                                                </button>

                                            </form>

                                            <button
                                                type="button"
                                                class="btn btn-outline-primary"
                                                title="Edit"
                                                onclick='openEdit(
                                                    <?= json_encode(
                                                        $isp,
                                                        JSON_HEX_TAG |
                                                        JSON_HEX_APOS |
                                                        JSON_HEX_AMP |
                                                        JSON_HEX_QUOT
                                                    ) ?>
                                                )'>

                                                <i class="bi bi-pencil"></i>

                                            </button>

                                            <?php if (
                                                (int)$isp['enabled']
                                            ): ?>

                                                <form method="post"
                                                      class="d-inline">

                                                    <input
                                                        type="hidden"
                                                        name="_csrf"
                                                        value="<?= e($csrf) ?>">

                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value="disable">

                                                    <input
                                                        type="hidden"
                                                        name="id"
                                                        value="<?= (int)$isp['id'] ?>">

                                                    <button
                                                        class="btn btn-outline-warning"
                                                        title="Disable"
                                                        onclick="return confirm('Disable ISP ini?')">

                                                        <i class="bi bi-pause"></i>

                                                    </button>

                                                </form>

                                            <?php else: ?>

                                                <form method="post"
                                                      class="d-inline">

                                                    <input
                                                        type="hidden"
                                                        name="_csrf"
                                                        value="<?= e($csrf) ?>">

                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value="enable">

                                                    <input
                                                        type="hidden"
                                                        name="id"
                                                        value="<?= (int)$isp['id'] ?>">

                                                    <button
                                                        class="btn btn-outline-success"
                                                        title="Enable">

                                                        <i class="bi bi-play"></i>

                                                    </button>

                                                </form>

                                            <?php endif; ?>

                                            <form method="post"
                                                  class="d-inline">

                                                <input
                                                    type="hidden"
                                                    name="_csrf"
                                                    value="<?= e($csrf) ?>">

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="delete">

                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= (int)$isp['id'] ?>">

                                                <button
                                                    class="btn btn-outline-danger"
                                                    title="Delete"
                                                    onclick="return confirm('Hapus ISP ini?')">

                                                    <i class="bi bi-trash"></i>

                                                </button>

                                            </form>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </section>

</main>

<?php if ($canManage): ?>

<div
    class="modal fade"
    id="ispModal"
    tabindex="-1"
    aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <form method="post">

                <div class="modal-header">

                    <h5 class="modal-title" id="modalTitle">
                        Add ISP
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"></button>

                </div>

                <div class="modal-body">

                    <input
                        type="hidden"
                        name="_csrf"
                        value="<?= e($csrf) ?>">

                    <input
                        type="hidden"
                        name="action"
                        id="formAction"
                        value="add">

                    <input
                        type="hidden"
                        name="id"
                        id="formId"
                        value="">

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label">Router MikroTik</label>
                            <select class="form-select" name="router_id" id="router_id" required>
                                <option value="">Pilih router</option>
                                <?php foreach ($routers as $router): ?>
                                    <option value="<?= (int)$router['id'] ?>">
                                        <?= e($router['name'] . ' - ' . $router['host']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">

                            <label class="form-label">
                                Nama ISP
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                name="name"
                                id="name"
                                placeholder="Contoh: ISP 1"
                                required>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label">
                                Interface
                            </label>

                            <select
                                class="form-select"
                                name="interface_name"
                                id="interface_name"
                                data-current=""
                                required>
                                <option value="">Pilih router terlebih dahulu</option>
                            </select>
                            <div class="form-text">Nama interface dibaca otomatis dari RouterOS.</div>

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                Gateway
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                name="gateway"
                                id="gateway"
                                placeholder="192.168.1.1"
                                required>

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                IP Address
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                name="ip_address"
                                id="ip_address"
                                placeholder="192.168.1.2">

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                Subnet
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                name="subnet"
                                id="subnet"
                                placeholder="192.168.1.0/24">

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                Public IP
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                name="public_ip"
                                id="public_ip"
                                placeholder="Opsional">

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                DNS Primary
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                name="dns_primary"
                                id="dns_primary"
                                placeholder="1.1.1.1">

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                DNS Secondary
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                name="dns_secondary"
                                id="dns_secondary"
                                placeholder="8.8.8.8">

                        </div>

                        <div class="col-md-6">

                            <label class="form-label">
                                Routing Mode
                            </label>

                            <select
                                class="form-select"
                                name="routing_mode"
                                id="routing_mode">

                                <option value="STATIC">
                                    STATIC
                                </option>

                                <option value="ECMP">
                                    ECMP
                                </option>

                                <option value="PCC">
                                    PCC
                                </option>

                                <option value="FAILOVER">
                                    FAILOVER
                                </option>

                            </select>

                        </div>

                        <div class="col-md-3">

                            <label class="form-label">
                                Distance
                            </label>

                            <input
                                type="number"
                                min="1"
                                class="form-control"
                                name="distance"
                                id="distance"
                                value="1">

                        </div>

                        <div class="col-md-3">

                            <label class="form-label">
                                Gateway Check
                            </label>

                            <select
                                class="form-select"
                                name="check_gateway"
                                id="check_gateway">

                                <option value="PING">
                                    PING
                                </option>

                                <option value="ARP">
                                    ARP
                                </option>

                                <option value="NONE">
                                    NONE
                                </option>

                            </select>

                        </div>

                        <div class="col-md-6">

                            <label class="form-label">
                                Bandwidth Download
                                <span class="text-muted">
                                    Mbps
                                </span>
                            </label>

                            <input
                                type="number"
                                min="0"
                                class="form-control"
                                name="bandwidth_download"
                                id="bandwidth_download"
                                placeholder="Contoh: 1000">

                        </div>

                        <div class="col-md-6">

                            <label class="form-label">
                                Bandwidth Upload
                                <span class="text-muted">
                                    Mbps
                                </span>
                            </label>

                            <input
                                type="number"
                                min="0"
                                class="form-control"
                                name="bandwidth_upload"
                                id="bandwidth_upload"
                                placeholder="Contoh: 500">

                        </div>

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

                        <i class="bi bi-save me-1"></i>
                        Simpan ISP

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<?php endif; ?>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js">
</script>

<script>

const modalElement =
    document.getElementById('ispModal');

const ispModal =
    modalElement
        ? bootstrap.Modal.getOrCreateInstance(modalElement)
        : null;

async function loadIspInterfaces(routerId, selected = '') {
    const select = document.getElementById('interface_name');
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
            const option = new Option(name + (item.type ? ' (' + item.type + ')' : ''), name, false, name === selected);
            select.add(option);
        });
        if (selected && !Array.from(select.options).some(option => option.value === selected)) {
            select.add(new Option(selected + ' (tersimpan)', selected, true, true));
        }
    } catch (error) {
        select.innerHTML = '<option value="">Interface gagal dibaca</option>';
    }
}

function setValue(id, value) {
    const element =
        document.getElementById(id);

    if (element) {
        element.value =
            value === null ||
            value === undefined
                ? ''
                : value;
    }
}

function openAdd() {

    setValue('modalTitle', 'Add ISP');

    document.getElementById(
        'modalTitle'
    ).textContent = 'Add ISP';

    setValue('formAction', 'add');
    setValue('formId', '');

    setValue('name', '');
    setValue('interface_name', '');
    setValue('gateway', '');
    setValue('ip_address', '');
    setValue('subnet', '');
    setValue('public_ip', '');
    setValue('dns_primary', '');
    setValue('dns_secondary', '');
    setValue('bandwidth_download', '');
    setValue('bandwidth_upload', '');
    setValue('routing_mode', 'STATIC');
    setValue('distance', '1');
    setValue('check_gateway', 'PING');
    loadIspInterfaces(document.getElementById('router_id')?.value || '');
}

function openEdit(isp) {

    document.getElementById(
        'modalTitle'
    ).textContent = 'Edit ISP';

    setValue('formAction', 'edit');
    setValue('formId', isp.id);
    setValue('router_id', isp.router_id);
    loadIspInterfaces(isp.router_id, isp.interface_name);

    setValue('name', isp.name);
    setValue(
        'interface_name',
        isp.interface_name
    );
    setValue('gateway', isp.gateway);
    setValue('ip_address', isp.ip_address);
    setValue('subnet', isp.subnet);
    setValue('public_ip', isp.public_ip);
    setValue('dns_primary', isp.dns_primary);
    setValue('dns_secondary', isp.dns_secondary);
    setValue(
        'bandwidth_download',
        isp.bandwidth_download
    );
    setValue(
        'bandwidth_upload',
        isp.bandwidth_upload
    );
    setValue(
        'routing_mode',
        isp.routing_mode
    );
    setValue(
        'distance',
        isp.distance
    );
    setValue(
        'check_gateway',
        isp.check_gateway
    );

    ispModal.show();
}

document.getElementById('router_id')?.addEventListener('change', function () {
    loadIspInterfaces(this.value);
});

</script>

<script src="assets/js/bajama.js"></script>

</body>
</html>
