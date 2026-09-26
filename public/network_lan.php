<?php
declare(strict_types=1);

use BAJAMA\Core\Auth;
use BAJAMA\Core\License;
use BAJAMA\Core\RBAC;
use BAJAMA\Core\Tenant;
use BAJAMA\Network\RouterOSProvisioningService;

require_once __DIR__ . '/../app/bootstrap.php';

Auth::requireLogin();
License::requireFeature($db, 'mikrotik');
RBAC::require($db, 'network.view');

$organizationId = Tenant::id();
$canManage = RBAC::hasPermission($db, 'network.manage');
$routerStmt = $db->prepare('SELECT id, name, host FROM mikrotik_routers WHERE organization_id=? ORDER BY name');
$routerStmt->execute([$organizationId]);
$routers = $routerStmt->fetchAll(PDO::FETCH_ASSOC);

$message = '';
$messageType = 'success';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function valid_ip_value(string $value): bool
{
    return filter_var($value, FILTER_VALIDATE_IP) !== false;
}

function valid_cidr(string $value): bool
{
    if (!preg_match('/^(.+)\/([0-9]{1,3})$/', trim($value), $m)) {
        return false;
    }

    $ip = $m[1];
    $prefix = (int)$m[2];

    if (!valid_ip_value($ip)) {
        return false;
    }

    $max = strpos($ip, ':') !== false ? 128 : 32;

    return $prefix >= 0 && $prefix <= $max;
}

