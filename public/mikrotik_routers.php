<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\License;
use BAJAMA\Core\RBAC;
use BAJAMA\Core\Tenant;
use BAJAMA\Network\MikroTik;

Auth::requireLogin();

$db = db();

License::requireFeature($db, 'mikrotik');

RBAC::require(
    $db,
    'mikrotik.view'
);

$organizationId = Tenant::id();

$canManage = RBAC::hasPermission(
    $db,
    'mikrotik.manage'
);

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

$routers = MikroTik::all(
    $db,
    $organizationId
);

$selectedRouterId =
    (int)($_GET['router_id'] ?? 0);

$message = '';
$error = '';

/*
|--------------------------------------------------------------------------
| POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        if (!$canManage) {
            throw new RuntimeException(
                'Anda tidak memiliki permission untuk mengelola MikroTik.'
            );
        }

        if (function_exists('verify_csrf')) {

            verify_csrf(
                (string)(
                    $_POST['csrf']
                    ?? ''
                )
            );

        } elseif (function_exists('verifyCsrf')) {

            verifyCsrf(
                (string)(
                    $_POST['csrf']
                    ?? ''
                )
            );

        } else {

            throw new RuntimeException(
                'Proteksi CSRF tidak tersedia.'
            );
        }

        $action =
            (string)(
                $_POST['action']
                ?? ''
            );

        /*
        |--------------------------------------------------------------------------
        | ADD
        |--------------------------------------------------------------------------
        */

        if ($action === 'add') {

            $name =
                trim(
                    (string)(
                        $_POST['name']
                        ?? ''
                    )
                );

            $host =
                trim(
                    (string)(
                        $_POST['host']
                        ?? ''
                    )
                );

            $port =
                (int)(
                    $_POST['port']
                    ?? 8728
                );

            $username =
                trim(
                    (string)(
                        $_POST['username']
                        ?? ''
                    )
                );

            $password =
                (string)(
                    $_POST['password']
                    ?? ''
                );

            $useSsl =
                !empty(
                    $_POST['use_ssl']
                )
                    ? 1
                    : 0;

            $timeout =
                (float)(
                    $_POST['timeout_seconds']
                    ?? 8
                );

            if ($name === '') {
                throw new RuntimeException(
                    'Nama router wajib diisi.'
                );
            }

            if ($host === '') {
                throw new RuntimeException(
                    'Host/IP MikroTik wajib diisi.'
                );
            }

            if ($port < 1 || $port > 65535) {
                throw new RuntimeException(
                    'Port tidak valid.'
                );
            }

            if ($username === '') {
                throw new RuntimeException(
                    'Username wajib diisi.'
                );
            }

            if ($password === '') {
                throw new RuntimeException(
                    'Password wajib diisi.'
                );
            }

            if (
                $timeout < 1 ||
                $timeout > 60
            ) {
                throw new RuntimeException(
                    'Timeout harus antara 1 sampai 60 detik.'
                );
            }

            $encrypted =
                MikroTik::encryptPassword(
                    $password
                );

            $stmt = $db->prepare(
                'INSERT INTO mikrotik_routers
                (
                    organization_id,
                    name,
                    host,
                    port,
                    username,
                    password_encrypted,
                    use_ssl,
                    timeout_seconds,
                    status
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    "UNKNOWN"
                )'
            );

            $stmt->execute([
                $organizationId,
                $name,
                $host,
                $port,
                $username,
                $encrypted,
                $useSsl,
                $timeout
            ]);

            $newId =
                (int)$db->lastInsertId();

            /*
             * Test otomatis setelah Add.
             */

            $router =
                MikroTik::find(
                    $db,
                    $organizationId,
                    $newId
                );

            if ($router) {

                $test =
                    MikroTik::test(
                        $db,
                        $router
                    );

                if ($test['ok'] ?? false) {

                    $message =
                        'Router berhasil ditambahkan dan koneksi berhasil.';

                } else {

                    $message =
                        'Router berhasil ditambahkan, tetapi koneksi gagal: ' .
                        ($test['message'] ?? 'Unknown error');
                }

            } else {

                $message =
                    'Router berhasil ditambahkan.';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | EDIT
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'edit') {

            $id =
                (int)(
                    $_POST['id']
                    ?? 0
                );

            if ($id <= 0) {
                throw new RuntimeException(
                    'Router tidak valid.'
                );
            }

            $router =
                MikroTik::find(
                    $db,
                    $organizationId,
                    $id
                );

            if (!$router) {
                throw new RuntimeException(
                    'Router tidak ditemukan.'
                );
            }

            $name =
                trim(
                    (string)(
                        $_POST['name']
                        ?? ''
                    )
                );

            $host =
                trim(
                    (string)(
                        $_POST['host']
                        ?? ''
                    )
                );

            $port =
                (int)(
                    $_POST['port']
                    ?? 8728
                );

            $username =
                trim(
                    (string)(
                        $_POST['username']
                        ?? ''
                    )
                );

            $password =
                (string)(
                    $_POST['password']
                    ?? ''
                );

            $useSsl =
                !empty(
                    $_POST['use_ssl']
                )
                    ? 1
                    : 0;

            $timeout =
                (float)(
                    $_POST['timeout_seconds']
                    ?? 8
                );

            if ($name === '') {
                throw new RuntimeException(
                    'Nama router wajib diisi.'
                );
            }

            if ($host === '') {
                throw new RuntimeException(
                    'Host/IP MikroTik wajib diisi.'
                );
            }

            if ($port < 1 || $port > 65535) {
                throw new RuntimeException(
                    'Port tidak valid.'
                );
            }

            if ($username === '') {
                throw new RuntimeException(
                    'Username wajib diisi.'
                );
            }

            if (
                $timeout < 1 ||
                $timeout > 60
            ) {
                throw new RuntimeException(
                    'Timeout harus antara 1 sampai 60 detik.'
                );
            }

            /*
             * Password kosong berarti
             * pertahankan password lama.
             */

            if ($password !== '') {

                $encrypted =
                    MikroTik::encryptPassword(
                        $password
                    );

                $sql =
                    'UPDATE mikrotik_routers
                     SET
                        name=?,
                        host=?,
                        port=?,
                        username=?,
                        password_encrypted=?,
                        use_ssl=?,
                        timeout_seconds=?,
                        status="UNKNOWN",
                        last_error=NULL
                     WHERE id=?
                       AND organization_id=?';

                $params = [
                    $name,
                    $host,
                    $port,
                    $username,
                    $encrypted,
                    $useSsl,
                    $timeout,
                    $id,
                    $organizationId
                ];

            } else {

                $sql =
                    'UPDATE mikrotik_routers
                     SET
                        name=?,
                        host=?,
                        port=?,
                        username=?,
                        use_ssl=?,
                        timeout_seconds=?,
                        status="UNKNOWN",
                        last_error=NULL
                     WHERE id=?
                       AND organization_id=?';

                $params = [
                    $name,
                    $host,
                    $port,
                    $username,
                    $useSsl,
                    $timeout,
                    $id,
                    $organizationId
                ];
            }

            $stmt =
                $db->prepare($sql);

            $stmt->execute($params);

            /*
             * Test ulang setelah edit.
             */

            $updated =
                MikroTik::find(
                    $db,
                    $organizationId,
                    $id
                );

            if ($updated) {

                $test =
                    MikroTik::test(
                        $db,
                        $updated
                    );

                if ($test['ok'] ?? false) {

                    $message =
                        'Router berhasil diperbarui dan koneksi berhasil.';

                } else {

                    $message =
                        'Router diperbarui, tetapi koneksi gagal: ' .
                        ($test['message'] ?? 'Unknown error');
                }

            } else {

                $message =
                    'Router berhasil diperbarui.';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | TEST
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'test') {

            $id =
                (int)(
                    $_POST['id']
                    ?? 0
                );

            $router =
                MikroTik::find(
                    $db,
                    $organizationId,
                    $id
                );

            if (!$router) {
                throw new RuntimeException(
                    'Router tidak ditemukan.'
                );
            }

            $result =
                MikroTik::test(
                    $db,
                    $router
                );

            if ($result['ok'] ?? false) {

                $identity =
                    $result['identity'][0]
                    ?? [];

                $identityName =
                    $identity['name']
                    ?? 'MikroTik';

                $message =
                    'Koneksi berhasil. Identity: ' .
                    $identityName;

            } else {

                $error =
                    'Koneksi gagal: ' .
                    ($result['message'] ?? 'Unknown error');
            }
        }

        /*
        |--------------------------------------------------------------------------
        | ENABLE
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'enable') {

            $id =
                (int)(
                    $_POST['id']
                    ?? 0
                );

            $router =
                MikroTik::find(
                    $db,
                    $organizationId,
                    $id
                );

            if (!$router) {
                throw new RuntimeException(
                    'Router tidak ditemukan.'
                );
            }

            $stmt =
                $db->prepare(
                    'UPDATE mikrotik_routers
                     SET status="UNKNOWN",
                         last_error=NULL
                     WHERE id=?
                       AND organization_id=?'
                );

            $stmt->execute([
                $id,
                $organizationId
            ]);

            $message =
                'Router diaktifkan. Silakan Test Connection.';
        }

        /*
        |--------------------------------------------------------------------------
        | DISABLE
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'disable') {

            $id =
                (int)(
                    $_POST['id']
                    ?? 0
                );

            $stmt =
                $db->prepare(
                    'UPDATE mikrotik_routers
                     SET status="DISABLED"
                     WHERE id=?
                       AND organization_id=?'
                );

            $stmt->execute([
                $id,
                $organizationId
            ]);

            $message =
                'Router berhasil dinonaktifkan.';
        }

        /*
        |--------------------------------------------------------------------------
        | DELETE
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'delete') {

            $id =
                (int)(
                    $_POST['id']
                    ?? 0
                );

            if ($id <= 0) {
                throw new RuntimeException(
                    'Router tidak valid.'
                );
            }

            $router =
                MikroTik::find(
                    $db,
                    $organizationId,
                    $id
                );

            if (!$router) {
                throw new RuntimeException(
                    'Router tidak ditemukan.'
                );
            }

            $stmt =
                $db->prepare(
                    'DELETE FROM mikrotik_routers
                     WHERE id=?
                       AND organization_id=?'
                );

            $stmt->execute([
                $id,
                $organizationId
            ]);

            $message =
                'Router berhasil dihapus.';
        }

    } catch (\Throwable $e) {

        $error =
            $e->getMessage();
    }

    /*
     * Redirect POST -> GET
     */

    $params = [];

    if ($message !== '') {
        $params['msg'] = $message;
    }

    if ($error !== '') {
        $params['error'] = $error;
    }

    if ($selectedRouterId > 0) {
        $params['router_id'] =
            $selectedRouterId;
    }

    $location =
        'mikrotik_routers.php';

    if (!empty($params)) {
        $location .= '?' .
            http_build_query($params);
    }

    header(
        'Location: ' . $location
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Refresh data
|--------------------------------------------------------------------------
*/

$routers =
    MikroTik::all(
        $db,
        $organizationId
    );

/*
|--------------------------------------------------------------------------
| Adaptive Sync UI Data
|--------------------------------------------------------------------------
| Halaman hanya membaca hasil scan yang tersimpan di database.
| Tidak ada koneksi langsung ke MikroTik dari UI.
|--------------------------------------------------------------------------
*/

$syncStates = [];
$syncSnapshots = [];

/*
 * Status sinkronisasi setiap router.
 */
$stmt = $db->prepare(
    'SELECT
        router_id,
        status,
        first_sync_at,
        last_sync_started_at,
        last_sync_at,
        last_success_at,
        last_error_at,
        last_duration_ms,
        total_scans,
        successful_scans,
        failed_scans,
        last_error
     FROM mikrotik_sync_state
     WHERE organization_id = ?'
);

$stmt->execute([
    $organizationId
]);

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $syncStates[(int)$row['router_id']] = $row;
}

/*
 * Snapshot terbaru untuk setiap router + kategori.
 */
$stmt = $db->prepare(
    'SELECT
        s.router_id,
        s.category,
        s.status,
        s.item_count,
        s.scanned_at,
        s.duration_ms,
        s.error_message
     FROM mikrotik_sync_snapshots s
     INNER JOIN (
        SELECT
            router_id,
            category,
            MAX(id) AS max_id
        FROM mikrotik_sync_snapshots
        WHERE organization_id = ?
        GROUP BY router_id, category
     ) latest
        ON latest.max_id = s.id
     WHERE s.organization_id = ?
     ORDER BY s.router_id, s.category'
);

$stmt->execute([
    $organizationId,
    $organizationId
]);

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {

    $routerId = (int)$row['router_id'];

    if (!isset($syncSnapshots[$routerId])) {
        $syncSnapshots[$routerId] = [];
    }

    $syncSnapshots[$routerId][$row['category']] = $row;
}

if (
    isset($_GET['msg']) &&
    $_GET['msg'] !== ''
) {
    $message =
        (string)$_GET['msg'];
}

if (
    isset($_GET['error']) &&
    $_GET['error'] !== ''
) {
    $error =
        (string)$_GET['error'];
}
?>
<!doctype html>
<html lang="id">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1">

<title>
    MikroTik Router Management - BAJAMA
</title>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet">

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
    rel="stylesheet">

<link href="assets/css/bajama.css" rel="stylesheet">

<style>

/* =========================================================
   BAJAMA - MIKROTIK ROUTER MANAGEMENT
   UI ONLY
   Tidak mengubah PHP / database / JavaScript / permission
   ========================================================= */

:root {
    --br-bg: #f4f7fb;
    --br-surface: #ffffff;
    --br-surface-soft: #f8fafc;
    --br-border: #e2e8f0;
    --br-border-soft: #eef2f7;

    --br-text: #172033;
    --br-muted: #64748b;

    --br-primary: #2563eb;
    --br-primary-dark: #1d4ed8;

    --br-success: #15803d;
    --br-success-bg: #dcfce7;

    --br-danger: #b91c1c;
    --br-danger-bg: #fee2e2;

    --br-warning: #b45309;
    --br-warning-bg: #fef3c7;

    --br-radius: 14px;
    --br-shadow:
        0 2px 8px rgba(15, 23, 42, .035);
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
    background: var(--br-bg);
    color: var(--br-text);

    font-family:
        Inter,
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
}


/* =========================================================
   MAIN PAGE
   ========================================================= */

.page-wrap,
.bajama-router-management-page {
    margin-left: 260px;

    width: calc(100% - 260px);

    min-height: 100vh;

    padding: 20px;
}


/* =========================================================
   TOP HEADER
   ========================================================= */

.top-card {
    position: relative;

    width: 100%;

    margin-bottom: 14px;
    padding: 17px 18px;

    background: var(--br-surface);

    border: 1px solid var(--br-border);
    border-radius: var(--br-radius);

    box-shadow: var(--br-shadow);
}

.bajama-router-management-page .top-card {
    border-radius: var(--br-radius);
}


/* Header title */

.top-card h4 {
    margin: 0;

    color: var(--br-text);

    font-size: 18px;
    font-weight: 750;
    line-height: 1.3;

    letter-spacing: -.015em;
}

.top-card h4 i {
    margin-right: 5px;

    color: var(--br-primary);
}

.top-card .text-secondary {
    color: var(--br-muted) !important;

    font-size: 12px;
    line-height: 1.45;
}


/* Back button */

.top-card .btn-light {
    width: 34px;
    height: 34px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    padding: 0;

    border: 1px solid var(--br-border);
    border-radius: 8px;

    background: #fff;

    color: #475569;
}

.top-card .btn-light:hover {
    background: #f8fafc;
    color: var(--br-primary);
    border-color: #cbd5e1;
}


/* Header buttons */

.top-card .btn {
    min-height: 36px;

    border-radius: 8px;

    font-size: 12px;
    font-weight: 650;
}

.top-card .btn-outline-primary {
    border-color: #bfdbfe;
    color: var(--br-primary);
}

.top-card .btn-outline-primary:hover {
    background: #eff6ff;
    border-color: #93c5fd;
    color: var(--br-primary-dark);
}

.top-card .btn-primary {
    background: var(--br-primary);
    border-color: var(--br-primary);
}

.top-card .btn-primary:hover {
    background: var(--br-primary-dark);
    border-color: var(--br-primary-dark);
}


/* =========================================================
   ROUTER DASHBOARD
   ========================================================= */

.router-dashboard {
    display: grid;

    gap: 14px;

    padding: 0;
}


/* =========================================================
   ROUTER CARD
   ========================================================= */

.router-dashboard-card,
.router-card {
    width: 100%;

    background: var(--br-surface);

    border: 1px solid var(--br-border);
    border-radius: var(--br-radius);

    overflow: hidden;

    box-shadow: var(--br-shadow);

    transition:
        border-color .16s ease,
        box-shadow .16s ease,
        transform .16s ease;
}

.router-dashboard-card:hover {
    border-color: #d4dce7;

    box-shadow:
        0 6px 20px rgba(15, 23, 42, .055);

    transform: translateY(-1px);
}


/* =========================================================
   ROUTER CARD HEADER
   ========================================================= */

.router-card-header {
    display: flex;

    align-items: center;
    justify-content: space-between;

    gap: 15px;

    padding: 16px 18px;

    border-bottom: 1px solid var(--br-border-soft);

    background:
        linear-gradient(
            180deg,
            #ffffff 0%,
            #fafcff 100%
        );
}

.router-card-identity {
    display: flex;

    align-items: center;

    gap: 12px;

    min-width: 0;
}

.router-icon {
    width: 44px;
    height: 44px;

    min-width: 44px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 11px;

    background: #eff6ff;
    color: var(--br-primary);

    font-size: 20px;

    border: 1px solid #dbeafe;
}

.router-card-name {
    min-width: 0;

    color: var(--br-text);

    font-size: 16px;
    font-weight: 750;
    line-height: 1.3;

    word-break: break-word;
}

.router-card-endpoint {
    display: flex;

    align-items: center;

    gap: 5px;

    flex-wrap: wrap;

    margin-top: 4px;

    color: var(--br-muted);

    font-size: 11px;
}

.router-card-endpoint i {
    color: #94a3b8;
}

.mini-badge {
    display: inline-flex;
    align-items: center;

    padding: 3px 7px;

    border-radius: 999px;

    background: #e0f2fe;
    color: #0369a1;

    font-size: 9px;
    font-weight: 750;
}


/* =========================================================
   STATUS
   ========================================================= */

.router-card-status {
    display: inline-flex;

    align-items: center;

    gap: 7px;

    padding: 6px 10px;

    border: 1px solid var(--br-border);

    border-radius: 999px;

    background: #f8fafc;

    white-space: nowrap;
}

.router-status-dot {
    width: 8px;
    height: 8px;

    min-width: 8px;

    display: inline-block;

    border-radius: 50%;
}

.status-dot-online {
    background: #16a34a;

    box-shadow:
        0 0 0 3px #dcfce7;
}

.status-dot-error {
    background: #dc2626;

    box-shadow:
        0 0 0 3px #fee2e2;
}

.status-dot-offline {
    background: #64748b;

    box-shadow:
        0 0 0 3px #e2e8f0;
}

.status-dot-disabled {
    background: #475569;

    box-shadow:
        0 0 0 3px #e2e8f0;
}

.status-dot-unknown {
    background: #f59e0b;

    box-shadow:
        0 0 0 3px #fef3c7;
}

.router-status-label {
    font-size: 10px;
    font-weight: 750;

    letter-spacing: .025em;
}

.status-label-online {
    color: var(--br-success);
}

.status-label-error {
    color: var(--br-danger);
}

.status-label-offline {
    color: #475569;
}

.status-label-disabled {
    color: #334155;
}

.status-label-unknown {
    color: var(--br-warning);
}


/* =========================================================
   SYNC SUMMARY
   ========================================================= */

.router-sync-summary {
    display: grid;

    grid-template-columns:
        repeat(5, minmax(0, 1fr));

    gap: 1px;

    background: var(--br-border);

    border-bottom: 1px solid var(--br-border);
}

.sync-summary-item {
    min-width: 0;

    padding: 11px 13px;

    background: #fff;
}

.sync-summary-label {
    display: flex;

    align-items: center;

    gap: 5px;

    margin-bottom: 4px;

    color: var(--br-muted);

    font-size: 10px;
    font-weight: 650;
}

.sync-summary-label i {
    font-size: 12px;
}

.sync-summary-item strong {
    display: block;

    color: var(--br-text);

    font-size: 12px;
    font-weight: 750;

    word-break: break-word;
}


/* =========================================================
   METRICS
   ========================================================= */

.router-metrics {
    display: grid;

    grid-template-columns:
        repeat(4, minmax(0, 1fr));

    gap: 10px;

    padding: 14px 16px;
}

.router-metric {
    display: flex;

    align-items: center;

    gap: 10px;

    min-width: 0;

    padding: 11px;

    border: 1px solid var(--br-border);

    border-radius: 10px;

    background: #fbfcfe;

    transition:
        border-color .15s ease,
        background .15s ease;
}

.router-metric:hover {
    background: #fff;

    border-color: #d5dee9;
}

.metric-icon {
    width: 36px;
    height: 36px;

    min-width: 36px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 9px;

    background: #f1f5f9;
    color: #334155;

    font-size: 16px;
}

.router-metric span {
    display: block;

    margin-bottom: 2px;

    color: var(--br-muted);

    font-size: 10px;
    line-height: 1.3;
}

.router-metric strong {
    display: block;

    color: var(--br-text);

    font-size: 16px;
    line-height: 1.2;
}


/* =========================================================
   FOOTER / ACTIONS
   ========================================================= */

.router-card-footer {
    display: flex;

    align-items: center;
    justify-content: space-between;

    gap: 12px;

    padding: 12px 16px;

    border-top: 1px solid var(--br-border-soft);

    background: #fafbfc;
}

.router-sync-info {
    display: flex;

    align-items: center;

    gap: 7px;

    flex-wrap: wrap;

    min-width: 0;
}

.sync-badge,
.deep-scan-badge {
    display: inline-flex;

    align-items: center;

    gap: 5px;

    padding: 4px 8px;

    border-radius: 999px;

    font-size: 9px;
    font-weight: 750;
}

.sync-idle {
    background: #f1f5f9;
    color: #475569;
}

.sync-syncing {
    background: #dbeafe;
    color: #1d4ed8;
}

.sync-online {
    background: var(--br-success-bg);
    color: var(--br-success);
}

.sync-error {
    background: var(--br-danger-bg);
    color: var(--br-danger);
}

.sync-offline {
    background: #e2e8f0;
    color: #475569;
}

.deep-scan-badge {
    background: #ede9fe;
    color: #6d28d9;
}

.first-sync-text {
    color: var(--br-muted);

    font-size: 10px;
}

.router-card-actions {
    display: flex;

    align-items: center;
    justify-content: flex-end;

    gap: 5px;

    flex-wrap: wrap;
}

.router-card-actions .btn {
    min-height: 32px;

    border-radius: 7px;

    font-size: 11px;
    font-weight: 600;

    white-space: nowrap;
}

.router-card-actions .btn i {
    font-size: 12px;
}


/* =========================================================
   ERROR
   ========================================================= */

.router-card-error {
    display: flex;

    align-items: flex-start;

    gap: 9px;

    margin: 0 16px 16px;

    padding: 11px 13px;

    border: 1px solid #fecaca;

    border-radius: 9px;

    background: #fef2f2;

    color: #991b1b;

    font-size: 11px;

    line-height: 1.45;
}

.router-card-error > i {
    margin-top: 2px;

    font-size: 14px;
}

.router-card-error strong {
    display: block;

    margin-bottom: 2px;

    font-weight: 750;
}


/* =========================================================
   EMPTY STATE
   ========================================================= */

.empty-router-state {
    padding: 55px 20px;

    text-align: center;

    color: var(--br-muted);
}

.empty-router-icon {
    width: 68px;
    height: 68px;

    margin: 0 auto 14px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 18px;

    background: #eff6ff;
    color: var(--br-primary);

    border: 1px solid #dbeafe;

    font-size: 30px;
}

.empty-router-state h5 {
    margin-bottom: 6px;

    color: var(--br-text);

    font-size: 16px;
    font-weight: 750;
}

.empty-router-state p {
    max-width: 440px;

    margin: 0 auto 17px;

    font-size: 12px;

    line-height: 1.5;
}


/* =========================================================
   OLD TABLE SUPPORT
   Tetap dipertahankan untuk kompatibilitas markup lama
   ========================================================= */

.table-wrap {
    width: 100%;

    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.router-table {
    min-width: 900px;

    margin-bottom: 0;
}

.router-table th {
    padding: 11px 12px;

    background: #f8fafc;

    border-bottom: 1px solid var(--br-border);

    color: #475569;

    white-space: nowrap;

    font-size: 11px;
    font-weight: 700;
}

.router-table td {
    padding: 10px 12px;

    vertical-align: middle;

    border-color: var(--br-border-soft);

    font-size: 12px;
}

.router-name {
    color: var(--br-text);

    font-weight: 700;
}

.router-host {
    color: var(--br-muted);

    font-size: 11px;
}

.status {
    display: inline-flex;

    align-items: center;

    gap: 5px;

    padding: 4px 8px;

    border-radius: 999px;

    font-size: 10px;
    font-weight: 700;
}

.status-online {
    background: var(--br-success-bg);
    color: var(--br-success);
}

.status-error {
    background: var(--br-danger-bg);
    color: var(--br-danger);
}

.status-disabled {
    background: #e5e7eb;
    color: #374151;
}

.status-unknown {
    background: #f1f5f9;
    color: #64748b;
}


/* =========================================================
   SYNC DETAIL MODAL
   ========================================================= */

.sync-detail-modal {
    border: 0;

    border-radius: 16px;

    overflow: hidden;

    box-shadow:
        0 20px 60px rgba(15,23,42,.18);
}

.sync-detail-modal .modal-header {
    padding: 15px 18px;

    border-bottom: 1px solid var(--br-border);

    background: #fff;
}

.sync-detail-modal .modal-title {
    color: var(--br-text);

    font-size: 16px;
    font-weight: 750;

    letter-spacing: -.01em;
}

.sync-detail-summary {
    display: grid;

    grid-template-columns:
        repeat(3, minmax(0, 1fr));

    gap: 10px;
}

.sync-detail-summary > div {
    padding: 12px 14px;

    background: #f8fafc;

    border: 1px solid var(--br-border);

    border-radius: 11px;
}

.sync-detail-table {
    border: 1px solid var(--br-border);

    border-radius: 11px;

    overflow: hidden;
}

.sync-detail-table thead th {
    padding: 9px 11px;

    background: #f8fafc;

    border-bottom: 1px solid var(--br-border);

    font-size: 10px;
    font-weight: 700;

    text-transform: uppercase;

    letter-spacing: .035em;

    white-space: nowrap;
}

.sync-detail-table tbody td {
    padding-top: 10px;
    padding-bottom: 10px;

    font-size: 12px;
}

.sync-detail-status {
    display: inline-flex;

    align-items: center;

    gap: 6px;

    padding: 4px 8px;

    border-radius: 999px;

    font-size: 10px;
    font-weight: 700;

    background: rgba(108,117,125,.10);
    color: #6c757d;

    white-space: nowrap;
}

.sync-detail-status.success {
    background: rgba(25,135,84,.10);
    color: #198754;
}

.sync-detail-status.error {
    background: rgba(220,53,69,.10);
    color: #dc3545;
}

.sync-detail-error {
    margin-top: 4px;

    color: #dc3545;

    font-size: 10px;

    line-height: 1.4;

    max-width: 520px;

    word-break: break-word;
}

.sync-detail-modal .modal-footer {
    border-top: 1px solid var(--br-border);
}


/* =========================================================
   SIDEBAR SAFETY
   ========================================================= */

.bajama-network-sidebar {
    z-index: 1050;
}


/* =========================================================
   TABLET
   ========================================================= */

@media (max-width: 1100px) {

    .router-sync-summary {
        grid-template-columns:
            repeat(3, minmax(0, 1fr));
    }

    .router-metrics {
        grid-template-columns:
            repeat(3, minmax(0, 1fr));
    }

}


/* =========================================================
   SMALL TABLET
   ========================================================= */

@media (max-width: 800px) {

    .page-wrap,
    .bajama-router-management-page {
        margin-left: 0;

        width: 100%;

        padding: 14px;

        padding-top: 70px;
    }

    .router-card-header {
        align-items: flex-start;
    }

    .router-card-status {
        flex-shrink: 0;
    }

    .router-metrics {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }

    .router-card-footer {
        align-items: flex-start;

        flex-direction: column;
    }

    .router-card-actions {
        width: 100%;

        justify-content: flex-start;
    }

}


/* =========================================================
   MOBILE
   ========================================================= */

@media (max-width: 600px) {

    .page-wrap,
    .bajama-router-management-page {
        padding: 10px;

        padding-top: 66px;
    }

    .top-card {
        padding: 13px;

        border-radius: 12px;
    }

    .top-card h4 {
        font-size: 15px;
    }

    .top-card .text-secondary {
        font-size: 10px;
    }

    .top-card > .d-flex {
        gap: 10px !important;
    }

    .top-card > .d-flex > .d-flex:last-child {
        width: 100%;

        display: grid !important;

        grid-template-columns:
            1fr 1fr;

        gap: 7px !important;
    }

    .top-card > .d-flex > .d-flex:last-child .btn {
        width: 100%;
    }

    .router-dashboard {
        gap: 10px;
    }

    .router-dashboard-card {
        border-radius: 12px;
    }

    .router-card-header {
        flex-direction: column;

        padding: 13px;

        gap: 10px;
    }

    .router-card-identity {
        width: 100%;
    }

    .router-icon {
        width: 40px;
        height: 40px;

        min-width: 40px;

        font-size: 18px;
    }

    .router-card-name {
        font-size: 15px;
    }

    .router-card-status {
        align-self: flex-start;
    }

    .router-sync-summary {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }

    .sync-summary-item {
        padding: 9px 10px;
    }

    .sync-summary-label {
        font-size: 9px;
    }

    .sync-summary-item strong {
        font-size: 11px;
    }

    .router-metrics {
        grid-template-columns: 1fr 1fr;

        gap: 7px;

        padding: 10px;
    }

    .router-metric {
        padding: 9px;

        gap: 7px;
    }

    .metric-icon {
        width: 30px;
        height: 30px;

        min-width: 30px;

        font-size: 13px;
    }

    .router-metric span {
        font-size: 9px;
    }

    .router-metric strong {
        font-size: 14px;
    }

    .router-card-footer {
        padding: 10px;
    }

    .router-card-actions {
        display: grid;

        grid-template-columns:
            repeat(2, minmax(0, 1fr));

        width: 100%;
    }

    .router-card-actions .btn {
        width: 100%;

        min-height: 34px;
    }

    .router-card-error {
        margin: 0 10px 10px;

        padding: 10px 11px;

        font-size: 10px;
    }

    .empty-router-state {
        padding: 45px 15px;
    }

    .sync-detail-summary {
        grid-template-columns: 1fr;
    }

    .sync-detail-modal .modal-body {
        padding: 12px;
    }

    .sync-detail-table {
        font-size: .82rem;
    }

    .sync-detail-table thead th {
        font-size: .68rem;
    }

}


/* =========================================================
   VERY SMALL PHONE
   ========================================================= */

@media (max-width: 380px) {

    .top-card > .d-flex > .d-flex:last-child {
        grid-template-columns: 1fr;
    }

    .router-sync-summary {
        grid-template-columns: 1fr;
    }

    .router-metrics {
        grid-template-columns: 1fr;
    }

    .router-card-actions {
        grid-template-columns: 1fr;
    }

    .router-card-name {
        font-size: 14px;
    }

}



/* =========================================================
   BAJAMA ROUTER MANAGEMENT HEADER
   ========================================================= */

.bajama-router-management-page .bajama-router-header {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 20px;

    margin: 0 0 24px;
    padding: 4px 2px 18px;

    border-bottom: 1px solid var(--br-border);

    background: transparent;
}

.bajama-router-management-page .bajama-router-header-main {
    display: flex;
    align-items: center;

    gap: 14px;

    min-width: 0;
}

.bajama-router-management-page .bajama-router-header-icon {
    width: 46px;
    height: 46px;

    flex: 0 0 46px;

    display: flex;
    align-items: center;
    justify-content: center;

    border: 1px solid #dbeafe;
    border-radius: 12px;

    background: #eff6ff;
    color: var(--br-primary);

    font-size: 21px;
}

.bajama-router-management-page .bajama-router-title {
    margin: 0;

    color: var(--br-text);

    font-size: 22px;
    line-height: 1.25;
    font-weight: 700;

    letter-spacing: -.02em;
}

.bajama-router-management-page .bajama-router-subtitle {
    margin-top: 5px;

    color: var(--br-muted);

    font-size: 13px;
    line-height: 1.5;
}

.bajama-router-management-page .bajama-router-header-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;

    gap: 8px;

    flex-wrap: wrap;
}

.bajama-router-management-page .bajama-menu-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    gap: 7px;

    min-height: 38px;

    border: 1px solid var(--br-border);
    border-radius: 9px;

    background: #fff;
    color: var(--br-text);

    font-weight: 600;
}

.bajama-router-management-page .bajama-menu-button:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: var(--br-text);
}

