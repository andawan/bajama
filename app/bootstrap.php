<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';

require_once __DIR__ . '/Core/Auth.php';
require_once __DIR__ . '/Core/I18n.php';
require_once __DIR__ . '/Core/Tenant.php';
require_once __DIR__ . '/Core/RBAC.php';
require_once __DIR__ . '/Core/License.php';
require_once __DIR__ . '/Core/Audit.php';
require_once __DIR__ . '/Core/Mailer.php';
require_once __DIR__ . '/Core/Otp.php';
require_once __DIR__ . '/Core/PaymentProof.php';
require_once __DIR__ . '/Network/RouterOSApi.php';
require_once __DIR__ . '/Network/MikroTik.php';
require_once __DIR__ . '/Network/RouterOsLiveData.php';
require_once __DIR__ . '/Network/MikroTikSyncService.php';
require_once __DIR__ . '/Network/ProvisioningService.php';
require_once __DIR__ . '/Network/MikroTikMenuManager.php';
require_once __DIR__ . '/Network/MikroTikMenuPermissionService.php';
require_once __DIR__ . '/Network/MikroTikPermissionGuard.php';
require_once __DIR__ . '/Network/MikroTikResourceLinkService.php';
require_once __DIR__ . '/Network/RouterOSProvisioningService.php';
require_once __DIR__ . '/Traffic/TrafficCatalog.php';
require_once __DIR__ . '/Traffic/TrafficDnsEvidenceResolver.php';
require_once __DIR__ . '/Traffic/TrafficObservationCollector.php';
require_once __DIR__ . '/Traffic/TrafficDiscoveryService.php';
require_once __DIR__ . '/Traffic/TrafficRealtimeService.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\Tenant;
use BAJAMA\Core\RBAC;
use BAJAMA\Core\License;
use BAJAMA\Core\Audit;

$db = db();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
 * Semua halaman HTML customer-facing memakai penerjemah yang sama.
 * Endpoint data/binary tidak terpengaruh karena injeksi hanya dilakukan
 * jika respons memiliki tag </body>.
 */
if (PHP_SAPI !== 'cli') {
    $bajamaLocale = \BAJAMA\Core\I18n::locale();
    $i18nScriptVersion = (string) @filemtime(__DIR__ . '/../public/assets/js/customer-i18n.js');
    ob_start(static function (string $output) use ($bajamaLocale, $i18nScriptVersion): string {
        if (stripos($output, '</body>') === false || stripos($output, '<html') === false) {
            return $output;
        }

        $script = '<script>window.BAJAMA_LOCALE = ' . json_encode($bajamaLocale, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>'
            . '<script src="assets/js/customer-i18n.js?v=' . rawurlencode($i18nScriptVersion) . '"></script>';
        $output = preg_replace('/(<html\b[^>]*\blang=)["\']id["\']/i', '$1"' . $bajamaLocale . '"', $output, 1) ?: $output;
        return str_ireplace('</body>', $script . '</body>', $output);
    });
}