function redirect_self(): void
{
    header('Location: network_lan.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| ACTIONS
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$canManage) {
        http_response_code(403);
        exit('403 - Anda tidak memiliki permission network.manage.');
    }

    verify_csrf(
        (string)($_POST['_csrf'] ?? $_POST['csrf'] ?? '')
    );

    $action = (string)($_POST['action'] ?? '');

    try {

        /*
        |--------------------------------------------------------------------------
        | SAVE
        |--------------------------------------------------------------------------
        */
        if ($action === 'save') {

            $id = (int)($_POST['id'] ?? 0);
            $routerId = (int)($_POST['router_id'] ?? 0);

            $name = trim((string)($_POST['name'] ?? ''));
            $interfaceName = trim((string)($_POST['interface_name'] ?? ''));
            $ipAddress = trim((string)($_POST['ip_address'] ?? ''));
            $subnet = trim((string)($_POST['subnet'] ?? ''));
            $networkType = strtoupper(trim((string)($_POST['network_type'] ?? 'LAN')));
            $gateway = trim((string)($_POST['gateway'] ?? ''));
            $vlanIdRaw = trim((string)($_POST['vlan_id'] ?? ''));
            $dhcpEnabled = isset($_POST['dhcp_enabled']) ? 1 : 0;
            $dhcpStart = trim((string)($_POST['dhcp_start'] ?? ''));
            $dhcpEnd = trim((string)($_POST['dhcp_end'] ?? ''));
            $dnsPrimary = trim((string)($_POST['dns_primary'] ?? ''));
            $dnsSecondary = trim((string)($_POST['dns_secondary'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $enabled = isset($_POST['enabled']) ? 1 : 0;

            $routerCheck = $db->prepare('SELECT id FROM mikrotik_routers WHERE id=? AND organization_id=?');
            $routerCheck->execute([$routerId, $organizationId]);
            if (!$routerCheck->fetchColumn()) {
                throw new RuntimeException('Router MikroTik wajib dipilih dan harus milik organisasi ini.');
            }

            $allowedTypes = [
                'LAN',
                'VLAN',
                'BRIDGE',
                'PPPOE',
                'HOTSPOT',
                'OTHER'
            ];

            if ($name === '') {
                throw new RuntimeException('Nama network wajib diisi.');
            }

            if ($interfaceName === '') {
                throw new RuntimeException('Nama interface wajib diisi.');
            }

            if ($ipAddress === '' || !valid_ip_value($ipAddress)) {
                throw new RuntimeException('IP address tidak valid.');
            }

            if ($subnet === '' || !valid_cidr($subnet)) {
                throw new RuntimeException(
                    'Subnet/CIDR tidak valid. Contoh: 192.168.20.0/24'
                );
            }

            if (!in_array($networkType, $allowedTypes, true)) {
                throw new RuntimeException('Tipe network tidak valid.');
            }

            if ($gateway !== '' && !valid_ip_value($gateway)) {
                throw new RuntimeException('Gateway tidak valid.');
            }

            if ($vlanIdRaw !== '') {
                $vlanId = (int)$vlanIdRaw;

                if ($networkType !== 'VLAN') {
                    throw new RuntimeException(
                        'VLAN ID hanya boleh diisi untuk tipe VLAN.'
                    );
                }

                if ($vlanId < 1 || $vlanId > 4094) {
                    throw new RuntimeException(
                        'VLAN ID harus berada antara 1 sampai 4094.'
                    );
                }
            } else {
                $vlanId = null;
            }

            if ($networkType === 'VLAN' && $vlanId === null) {
                throw new RuntimeException(
                    'VLAN ID wajib diisi untuk network tipe VLAN.'
                );
            }

            if ($dhcpStart !== '' && !valid_ip_value($dhcpStart)) {
                throw new RuntimeException('DHCP start tidak valid.');
            }

            if ($dhcpEnd !== '' && !valid_ip_value($dhcpEnd)) {
                throw new RuntimeException('DHCP end tidak valid.');
            }

            if ($dnsPrimary !== '' && !valid_ip_value($dnsPrimary)) {
                throw new RuntimeException('DNS primary tidak valid.');
            }

            if ($dnsSecondary !== '' && !valid_ip_value($dnsSecondary)) {
                throw new RuntimeException('DNS secondary tidak valid.');
            }

            if ($dhcpEnabled === 1 && $dhcpStart === '' && $dhcpEnd === '') {
                throw new RuntimeException(
                    'DHCP aktif. Isi minimal DHCP start atau DHCP end.'
                );
            }

            /*
             * Pastikan tenant hanya bisa mengubah record miliknya.
             */
            if ($id > 0) {

                $check = $db->prepare(
                    'SELECT id
                     FROM network_lans
                     WHERE id = ?
                       AND organization_id = ?
                     LIMIT 1'
                );

                $check->execute([
                    $id,
                    $organizationId
                ]);

                if (!$check->fetchColumn()) {
                    throw new RuntimeException(
                        'Data network tidak ditemukan.'
                    );
                }

                /*
                 * Karena satu interface dapat memiliki beberapa IP,
                 * uniqueness mengikuti:
                 * organization + interface + IP.
                 */
                $duplicate = $db->prepare(
                    'SELECT id
                     FROM network_lans
                     WHERE organization_id = ?
                       AND interface_name = ?
                       AND ip_address = ?
                       AND id <> ?
                     LIMIT 1'
                );

                $duplicate->execute([
                    $organizationId,
                    $interfaceName,
                    $ipAddress,
                    $id
                ]);

                if ($duplicate->fetchColumn()) {
                    throw new RuntimeException(
                        'IP tersebut sudah terdaftar pada interface yang sama.'
                    );
                }

                $stmt = $db->prepare(
                    'UPDATE network_lans
                     SET
                                router_id = ?,
                        name = ?,
                        interface_name = ?,
                        ip_address = ?,
                        subnet = ?,
                        vlan_id = ?,
                        network_type = ?,
                        gateway = ?,
                        dhcp_enabled = ?,
                        dhcp_start = ?,
                        dhcp_end = ?,
                        dns_primary = ?,
                        dns_secondary = ?,
                        enabled = ?,
                        description = ?
                     WHERE id = ?
                       AND organization_id = ?'
                );

                $stmt->execute([
                    $routerId,
                    $name,
                    $interfaceName,
                    $ipAddress,
                    $subnet,
                    $vlanId,
                    $networkType,
                    $gateway !== '' ? $gateway : null,
                    $dhcpEnabled,
                    $dhcpStart !== '' ? $dhcpStart : null,
                    $dhcpEnd !== '' ? $dhcpEnd : null,
                    $dnsPrimary !== '' ? $dnsPrimary : null,
                    $dnsSecondary !== '' ? $dnsSecondary : null,
                    $enabled,
                    $description !== '' ? $description : null,
                    $id,
                    $organizationId
                ]);

                $message = 'Network berhasil diperbarui.';

            } else {

                $duplicate = $db->prepare(
                    'SELECT id
                     FROM network_lans
                     WHERE organization_id = ?
                       AND interface_name = ?
                       AND ip_address = ?
                     LIMIT 1'
                );

                $duplicate->execute([
                    $organizationId,
                    $interfaceName,
                    $ipAddress
                ]);

                if ($duplicate->fetchColumn()) {
                    throw new RuntimeException(
                        'IP tersebut sudah terdaftar pada interface yang sama.'
                    );
                }

                $stmt = $db->prepare(
                    'INSERT INTO network_lans
                    (
                        organization_id,
                        router_id,
                        name,
                        interface_name,
                        ip_address,
                        subnet,
                        vlan_id,
                        network_type,
                        gateway,
                        dhcp_enabled,
                        dhcp_start,
                        dhcp_end,
                        dns_primary,
                        dns_secondary,
                        status,
                        enabled,
                        description
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        "UNKNOWN", ?, ?
                    )'
                );

                $stmt->execute([
                    $organizationId,
                    $routerId,
                    $name,
                    $interfaceName,
                    $ipAddress,
                    $subnet,
                    $vlanId,
                    $networkType,
                    $gateway !== '' ? $gateway : null,
                    $dhcpEnabled,
                    $dhcpStart !== '' ? $dhcpStart : null,
                    $dhcpEnd !== '' ? $dhcpEnd : null,
                    $dnsPrimary !== '' ? $dnsPrimary : null,
                    $dnsSecondary !== '' ? $dnsSecondary : null,
                    $enabled,
                    $description !== '' ? $description : null
                ]);

                $message = 'Network berhasil ditambahkan.';
            }

            redirect_self();
        }

        /*
        |--------------------------------------------------------------------------
        | DELETE
        |--------------------------------------------------------------------------
        */
        if ($action === 'delete') {

            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('ID network tidak valid.');
            }

            (new RouterOSProvisioningService($db, (int)$organizationId)
                )->remove('network_lan', $id);

            $stmt = $db->prepare(
                'DELETE FROM network_lans
                 WHERE id = ?
                   AND organization_id = ?'
            );

            $stmt->execute([
                $id,
                $organizationId
            ]);

            if ($stmt->rowCount() < 1) {
                throw new RuntimeException(
                    'Data network tidak ditemukan.'
                );
            }

            $message = 'Network berhasil dihapus.';

            redirect_self();
        }

        /*
        |--------------------------------------------------------------------------
        | TOGGLE
        |--------------------------------------------------------------------------
        */
        if ($action === 'toggle') {

            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('ID network tidak valid.');
            }

            $current = $db->prepare(
                'SELECT enabled FROM network_lans WHERE id=? AND organization_id=? LIMIT 1'
            );
            $current->execute([$id, $organizationId]);
            $currentEnabled = (int)$current->fetchColumn();

            $service = new RouterOSProvisioningService($db, (int)$organizationId);
            $desiredEnabled = !$currentEnabled;
            try {
                $service->setEnabled('network_lan', $id, $desiredEnabled);
            } catch (RuntimeException $e) {
                if (strpos($e->getMessage(), 'belum pernah di-Apply') === false) {
                    throw $e;
                }
                if ($desiredEnabled) {
                    $db->prepare('UPDATE network_lans SET enabled=1 WHERE id=? AND organization_id=?')->execute([$id, $organizationId]);
                    try {
                        $service->apply('network_lan', $id);
                    } catch (Throwable $applyError) {
                        $db->prepare('UPDATE network_lans SET enabled=0 WHERE id=? AND organization_id=?')->execute([$id, $organizationId]);
                        throw $applyError;
                    }
                }
                // Disabling a never-applied record only changes the local state.
            }

            $stmt = $db->prepare(
                'UPDATE network_lans
                 SET enabled=?, status=?
                 WHERE id=? AND organization_id=?'
            );
            $stmt->execute([$desiredEnabled ? 1 : 0, $desiredEnabled ? 'ONLINE' : 'DISABLED', $id, $organizationId]);

            redirect_self();
        }

        if ($action === 'apply') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('ID network tidak valid.');
            }
            (new RouterOSProvisioningService($db, (int)$organizationId))->apply('network_lan', $id);
            $stmt = $db->prepare('UPDATE network_lans SET status=? WHERE id=? AND organization_id=?');
            $stmt->execute(['ONLINE', $id, $organizationId]);
            redirect_self();
        }

        /*
        |--------------------------------------------------------------------------
        | STATUS
        |--------------------------------------------------------------------------
        */
        if ($action === 'status') {

            $id = (int)($_POST['id'] ?? 0);
            $status = strtoupper(trim((string)($_POST['status'] ?? 'UNKNOWN')));

            $allowedStatus = [
                'UNKNOWN',
                'ONLINE',
                'OFFLINE',
                'ERROR',
                'DISABLED'
            ];

            if ($id <= 0 || !in_array($status, $allowedStatus, true)) {
                throw new RuntimeException('Status tidak valid.');
            }

            $stmt = $db->prepare(
                'UPDATE network_lans
                 SET status = ?
                 WHERE id = ?
                   AND organization_id = ?'
            );

            $stmt->execute([
                $status,
                $id,
                $organizationId
            ]);

            redirect_self();
        }

        throw new RuntimeException('Action tidak dikenal.');

    } catch (Throwable $e) {

        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

/*
|--------------------------------------------------------------------------
| EDIT DATA
|--------------------------------------------------------------------------
*/
$edit = null;

if (isset($_GET['edit'])) {

    $editId = (int)$_GET['edit'];

    if ($editId > 0) {

        $stmt = $db->prepare(
            'SELECT *
             FROM network_lans
             WHERE id = ?
               AND organization_id = ?
             LIMIT 1'
        );

        $stmt->execute([
            $editId,
            $organizationId
        ]);

        $edit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

/*
|--------------------------------------------------------------------------
| LIST
|--------------------------------------------------------------------------
*/
$stmt = $db->prepare(
    'SELECT *
     FROM network_lans
     WHERE organization_id = ?
     ORDER BY
        CASE network_type
            WHEN "VLAN" THEN 2
            WHEN "PPPOE" THEN 3
            WHEN "HOTSPOT" THEN 4
            WHEN "LAN" THEN 1
            ELSE 5
        END,
        interface_name,
        ip_address'
);

$stmt->execute([
    $organizationId
]);

$lans = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/
$total = count($lans);

$online = 0;
$offline = 0;
$disabled = 0;
$vlan = 0;
$pppoe = 0;

foreach ($lans as $row) {

    if ((string)$row['status'] === 'ONLINE') {
        $online++;
    }

    if ((string)$row['status'] === 'OFFLINE') {
        $offline++;
    }

    if ((int)$row['enabled'] === 0 ||
        (string)$row['status'] === 'DISABLED') {
        $disabled++;
    }

    if ((string)$row['network_type'] === 'VLAN') {
        $vlan++;
    }

    if ((string)$row['network_type'] === 'PPPOE') {
        $pppoe++;
    }
}

$csrf = csrf_token();

$form = [
    'id' => $edit['id'] ?? 0,
    'name' => $edit['name'] ?? '',
    'interface_name' => $edit['interface_name'] ?? '',
    'ip_address' => $edit['ip_address'] ?? '',
    'subnet' => $edit['subnet'] ?? '',
    'vlan_id' => $edit['vlan_id'] ?? '',
    'network_type' => $edit['network_type'] ?? 'LAN',
    'gateway' => $edit['gateway'] ?? '',
    'dhcp_enabled' => $edit['dhcp_enabled'] ?? 0,
    'dhcp_start' => $edit['dhcp_start'] ?? '',
    'dhcp_end' => $edit['dhcp_end'] ?? '',
    'dns_primary' => $edit['dns_primary'] ?? '',
    'dns_secondary' => $edit['dns_secondary'] ?? '',
    'enabled' => $edit !== null ? $edit['enabled'] : 1,
    'description' => $edit['description'] ?? ''
];

function status_badge(string $status, int $enabled): string
{
    if (!$enabled || $status === 'DISABLED') {
        return '<span class="badge rounded-pill text-bg-secondary">
                    <i class="bi bi-pause-circle me-1"></i>Disabled
                </span>';
    }

    switch ($status) {
        case 'ONLINE':
            return '<span class="badge rounded-pill text-bg-success">
                        <i class="bi bi-check-circle me-1"></i>Online
                    </span>';

        case 'OFFLINE':
            return '<span class="badge rounded-pill text-bg-danger">
                        <i class="bi bi-x-circle me-1"></i>Offline
                    </span>';

        case 'ERROR':
            return '<span class="badge rounded-pill text-bg-danger">
                        <i class="bi bi-exclamation-triangle me-1"></i>Error
                    </span>';

        default:
            return '<span class="badge rounded-pill text-bg-warning">
                        <i class="bi bi-question-circle me-1"></i>Belum dicek
                    </span>';
    }
}

function type_badge(string $type): string
{
    switch ($type) {
        case 'VLAN':
            return '<span class="badge text-bg-primary">
                        <i class="bi bi-diagram-3 me-1"></i>VLAN
                    </span>';

        case 'BRIDGE':
            return '<span class="badge text-bg-success">
                        <i class="bi bi-bezier2 me-1"></i>Bridge
                    </span>';

        case 'PPPOE':
            return '<span class="badge text-bg-info">
                        <i class="bi bi-person-lines-fill me-1"></i>PPPoE
                    </span>';

        case 'HOTSPOT':
            return '<span class="badge text-bg-warning">
                        <i class="bi bi-wifi me-1"></i>Hotspot
                    </span>';

        case 'LAN':
            return '<span class="badge text-bg-light border text-dark">
                        <i class="bi bi-hdd-network me-1"></i>LAN
                    </span>';

        default:
            return '<span class="badge text-bg-light border text-dark">'
                . h($type)
                . '</span>';
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>LAN & VLAN - BAJAMA</title>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    rel="stylesheet"
>

<link href="assets/css/bajama.css" rel="stylesheet">

<style>
:root {
    --bajama-primary: #0d6efd;
    --bajama-dark: #111827;
    --bajama-bg: #f4f7fb;
    --bajama-border: #e5e7eb;
}

body {
    background: var(--bajama-bg);
    color: #1f2937;
    font-family:
        Inter,
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
}

.sidebar {
    width: 260px;
    min-height: 100vh;
    position: fixed;
    left: 0;
    top: 0;
    background: var(--bajama-dark);
    color: #fff;
    z-index: 1030;
    transition: .2s ease;
}

.sidebar-brand {
    height: 72px;
    display: flex;
    align-items: center;
    padding: 0 22px;
    border-bottom: 1px solid rgba(255,255,255,.08);
}

.brand-icon {
    width: 38px;
    height: 38px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 11px;
    background: rgba(13,110,253,.18);
    color: #6ea8fe;
    margin-right: 11px;
}

.sidebar-nav {
    padding: 16px 12px;
}

.sidebar-nav a {
    color: #aeb8c7;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 10px 13px;
    margin-bottom: 4px;
    border-radius: 9px;
    font-size: .93rem;
    transition: .15s ease;
}

.sidebar-nav a:hover,
.sidebar-nav a.active {
    color: #fff;
    background: rgba(255,255,255,.09);
}

.sidebar-nav a.active {
    background: rgba(13,110,253,.22);
}

.sidebar-nav .section-title {
    color: #68758a;
    font-size: .69rem;
    text-transform: uppercase;
    letter-spacing: .08em;
    padding: 17px 13px 7px;
}

.main {
    margin-left: 260px;
    min-height: 100vh;
    padding-top: 86px;
}

.topbar {
    min-height: 72px;
    background: #fff;
    border-bottom: 1px solid var(--bajama-border);
    display: flex;
    align-items: center;
    padding: 0 28px;
}

.content {
    padding: 28px;
    min-width: 0;
}

.page-title {
    font-weight: 750;
    letter-spacing: -.02em;
}

.page-subtitle {
    color: #6b7280;
    font-size: .92rem;
}

.stat-card {
    border: 1px solid var(--bajama-border);
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 20px rgba(15,23,42,.035);
    height: 100%;
}

.stat-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #eef4ff;
    color: var(--bajama-primary);
    font-size: 1.25rem;
}

.card-modern {
    border: 1px solid var(--bajama-border);
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 20px rgba(15,23,42,.035);
}

.card-modern .card-header {
    background: #fff;
    border-bottom: 1px solid var(--bajama-border);
    border-radius: 16px 16px 0 0;
}

.table > :not(caption) > * > * {
    padding: 14px 12px;
    vertical-align: middle;
}

.table thead th {
    color: #6b7280;
    font-size: .73rem;
    text-transform: uppercase;
    letter-spacing: .045em;
    white-space: nowrap;
    background: #f8fafc;
}

.interface-name {
    font-weight: 700;
    color: #111827;
}

.ip-address {
    font-family:
        ui-monospace,
        SFMono-Regular,
        Menlo,
        Monaco,
        Consolas,
        monospace;
    font-size: .88rem;
}

.network-subnet {
    color: #6b7280;
    font-size: .78rem;
}

.empty-state {
    padding: 55px 20px;
    text-align: center;
    color: #6b7280;
}

.empty-icon {
    width: 64px;
    height: 64px;
    border-radius: 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #eef4ff;
    color: var(--bajama-primary);
    font-size: 1.7rem;
    margin-bottom: 15px;
}

.form-label {
    font-weight: 650;
    font-size: .86rem;
}

.help-text {
    font-size: .76rem;
    color: #6b7280;
    margin-top: 5px;
}

.modal-content {
    border: 0;
    border-radius: 18px;
    overflow: hidden;
    max-height: calc(100vh - 2rem);
    display: flex;
    flex-direction: column;
}

#networkModal .modal-content > form {
    display: flex;
    flex-direction: column;
    min-height: 0;
    height: 100%;
}

.modal-header {
    background: #f8fafc;
    border-bottom: 1px solid var(--bajama-border);
    flex: 0 0 auto;
}

.modal-body {
    overflow-y: auto;
    min-height: 0;
}

.modal-footer {
    flex: 0 0 auto;
    background: #fff;
}

.mobile-card {
    display: none;
}

@media (max-width: 991.98px) {

    .sidebar {
        transform: translateX(-100%);
    }

    .sidebar.show {
        transform: translateX(0);
    }

    .main {
        margin-left: 0;
        padding-top: 86px;
    }

    .content {
        padding: 20px 15px;
    }

    .topbar {
        padding: 0 15px;
    }
}

@media (max-width: 767.98px) {

    .desktop-table {
        display: none;
    }

    .mobile-card {
        display: block;
    }

    .stat-card {
        border-radius: 14px;
    }

    .page-title {
        font-size: 1.45rem;
    }

    .content {
        padding: 18px 12px 28px;
    }

    .modal-dialog {
        width: auto;
        height: calc(100dvh - 1rem);
        margin: .5rem;
    }

    .modal-body {
        max-height: none;
    }

    .modal-content {
        height: 100%;
        max-height: none;
    }

    .modal-dialog-scrollable .modal-content {
        height: 100%;
        max-height: none;
    }

    .modal-dialog-scrollable .modal-body {
        overflow-y: auto !important;
        min-height: 0;
    }

    .modal-footer {
        gap: .5rem;
        padding: .75rem;
    }

    .modal-footer .btn {
        min-height: 44px;
    }
}

@media (min-width: 768px) {
    .mobile-card {
        display: none;
    }
}

/* BAJAMA Network - LAN */
body.bajama-network-lan-page .bajama-network-mobile-button {
    display: none !important;
}

</style>
</head>

<body class="bajama-network-lan-page">

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- SIDEBAR -->
<?php
require_once __DIR__ . '/../app/layout/sidebar.php';
?>

<!-- MAIN -->
<main class="main">

    <?php
    $pageTitle = 'LAN / VLAN';
    $userName = $_SESSION['username'] ?? 'User';
    $displayRole = 'OWNER';
    require __DIR__ . '/../app/layout/header.php';
    ?>

    <header class="legacy-network-topbar d-none">

        <button
            type="button"
            class="btn btn-light d-lg-none me-3"
            onclick="document.getElementById('sidebarToggle')?.click()"
            aria-label="Buka menu Network"
        >
            <i class="bi bi-list fs-5"></i>
        </button>

        <div class="flex-grow-1">
            <div class="fw-semibold">Network Management</div>
            <div class="small text-muted">LAN & VLAN</div>
        </div>

        <div class="text-end d-none d-sm-block">
            <div class="small fw-semibold">BAJAMA</div>
            <div class="small text-muted">Network</div>
        </div>

    </header>

    <div class="content">

        <!-- PAGE HEADER -->
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">

            <div>
                <h1 class="page-title h3 mb-1">
                    <i class="bi bi-diagram-3 me-2 text-primary"></i>
                    LAN & VLAN
                </h1>

                <div class="page-subtitle">
                    Kelola jaringan LAN, VLAN, Bridge, PPPoE dan Hotspot dalam organisasi Anda.
                </div>
            </div>

            <?php if ($canManage): ?>

                <button
                    type="button"
                    class="btn btn-primary px-4"
                    onclick="newNetwork()"
                >
                    <i class="bi bi-plus-lg me-1"></i>
                    Tambah Network
                </button>

            <?php endif; ?>

        </div>

        <!-- ALERT -->
        <?php if ($message !== ''): ?>

            <div
                class="alert alert-<?php echo h($messageType); ?> alert-dismissible fade show"
                role="alert"
            >
                <i class="bi bi-info-circle me-2"></i>
                <?php echo h($message); ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>
            </div>

        <?php endif; ?>

        <!-- STATISTICS -->
        <div class="row g-3 mb-4">

            <div class="col-6 col-xl-2">
                <div class="stat-card p-3">

                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon">
                            <i class="bi bi-diagram-3"></i>
                        </div>

                        <div>
                            <div class="small text-muted">Total</div>
                            <div class="fs-4 fw-bold">
                                <?php echo $total; ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <div class="col-6 col-xl-2">
                <div class="stat-card p-3">

                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon text-success bg-success-subtle">
                            <i class="bi bi-check-circle"></i>
                        </div>

                        <div>
                            <div class="small text-muted">Online</div>
                            <div class="fs-4 fw-bold">
                                <?php echo $online; ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <div class="col-6 col-xl-2">
                <div class="stat-card p-3">

                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon text-danger bg-danger-subtle">
                            <i class="bi bi-x-circle"></i>
                        </div>

                        <div>
                            <div class="small text-muted">Offline</div>
                            <div class="fs-4 fw-bold">
                                <?php echo $offline; ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <div class="col-6 col-xl-2">
                <div class="stat-card p-3">

                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon text-secondary bg-secondary-subtle">
                            <i class="bi bi-pause-circle"></i>
                        </div>

                        <div>
                            <div class="small text-muted">Disabled</div>
                            <div class="fs-4 fw-bold">
                                <?php echo $disabled; ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <div class="col-6 col-xl-2">
                <div class="stat-card p-3">

                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon text-primary bg-primary-subtle">
                            <i class="bi bi-diagram-3"></i>
                        </div>

                        <div>
                            <div class="small text-muted">VLAN</div>
                            <div class="fs-4 fw-bold">
                                <?php echo $vlan; ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <div class="col-6 col-xl-2">
                <div class="stat-card p-3">

                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon text-info bg-info-subtle">
                            <i class="bi bi-person-lines-fill"></i>
                        </div>

                        <div>
                            <div class="small text-muted">PPPoE</div>
                            <div class="fs-4 fw-bold">
                                <?php echo $pppoe; ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

        </div>

        <!-- NETWORK TABLE -->
        <div class="card-modern">

            <div class="card-header p-3 p-md-4">

                <div class="d-flex flex-column flex-md-row gap-3 justify-content-between align-items-md-center">

                    <div>
                        <h5 class="mb-1 fw-bold">
                            Daftar Network
                        </h5>

                        <div class="small text-muted">
                            Konfigurasi jaringan yang tercatat pada BAJAMA.
                        </div>
                    </div>

                    <div class="input-group" style="max-width: 340px;">
                        <span class="input-group-text bg-white">
                            <i class="bi bi-search"></i>
                        </span>

                        <input
                            type="search"
                            class="form-control"
                            id="networkSearch"
                            placeholder="Cari network..."
                            autocomplete="off"
                        >
                    </div>

                </div>

            </div>

            <!-- DESKTOP -->
            <div class="desktop-table table-responsive">

                <?php if (!$lans): ?>

                    <div class="empty-state">

                        <div class="empty-icon">
                            <i class="bi bi-diagram-3"></i>
                        </div>

                        <h5 class="fw-bold text-dark">
                            Belum ada network
                        </h5>

                        <p class="mb-0">
                            Tambahkan LAN, VLAN, PPPoE atau Hotspot.
                        </p>

                    </div>

                <?php else: ?>

                    <table class="table table-hover mb-0" id="networkTable">

                        <thead>
                        <tr>
                            <th>Network</th>
                            <th>Interface</th>
                            <th>IP / Subnet</th>
                            <th>Gateway</th>
                            <th>Tipe</th>
                            <th>Status</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($lans as $row): ?>

                            <tr data-search="<?php
                                echo h(
                                    strtolower(
                                        implode(' ', [
                                            $row['name'],
                                            $row['interface_name'],
                                            $row['ip_address'],
                                            $row['subnet'],
                                            $row['network_type'],
                                            $row['gateway']
                                        ])
                                    )
                                );
                            ?>">

                                <td>
                                    <div class="fw-semibold">
                                        <?php echo h($row['name']); ?>
                                    </div>

                                    <?php if (!empty($row['description'])): ?>
                                        <div class="small text-muted">
                                            <?php echo h($row['description']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="interface-name">
                                        <i class="bi bi-ethernet me-1 text-primary"></i>
                                        <?php echo h($row['interface_name']); ?>
                                    </span>

                                    <?php if (!empty($row['vlan_id'])): ?>
                                        <div class="small text-muted">
                                            VLAN ID <?php echo (int)$row['vlan_id']; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="ip-address">
                                        <?php echo h($row['ip_address']); ?>
                                    </div>

                                    <div class="network-subnet">
                                        <?php echo h($row['subnet']); ?>
                                    </div>
                                </td>

                                <td>
                                    <?php if (!empty($row['gateway'])): ?>
                                        <span class="ip-address">
                                            <?php echo h($row['gateway']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php echo type_badge((string)$row['network_type']); ?>
                                </td>

                                <td>
                                    <?php
                                    echo status_badge(
                                        (string)$row['status'],
                                        (int)$row['enabled']
                                    );
                                    ?>
                                </td>

                                <td class="text-end">

                                    <div class="btn-group btn-group-sm">

                                        <?php if ($canManage): ?>

                                            <a
                                                href="network_lan.php?edit=<?php echo (int)$row['id']; ?>"
                                                class="btn btn-outline-primary"
                                                title="Edit"
                                            >
                                                <i class="bi bi-pencil"></i>
                                            </a>

                                            <form method="post" class="d-inline" onsubmit="return confirm('Terapkan konfigurasi network ini ke MikroTik?')">
                                                <input type="hidden" name="_csrf" value="<?php echo h($csrf); ?>">
                                                <input type="hidden" name="action" value="apply">
                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                <button type="submit" class="btn btn-outline-success" title="Terapkan ke MikroTik">
                                                    <i class="bi bi-cloud-upload"></i>
                                                </button>
                                            </form>

                                            <form
                                                method="post"
                                                class="d-inline"
                                                onsubmit="return confirm('Ubah status network ini?')"
                                            >
                                                <input
                                                    type="hidden"
                                                    name="_csrf"
                                                    value="<?php echo h($csrf); ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="toggle"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?php echo (int)$row['id']; ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-outline-secondary"
                                                    title="<?php echo (int)$row['enabled'] ? 'Disable' : 'Enable'; ?>"
                                                >
                                                    <i class="bi bi-power"></i>
                                                </button>
                                            </form>

                                            <form
                                                method="post"
                                                class="d-inline"
                                                onsubmit="return confirm('Hapus network ini? Data yang dihapus tidak dapat dikembalikan.')"
                                            >
                                                <input
                                                    type="hidden"
                                                    name="_csrf"
                                                    value="<?php echo h($csrf); ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="delete"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?php echo (int)$row['id']; ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-outline-danger"
                                                    title="Hapus"
                                                >
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>

                                        <?php else: ?>

                                            <span class="text-muted small px-2">
                                                View only
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php endif; ?>

            </div>

            <!-- MOBILE -->
            <div class="mobile-card p-3">

                <?php if (!$lans): ?>

                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="bi bi-diagram-3"></i>
                        </div>

                        <h5 class="fw-bold text-dark">
                            Belum ada network
                        </h5>

                        <p class="mb-0">
                            Tambahkan network pertama Anda.
                        </p>
                    </div>

                <?php else: ?>

                    <div id="mobileNetworkList">

                        <?php foreach ($lans as $row): ?>

                            <div
                                class="border rounded-4 p-3 mb-3 network-mobile-item"
                                data-search="<?php
                                    echo h(
                                        strtolower(
                                            implode(' ', [
                                                $row['name'],
                                                $row['interface_name'],
                                                $row['ip_address'],
                                                $row['subnet'],
                                                $row['network_type'],
                                                $row['gateway']
                                            ])
                                        )
                                    );
                                ?>"
                            >

                                <div class="d-flex justify-content-between align-items-start gap-3">

                                    <div class="min-width-0">

                                        <div class="fw-bold text-truncate">
                                            <?php echo h($row['name']); ?>
                                        </div>

                                        <div class="small text-muted mt-1">
                                            <i class="bi bi-ethernet me-1"></i>
                                            <?php echo h($row['interface_name']); ?>
                                        </div>

                                    </div>

                                    <?php
                                    echo status_badge(
                                        (string)$row['status'],
                                        (int)$row['enabled']
                                    );
                                    ?>

                                </div>

                                <div class="border-top mt-3 pt-3">

                                    <div class="row g-3">

                                        <div class="col-6">

                                            <div class="small text-muted">
                                                IP Address
                                            </div>

                                            <div class="ip-address fw-semibold">
                                                <?php echo h($row['ip_address']); ?>
                                            </div>

                                        </div>

                                        <div class="col-6">

                                            <div class="small text-muted">
                                                Subnet
                                            </div>

                                            <div class="ip-address">
                                                <?php echo h($row['subnet']); ?>
                                            </div>

                                        </div>

                                        <div class="col-6">

                                            <div class="small text-muted">
                                                Gateway
                                            </div>

                                            <div class="ip-address">
                                                <?php echo $row['gateway']
                                                    ? h($row['gateway'])
                                                    : '—'; ?>
                                            </div>

                                        </div>

                                        <div class="col-6">

                                            <div class="small text-muted">
                                                Tipe
                                            </div>

                                            <div class="mt-1">
                                                <?php
                                                echo type_badge(
                                                    (string)$row['network_type']
                                                );
                                                ?>
                                            </div>

                                        </div>

                                    </div>

                                </div>

                                <?php if (!empty($row['vlan_id'])): ?>

                                    <div class="alert alert-primary py-2 px-3 mt-3 mb-0 small">
                                        <i class="bi bi-diagram-3 me-1"></i>
                                        VLAN ID:
                                        <strong>
                                            <?php echo (int)$row['vlan_id']; ?>
                                        </strong>
                                    </div>

                                <?php endif; ?>

                                <?php if ($canManage): ?>

                                    <div class="d-flex gap-2 mt-3">

                                        <a
                                            href="network_lan.php?edit=<?php echo (int)$row['id']; ?>"
                                            class="btn btn-outline-primary btn-sm flex-fill"
                                        >
                                            <i class="bi bi-pencil me-1"></i>
                                            Edit
                                        </a>

                                        <form method="post" class="flex-fill" onsubmit="return confirm('Terapkan konfigurasi network ini ke MikroTik?')">
                                            <input type="hidden" name="_csrf" value="<?php echo h($csrf); ?>">
                                            <input type="hidden" name="action" value="apply">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit" class="btn btn-outline-success btn-sm w-100">
                                                <i class="bi bi-cloud-upload me-1"></i>Apply
                                            </button>
                                        </form>

                                        <form
                                            method="post"
                                            class="flex-fill"
                                            onsubmit="return confirm('Ubah status network ini?')"
                                        >

                                            <input
                                                type="hidden"
                                                name="_csrf"
                                                value="<?php echo h($csrf); ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="toggle"
                                            >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?php echo (int)$row['id']; ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-outline-secondary btn-sm w-100"
                                            >
                                                <i class="bi bi-power me-1"></i>
                                                <?php echo (int)$row['enabled']
                                                    ? 'Disable'
                                                    : 'Enable'; ?>
                                            </button>

                                        </form>

                                        <form
                                            method="post"
                                            class="flex-fill"
                                            onsubmit="return confirm('Hapus network ini?')"
                                        >

                                            <input
                                                type="hidden"
                                                name="_csrf"
                                                value="<?php echo h($csrf); ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete"
                                            >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?php echo (int)$row['id']; ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-outline-danger btn-sm w-100"
                                            >
                                                <i class="bi bi-trash"></i>
                                            </button>

                                        </form>

                                    </div>

                                <?php endif; ?>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>

</main>

<!-- MODAL -->
<div
    class="modal fade"
    id="networkModal"
    tabindex="-1"
    aria-hidden="true"
>
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content">

            <form method="post">

                <div class="modal-header">

                    <div>
                        <h5 class="modal-title fw-bold">
                            <i class="bi bi-diagram-3 me-2 text-primary"></i>

                            <?php echo $form['id']
                                ? 'Edit Network'
                                : 'Tambah Network'; ?>
                        </h5>

                        <div class="small text-muted">
                            Data ini hanya disimpan di BAJAMA.
                        </div>
                    </div>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                    ></button>

                </div>

                <div class="modal-body">

                    <input
                        type="hidden"
                        name="_csrf"
                        value="<?php echo h($csrf); ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="save"
                    >

                    <input
                        type="hidden"
                        name="id"
                        value="<?php echo (int)$form['id']; ?>"
                    >

                    <div class="row g-4">

                        <div class="col-12 col-lg-6">

                            <h6 class="fw-bold border-bottom pb-2 mb-3">
                                Identitas Network
                            </h6>

                            <div class="mb-3">

                                <label class="form-label">
                                    Router MikroTik
                                </label>

                                <select
                                    class="form-select"
                                    name="router_id"
                                    id="lan_router_id"
                                    required
                                >
                                    <option value="">Pilih router</option>
                                    <?php foreach ($routers as $router): ?>
                                        <option
                                            value="<?= (int)$router['id'] ?>"
                                            <?= ((int)($form['router_id'] ?? 0) === (int)$router['id']) ? 'selected' : '' ?>
                                        >
                                            <?= h($router['name'] . ' - ' . $router['host']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                            </div>

                            <div class="mb-3">

                                <label class="form-label">
                                    Nama Network
                                </label>

                                <input
                                    type="text"
                                    name="name"
                                    class="form-control"
                                    maxlength="150"
                                    required
                                    value="<?php echo h($form['name']); ?>"
                                    placeholder="Contoh: LAN PC"
                                >

                            </div>

                            <div class="mb-3">

                                <label class="form-label">
                                    Interface
                                </label>

                                <select
                                    name="interface_name"
                                    id="lan_interface_name"
                                    class="form-select"
                                    required
                                    data-current="<?php echo h($form['interface_name']); ?>">
                                    <option value="">Pilih router terlebih dahulu</option>
                                </select>

                                <div class="help-text">
                                    Nama interface dibaca otomatis dari RouterOS. Untuk Bridge, pilih bridge yang sudah dibuat di MikroTik.
                                </div>

                            </div>

                            <div class="mb-3">

                                <label class="form-label">
                                    Tipe Network
                                </label>

                                <select
                                    name="network_type"
                                    id="networkType"
                                    class="form-select"
                                    onchange="updateNetworkType()"
                                >

                                    <?php
                                    $types = [
                                        'LAN' => 'LAN',
                                        'VLAN' => 'VLAN',
                                        'BRIDGE' => 'Bridge',
                                        'PPPOE' => 'PPPoE',
                                        'HOTSPOT' => 'Hotspot',
                                        'OTHER' => 'Other'
                                    ];

                                    foreach ($types as $value => $label):
                                    ?>

                                        <option
                                            value="<?php echo $value; ?>"
                                            <?php echo $form['network_type'] === $value
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?php echo $label; ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div
                                class="mb-3"
                                id="vlanIdGroup"
                            >

                                <label class="form-label">
                                    VLAN ID
                                </label>

                                <input
                                    type="number"
                                    name="vlan_id"
                                    id="vlanId"
                                    class="form-control"
                                    min="1"
                                    max="4094"
                                    value="<?php echo h($form['vlan_id']); ?>"
                                    placeholder="1 - 4094"
                                >

                                <div class="help-text">
                                    Hanya digunakan jika tipe network adalah VLAN.
                                </div>

                            </div>

                            <div class="mb-3">

                                <label class="form-label">
                                    Description
                                </label>

                                <textarea
                                    name="description"
                                    class="form-control"
                                    rows="3"
                                    maxlength="1000"
                                    placeholder="Keterangan network..."
                                ><?php echo h($form['description']); ?></textarea>

                            </div>

                        </div>

                        <div class="col-12 col-lg-6">

                            <h6 class="fw-bold border-bottom pb-2 mb-3">
                                IP Configuration
                            </h6>

                            <div class="row g-3">

                                <div class="col-12 col-md-6">

                                    <label class="form-label">
                                        IP Address
                                    </label>

                                    <input
                                        type="text"
                                        name="ip_address"
                                        class="form-control"
                                        required
                                        value="<?php echo h($form['ip_address']); ?>"
                                        placeholder="192.168.20.1"
                                    >

                                </div>

                                <div class="col-12 col-md-6">

                                    <label class="form-label">
                                        Network / CIDR
                                    </label>

                                    <input
                                        type="text"
                                        name="subnet"
                                        class="form-control"
                                        required
                                        value="<?php echo h($form['subnet']); ?>"
                                        placeholder="192.168.20.0/24"
                                    >

                                </div>

                                <div class="col-12">

                                    <label class="form-label">
                                        Gateway
                                    </label>

                                    <input
                                        type="text"
                                        name="gateway"
                                        class="form-control"
                                        value="<?php echo h($form['gateway']); ?>"
                                        placeholder="192.168.20.1"
                                    >

                                </div>

                            </div>

                            <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4">
                                DHCP & DNS
                            </h6>

                            <div class="form-check form-switch mb-3">

                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="dhcp_enabled"
                                    id="dhcpEnabled"
                                    <?php echo (int)$form['dhcp_enabled']
                                        ? 'checked'
                                        : ''; ?>
                                >

                                <label
                                    class="form-check-label fw-semibold"
                                    for="dhcpEnabled"
                                >
                                    DHCP aktif
                                </label>

                            </div>

                            <div class="row g-3">

                                <div class="col-12 col-md-6">

                                    <label class="form-label">
                                        DHCP Start
                                    </label>

                                    <input
                                        type="text"
                                        name="dhcp_start"
                                        class="form-control"
                                        value="<?php echo h($form['dhcp_start']); ?>"
                                        placeholder="192.168.20.10"
                                    >

                                </div>

                                <div class="col-12 col-md-6">

                                    <label class="form-label">
                                        DHCP End
                                    </label>

                                    <input
                                        type="text"
                                        name="dhcp_end"
                                        class="form-control"
                                        value="<?php echo h($form['dhcp_end']); ?>"
                                        placeholder="192.168.20.200"
                                    >

                                </div>

                                <div class="col-12 col-md-6">

                                    <label class="form-label">
                                        DNS Primary
                                    </label>

                                    <input
                                        type="text"
                                        name="dns_primary"
                                        class="form-control"
                                        value="<?php echo h($form['dns_primary']); ?>"
                                        placeholder="1.1.1.1"
                                    >

                                </div>

                                <div class="col-12 col-md-6">

                                    <label class="form-label">
                                        DNS Secondary
                                    </label>

                                    <input
                                        type="text"
                                        name="dns_secondary"
                                        class="form-control"
                                        value="<?php echo h($form['dns_secondary']); ?>"
                                        placeholder="8.8.8.8"
                                    >

                                </div>

                            </div>

                            <div class="form-check form-switch mt-4">

                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="enabled"
                                    id="networkEnabled"
                                    <?php echo (int)$form['enabled']
                                        ? 'checked'
                                        : ''; ?>
                                >

                                <label
                                    class="form-check-label fw-semibold"
                                    for="networkEnabled"
                                >
                                    Network enabled
                                </label>

                            </div>

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
                        class="btn btn-primary px-4"
                    >
                        <i class="bi bi-check-lg me-1"></i>
                        Simpan Network
                    </button>

                </div>

            </form>

        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');

    if (sidebar) {
        sidebar.classList.toggle('show');
    }
}

function newNetwork() {
    const modalElement = document.getElementById('networkModal');

    if (!modalElement) {
        console.error('networkModal tidak ditemukan.');
        return;
    }

    /*
     * Reset form secara aman.
     */
    const form = modalElement.querySelector('form');

    if (form) {
        form.reset();

        const methodInput = form.querySelector('input[name="action"]');
        if (methodInput) {
            methodInput.value = 'save';
        }

        const idInput = form.querySelector('input[name="id"]');
        if (idInput) {
            idInput.value = '';
        }
    }

    /*
     * Judul modal.
     */
    const title = modalElement.querySelector('.modal-title');

    if (title) {
        title.innerHTML =
            '<i class="bi bi-plus-circle me-2"></i>Tambah Network';
    }

    /*
     * Hapus instance Bootstrap lama agar tidak terjadi
     * konflik show/hide atau backdrop ganda.
     */
    if (window.bootstrap && bootstrap.Modal) {
        const existing = bootstrap.Modal.getInstance(modalElement);

        if (existing) {
            existing.dispose();
        }

        const modal = new bootstrap.Modal(modalElement, {
            backdrop: true,
            keyboard: true,
            focus: true
        });

        modal.show();
    } else {
        console.error('Bootstrap Modal tidak tersedia.');
    }
}

function updateNetworkType() {

    const type = document.getElementById('networkType');
    const group = document.getElementById('vlanIdGroup');
    const input = document.getElementById('vlanId');

    if (!type || !group || !input) {
        return;
    }

    if (type.value === 'VLAN') {

        group.style.display = '';

    } else {

        group.style.display = 'none';
        input.value = '';
    }
}

async function loadLanInterfaces(routerId, selected = '') {
    const select = document.getElementById('lan_interface_name');
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
            if (name) select.add(new Option(name + (item.type ? ' (' + item.type + ')' : ''), name, false, name === selected));
        });
        if (selected && !Array.from(select.options).some(option => option.value === selected)) {
            select.add(new Option(selected + ' (tersimpan)', selected, true, true));
        }
    } catch (error) {
        select.innerHTML = '<option value="">Interface gagal dibaca</option>';
    }
}

document.addEventListener('DOMContentLoaded', function () {

    updateNetworkType();

    const lanRouter = document.getElementById('lan_router_id');
    if (lanRouter) {
        lanRouter.addEventListener('change', function () {
            loadLanInterfaces(this.value);
        });
        loadLanInterfaces(lanRouter.value, document.getElementById('lan_interface_name')?.dataset.current || '');
    }

    const search = document.getElementById('networkSearch');

    if (!search) {
        return;
    }

    search.addEventListener('input', function () {

        const query = this.value
            .toLowerCase()
            .trim();

        document
            .querySelectorAll('#networkTable tbody tr')
            .forEach(function (row) {

                const text = row.dataset.search || '';

                row.style.display =
                    !query || text.includes(query)
                        ? ''
                        : 'none';
            });

        document
            .querySelectorAll('.network-mobile-item')
            .forEach(function (card) {

                const text = card.dataset.search || '';

                card.style.display =
                    !query || text.includes(query)
                        ? ''
                        : 'none';
            });

    });

    <?php if ($edit !== null): ?>

    const modalElement = document.getElementById('networkModal');

    if (modalElement) {

        const modal = new bootstrap.Modal(modalElement);

        modal.show();
    }

    <?php endif; ?>

});
</script>

<script src="assets/js/bajama.js"></script>

</body>
</html>