.bajama-router-management-page .bajama-menu-button i {
    font-size: 17px;
}

@media (max-width: 800px) {

    .bajama-router-management-page .bajama-router-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .bajama-router-management-page .bajama-router-header-actions {
        width: 100%;
        justify-content: flex-start;
    }

}

@media (max-width: 480px) {

    .bajama-router-management-page .bajama-router-header-main {
        gap: 11px;
    }

    .bajama-router-management-page .bajama-router-header-icon {
        width: 40px;
        height: 40px;
        flex-basis: 40px;

        font-size: 18px;
    }

    .bajama-router-management-page .bajama-router-title {
        font-size: 18px;
    }

    .bajama-router-management-page .bajama-router-subtitle {
        font-size: 12px;
    }

    .bajama-router-management-page .bajama-router-header-actions {
        gap: 6px;
    }

}



/* Router Management uses the header Menu button instead
   of the global floating mobile menu button. */
body:has(.bajama-router-management-page) .bajama-network-mobile-button {
    display: none !important;
}

</style>

</head>

<body>

<div class="bajama-wrapper">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <?php require __DIR__ . '/../app/layout/sidebar.php'; ?>
    <main class="main-content">
        <?php
        $pageTitle = 'MikroTik Router Management';
        $userName = $_SESSION['username'] ?? 'User';
        $displayRole = 'OWNER';
        $headerRoles = \BAJAMA\Core\RBAC::roles($db);
        if (in_array('SUPER_ADMIN', $headerRoles, true)) {
            $displayRole = 'SUPERADMIN';
        } elseif (!empty($headerRoles)) {
            $displayRole = strtoupper((string)$headerRoles[0]);
        }
        require __DIR__ . '/../app/layout/header.php';
        ?>
        <div class="content-area">

