<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\License;
use BAJAMA\Core\RBAC;
use BAJAMA\Network\MikroTikMenuPermissionService;

Auth::requireLogin();

$db = db();

$organizationId = (int) Auth::organizationId();
$currentUserId  = (int) Auth::userId();

if ($organizationId <= 0 || $currentUserId <= 0) {
    http_response_code(403);
    exit('Organization atau user tidak valid.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method tidak diizinkan.');
}

if (!RBAC::hasPermission($db, 'mikrotik.manage')) {
    http_response_code(403);
    exit('Anda tidak memiliki permission mikrotik.manage.');
}

if (!License::hasFeature($db, 'mikrotik')) {
    http_response_code(403);
    exit('Fitur MikroTik tidak aktif.');
}

/*
 * CSRF
 *
 * Gunakan mekanisme CSRF pusat BAJAMA.
 */
$postToken = (string) (
    $_POST['_csrf']
    ?? $_POST['csrf']
    ?? ''
);

if (function_exists('verify_csrf')) {
    verify_csrf($postToken);
} elseif (function_exists('verifyCsrf')) {
    verifyCsrf($postToken);
} else {
    http_response_code(500);
    exit('Proteksi CSRF tidak tersedia.');
}

/*
 * Target router dan user.
 */
$routerId = (int) (
    $_POST['router_id']
    ?? 0
);

$targetUserId = (int) (
    $_POST['user_id']
    ?? 0
);

if ($routerId <= 0 || $targetUserId <= 0) {
    http_response_code(400);
    exit('Router atau user tidak valid.');
}

/*
 * Permission matrix.
 *
 * Tidak boleh menerima data selain array.
 */
$permissions = $_POST['permissions'] ?? [];

if (!is_array($permissions)) {
    http_response_code(400);
    exit('Format permission tidak valid.');
}

/*
 * Gunakan service pusat.
 *
 * Endpoint ini TIDAK melakukan INSERT/UPDATE permission
 * secara langsung.
 */
$service = new MikroTikMenuPermissionService($db);

try {

    if (!$service->validateContext(
        $organizationId,
        $targetUserId,
        $routerId
    )) {
        http_response_code(403);
        exit(
            'User dan MikroTik tidak berada pada organization yang sama.'
        );
    }

    /*
     * Simpan seluruh matrix dalam satu transaction.
     */
    $savedCount = $service->saveAll(
        $organizationId,
        $targetUserId,
        $routerId,
        $permissions
    );

    /*
     * Audit.
     *
     * Permission tetap disimpan melalui
     * MikroTikMenuPermissionService.
     */
    try {
        if (class_exists('\\BAJAMA\\Core\\Audit')) {
            \BAJAMA\Core\Audit::log(
                $db,
                'MIKROTIK_MENU_PERMISSIONS_UPDATED',
                'mikrotik',
                'router',
                $routerId,
                [
                    'target_user_id' => $targetUserId,
                    'saved_count' => $savedCount
                ]
            );
        }
    } catch (\Throwable $auditError) {
        /*
         * Kegagalan audit tidak membatalkan
         * permission yang sudah tersimpan.
         */
        error_log(
            'BAJAMA audit permission error: '
            . $auditError->getMessage()
        );
    }

    /*
     * Redirect kembali ke matrix.
     */
    header(
        'Location: mikrotik_menu_permissions.php'
        . '?router_id=' . rawurlencode((string) $routerId)
        . '&user_id=' . rawurlencode((string) $targetUserId)
        . '&saved=1'
    );

    exit;

} catch (\Throwable $e) {

    error_log(
        'BAJAMA MikroTik permission save error: '
        . $e->getMessage()
    );

    http_response_code(500);

    exit(
        'Gagal menyimpan permission MikroTik.'
    );
}
