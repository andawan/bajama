<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\License;
use BAJAMA\Core\RBAC;
use BAJAMA\Core\Tenant;
use BAJAMA\Network\MikroTik;
use BAJAMA\Network\MikroTikPermissionGuard;

Auth::requireLogin();

$db = db();

License::requireFeature($db, 'mikrotik');
RBAC::require($db, 'mikrotik.view');

$organizationId = Tenant::id();
$canManage = RBAC::hasPermission($db, 'mikrotik.manage');

$routers = MikroTik::all($db, $organizationId);

$routerId = (int)($_GET['router_id'] ?? 0);

if ($routerId <= 0 && !empty($routers)) {
    $routerId = (int)$routers[0]['id'];
}

$selectedRouter = null;

foreach ($routers as $router) {
    if ((int)$router['id'] === $routerId) {
        $selectedRouter = $router;
        break;
    }
}

if (!$selectedRouter && !empty($routers)) {
    $selectedRouter = $routers[0];
    $routerId = (int)$selectedRouter['id'];
}

/*
 * CENTRAL MIKROTIK MENU PERMISSION
 *
 * Permission berlaku berdasarkan:
 * organization + user + router + menu_key + action.
 */
$menuGuard = null;

if ($routerId > 0) {
    $menuGuard = new MikroTikPermissionGuard(
        $db,
        (int)$organizationId,
        (int)Auth::userId(),
        (int)$routerId
    );
}

$csrfToken = function_exists('csrf_token')
    ? csrf_token()
    : '';

function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

$routerName = $selectedRouter['name'] ?? 'MikroTik';
$routerHost = $selectedRouter['host'] ?? '-';
$routerPort = $selectedRouter['port'] ?? 8728;

$currentAction = trim((string)($_GET['action'] ?? ''));

$menus = [
    'interfaces' => [
        'menu_key' => 'interfaces.list',
        'title' => 'Interfaces',
        'icon' => 'bi-ethernet',
        'group' => 'Interfaces',
        'actions' => ['edit','enable','disable']
    ],

    'vlans' => [
        'menu_key' => 'interfaces.vlans',
        'title' => 'VLAN',
        'icon' => 'bi-diagram-3',
        'group' => 'Interfaces',
        'actions' => ['add','edit','enable','disable','delete']
    ],

    'ip_addresses' => [
        'menu_key' => 'ip.addresses',
        'title' => 'IP Addresses',
        'icon' => 'bi-diagram-3',
        'group' => 'IP',
        'actions' => ['add','edit','delete']
    ],

    'ip_pools' => [
        'menu_key' => 'ip.pools',
        'title' => 'IP Pools',
        'icon' => 'bi-collection',
        'group' => 'IP',
        'actions' => ['add','edit','delete']
    ],

    'routes' => [
        'menu_key' => 'ip.routes',
        'title' => 'Routes',
        'icon' => 'bi-signpost-split',
        'group' => 'IP',
        'actions' => ['add','edit','enable','disable','delete']
    ],

    'arp' => [
        'menu_key' => 'ip.arp',
        'title' => 'ARP',
        'icon' => 'bi-hdd-network',
        'group' => 'IP',
        'actions' => ['add','edit','delete']
    ],

    'dhcp_servers' => [
        'menu_key' => 'dhcp.servers',
        'title' => 'DHCP Servers',
        'icon' => 'bi-router',
        'group' => 'IP',
        'actions' => ['add','edit','enable','disable','delete']
    ],

    'dhcp_networks' => [
        'menu_key' => 'dhcp.networks',
        'title' => 'DHCP Networks',
        'icon' => 'bi-diagram-2',
        'group' => 'IP',
        'actions' => ['add','edit','delete']
    ],

    'dhcp_leases' => [
        'menu_key' => 'dhcp.leases',
        'title' => 'DHCP Leases',
        'icon' => 'bi-pc-display',
        'group' => 'IP',
        'actions' => ['add','edit','enable','disable','delete']
    ],

    'ppp_profiles' => [
        'menu_key' => 'ppp.profiles',
        'title' => 'PPP Profiles',
        'icon' => 'bi-person-badge',
        'group' => 'PPP',
        'actions' => ['add','edit','delete']
    ],

    'pppoe' => [
        'menu_key' => 'ppp.secrets',
        'title' => 'PPPoE Secrets',
        'icon' => 'bi-person-lines-fill',
        'group' => 'PPP',
        'actions' => ['add','edit','enable','disable','delete']
    ],

    'ppp_active' => [
        'menu_key' => 'ppp.active',
        'title' => 'PPP Active',
        'icon' => 'bi-activity',
        'group' => 'PPP',
        'actions' => []
    ],

    'hotspot_servers' => [
        'menu_key' => 'hotspot.servers',
        'title' => 'Hotspot Servers',
        'icon' => 'bi-wifi',
        'group' => 'Hotspot',
        'actions' => ['add','edit','enable','disable','delete']
    ],

    'hotspot' => [
        'menu_key' => 'hotspot.users',
        'title' => 'Hotspot Users',
        'icon' => 'bi-people',
        'group' => 'Hotspot',
        'actions' => ['add','edit','enable','disable','delete']
    ],

    'hotspot_active' => [
        'menu_key' => 'hotspot.active',
        'title' => 'Hotspot Active',
        'icon' => 'bi-broadcast-pin',
        'group' => 'Hotspot',
        'actions' => []
    ],

    'queues' => [
        'menu_key' => 'queues.simple',
        'title' => 'Simple Queues',
        'icon' => 'bi-speedometer2',
        'group' => 'Traffic',
        'actions' => ['add','edit','enable','disable','delete']
    ],

    'firewall_filter' => [
        'menu_key' => 'firewall.filter',
        'title' => 'Firewall Filter',
        'icon' => 'bi-shield-check',
        'group' => 'Firewall',
        'actions' => ['add','edit','enable','disable','delete']
    ],

    'firewall_nat' => [
        'menu_key' => 'firewall.nat',
        'title' => 'Firewall NAT',
        'icon' => 'bi-shuffle',
        'group' => 'Firewall',
        'actions' => ['add','edit','enable','disable','delete']
    ],

    'firewall_mangle' => [
        'menu_key' => 'firewall.mangle',
        'title' => 'Firewall Mangle',
        'icon' => 'bi-sliders',
        'group' => 'Firewall',
        'actions' => ['add','edit','enable','disable','delete']
    ],

    'firewall_raw' => [
        'menu_key' => 'firewall.raw',
        'title' => 'Firewall Raw',
        'icon' => 'bi-filter-square',
        'group' => 'Firewall',
        'actions' => ['add','edit','enable','disable','delete']
    ],

    'firewall_address_list' => [
        'menu_key' => 'firewall.address_list',
        'title' => 'Address Lists',
        'icon' => 'bi-list-check',
        'group' => 'Firewall',
        'actions' => ['add','edit','delete']
    ],

    'dns' => [
        'menu_key' => 'system.dns',
        'title' => 'DNS',
        'icon' => 'bi-globe2',
        'group' => 'System',
        'actions' => ['edit']
    ],

    'identity' => [
        'menu_key' => 'system.identity',
        'title' => 'Identity',
        'icon' => 'bi-fingerprint',
        'group' => 'System',
        'actions' => ['edit']
    ],

    'resource' => [
        'menu_key' => 'system.resource',
        'title' => 'Resource',
        'icon' => 'bi-cpu',
        'group' => 'System',
        'actions' => []
    ],

    'health' => [
        'menu_key' => 'system.health',
        'title' => 'Health',
        'icon' => 'bi-heart-pulse',
        'group' => 'System',
        'actions' => []
    ],

    'clock' => [
        'menu_key' => 'system.clock',
        'title' => 'Clock',
        'icon' => 'bi-clock',
        'group' => 'System',
        'actions' => []
    ],

    'users' => [
        'menu_key' => 'system.users',
        'title' => 'Router Users',
        'icon' => 'bi-person-gear',
        'group' => 'System',
        'actions' => ['add','edit','enable','disable','delete']
    ],

    'logs' => [
        'menu_key' => 'system.logs',
        'title' => 'Logs',
        'icon' => 'bi-journal-text',
        'group' => 'System',
        'actions' => []
    ],
];