<div class="page-wrap bajama-router-management-page">

    <div class="bajama-router-header">

        <div class="bajama-router-header-main">

            <div class="bajama-router-header-icon">
                <i class="bi bi-router"></i>
            </div>

            <div>

                <h1 class="bajama-router-title">
                    MikroTik Router Management
                </h1>

                <div class="bajama-router-subtitle">
                    Kelola koneksi dan router
                    MikroTik organisasi Anda.
                </div>

            </div>

        </div>

        <div class="bajama-router-header-actions">

            <a
                href="mikrotik.php"
                class="btn btn-outline-primary">

                <i class="bi bi-tools"></i>

                Winbox Tools

            </a>

            <?php if ($canManage): ?>

            <button
                class="btn btn-primary"
                data-bs-toggle="modal"
                data-bs-target="#routerModal"
                onclick="prepareAdd()">

                <i class="bi bi-plus-lg"></i>

                Add Router

            </button>

            <?php endif; ?>

        </div>

    </div>

    <?php if ($message !== ''): ?>

        <div class="alert alert-success alert-dismissible fade show">

            <i class="bi bi-check-circle"></i>

            <?= h($message) ?>

            <button
                class="btn-close"
                data-bs-dismiss="alert">
            </button>

        </div>

    <?php endif; ?>

    <?php if ($error !== ''): ?>

        <div class="alert alert-danger alert-dismissible fade show">

            <i class="bi bi-exclamation-triangle"></i>

            <?= h($error) ?>

            <button
                class="btn-close"
                data-bs-dismiss="alert">
            </button>

        </div>

    <?php endif; ?>

    <div class="router-card">

        <div class="p-3 border-bottom">

            <div class="d-flex
                        align-items-center
                        justify-content-between
                        gap-2
                        flex-wrap">

                <div>

                    <strong>
                        Router MikroTik
                    </strong>

                    <div class="text-secondary small">
                        <?= count($routers) ?>
                        router terdaftar
                    </div>

                </div>

                <button
                    class="btn btn-outline-secondary btn-sm"
                    onclick="location.reload()">

                    <i class="bi bi-arrow-clockwise"></i>

                    Refresh

                </button>

            </div>

        </div>

        <!-- =====================================================
             ROUTER DASHBOARD CARDS
        ====================================================== -->

        <div class="router-dashboard">

            <?php if (!$routers): ?>

                <div class="empty-router-state">
                    <div class="empty-router-icon">
                        <i class="bi bi-router"></i>
                    </div>

                    <h5>Belum ada MikroTik</h5>

                    <p>
                        Tambahkan router MikroTik pertama Anda
                        untuk mulai memantau jaringan melalui BAJAMA.
                    </p>

                    <?php if ($canManage): ?>
                        <button
                            type="button"
                            class="btn btn-primary"
                            onclick="prepareAdd()">
                            <i class="bi bi-plus-lg"></i>
                            Tambah MikroTik
                        </button>
                    <?php endif; ?>
                </div>

            <?php else: ?>

                <?php foreach ($routers as $router): ?>

                    <?php
                    $routerId = (int)$router['id'];

                    $sync = $syncStates[$routerId] ?? [];
                    $snapshots = $syncSnapshots[$routerId] ?? [];

                    $syncStatus = strtoupper(
                        (string)($sync['status'] ?? 'IDLE')
                    );

                    $routerStatus = strtoupper(
                        (string)($router['status'] ?? 'UNKNOWN')
                    );

                    if ($syncStatus === 'ONLINE') {
                        $displayStatus = 'ONLINE';
                    } elseif ($syncStatus === 'ERROR') {
                        $displayStatus = 'ERROR';
                    } elseif ($syncStatus === 'OFFLINE') {
                        $displayStatus = 'OFFLINE';
                    } elseif ($routerStatus === 'ONLINE') {
                        $displayStatus = 'ONLINE';
                    } elseif ($routerStatus === 'ERROR') {
                        $displayStatus = 'ERROR';
                    } elseif ($routerStatus === 'DISABLED') {
                        $displayStatus = 'DISABLED';
                    } else {
                        $displayStatus = 'UNKNOWN';
                    }

                    $statusClass = strtolower($displayStatus);

                    $snapshotCount = function (
                        string $category
                    ) use ($snapshots): int {
                        if (!isset($snapshots[$category])) {
                            return 0;
                        }

                        return (int)(
                            $snapshots[$category]['item_count'] ?? 0
                        );
                    };

                    $interfaces = $snapshotCount('interfaces');
                    $pppActive = $snapshotCount('ppp_active');
                    $hotspotActive = $snapshotCount('hotspot_active');
                    $hotspotUsers = $snapshotCount('hotspot_users');
                    $dhcpLeases = $snapshotCount('dhcp_leases');
                    $arp = $snapshotCount('arp');
                    $simpleQueues = $snapshotCount('simple_queues');

                    $firewallFilter =
                        $snapshotCount('firewall_filter');

                    $firewallNat =
                        $snapshotCount('firewall_nat');

                    $firewallMangle =
                        $snapshotCount('firewall_mangle');

                    $deepTotal =
                        $firewallFilter +
                        $firewallNat +
                        $firewallMangle;

                    $lastScan =
                        $sync['last_success_at']
                        ?? $sync['last_sync_at']
                        ?? null;

                    $lastDuration = (int)(
                        $sync['last_duration_ms'] ?? 0
                    );

                    $totalScans = (int)(
                        $sync['total_scans'] ?? 0
                    );

                    $successfulScans = (int)(
                        $sync['successful_scans'] ?? 0
                    );

                    $failedScans = (int)(
                        $sync['failed_scans'] ?? 0
                    );

                    $firstSync =
                        $sync['first_sync_at'] ?? null;

                    $lastError = trim(
                        (string)(
                            $sync['last_error']
                            ?? $router['last_error']
                            ?? ''
                        )
                    );

                    $deepScanned =
                        isset($snapshots['firewall_filter']) ||
                        isset($snapshots['firewall_nat']) ||
                        isset($snapshots['firewall_mangle']) ||
                        isset($snapshots['pppoe_secrets']) ||
                        isset($snapshots['hotspot_users']);

                    $syncLabel = 'Menunggu';

                    if ($syncStatus === 'SYNCING') {
                        $syncLabel = 'Sedang scan';
                    } elseif ($lastScan) {
                        $syncLabel = 'Tersinkron';
                    }
                    ?>

                    <article
                        class="router-dashboard-card"
                        data-router-id="<?= $routerId ?>">

                        <div class="router-card-header">

                            <div class="router-card-identity">

                                <div class="router-icon">
                                    <i class="bi bi-router"></i>
                                </div>

                                <div>

                                    <div class="router-card-name">
                                        <?= h($router['name']) ?>
                                    </div>

                                    <div class="router-card-endpoint">
                                        <i class="bi bi-hdd-network"></i>
                                        <?= h($router['host']) ?>:
                                        <?= (int)$router['port'] ?>

                                        <?php if (!empty($router['use_ssl'])): ?>
                                            <span class="mini-badge">
                                                SSL
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                </div>

                            </div>

                            <div class="router-card-status">

                                <span
                                    class="router-status-dot status-dot-<?= h($statusClass) ?>">
                                </span>

                                <span
                                    class="router-status-label status-label-<?= h($statusClass) ?>">
                                    <?= h($displayStatus) ?>
                                </span>

                            </div>

                        </div>


                        <div class="router-sync-summary">

                            <div class="sync-summary-item">
                                <span class="sync-summary-label">
                                    <i class="bi bi-clock-history"></i>
                                    Last Scan
                                </span>

                                <strong>
                                    <?php if ($lastScan): ?>
                                        <?= h(
                                            date(
                                                'd M Y H:i:s',
                                                strtotime($lastScan)
                                            )
                                        ) ?>
                                    <?php else: ?>
                                        Belum pernah
                                    <?php endif; ?>
                                </strong>
                            </div>


                            <div class="sync-summary-item">
                                <span class="sync-summary-label">
                                    <i class="bi bi-speedometer2"></i>
                                    Duration
                                </span>

                                <strong>
                                    <?php if ($lastDuration > 0): ?>
                                        <?= number_format(
                                            $lastDuration
                                        ) ?> ms
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </strong>
                            </div>


                            <div class="sync-summary-item">
                                <span class="sync-summary-label">
                                    <i class="bi bi-arrow-repeat"></i>
                                    Total Scan
                                </span>

                                <strong>
                                    <?= number_format($totalScans) ?>
                                </strong>
                            </div>


                            <div class="sync-summary-item">
                                <span class="sync-summary-label">
                                    <i class="bi bi-check-circle"></i>
                                    Berhasil
                                </span>

                                <strong class="text-success">
                                    <?= number_format(
                                        $successfulScans
                                    ) ?>
                                </strong>
                            </div>


                            <div class="sync-summary-item">
                                <span class="sync-summary-label">
                                    <i class="bi bi-x-circle"></i>
                                    Gagal
                                </span>

                                <strong
                                    class="<?= $failedScans > 0
                                        ? 'text-danger'
                                        : '' ?>">
                                    <?= number_format($failedScans) ?>
                                </strong>
                            </div>

                        </div>


                        <div class="router-metrics">

                            <div class="router-metric">
                                <div class="metric-icon">
                                    <i class="bi bi-diagram-3"></i>
                                </div>

                                <div>
                                    <span>Interfaces</span>
                                    <strong>
                                        <?= number_format($interfaces) ?>
                                    </strong>
                                </div>
                            </div>


                            <div class="router-metric">
                                <div class="metric-icon">
                                    <i class="bi bi-person-check"></i>
                                </div>

                                <div>
                                    <span>PPPoE Active</span>
                                    <strong>
                                        <?= number_format($pppActive) ?>
                                    </strong>
                                </div>
                            </div>


                            <div class="router-metric">
                                <div class="metric-icon">
                                    <i class="bi bi-wifi"></i>
                                </div>

                                <div>
                                    <span>Hotspot Active</span>
                                    <strong>
                                        <?= number_format($hotspotActive) ?>
                                    </strong>
                                </div>
                            </div>


                            <div class="router-metric">
                                <div class="metric-icon">
                                    <i class="bi bi-people"></i>
                                </div>

                                <div>
                                    <span>Hotspot Users</span>
                                    <strong>
                                        <?= number_format($hotspotUsers) ?>
                                    </strong>
                                </div>
                            </div>


                            <div class="router-metric">
                                <div class="metric-icon">
                                    <i class="bi bi-pc-display"></i>
                                </div>

                                <div>
                                    <span>DHCP Leases</span>
                                    <strong>
                                        <?= number_format($dhcpLeases) ?>
                                    </strong>
                                </div>
                            </div>


                            <div class="router-metric">
                                <div class="metric-icon">
                                    <i class="bi bi-link-45deg"></i>
                                </div>

                                <div>
                                    <span>ARP</span>
                                    <strong>
                                        <?= number_format($arp) ?>
                                    </strong>
                                </div>
                            </div>


                            <div class="router-metric">
                                <div class="metric-icon">
                                    <i class="bi bi-list-check"></i>
                                </div>

                                <div>
                                    <span>Simple Queue</span>
                                    <strong>
                                        <?= number_format($simpleQueues) ?>
                                    </strong>
                                </div>
                            </div>


                            <div class="router-metric">
                                <div class="metric-icon">
                                    <i class="bi bi-shield-check"></i>
                                </div>

                                <div>
                                    <span>Firewall</span>
                                    <strong>
                                        <?= number_format($deepTotal) ?>
                                    </strong>
                                </div>
                            </div>

                        </div>


                        <div class="router-card-footer">

                            <div class="router-sync-info">

                                <span
                                    class="sync-badge sync-<?= h(
                                        strtolower($syncStatus)
                                    ) ?>">

                                    <i class="bi bi-arrow-repeat"></i>

                                    <?= h($syncLabel) ?>

                                </span>

                                <?php if ($firstSync): ?>

                                    <span class="first-sync-text">
                                        First sync:
                                        <?= h(
                                            date(
                                                'd M Y H:i',
                                                strtotime($firstSync)
                                            )
                                        ) ?>
                                    </span>

                                <?php endif; ?>

                                <?php if ($deepScanned): ?>

                                    <span class="deep-scan-badge">
                                        <i class="bi bi-layers"></i>
                                        Deep Scan OK
                                    </span>

                                <?php endif; ?>

                            </div>


                            <div class="router-card-actions">

                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-info"
                                    onclick="showSyncDetail(
                                        <?= $routerId ?>
                                    )">

                                    <i class="bi bi-bar-chart-line"></i>
                                    Detail Sync

                                </button>


                                <a
                                    href="mikrotik.php?router_id=<?= $routerId ?>"
                                    class="btn btn-sm btn-outline-primary">

                                    <i class="bi bi-tools"></i>
                                    Open

                                </a>


                                <?php if ($canManage): ?>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-success"
                                        onclick="testRouter(
                                            <?= $routerId ?>
                                        )">

                                        <i class="bi bi-plug"></i>
                                        Test

                                    </button>


                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        onclick='prepareEdit(
                                            <?= json_encode(
                                                $router,
                                                JSON_HEX_TAG |
                                                JSON_HEX_APOS |
                                                JSON_HEX_QUOT |
                                                JSON_HEX_AMP
                                            ) ?>
                                        )'
                                        data-bs-toggle="modal"
                                        data-bs-target="#routerModal">

                                        <i class="bi bi-pencil"></i>
                                        Edit

                                    </button>


                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-danger"
                                        onclick="deleteRouter(
                                            <?= $routerId ?>,
                                            '<?= h($router['name']) ?>'
                                        )">

                                        <i class="bi bi-trash"></i>
                                        Delete

                                    </button>

                                <?php endif; ?>

                            </div>

                        </div>


                        <?php if ($lastError): ?>

                            <div class="router-card-error">

                                <i class="bi bi-exclamation-triangle"></i>

                                <div>
                                    <strong>
                                        Sync terakhir mengalami error
                                    </strong>

                                    <div>
                                        <?= h($lastError) ?>
                                    </div>
                                </div>

                            </div>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>


        <!-- =====================================================
             END ROUTER DASHBOARD CARDS
        ====================================================== -->


