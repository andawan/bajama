<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Audit;
use BAJAMA\Core\Auth;
use BAJAMA\Core\License;
use BAJAMA\Core\RBAC;
use BAJAMA\Core\Tenant;
use BAJAMA\Network\RouterOSProvisioningService;

Auth::requireLogin();
$db = db();
License::requireFeature($db, 'mikrotik');
RBAC::require($db, 'network.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method harus POST.');
}

verify_csrf((string)($_POST['_csrf'] ?? $_POST['csrf'] ?? ''));

$sourceType = trim((string)($_POST['source_type'] ?? ''));
$sourceId = (int)($_POST['source_id'] ?? 0);
$action = trim((string)($_POST['routeros_action'] ?? ''));
$allowedTypes = [
    'network_isp',
    'network_lan',
    'network_route',
    'network_firewall',
    'network_static_ip',
    'network_hotspot_voucher',
    'network_load_balance',
    'network_ftth',
    'network_olt',
];

if (!in_array($sourceType, $allowedTypes, true) || $sourceId <= 0) {
    http_response_code(422);
    exit('Entitas jaringan tidak valid.');
}

if (!in_array($action, ['apply', 'enable', 'disable', 'remove'], true)) {
    http_response_code(422);
    exit('Aksi RouterOS tidak valid.');
}

try {
    $service = new RouterOSProvisioningService($db, (int)Tenant::id());
    if ($action === 'apply') {
        $result = $service->apply($sourceType, $sourceId);
    } elseif ($action === 'enable' || $action === 'disable') {
        $result = $service->setEnabled($sourceType, $sourceId, $action === 'enable');
    } else {
        $result = $service->remove($sourceType, $sourceId);
    }

    Audit::log($db, 'routeros_action', 'network', $sourceType, $sourceId, [
        'action' => $action,
    ]);

    $returnTo = trim((string)($_POST['return_to'] ?? ''));
    if ($returnTo === '' || strpos($returnTo, '/') !== false || strpos($returnTo, ':') !== false) {
        $returnTo = 'dashboard.php';
    }

    $separator = strpos($returnTo, '?') === false ? '?' : '&';
    header('Location: ' . $returnTo . $separator . 'routeros_success=' . rawurlencode($action));
    exit;
} catch (Throwable $e) {
    http_response_code(400);
    exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