/*
 * Terapkan VIEW permission ke menu.
 *
 * Menu yang OFF tidak dimasukkan ke $groups,
 * sehingga tidak akan tampil di UI dan tidak dapat
 * menjadi menu awal otomatis.
 */
foreach ($menus as $key => $menu) {
    $menuKey = (string)($menu['menu_key'] ?? '');

    if (
        $menuKey === ''
        || !$menuGuard
        || !$menuGuard->allows($menuKey, 'view')
    ) {
        unset($menus[$key]);
    }
}

/*
 * URL action menggunakan menu_key dari MikroTik sidebar,
 * sedangkan menuConfig JavaScript menggunakan internal key.
 *
 * Contoh:
 *   ppp.secrets   -> pppoe
 *   ip.addresses  -> ip_addresses
 *   ip.routes     -> routes
 *
 * Konversi ini menjaga sidebar, permission central,
 * dan menuConfig tetap sinkron.
 */
$initialAction = '';

if ($currentAction !== '') {
    foreach ($menus as $internalKey => $menu) {
        if (
            isset($menu['menu_key'])
            && (string)$menu['menu_key'] === $currentAction
        ) {
            $initialAction = (string)$internalKey;
            break;
        }
    }
}

if ($initialAction === '') {
    $initialAction = array_key_first($menus) ?? '';
}

$groups = [];

foreach ($menus as $key => $menu) {
    $groups[$menu['group']][$key] = $menu;
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width, initial-scale=1">

<title><?= h($routerName) ?> - MikroTik</title>

<link
 href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
 rel="stylesheet">

<link
 href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
 rel="stylesheet">

<style>
:root {
    --mk-bg: #f4f7fb;
    --mk-surface: #ffffff;
    --mk-sidebar: #111827;
    --mk-sidebar-2: #1f2937;
    --mk-sidebar-hover: #263449;
    --mk-primary: #2563eb;
    --mk-primary-dark: #1d4ed8;
    --mk-border: #e2e8f0;
    --mk-text: #172033;
    --mk-muted: #64748b;
    --mk-radius: 12px;
}

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    min-height: 100%;
}

body {
    background: var(--mk-bg);
    color: var(--mk-text);
    font-family:
        Inter,
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
}

/* =========================================================
   MAIN APPLICATION LAYOUT
   ========================================================= */

.mk-layout {
    min-height: 100vh;
    display: flex;
    width: 100%;
}

/* =========================================================
   SIDEBAR
   ========================================================= */

.mk-sidebar {
    width: 265px;
    min-width: 265px;
    flex: 0 0 265px;

    background: var(--mk-sidebar);
    color: #fff;

    position: fixed;
    inset: 0 auto 0 0;

    overflow-x: hidden;
    overflow-y: auto;

    z-index: 1000;

    scrollbar-width: thin;
    scrollbar-color: #374151 transparent;
}

.mk-sidebar::-webkit-scrollbar {
    width: 6px;
}

.mk-sidebar::-webkit-scrollbar-track {
    background: transparent;
}

.mk-sidebar::-webkit-scrollbar-thumb {
    background: #374151;
    border-radius: 20px;
}

/* Brand */

.mk-brand {
    padding: 18px 18px 15px;
    border-bottom: 1px solid rgba(255,255,255,.08);
    background: linear-gradient(
        180deg,
        #111827 0%,
        #151f30 100%
    );
}

.mk-brand-title {
    display: flex;
    align-items: center;
    gap: 8px;

    font-size: 18px;
    font-weight: 700;
    letter-spacing: -.01em;
}

.mk-brand-title i {
    font-size: 20px;
    color: #60a5fa;
}

.mk-brand-sub {
    margin-top: 3px;
    padding-left: 28px;

    font-size: 11px;
    color: #94a3b8;
}

/* Router selector card */

.mk-router {
    margin: 12px;
    padding: 12px;

    border: 1px solid rgba(255,255,255,.07);
    border-radius: 12px;

    background: var(--mk-sidebar-2);
    box-shadow: 0 4px 15px rgba(0,0,0,.12);
}

.mk-router-name {
    font-size: 13px;
    font-weight: 700;
    color: #f8fafc;
}

.mk-router-host {
    color: #94a3b8;
    font-size: 11px;
}

.mk-router .form-select {
    min-height: 36px;

    border-radius: 8px !important;

    background-color: #111827 !important;
    color: #f8fafc !important;
    border-color: #374151 !important;

    font-size: 12px;
}