<!-- =====================================================
     DETAIL SYNC MODAL
====================================================== -->

<div
    class="modal fade"
    id="syncDetailModal"
    tabindex="-1"
    aria-hidden="true">

    <div
        class="modal-dialog modal-xl modal-dialog-scrollable">

        <div class="modal-content sync-detail-modal">

            <div class="modal-header">

                <div>
                    <h5 class="modal-title mb-1">
                        <i class="bi bi-arrow-repeat me-2"></i>
                        Detail Sinkronisasi
                    </h5>

                    <div
                        class="small text-secondary"
                        id="syncDetailRouter">
                        -
                    </div>
                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close">
                </button>

            </div>

            <div class="modal-body">

                <div class="sync-detail-summary mb-3">

                    <div>
                        <div class="small text-secondary">
                            Status Router
                        </div>

                        <div
                            id="syncDetailStatus"
                            class="fw-semibold">
                            -
                        </div>
                    </div>

                    <div>
                        <div class="small text-secondary">
                            Sinkronisasi Terakhir
                        </div>

                        <div
                            id="syncDetailLastScan"
                            class="fw-semibold">
                            -
                        </div>
                    </div>

                    <div>
                        <div class="small text-secondary">
                            Kategori
                        </div>

                        <div
                            id="syncDetailCategoryCount"
                            class="fw-semibold">
                            0
                        </div>
                    </div>

                </div>

                <div class="table-responsive">

                    <table
                        class="table table-hover align-middle sync-detail-table mb-0">

                        <thead>
                            <tr>

                                <th style="min-width:220px;">
                                    Data
                                </th>

                                <th>
                                    Status
                                </th>

                                <th class="text-end">
                                    Item
                                </th>

                                <th>
                                    Terakhir Scan
                                </th>

                                <th class="text-end">
                                    Durasi
                                </th>

                            </tr>
                        </thead>

                        <tbody id="syncDetailBody">
                        </tbody>

                    </table>

                </div>

                <div
                    id="syncDetailEmpty"
                    class="text-center text-secondary py-5 d-none">

                    <i
                        class="bi bi-database-x fs-1 d-block mb-3">
                    </i>

                    <div class="fw-semibold">
                        Belum ada data sinkronisasi
                    </div>

                    <div class="small mt-1">
                        Router belum memiliki snapshot hasil scan.
                    </div>

                </div>

            </div>

            <div class="modal-footer">

                <span class="small text-secondary me-auto">
                    <i class="bi bi-info-circle me-1"></i>
                    Data berasal dari snapshot database BAJAMA.
                </span>

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal">
                    Tutup
                </button>

            </div>

        </div>

    </div>

</div>



<div
    class="modal fade"
    id="routerModal"
    tabindex="-1">

    <div
        class="modal-dialog modal-lg modal-dialog-scrollable">

        <div class="modal-content">

            <form
                method="post"
                id="routerForm">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= h($csrfToken) ?>">

                <input
                    type="hidden"
                    name="action"
                    id="formAction"
                    value="add">

                <input
                    type="hidden"
                    name="id"
                    id="routerId"
                    value="">

                <div class="modal-header">

                    <h5
                        class="modal-title"
                        id="modalTitle">

                        Add MikroTik Router

                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal">
                    </button>

                </div>

                <div class="modal-body">

                    <div class="row g-3">

                        <div class="col-md-6">

                            <label class="form-label">
                                Nama Router
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                name="name"
                                id="routerName"
                                required
                                maxlength="150"
                                placeholder="BGM.NET">

                        </div>

                        <div class="col-md-6">

                            <label class="form-label">
                                Host / IP Address
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                name="host"
                                id="routerHost"
                                required
                                maxlength="255"
                                placeholder="10.20.30.1">

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                API Port
                            </label>

                            <input
                                type="number"
                                class="form-control"
                                name="port"
                                id="routerPort"
                                value="8728"
                                min="1"
                                max="65535"
                                required>

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                Username
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                name="username"
                                id="routerUsername"
                                required
                                maxlength="150"
                                placeholder="admin">

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                Timeout
                            </label>

                            <input
                                type="number"
                                class="form-control"
                                name="timeout_seconds"
                                id="routerTimeout"
                                value="8"
                                min="1"
                                max="60"
                                step="0.5">

                        </div>

                        <div class="col-12">

                            <label class="form-label">

                                Password

                                <span
                                    id="passwordHelp"
                                    class="text-secondary">

                                    wajib untuk router baru

                                </span>

                            </label>

                            <input
                                type="password"
                                class="form-control"
                                name="password"
                                id="routerPassword"
                                autocomplete="new-password">

                        </div>

                        <div class="col-12">

                            <div class="form-check">

                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="use_ssl"
                                    value="1"
                                    id="routerSsl">

                                <label
                                    class="form-check-label"
                                    for="routerSsl">

                                    Gunakan MikroTik API SSL
                                    <span class="text-secondary">
                                        (default port biasanya 8729)
                                    </span>

                                </label>

                            </div>

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

                        <i class="bi bi-save"></i>

                        Simpan & Test Connection

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<!-- TEST FORM -->