.mk-router .form-select:focus {
    border-color: #60a5fa !important;
    box-shadow: 0 0 0 .2rem rgba(37,99,235,.15) !important;
}

/* Sidebar groups */

.mk-group {
    margin: 10px 9px 0;
}

.mk-group-title {
    color: #64748b;

    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .09em;

    padding: 8px 10px 6px;
}

.mk-menu {
    display: flex;
    align-items: center;
    gap: 10px;

    min-height: 38px;

    padding: 8px 10px;
    margin-bottom: 2px;

    border-radius: 8px;

    color: #cbd5e1;
    text-decoration: none;

    font-size: 13px;
    font-weight: 500;

    transition:
        background .15s ease,
        color .15s ease,
        transform .15s ease;
}

.mk-menu i {
    width: 18px;
    min-width: 18px;

    text-align: center;

    color: #94a3b8;
    font-size: 15px;
}

.mk-menu:hover {
    background: var(--mk-sidebar-hover);
    color: #fff;
}

.mk-menu:hover i {
    color: #93c5fd;
}

.mk-menu.active {
    background: var(--mk-primary);
    color: #fff;

    box-shadow:
        0 3px 10px rgba(37,99,235,.22);
}

.mk-menu.active i {
    color: #fff;
}

/* =========================================================
   MAIN AREA
   ========================================================= */

.mk-main {
    width: calc(100% - 265px);
    min-width: 0;

    margin-left: 265px;

    min-height: 100vh;

    display: flex;
    flex-direction: column;
}

/* =========================================================
   TOP HEADER
   ========================================================= */

.mk-topbar {
    min-height: 68px;

    padding: 11px 20px;

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 16px;

    background: rgba(255,255,255,.96);

    border-bottom: 1px solid var(--mk-border);

    position: sticky;
    top: 0;

    z-index: 900;

    box-shadow: 0 1px 3px rgba(15,23,42,.03);
}

.mk-topbar > div {
    min-width: 0;
}