<form
    method="post"
    id="testForm"
    class="d-none">

    <input
        type="hidden"
        name="csrf"
        value="<?= h($csrfToken) ?>">

    <input
        type="hidden"
        name="action"
        value="test">

    <input
        type="hidden"
        name="id"
        id="testRouterId">

</form>


<!-- DELETE FORM -->

<form
    method="post"
    id="deleteForm"
    class="d-none">

    <input
        type="hidden"
        name="csrf"
        value="<?= h($csrfToken) ?>">

    <input
        type="hidden"
        name="action"
        value="delete">

    <input
        type="hidden"
        name="id"
        id="deleteRouterId">

</form>


<script
src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>

<script>

function prepareAdd() {

    document.getElementById(
        'modalTitle'
    ).textContent =
        'Add MikroTik Router';

    document.getElementById(
        'formAction'
    ).value =
        'add';

    document.getElementById(
        'routerId'
    ).value =
        '';

    document.getElementById(
        'routerName'
    ).value =
        '';

    document.getElementById(
        'routerHost'
    ).value =
        '';

    document.getElementById(
        'routerPort'
    ).value =
        '8728';

    document.getElementById(
        'routerUsername'
    ).value =
        '';

    document.getElementById(
        'routerPassword'
    ).value =
        '';

    document.getElementById(
        'routerTimeout'
    ).value =
        '8';

    document.getElementById(
        'routerSsl'
    ).checked =
        false;

    document.getElementById(
        'passwordHelp'
    ).textContent =
        'wajib untuk router baru';
}

function prepareEdit(router) {

    document.getElementById(
        'modalTitle'
    ).textContent =
        'Edit MikroTik Router';

    document.getElementById(
        'formAction'
    ).value =
        'edit';

    document.getElementById(
        'routerId'
    ).value =
        router.id || '';

    document.getElementById(
        'routerName'
    ).value =
        router.name || '';

    document.getElementById(
        'routerHost'
    ).value =
        router.host || '';

    document.getElementById(
        'routerPort'
    ).value =
        router.port || 8728;

    document.getElementById(
        'routerUsername'
    ).value =
        router.username || '';

    document.getElementById(
        'routerPassword'
    ).value =
        '';

    document.getElementById(
        'routerTimeout'
    ).value =
        router.timeout_seconds || 8;

    document.getElementById(
        'routerSsl'
    ).checked =
        !!Number(router.use_ssl);

    document.getElementById(
        'passwordHelp'
    ).textContent =
        'kosongkan jika password tidak berubah';
}

function testRouter(id) {

    if (!confirm(
        'Test koneksi ke router ini sekarang?'
    )) {
        return;
    }

    document.getElementById(
        'testRouterId'
    ).value =
        id;

    document.getElementById(
        'testForm'
    ).submit();
}

function deleteRouter(id, name) {

    if (!confirm(
        'Hapus router "' +
        name +
        '"?\n\n' +
        'Data koneksi router akan dihapus.'
    )) {
        return;
    }

    document.getElementById(
        'deleteRouterId'
    ).value =
        id;

    document.getElementById(
        'deleteForm'
    ).submit();
}