.mk-title {
    font-size: 19px;
    line-height: 1.2;
    font-weight: 700;

    color: #172033;

    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.mk-subtitle {
    margin-top: 3px;

    color: var(--mk-muted);

    font-size: 11px;

    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Header buttons */

.mk-topbar .btn {
    min-height: 36px;

    border-radius: 8px;

    font-size: 12px;
    font-weight: 600;
}

.mk-topbar .btn i {
    font-size: 14px;
}

.mk-mobile-toggle {
    display: none;
}

/* =========================================================
   CONTENT
   ========================================================= */

.mk-content {
    width: 100%;
    padding: 18px 20px 24px;

    flex: 1;
}

/* =========================================================
   TOOLBAR
   ========================================================= */

.mk-toolbar {
    width: 100%;

    min-height: 52px;

    margin-bottom: 12px;
    padding: 8px;

    display: flex;
    align-items: center;

    gap: 7px;
    flex-wrap: wrap;

    background: var(--mk-surface);

    border: 1px solid var(--mk-border);
    border-radius: var(--mk-radius);

    box-shadow:
        0 1px 2px rgba(15,23,42,.03);
}

.mk-toolbar .btn {
    min-height: 34px;

    border-radius: 7px;

    font-size: 12px;
    font-weight: 600;
}

.mk-search {
    width: 280px;
    max-width: 280px;

    min-height: 34px;

    border-radius: 7px;

    font-size: 12px;
}

/* =========================================================
   TABLE
   ========================================================= */

.mk-table-card {
    background: var(--mk-surface);

    border: 1px solid var(--mk-border);
    border-radius: var(--mk-radius);

    overflow: hidden;

    box-shadow:
        0 1px 3px rgba(15,23,42,.035);
}

.mk-table-wrap {
    width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
}

.mk-table {
    width: 100%;
    min-width: 760px;

    margin: 0;

    font-size: 12.5px;
}

.mk-table th {
    white-space: nowrap;

    padding: 11px 12px;

    background: #f8fafc;

    border-bottom: 1px solid var(--mk-border);

    color: #475569;

    font-size: 11px;
    font-weight: 700;
}

.mk-table td {
    padding: 10px 12px;

    vertical-align: middle;

    border-color: #eef2f7;
}

.mk-table tbody tr {
    cursor: pointer;

    transition: background .12s ease;
}

.mk-table tbody tr:hover {
    background: #f8fbff;
}

.mk-table tbody tr.selected {
    background: #eaf2ff;
}

/* =========================================================
   STATUS
   ========================================================= */

.mk-status {
    display: inline-flex;
    align-items: center;
    gap: 5px;

    padding: 4px 8px;

    border-radius: 999px;

    font-size: 10px;
    font-weight: 700;
}

.mk-status.up {
    background: #dcfce7;
    color: #166534;
}

.mk-status.down {
    background: #fee2e2;
    color: #991b1b;
}

.mk-status.unknown {
    background: #f1f5f9;
    color: #64748b;
}

/* =========================================================
   EMPTY / LOADING
   ========================================================= */

.mk-empty,
.mk-loading {
    padding: 55px 20px;

    text-align: center;

    color: var(--mk-muted);
}

/* =========================================================
   BOTTOM BAR
   ========================================================= */

.mk-bottom {
    min-height: 51px;

    padding: 8px 10px;

    border-top: 1px solid var(--mk-border);

    background: #fff;
}

.mk-info {
    display: flex;
    align-items: center;

    gap: 8px;

    margin-left: auto;

    color: var(--mk-muted);

    font-size: 11px;
}

/* =========================================================
   MOBILE OVERLAY
   ========================================================= */

.mk-overlay {
    display: none;

    position: fixed;
    inset: 0;

    background: rgba(15,23,42,.48);

    z-index: 950;

    backdrop-filter: blur(1px);
}

.mk-overlay.show {
    display: block;
}

/* =========================================================
   TABLET / MOBILE
   ========================================================= */

@media (max-width: 900px) {

    .mk-sidebar {
        width: 265px;
        min-width: 265px;

        transform: translateX(-105%);

        transition: transform .22s ease;

        box-shadow:
            10px 0 35px rgba(0,0,0,.25);
    }

    .mk-sidebar.open {
        transform: translateX(0);
    }

    .mk-main {
        width: 100%;
        margin-left: 0;
    }

    .mk-mobile-toggle {
        display: inline-flex;

        width: 36px;
        height: 36px;

        align-items: center;
        justify-content: center;

        padding: 0;

        border: 1px solid var(--mk-border);
        border-radius: 8px;

        background: #fff;

        color: #334155;

        flex: 0 0 36px;
    }

    .mk-mobile-toggle:hover {
        background: #f8fafc;
    }

    .mk-topbar {
        min-height: 62px;

        padding: 9px 12px;

        gap: 10px;
    }

    .mk-title {
        font-size: 17px;
    }

    .mk-content {
        padding: 12px;
    }

    .mk-search {
        width: 100%;
        max-width: none;

        flex: 1 1 100%;

        order: 20;
    }
}

/* =========================================================
   SMALL MOBILE
   ========================================================= */

@media (max-width: 600px) {

    .mk-topbar {
        min-height: 58px;

        padding: 8px 10px;
    }

    .mk-title {
        font-size: 16px;
    }

    .mk-subtitle {
        font-size: 10px;
    }

    .mk-topbar > div:last-child {
        gap: 5px !important;
    }

    .mk-topbar .btn {
        width: 36px;
        height: 36px;

        min-height: 36px;

        padding: 0;

        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .mk-content {
        padding: 10px;
    }

    .mk-toolbar {
        padding: 7px;

        border-radius: 10px;
    }

    .mk-toolbar .btn {
        flex: 1 1 auto;

        min-width: 70px;
    }

    .mk-table {
        min-width: 650px;
    }

    .mk-bottom {
        flex-wrap: wrap;
    }

    .mk-info {
        width: 100%;
        margin-left: 0;
        justify-content: flex-end;
    }
}
</style>
</head>

<body>

<div class="mk-layout">

<aside class="mk-sidebar" id="sidebar">

    <div class="mk-brand">
        <div class="mk-brand-title">
            <i class="bi bi-router"></i>
            BAJAMA MikroTik
        </div>

        <div class="mk-brand-sub">
            Router Management
        </div>
    </div>

    <div class="mk-router">

        <div class="d-flex align-items-center gap-2 mb-2">

            <div class="flex-grow-1">

                <div class="mk-router-name">
                    <i class="bi bi-router me-1"></i>
                    MikroTik Router
                </div>

            </div>

            <span
                class="badge text-bg-success"
                style="font-size:10px">

                ONLINE
            </span>

        </div>

        <select
            class="form-select form-select-sm"
            id="routerSelector"
            style="background:#111827;
                   color:#fff;
                   border-color:#374151;">

            <?php if (empty($routers)): ?>

                <option value="">
                    Tidak ada router
                </option>

            <?php else: ?>

                <?php foreach ($routers as $router): ?>

                    <option
                        value="<?= (int)$router['id'] ?>"
                        <?= (int)$router['id'] === $routerId ? 'selected' : '' ?>>

                        <?= h($router['name'] ?? 'Router') ?>
                        —
                        <?= h($router['host'] ?? '-') ?>

                    </option>

                <?php endforeach; ?>

            <?php endif; ?>

        </select>

        <div class="mk-router-host mt-2">

            <i class="bi bi-hdd-network me-1"></i>

            <?= h($routerHost) ?>:<?= h($routerPort) ?>

        </div>

    </div>

    <?php
    /*
     * Dedicated MikroTik navigation.
     *
     * Sidebar ini KHUSUS halaman MikroTik.
     * Sidebar utama BAJAMA tetap berada di dashboard.php.
     *
     * Permission tetap berasal dari central
     * User x Router x Menu x Action.
     */
    $mikrotikRouterId = $routerId;
    $mikrotikActive = $currentAction ?? '';

    $mikrotikMenuGuard = $menuGuard;

    require __DIR__ . '/../app/Views/mikrotik_sidebar.php';

    unset(
        $mikrotikRouterId,
        $mikrotikActive,
        $mikrotikMenuGuard
    );
    ?>

</aside>

<div class="mk-overlay" id="sidebarOverlay"></div>

<main class="mk-main">

<header class="mk-topbar">

    <div class="d-flex align-items-center gap-2">

        <button
            class="btn btn-light mk-mobile-toggle"
            id="mobileMenu">

            <i class="bi bi-list"></i>
        </button>

        <div>
            <div class="mk-title" id="pageTitle">
                Router Dashboard
            </div>

            <div class="mk-subtitle">
                <?= h($routerName) ?> · <?= h($routerHost) ?>
            </div>
        </div>

    </div>

    <div class="d-flex gap-2 align-items-center">

        <a
            href="dashboard.php"
            class="btn btn-outline-secondary btn-sm">

            <i class="bi bi-speedometer2"></i>
            <span class="d-none d-sm-inline">
                Dashboard BAJAMA
            </span>
        </a>

        <button
            class="btn btn-outline-primary btn-sm"
            id="refreshBtn">

            <i class="bi bi-arrow-clockwise"></i>

            <span class="d-none d-sm-inline">
                Refresh
            </span>

        </button>

    </div>

</header>

<section class="mk-content">

    <div class="mk-toolbar" id="toolbar">

        <button
            class="btn btn-primary btn-sm action-add d-none">

            <i class="bi bi-plus-lg"></i>
            Add
        </button>

        <button
            class="btn btn-outline-primary btn-sm action-edit d-none">

            <i class="bi bi-pencil"></i>
            Edit
        </button>

        <button
            class="btn btn-outline-success btn-sm action-enable d-none">

            <i class="bi bi-check-circle"></i>
            Enable
        </button>

        <button
            class="btn btn-outline-warning btn-sm action-disable d-none">

            <i class="bi bi-pause-circle"></i>
            Disable
        </button>

        <button
            class="btn btn-outline-danger btn-sm action-delete d-none">

            <i class="bi bi-trash"></i>
            Delete
        </button>

        <input
            type="search"
            class="form-control form-control-sm mk-search ms-auto"
            id="search"
            placeholder="Search...">

    </div>

    <div class="mk-table-card">

        <div class="mk-table-wrap">

            <table class="table mk-table" id="dataTable">

                <thead id="tableHead"></thead>

                <tbody id="tableBody">
                    <tr>
                        <td>
                            <div class="mk-loading">
                                Pilih menu di sebelah kiri.
                            </div>
                        </td>
                    </tr>
                </tbody>

            </table>

        </div>

        <div class="mk-bottom d-flex align-items-center gap-2">

            <button
                class="btn btn-primary btn-sm action-add d-none">

                <i class="bi bi-plus-lg"></i>
                Add
            </button>

            <button
                class="btn btn-outline-primary btn-sm action-edit d-none">

                <i class="bi bi-pencil"></i>
                Edit
            </button>

            <button
                class="btn btn-outline-success btn-sm action-enable d-none">

                <i class="bi bi-check-circle"></i>
                Enable
            </button>

            <button
                class="btn btn-outline-warning btn-sm action-disable d-none">

                <i class="bi bi-pause-circle"></i>
                Disable
            </button>

            <button
                class="btn btn-outline-danger btn-sm action-delete d-none">

                <i class="bi bi-trash"></i>
                Delete
            </button>

            <div class="mk-info" id="rowInfo">
                0 item
            </div>

        </div>

    </div>

</section>

</main>

</div>

<!-- ADD / EDIT MODAL -->

<div
    class="modal fade"
    id="editModal"
    tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-scrollable">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title" id="modalTitle">
                    Edit
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal">
                </button>

            </div>

            <div class="modal-body">

                <form id="editForm">

                    <div id="formFields"></div>

                </form>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal">

                    Batal
                </button>

                <button
                    type="button"
                    class="btn btn-primary"
                    id="saveBtn">

                    Simpan
                </button>

            </div>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const routerId = <?= (int)$routerId ?>;
const canManage = <?= $canManage ? 'true' : 'false' ?>;
const csrfToken = <?= json_encode($csrfToken) ?>;

const menuConfig = <?= json_encode(
    $menus,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
) ?>;

let currentAction = <?= json_encode($initialAction, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let currentRows = [];
let selectedIndex = -1;
let modalAction = 'edit';

const tableHead = document.getElementById('tableHead');
const tableBody = document.getElementById('tableBody');
const pageTitle = document.getElementById('pageTitle');
const rowInfo = document.getElementById('rowInfo');
const search = document.getElementById('search');

const editModal = new bootstrap.Modal(
    document.getElementById('editModal')
);

function apiUrl(action) {

    const params = new URLSearchParams();

    params.set('router_id', String(routerId));
    params.set('action', action);

    return 'mikrotik_api.php?' + params.toString();
}

async function api(action, options = {}) {

    const response = await fetch(
        apiUrl(action),
        {
            ...options,
            credentials:'same-origin',
            cache:'no-store',
            headers:{
                'Accept':'application/json',
                'X-Requested-With':'XMLHttpRequest',
                ...(options.headers || {})
            }
        }
    );

    const text = await response.text();

    let data;

    try {
        data = JSON.parse(text);
    } catch(e) {
        throw new Error(
            'Response API bukan JSON. HTTP ' +
            response.status
        );
    }

    if (!response.ok || data.ok === false) {
        throw new Error(
            data.message ||
            data.error ||
            ('HTTP ' + response.status)
        );
    }

    return data;
}

function escapeHtml(value) {

    const div = document.createElement('div');

    div.textContent =
        value === null ||
        value === undefined
            ? ''
            : String(value);

    return div.innerHTML;
}

function normalizeRows(data) {

    if (Array.isArray(data)) {
        return data;
    }

    if (
        data &&
        typeof data === 'object'
    ) {
        return [data];
    }

    return [];
}

function statusHtml(value) {

    const v = String(value ?? '').toLowerCase();

    if (
        v === 'true' ||
        v === 'yes' ||
        v === 'up' ||
        v === 'running' ||
        v === 'connected'
    ) {
        return '<span class="mk-status up">' +
               '<i class="bi bi-circle-fill"></i> ACTIVE' +
               '</span>';
    }

    if (
        v === 'false' ||
        v === 'no' ||
        v === 'down' ||
        v === 'disabled'
    ) {
        return '<span class="mk-status down">' +
               '<i class="bi bi-circle-fill"></i> NONACTIVE' +
               '</span>';
    }

    return '<span class="mk-status unknown">' +
           escapeHtml(value ?? '-') +
           '</span>';
}

function parseRouterOSNumber(value) {
    if (value === null || value === undefined) return null;
    const s = String(value).trim();
    if (s === '') return null;
    const m = s.match(/^([0-9]+(?:\.[0-9]+)?)\s*([kKmMgGtTpPeE]?)(?:[bB])?$/);
    if (!m) return null;
    const n = Number(m[1]);
    const unit = m[2].toUpperCase();
    const powers = {'':0,'K':1,'M':2,'G':3,'T':4,'P':5,'E':6};
    return n * Math.pow(1000, powers[unit] || 0);
}

function formatRatePart(value) {
    if (value === null || value === undefined || value === '') return '-';
    const n = parseRouterOSNumber(value);
    if (n === null || !isFinite(n)) return escapeHtml(value);
    if (n >= 1000000000) return (n / 1000000000).toFixed(2).replace(/\.00$/, '') + ' Gbps';
    if (n >= 1000000) return (n / 1000000).toFixed(2).replace(/\.00$/, '') + ' Mbps';
    if (n >= 1000) return (n / 1000).toFixed(2).replace(/\.00$/, '') + ' Kbps';
    return n.toFixed(0) + ' bps';
}

function formatBytesPart(value) {
    if (value === null || value === undefined || value === '') return '-';
    const n = Number(String(value).trim());
    if (!isFinite(n)) return escapeHtml(value);
    if (n >= 1099511627776) return (n / 1099511627776).toFixed(2).replace(/\.00$/, '') + ' TB';
    if (n >= 1073741824) return (n / 1073741824).toFixed(2).replace(/\.00$/, '') + ' GB';
    if (n >= 1048576) return (n / 1048576).toFixed(2).replace(/\.00$/, '') + ' MB';
    if (n >= 1024) return (n / 1024).toFixed(2).replace(/\.00$/, '') + ' KB';
    return n.toFixed(0) + ' B';
}

function formatDualValue(value, type) {
    if (value === null || value === undefined || value === '') return '-';
    const raw = String(value).trim();
    if (raw.indexOf('/') !== -1) {
        const parts = raw.split('/');
        const left = type === 'rate' ? formatRatePart(parts[0]) : formatBytesPart(parts[0]);
        const right = type === 'rate' ? formatRatePart(parts[1]) : formatBytesPart(parts[1]);
        return left + ' ↓ / ' + right + ' ↑';
    }
    return type === 'rate' ? formatRatePart(raw) : formatBytesPart(raw);
}

function formatQueueValue(value, key) {
    const k = String(key || '').toLowerCase();

    if (k === 'max-limit' || k === 'limit-at' || k === 'rate' ||
        k === 'rx-rate' || k === 'tx-rate' ||
        k === 'rx-bits-per-second' || k === 'tx-bits-per-second' ||
        k.indexOf('rate') !== -1) {
        return formatDualValue(value, 'rate');
    }

    if (k === 'bytes' || k === 'total-bytes' || k === 'byte' ||
        k === 'rx-byte' || k === 'tx-byte') {
        return formatDualValue(value, 'bytes');
    }

    return formatValue(value);
}

function formatValue(value) {
    if (value === null || value === undefined || value === '') return '-';

    if (typeof value === 'boolean') {
        return value ? statusHtml('true') : statusHtml('false');
    }

    if (typeof value === 'object') {
        return escapeHtml(JSON.stringify(value));
    }

    const text = String(value);

    if (text === 'true' || text === 'false' || text === 'yes' ||
        text === 'no' || text === 'up' || text === 'down' ||
        text === 'running' || text === 'disabled') {
        return statusHtml(text);
    }

    return escapeHtml(text);
}

function columnsForRows(rows) {

    const columns = [];

    rows.forEach(row => {

        if (
            row &&
            typeof row === 'object' &&
            !Array.isArray(row)
        ) {

            Object.keys(row).forEach(key => {

                if (!columns.includes(key)) {
                    columns.push(key);
                }

            });

        }

    });

    return columns;
}

function renderTable(rows) {

    currentRows = rows;
    selectedIndex = -1;

    tableHead.innerHTML = '';
    tableBody.innerHTML = '';

    if (!rows.length) {

        tableBody.innerHTML =
            '<tr><td>' +
            '<div class="mk-empty">' +
            '<i class="bi bi-inbox fs-1 d-block mb-3"></i>' +
            'Tidak ada data.' +
            '</div>' +
            '</td></tr>';

        rowInfo.textContent = '0 item';

        return;
    }

    const columns =
        currentAction === 'queues'
            ? ['name', 'target', 'max-limit', 'bytes', 'packets', 'dropped', 'comment']
            : columnsForRows(rows);

    const tr = document.createElement('tr');

    tr.innerHTML =
        '<th style="width:42px">' +
        '<input type="checkbox" id="selectAll">' +
        '</th>' +
        columns.map(
            key => {
                const queueLabels = {
                    'name': 'Name',
                    'target': 'Target',
                    'max-limit': 'Max Limit',
                    'bytes': 'Bytes',
                    'packets': 'Packets',
                    'dropped': 'Drop',
                    'comment': 'Comment'
                };

                const label =
                    currentAction === 'queues' && queueLabels[key]
                        ? queueLabels[key]
                        : key;

                return '<th>' + escapeHtml(label) + '</th>';
            }
        ).join('');

    tableHead.appendChild(tr);

    rows.forEach((row, index) => {

        const tr = document.createElement('tr');

        tr.dataset.index = String(index);

        tr.innerHTML =
            '<td>' +
            '<input type="checkbox" class="row-check">' +
            '</td>' +
            columns.map(
                key =>
                    '<td>' +
                    (
                        currentAction === 'queues'
                            ? formatQueueValue(row[key], key)
                            : formatValue(row[key])
                    ) +
                    '</td>'
            ).join('');

        tr.addEventListener(
            'click',
            event => {

                if (
                    event.target.tagName === 'INPUT'
                ) {
                    selectedIndex = index;
                } else {
                    selectedIndex = index;

                    document
                        .querySelectorAll(
                            '#tableBody tr'
                        )
                        .forEach(x =>
                            x.classList.remove(
                                'selected'
                            )
                        );

                    tr.classList.add('selected');

                    document
                        .querySelector(
                            '.row-check'
                        );
                }

                updateActions();
            }
        );

        tableBody.appendChild(tr);
    });

    const selectAll =
        document.getElementById('selectAll');

    if (selectAll) {

        selectAll.addEventListener(
            'change',
            function() {

                document
                    .querySelectorAll('.row-check')
                    .forEach(cb => {
                        cb.checked = this.checked;
                    });

                updateActions();
            }
        );
    }

    document
        .querySelectorAll('.row-check')
        .forEach((cb, index) => {

            cb.addEventListener(
                'change',
                function() {

                    if (this.checked) {
                        selectedIndex = index;
                    }

                    updateActions();
                }
            );
        });

    rowInfo.textContent =
        rows.length + ' item';
}

function getSelectedIndexes() {

    const indexes = [];

    document
        .querySelectorAll('.row-check')
        .forEach((cb, index) => {

            if (cb.checked) {
                indexes.push(index);
            }

        });

    if (
        indexes.length === 0 &&
        selectedIndex >= 0
    ) {
        indexes.push(selectedIndex);
    }

    return indexes;
}

function getSelectedRows() {

    return getSelectedIndexes()
        .map(index => currentRows[index])
        .filter(Boolean);
}

function updateActions() {

    const menu =
        menuConfig[currentAction];

    if (!menu) return;

    const actions =
        menu.actions || [];

    [
        'add',
        'edit',
        'enable',
        'disable',
        'delete'
    ].forEach(action => {

        document
            .querySelectorAll(
                '.action-' + action
            )
            .forEach(btn => {

                const allowed =
                    canManage &&
                    actions.includes(action);

                btn.classList.toggle(
                    'd-none',
                    !allowed
                );

            });

    });

    const selected =
        getSelectedRows().length;

    document
        .querySelectorAll('.action-edit')
        .forEach(btn => {

            if (selected !== 1) {
                btn.classList.add('disabled');
            } else {
                btn.classList.remove('disabled');
            }

        });
}

async function loadAction(action) {

    if (!routerId) return;

    currentAction = action;

    const menu =
        menuConfig[action];

    if (!menu) return;

    pageTitle.textContent =
        menu.title;

    tableHead.innerHTML = '';

    tableBody.innerHTML =
        '<tr><td>' +
        '<div class="mk-loading">' +
        '<div class="spinner-border spinner-border-sm me-2"></div>' +
        'Memuat data...' +
        '</div>' +
        '</td></tr>';

    try {

        const response =
            await api(action);

        const data =
            response.data ??
            response.result ??
            response.rows ??
            response;

        renderTable(
            normalizeRows(data)
        );

    } catch(error) {

        tableBody.innerHTML =
            '<tr><td>' +
            '<div class="mk-empty text-danger">' +
            '<i class="bi bi-exclamation-triangle fs-2 d-block mb-3"></i>' +
            escapeHtml(error.message) +
            '</div>' +
            '</td></tr>';

        rowInfo.textContent =
            'API Error';
    }

    updateActions();

    if (currentAction === 'queues') {
        startQueueStatsAutoUpdate();
    } else {
        stopQueueStatsAutoUpdate();
    }
}

let queueStatsTimer = null;
let queueStatsBusy = false;

function stopQueueStatsAutoUpdate() {
    if (queueStatsTimer) {
        clearInterval(queueStatsTimer);
        queueStatsTimer = null;
    }
}

async function updateQueueStatsOnly() {
    if (!routerId || currentAction !== 'queues' || queueStatsBusy) return;

    queueStatsBusy = true;

    try {
        const response = await api('queues');
        const data = response.data ?? response.result ?? response.rows ?? response;
        const freshRows = normalizeRows(data);

        if (!freshRows.length) return;

        currentRows.forEach(row => {
            const id = row['.id'] ?? row['id'];

            const fresh = freshRows.find(item => {
                const freshId = item['.id'] ?? item['id'];

                if (id && freshId) {
                    return String(id) === String(freshId);
                }

                return String(item.name ?? '') === String(row.name ?? '') &&
                       String(item.target ?? '') === String(row.target ?? '');
            });

            if (!fresh) return;

            ['bytes', 'packets', 'dropped'].forEach(key => {
                if (Object.prototype.hasOwnProperty.call(fresh, key)) {
                    row[key] = fresh[key];
                }
            });
        });

        // Hanya memperbarui 3 kolom statistik: Bytes, Packets, Drop.
        document.querySelectorAll('#tableBody tr[data-index]').forEach(tr => {
            const index = Number(tr.dataset.index);
            const row = currentRows[index];
            if (!row) return;

            const cells = tr.querySelectorAll('td');

            // +1 karena kolom pertama adalah checkbox.
            const statColumns = {
                bytes: 4,
                packets: 5,
                dropped: 6
            };

            Object.keys(statColumns).forEach(key => {
                const cell = cells[statColumns[key]];
                if (cell) {
                    cell.innerHTML = formatQueueValue(row[key], key);
                }
            });
        });
    } catch (error) {
        // Jangan mengganggu tabel jika polling gagal sesaat.
    } finally {
        queueStatsBusy = false;
    }
}

function startQueueStatsAutoUpdate() {
    stopQueueStatsAutoUpdate();

    if (currentAction !== 'queues') return;

    updateQueueStatsOnly();

    queueStatsTimer = setInterval(
        updateQueueStatsOnly,
        1000
    );
}

function buildForm(row = {}) {

    const fields =
        Object.keys(row);

    if (!fields.length) {

        return `
            <div class="alert alert-info">
                Gunakan form API sesuai menu ini.
            </div>
        `;
    }

    return fields
        .filter(key =>
            ![
                '.id',
                'id',
                '0'
            ].includes(key)
        )
        .map(key => {

            const value =
                row[key] === null ||
                row[key] === undefined
                    ? ''
                    : row[key];

            return `
                <div class="mb-3">
                    <label class="form-label">
                        ${escapeHtml(key)}
                    </label>

                    <input
                        class="form-control"
                        name="${escapeHtml(key)}"
                        value="${escapeHtml(value)}">
                </div>
            `;

        })
        .join('');
}

function openEdit() {

    const selected =
        getSelectedRows();

    if (selected.length !== 1) {

        alert(
            'Pilih tepat satu item.'
        );

        return;
    }

    modalAction = 'edit';

    document.getElementById(
        'modalTitle'
    ).textContent =
        'Edit ' +
        (menuConfig[currentAction]?.title || '');

    document.getElementById(
        'formFields'
    ).innerHTML =
        buildForm(selected[0]);

    editModal.show();
}

function openAdd() {

    modalAction = 'add';

    const addFields = {
        interfaces: ['name', 'comment'],
        vlans: ['name', 'vlan-id', 'interface', 'comment'],
        ip_addresses: ['address', 'interface', 'comment'],
        routes: ['dst-address', 'gateway', 'distance', 'check-gateway', 'comment'],
        dhcp_servers: ['name', 'interface', 'address-pool', 'lease-time'],
        dhcp_leases: ['address', 'mac-address', 'server', 'comment'],
        pppoe: ['name', 'password', 'profile', 'service', 'comment'],
        ppp_profiles: ['name', 'local-address', 'remote-address', 'rate-limit'],
        hotspot: ['name', 'password', 'profile', 'limit-uptime', 'comment'],
        hotspot_servers: ['name', 'interface', 'address-pool', 'profile'],
        queues: ['name', 'target', 'max-limit', 'bytes', 'packets', 'dropped', 'comment'],
        firewall_filter: ['chain', 'action', 'protocol', 'src-address', 'dst-address', 'src-port', 'dst-port', 'comment'],
        firewall_nat: ['chain', 'action', 'protocol', 'src-address', 'dst-address', 'to-addresses', 'to-ports', 'comment'],
        firewall_mangle: ['chain', 'action', 'protocol', 'src-address', 'dst-address', 'new-connection-mark', 'new-routing-mark', 'comment'],
        firewall_address_list: ['list', 'address', 'timeout', 'comment'],
        firewall_raw: ['chain', 'action', 'protocol', 'src-address', 'dst-address', 'comment'],
        ip_pools: ['name', 'ranges'],
        dhcp_networks: ['address', 'gateway', 'dns-server', 'comment'],
        interface_lists: ['name', 'comment'],
        users: ['name', 'group', 'password', 'address', 'comment'],
        dns: ['servers', 'allow-remote-requests', 'cache-size'],
        identity: ['name']
    };

    const fields = addFields[currentAction] || ['name', 'comment'];

    document.getElementById(
        'modalTitle'
    ).textContent =
        'Add ' +
        (menuConfig[currentAction]?.title || '');

    document.getElementById(
        'formFields'
    ).innerHTML = fields.map(key => `
        <div class="mb-3">
            <label class="form-label">${escapeHtml(key)}</label>
            <input class="form-control" name="${escapeHtml(key)}">
        </div>
    `).join('');

    editModal.show();
}

async function executeAction(action) {

    if (!canManage) {
        alert('Anda tidak memiliki permission.');
        return;
    }

    const selected =
        getSelectedRows();

    if (
        ['edit'].includes(action) &&
        selected.length !== 1
    ) {
        alert('Pilih satu item.');
        return;
    }

    if (
        ['enable','disable','delete'].includes(action) &&
        selected.length < 1
    ) {
        alert('Pilih minimal satu item.');
        return;
    }

    if (
        action === 'delete' &&
        !confirm(
            'Hapus ' +
            selected.length +
            ' item yang dipilih?'
        )
    ) {
        return;
    }

    /*
     * Mutation endpoint tetap melalui
     * mikrotik_api.php dan session BAJAMA.
     */

    try {

        const payload = new URLSearchParams();

        payload.set(
            'router_id',
            String(routerId)
        );

        payload.set(
            'item_action',
            action
        );

        payload.set(
            'resource',
            currentAction
        );

        const selectedId =
            selected[0]?.['.id'] ||
            selected[0]?.id ||
            '';

        payload.set(
            'item_id',
            String(selectedId)
        );

        payload.set(
            'csrf',
            csrfToken
        );

        payload.set(
            'items',
            JSON.stringify(selected)
        );

        const response =
            await fetch(
                'mikrotik_api.php',
                {
                    method:'POST',
                    credentials:'same-origin',
                    headers:{
                        'Accept':'application/json',
                        'X-Requested-With':
                            'XMLHttpRequest',
                        'Content-Type':
                            'application/x-www-form-urlencoded'
                    },
                    body:payload.toString()
                }
            );

        const text =
            await response.text();

        let data;

        try {
            data = JSON.parse(text);
        } catch(e) {
            throw new Error(
                'API mutation bukan JSON.'
            );
        }

        if (
            !response.ok ||
            data.ok === false
        ) {
            throw new Error(
                data.message ||
                data.error ||
                'Action gagal.'
            );
        }

        editModal.hide();

        await loadAction(
            currentAction
        );

    } catch(error) {

        alert(
            'Gagal: ' +
            error.message
        );
    }
}

async function saveItem() {

    if (!canManage) {
        alert('Anda tidak memiliki permission.');
        return;
    }

    const selected = getSelectedRows();
    const selectedId = selected[0]?.['.id'] || selected[0]?.id || '';
    const formData = new FormData(document.getElementById('editForm'));
    const attributes = {};

    formData.forEach((value, key) => {
        if (key !== '.id' && key !== 'id') {
            attributes[key] = value;
        }
    });

    if (modalAction === 'edit' && !selectedId) {
        alert('ID item RouterOS tidak ditemukan.');
        return;
    }

    try {
        const payload = new URLSearchParams();
        payload.set('router_id', String(routerId));
        payload.set('item_action', modalAction);
        payload.set('resource', currentAction);
        payload.set('item_id', String(selectedId));
        payload.set('csrf', csrfToken);
        payload.set('attributes', JSON.stringify(attributes));

        const response = await fetch('mikrotik_api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: payload.toString()
        });

        const data = await response.json();
        if (!response.ok || data.ok === false) {
            throw new Error(data.message || 'Penyimpanan RouterOS gagal.');
        }

        editModal.hide();
        await loadAction(currentAction);
    } catch (error) {
        alert('Gagal: ' + error.message);
    }
}

document.getElementById('saveBtn').addEventListener('click', saveItem);

const routerSelector =
    document.getElementById('routerSelector');

if (routerSelector) {

    routerSelector.addEventListener(
        'change',
        function() {

            const id =
                parseInt(this.value || '0', 10);

            if (!id) {
                return;
            }

            const url =
                new URL(
                    window.location.href
                );

            url.searchParams.set(
                'router_id',
                String(id)
            );

            window.location.href =
                url.toString();
        }
    );
}

document
    .querySelectorAll('.action-add')
    .forEach(btn =>
        btn.addEventListener(
            'click',
            openAdd
        )
    );

document
    .querySelectorAll('.action-edit')
    .forEach(btn =>
        btn.addEventListener(
            'click',
            openEdit
        )
    );

[
    'enable',
    'disable',
    'delete'
].forEach(action => {

    document
        .querySelectorAll(
            '.action-' + action
        )
        .forEach(btn =>
            btn.addEventListener(
                'click',
                () =>
                    executeAction(action)
            )
        );

});

document
    .getElementById('refreshBtn')
    .addEventListener(
        'click',
        () => {

            if (currentAction) {
                loadAction(
                    currentAction
                );
            }

        }
    );

const sidebar =
    document.getElementById('sidebar');

const mobileMenu =
    document.getElementById('mobileMenu');

const sidebarOverlay =
    document.getElementById('sidebarOverlay');

function openSidebar() {

    sidebar.classList.add('open');

    if (sidebarOverlay) {
        sidebarOverlay.classList.add('show');
    }
}

function closeSidebar() {

    sidebar.classList.remove('open');

    if (sidebarOverlay) {
        sidebarOverlay.classList.remove('show');
    }
}

mobileMenu.addEventListener(
    'click',
    function(event) {

        event.preventDefault();
        event.stopPropagation();

        if (
            sidebar.classList.contains('open')
        ) {
            closeSidebar();
        } else {
            openSidebar();
        }

    }
);

if (sidebarOverlay) {

    sidebarOverlay.addEventListener(
        'click',
        closeSidebar
    );
}

search.addEventListener(
    'input',
    function() {

        const q =
            this.value
                .trim()
                .toLowerCase();

        if (!q) {
            renderTable(currentRows);
            return;
        }

        const filtered =
            currentRows.filter(row =>
                JSON.stringify(row)
                    .toLowerCase()
                    .includes(q)
            );

        renderTable(filtered);
    }
);

window.addEventListener(
    'resize',
    function() {

        if (window.innerWidth > 900) {
            closeSidebar();
        }

    }
);

document.addEventListener(
    'keydown',
    event => {

        if (
            event.key === 'Escape'
        ) {
            closeSidebar();
        }

    }
);

/*
|--------------------------------------------------------------------------
| Dashboard awal
|--------------------------------------------------------------------------
*/

const firstMenu =
    Object.keys(menuConfig)[0];

if (
    !currentAction ||
    !menuConfig[currentAction]
) {
    currentAction = firstMenu || '';
}

if (currentAction) {
    loadAction(currentAction);
}
window.addEventListener('beforeunload', stopQueueStatsAutoUpdate);
</script>

</body>
</html>