/* =====================================================
 * BAJAMA SYNC SNAPSHOT DATA
 * Hanya metadata snapshot dari database.
 * Tidak ada password / secret / data_json.
 * ===================================================== */

const syncSnapshotData =
    <?= json_encode(
        $syncSnapshots,
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_QUOT |
        JSON_HEX_AMP
    ) ?>;

const syncRouterData =
    <?= json_encode(
        array_map(
            function ($router) {
                return [
                    'id' => (int)$router['id'],
                    'name' => (string)$router['name'],
                    'host' => (string)$router['host'],
                    'port' => (int)$router['port'],
                    'status' => (string)$router['status'],
                ];
            },
            $routers
        ),
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_QUOT |
        JSON_HEX_AMP
    ) ?>;


/* =====================================================
 * LABEL KATEGORI
 * ===================================================== */

const syncCategoryLabels = {
    identity: 'Identity',
    resource: 'Resource',
    health: 'Health',
    interfaces: 'Interfaces',
    ip_addresses: 'IP Addresses',
    routes: 'Routes',
    arp: 'ARP',
    dhcp_servers: 'DHCP Server',
    dhcp_leases: 'DHCP Leases',
    ppp_profiles: 'PPP Profiles',
    pppoe_secrets: 'PPPoE Secrets',
    ppp_active: 'PPP Active',
    hotspot_servers: 'Hotspot Server',
    hotspot_users: 'Hotspot Users',
    hotspot_active: 'Hotspot Active',
    simple_queues: 'Simple Queue',
    firewall_filter: 'Firewall Filter',
    firewall_nat: 'Firewall NAT',
    firewall_mangle: 'Firewall Mangle'
};


/* =====================================================
 * ESCAPE HTML
 * ===================================================== */

function syncEscapeHtml(value) {

    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}


/* =====================================================
 * FORMAT STATUS
 * ===================================================== */

function syncStatusBadge(status) {

    status = String(status || 'UNKNOWN')
        .toUpperCase();

    if (status === 'SUCCESS') {

        return `
            <span class="sync-detail-status success">
                <i class="bi bi-check-circle-fill"></i>
                Berhasil
            </span>
        `;

    }

    if (status === 'ERROR') {

        return `
            <span class="sync-detail-status error">
                <i class="bi bi-x-circle-fill"></i>
                Error
            </span>
        `;

    }

    return `
        <span class="sync-detail-status">
            ${syncEscapeHtml(status)}
        </span>
    `;
}


/* =====================================================
 * DETAIL SYNC
 * ===================================================== */

function showSyncDetail(routerId) {

    routerId = String(routerId);

    const snapshots =
        syncSnapshotData[routerId] || {};

    const router =
        syncRouterData.find(function (item) {

            return String(item.id) === routerId;

        }) || null;

    const routerElement =
        document.getElementById('syncDetailRouter');

    const statusElement =
        document.getElementById('syncDetailStatus');

    const lastScanElement =
        document.getElementById('syncDetailLastScan');

    const countElement =
        document.getElementById('syncDetailCategoryCount');

    const bodyElement =
        document.getElementById('syncDetailBody');

    const emptyElement =
        document.getElementById('syncDetailEmpty');

    if (!bodyElement) {
        return;
    }

    if (router) {

        routerElement.textContent =
            router.name +
            ' • ' +
            router.host +
            ':' +
            router.port;

        statusElement.textContent =
            router.status || 'UNKNOWN';

    } else {

        routerElement.textContent =
            'Router #' + routerId;

        statusElement.textContent =
            '-';
    }

    bodyElement.innerHTML = '';

    const categories =
        Object.keys(syncCategoryLabels);

    let found = 0;
    let latestScan = '';

    categories.forEach(function (category) {

        const row =
            snapshots[category] || null;

        if (!row) {
            return;
        }

        found++;

        const scannedAt =
            row.scanned_at ||
            '-';

        if (
            scannedAt !== '-' &&
            (
                latestScan === '' ||
                String(scannedAt) > String(latestScan)
            )
        ) {
            latestScan = scannedAt;
        }

        const itemCount =
            Number(row.item_count || 0);

        const duration =
            row.duration_ms !== null &&
            row.duration_ms !== undefined
                ? Number(row.duration_ms) + ' ms'
                : '-';

        const status =
            String(row.status || 'UNKNOWN')
                .toUpperCase();

        const error =
            row.error_message || '';

        const tr =
            document.createElement('tr');

        let errorHtml = '';

        if (status === 'ERROR' && error) {

            errorHtml = `
                <div class="sync-detail-error">
                    ${syncEscapeHtml(error)}
                </div>
            `;
        }

        tr.innerHTML = `
            <td>
                <div class="fw-semibold">
                    ${syncEscapeHtml(
                        syncCategoryLabels[category]
                    )}
                </div>

                ${errorHtml}
            </td>

            <td>
                ${syncStatusBadge(status)}
            </td>

            <td class="text-end">
                <span class="fw-semibold">
                    ${itemCount.toLocaleString('id-ID')}
                </span>
            </td>

            <td>
                <span class="small">
                    ${syncEscapeHtml(scannedAt)}
                </span>
            </td>

            <td class="text-end">
                <span class="small">
                    ${syncEscapeHtml(duration)}
                </span>
            </td>
        `;

        bodyElement.appendChild(tr);
    });

    countElement.textContent =
        found +
        ' / ' +
        categories.length;

    lastScanElement.textContent =
        latestScan || '-';

    if (found === 0) {

        emptyElement.classList.remove('d-none');

        bodyElement
            .closest('.table-responsive')
            .classList.add('d-none');

    } else {

        emptyElement.classList.add('d-none');

        bodyElement
            .closest('.table-responsive')
            .classList.remove('d-none');
    }

    const modalElement =
        document.getElementById('syncDetailModal');

    if (
        modalElement &&
        typeof bootstrap !== 'undefined'
    ) {

        const modal =
            bootstrap.Modal.getOrCreateInstance(
                modalElement
            );

        modal.show();
    }
}

</script>

        </div>
        <?php require __DIR__ . '/../app/layout/footer.php'; ?>
    </main>
</div>

<script src="assets/js/bajama.js"></script>

</body>
</html>
