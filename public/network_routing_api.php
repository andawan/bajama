<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\License;
use BAJAMA\Core\RBAC;
use BAJAMA\Core\Tenant;
use BAJAMA\Network\MikroTik;

header('Content-Type: application/json; charset=utf-8');

function routing_json(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
    exit;
}

function routing_error(string $message, int $status = 400): void
{
    routing_json([
        'ok' => false,
        'error' => $message,
    ], $status);
}

function routing_csrf(): void
{
    $token = (string)($_POST['_csrf'] ?? $_POST['csrf'] ?? $_POST['csrf_token'] ?? '');

    if (function_exists('verify_csrf')) {
        verify_csrf($token);
        return;
    }

    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');

    if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        routing_error('CSRF token tidak valid.', 419);
    }
}

function routing_input(string $key, $default = null)
{
    return array_key_exists($key, $_POST)
        ? $_POST[$key]
        : $default;
}

function routing_int(string $key, ?int $default = null): ?int
{
    $value = routing_input($key, $default);

    if ($value === null || $value === '') {
        return $default;
    }

    if (!is_numeric($value)) {
        return $default;
    }

    return (int)$value;
}

function routing_bool(string $key, bool $default = true): bool
{
    $value = routing_input($key, $default);

    if (is_bool($value)) {
        return $value;
    }

    return in_array(
        strtolower((string)$value),
        ['1', 'true', 'yes', 'on'],
        true
    );
}

function routing_route(PDO $db, int $organizationId, int $routeId): ?array
{
    $stmt = $db->prepare(
        "SELECT *
         FROM network_routes
         WHERE id = ?
           AND organization_id = ?
         LIMIT 1"
    );

    $stmt->execute([$routeId, $organizationId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function routing_router(PDO $db, int $organizationId, int $routerId): ?array
{
    return MikroTik::find($db, $organizationId, $routerId);
}

function routing_ros_attributes(array $route, string $version): array
{
    $attributes = [];

    $destination = trim((string)$route['destination']);
    $gateway = trim((string)($route['gateway'] ?? ''));
    $interface = trim((string)($route['interface_name'] ?? ''));
    $table = trim((string)($route['routing_table'] ?? 'main'));

    if ($destination !== '') {
        $attributes['dst-address'] = $destination;
    }

    if ($gateway !== '') {
        $attributes['gateway'] = $gateway;
    } elseif ($interface !== '') {
        $attributes['gateway'] = $interface;
    }

    $attributes['distance'] = (string)((int)$route['distance']);
    $attributes['scope'] = (string)((int)$route['scope']);
    $attributes['target-scope'] = (string)((int)$route['target_scope']);

    $checkGateway = strtoupper((string)$route['check_gateway']);

    if ($checkGateway !== 'NONE') {
        $attributes['check-gateway'] = strtolower($checkGateway);
    } else {
        $attributes['check-gateway'] = 'none';
    }

    $attributes['disabled'] = ((int)$route['enabled'] === 1)
        ? 'no'
        : 'yes';

    if ($table !== '' && $table !== 'main') {
        if (version_compare($version, '7.0', '>=')) {
            $attributes['routing-table'] = $table;
        } else {
            $attributes['routing-mark'] = $table;
        }
    }

    $comment = trim((string)($route['comment'] ?? ''));
    $marker = 'BAJAMA_ROUTE_ID=' . (int)$route['id'];

    if ($comment === '') {
        $attributes['comment'] = $marker;
    } elseif (strpos($comment, $marker) === false) {
        $attributes['comment'] = $comment . ' | ' . $marker;
    } else {
        $attributes['comment'] = $comment;
    }

    return $attributes;
}

function routing_find_ros_route(
    BAJAMA\Network\RouterOSApi $api,
    int $routeId
): ?array {
    $rows = $api->command('/ip/route/print');

    $marker = 'BAJAMA_ROUTE_ID=' . $routeId;

    foreach ($rows as $row) {
        $comment = (string)($row['comment'] ?? '');

        if (
            isset($row['.id']) &&
            strpos($comment, $marker) !== false
        ) {
            return $row;
        }
    }

    return null;
}

function routing_owned_ros_route(
    BAJAMA\Network\RouterOSApi $api,
    int $routeId,
    string $routerosId
): ?array {
    $routerosId = trim($routerosId);
    if ($routerosId === '') {
        return null;
    }
    foreach ($api->command('/ip/route/print') as $row) {
        if ((string)($row['.id'] ?? '') !== $routerosId) {
            continue;
        }
        $marker = 'BAJAMA_ROUTE_ID=' . $routeId;
        return strpos((string)($row['comment'] ?? ''), $marker) !== false ? $row : null;
    }
    return null;
}

try {
    Auth::requireLogin();
    License::requireFeature($db, 'mikrotik');
    RBAC::require($db, 'network.view');

    $organizationId = (int)Tenant::id();

    $action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));

    if ($action === '') {
        routing_error('Action tidak ditemukan.');
    }

    /*
     * TEST GATEWAY
     */
    if ($action === 'test_gateway') {
        RBAC::require($db, 'network.manage');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            routing_error('Method harus POST.', 405);
        }

        routing_csrf();

        $routeId = routing_int('route_id');
        $routerId = routing_int('router_id');

        /*
         * Jika route_id tersedia, router pada database route
         * menjadi sumber utama. Ini mencegah test gateway
         * diarahkan ke router yang berbeda.
         */
        if ($routeId) {
            $route = routing_route(
                $db,
                $organizationId,
                $routeId
            );

            if (!$route) {
                routing_error('Route tidak ditemukan.', 404);
            }

            $routeRouterId = (int)($route['router_id'] ?? 0);

            if ($routeRouterId <= 0) {
                routing_error(
                    'Route belum memiliki Router MikroTik.'
                );
            }

            if (
                $routerId > 0
                && $routerId !== $routeRouterId
            ) {
                routing_error(
                    'Router yang dikirim tidak sesuai dengan Router pada route.'
                );
            }

            $routerId = $routeRouterId;
        }

        if (!$routerId) {
            routing_error('Router ID wajib diisi.');
        }

        $router = routing_router(
            $db,
            $organizationId,
            $routerId
        );

        if (!$router) {
            routing_error('Router tidak ditemukan.', 404);
        }

        $api = MikroTik::connect($router);

        try {
            /*
             * TEST GATEWAY
             *
             * RouterOS mengembalikan identity dan ping sebagai
             * array rows. Normalisasi di backend supaya frontend
             * menerima data yang konsisten.
             */

            $identityRows = $api->identity();

            $identityName = trim(
                (string)($router['name'] ?? '')
            );

            if (is_array($identityRows)) {
                foreach ($identityRows as $identityRow) {
                    if (!is_array($identityRow)) {
                        continue;
                    }

                    $candidate = trim(
                        (string)(
                            $identityRow['name']
                            ?? $identityRow['identity']
                            ?? ''
                        )
                    );

                    if ($candidate !== '') {
                        $identityName = $candidate;
                        break;
                    }
                }
            }

            $result = [
                'ok' => true,
                'action' => 'test_gateway',
                'router_id' => $routerId,
                'router_name' => $router['name'] ?? '',
                'identity' => $identityName,
                'gateway' => null,
                'ping' => null,
            ];

            if ($routeId) {
                $route = routing_route(
                    $db,
                    $organizationId,
                    $routeId
                );

                if (!$route) {
                    routing_error(
                        'Route tidak ditemukan.',
                        404
                    );
                }

                $gateway = trim(
                    (string)($route['gateway'] ?? '')
                );

                if ($gateway === '') {
                    routing_error(
                        'Route ini tidak memiliki Gateway / Next Hop.'
                    );
                }

                $result['gateway'] = $gateway;

                $ping = $api->command(
                    '/ping',
                    [
                        'address' => $gateway,
                        'count' => '3',
                    ]
                );

                $received = 0;
                $times = [];

                foreach ($ping as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $status = strtolower(
                        trim(
                            (string)($item['status'] ?? '')
                        )
                    );

                    if ($status === 'timeout') {
                        continue;
                    }

                    $timeRaw = trim(
                        (string)($item['time'] ?? '')
                    );

                    /*
                     * RouterOS bisa mengirim:
                     *  1ms
                     *  1.25ms
                     *  250us
                     *  00:00:00.001
                     *  atau angka biasa.
                     */

                    $latency = null;

                    if ($timeRaw !== '') {

                        if (preg_match(
                            '/([0-9]+(?:\.[0-9]+)?)\s*ms/i',
                            $timeRaw,
                            $m
                        )) {
                            $latency = (float)$m[1];

                        } elseif (preg_match(
                            '/([0-9]+(?:\.[0-9]+)?)\s*us/i',
                            $timeRaw,
                            $m
                        )) {
                            $latency = (float)$m[1] / 1000;

                        } elseif (preg_match(
                            '/^(\d+):(\d{2}):(\d{2})\.(\d+)$/',
                            $timeRaw,
                            $m
                        )) {
                            $hours = (float)$m[1];
                            $minutes = (float)$m[2];
                            $seconds = (float)$m[3];
                            $fraction = (float)(
                                '0.' . $m[4]
                            );

                            $latency =
                                ($hours * 3600000) +
                                ($minutes * 60000) +
                                ($seconds * 1000) +
                                ($fraction * 1000);

                        } elseif (is_numeric($timeRaw)) {
                            $latency = (float)$timeRaw;
                        }
                    }

                    if ($latency !== null) {
                        $times[] = $latency;
                        $received++;
                        continue;
                    }

                    /*
                     * Jika tidak ada time tetapi ada TTL,
                     * RouterOS tetap memberikan reply.
                     */
                    if (isset($item['ttl'])) {
                        $received++;
                    }
                }

                /*
                 * Jangan menghitung paket lebih dari jumlah request.
                 */
                $received = min(3, $received);

                $result['ping'] = [
                    'sent' => 3,
                    'received' => $received,
                    'loss' => max(0, 3 - $received),
                    'times' => $times,
                    'reachable' => $received > 0,
                ];
            }

            routing_json($result);
        } finally {
            $api->disconnect();
        }
    }

    /*
     * SYNC FROM MIKROTIK
     *
     * Hanya membaca /ip/route/print.
     * Tidak mengubah MikroTik dan tidak otomatis menulis DB.
     */
    if ($action === 'sync') {
        RBAC::require($db, 'network.view');

        $routerId = routing_int('router_id');

        if (!$routerId) {
            routing_error('Router ID wajib diisi.');
        }

        $router = routing_router(
            $db,
            $organizationId,
            $routerId
        );

        if (!$router) {
            routing_error('Router tidak ditemukan.', 404);
        }

        $api = MikroTik::connect($router);

        try {
            $identity = $api->identity();
            $routes = $api->command('/ip/route/print');

            routing_json([
                'ok' => true,
                'action' => 'sync',
                'router_id' => $routerId,
                'router_name' => $router['name'] ?? '',
                'identity' => $identity,
                'count' => count($routes),
                'routes' => $routes,
            ]);
        } finally {
            $api->disconnect();
        }
    }

    /*
     * APPLY
     *
     * Menulis SATU route ke MikroTik.
     * Hanya dilakukan ketika user menekan Apply.
     */
    if ($action === 'apply') {
        RBAC::require($db, 'network.manage');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            routing_error('Method harus POST.', 405);
        }

        routing_csrf();

        $routeId = routing_int('route_id');
        $requestedRouterId = routing_int('router_id');

        if (!$routeId) {
            routing_error('Route ID wajib diisi.');
        }

        $route = routing_route(
            $db,
            $organizationId,
            $routeId
        );

        if (!$route) {
            routing_error('Route tidak ditemukan.', 404);
        }

        /*
         * Router authoritative berasal dari route yang tersimpan.
         */
        $routerId = (int)($route['router_id'] ?? 0);

        if ($routerId <= 0) {
            routing_error(
                'Route belum memiliki Router MikroTik.'
            );
        }

        /*
         * Jika UI mengirim router_id, nilainya harus sama
         * dengan router yang tersimpan pada route.
         */
        if (
            $requestedRouterId > 0
            && $requestedRouterId !== $routerId
        ) {
            routing_error(
                'Router yang dipilih tidak sesuai dengan Router pada route.'
            );
        }

        $router = routing_router(
            $db,
            $organizationId,
            $routerId
        );

        if (!$router) {
            routing_error('Router tidak ditemukan.', 404);
        }

        $api = MikroTik::connect($router);

        try {
            $resource = $api->resource();

            $version = trim(
                (string)($resource['version'] ?? '6.0')
            );

            $attributes = routing_ros_attributes(
                $route,
                $version
            );

            /*
             * PENTING:
             * Jangan langsung percaya mikrotik_id dari database.
             *
             * Route bisa saja sudah dihapus manual melalui Winbox,
             * sehingga .id lama tidak lagi valid.
             *
             * Marker BAJAMA_ROUTE_ID menjadi identitas utama.
             */
            $existing = routing_find_ros_route(
                $api,
                (int)$route['id']
            );

            $rosId = '';

            if ($existing && isset($existing['.id'])) {
                /*
                 * Route sudah ada di MikroTik.
                 * Update route tersebut, bukan membuat route baru.
                 */
                $rosId = (string)$existing['.id'];

                $api->command(
                    '/ip/route/set',
                    array_merge(
                        ['.id' => $rosId],
                        $attributes
                    )
                );

                $operation = 'updated';
            } else {
                /*
                 * Marker belum ditemukan.
                 * Baru sekarang kita ADD route baru.
                 */
                $api->command(
                    '/ip/route/add',
                    $attributes
                );

                /*
                 * Setelah ADD, wajib mencari kembali marker
                 * untuk mendapatkan .id RouterOS yang sebenarnya.
                 */
                $found = routing_find_ros_route(
                    $api,
                    (int)$route['id']
                );

                if (!$found || !isset($found['.id'])) {
                    /*
                     * Jangan menyimpan route sebagai ACTIVE jika
                     * .id tidak berhasil diverifikasi.
                     *
                     * Route mungkin sebenarnya sudah dibuat,
                     * tetapi marker tidak berhasil dibaca kembali.
                     * User harus memeriksa MikroTik sebelum retry.
                     */
                    routing_error(
                        'Route sudah dikirim ke MikroTik, tetapi ID RouterOS tidak berhasil diverifikasi. Periksa IP → Routes sebelum menekan Apply lagi.',
                        502
                    );
                }

                $rosId = (string)$found['.id'];
                $operation = 'created';
            }

            /*
             * Simpan .id RouterOS yang sudah diverifikasi.
             */
            $stmt = $db->prepare(
                "UPDATE network_routes
                 SET router_id = ?,
                     mikrotik_id = ?
                 WHERE id = ?
                   AND organization_id = ?"
            );

            $stmt->execute([
                $routerId,
                $rosId,
                $routeId,
                $organizationId,
            ]);

            routing_json([
                'ok' => true,
                'action' => 'apply',
                'operation' => $operation,
                'route_id' => $routeId,
                'router_id' => $routerId,
                'mikrotik_id' => $rosId,
                'status' => 'ACTIVE',
                'router_version' => $version,
            ]);
        } finally {
            $api->disconnect();
        }
    }

    /*
     * ENABLE / DISABLE
     */
    if ($action === 'enable' || $action === 'disable') {
        RBAC::require($db, 'network.manage');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            routing_error('Method harus POST.', 405);
        }

        routing_csrf();

        $routeId = routing_int('route_id');

        if (!$routeId) {
            routing_error('Route ID wajib diisi.');
        }

        $route = routing_route(
            $db,
            $organizationId,
            $routeId
        );

        if (!$route) {
            routing_error('Route tidak ditemukan.', 404);
        }

        $routerId = (int)($route['router_id'] ?? 0);
        $rosId = trim((string)($route['mikrotik_id'] ?? ''));

        if (!$routerId || $rosId === '') {
            routing_error(
                'Route belum terhubung dengan route MikroTik.'
            );
        }

        $router = routing_router(
            $db,
            $organizationId,
            $routerId
        );

        if (!$router) {
            routing_error('Router tidak ditemukan.', 404);
        }

        $api = MikroTik::connect($router);

        try {
            if (!routing_owned_ros_route($api, $routeId, $rosId)) {
                $db->prepare('UPDATE network_routes SET mikrotik_id=NULL WHERE id=? AND organization_id=?')->execute([$routeId, $organizationId]);
                routing_error('ID RouterOS stale atau bukan milik route BAJAMA ini. Apply ulang dibutuhkan; tidak ada route yang diubah.', 409);
            }
            $command = $action === 'enable'
                ? '/ip/route/enable'
                : '/ip/route/disable';

            $api->command(
                $command,
                ['.id' => $rosId]
            );

            $enabled = $action === 'enable' ? 1 : 0;
            $status = $enabled ? 'ACTIVE' : 'DISABLED';

            /*
             * network_routes hanya menyimpan enabled.
             * Status dikembalikan melalui JSON.
             */
            $stmt = $db->prepare(
                "UPDATE network_routes
                 SET enabled = ?
                 WHERE id = ?
                   AND organization_id = ?"
            );

            $stmt->execute([
                $enabled,
                $routeId,
                $organizationId,
            ]);

            routing_json([
                'ok' => true,
                'action' => $action,
                'route_id' => $routeId,
                'status' => $status,
            ]);
        } finally {
            $api->disconnect();
        }
    }

    /*
     * REMOVE FROM MIKROTIK
     *
     * Hanya menghapus route dari RouterOS.
     * Data route BAJAMA tetap disimpan.
     */
    if ($action === 'remove') {
        RBAC::require($db, 'network.manage');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            routing_error('Method harus POST.', 405);
        }

        routing_csrf();

        $routeId = routing_int('route_id');

        if (!$routeId) {
            routing_error('Route ID wajib diisi.');
        }

        $route = routing_route(
            $db,
            $organizationId,
            $routeId
        );

        if (!$route) {
            routing_error('Route tidak ditemukan.', 404);
        }

        $routerId = (int)($route['router_id'] ?? 0);
        $rosId = trim((string)($route['mikrotik_id'] ?? ''));

        if (!$routerId || $rosId === '') {
            routing_error(
                'Route belum terhubung dengan MikroTik.'
            );
        }

        $router = routing_router(
            $db,
            $organizationId,
            $routerId
        );

        if (!$router) {
            routing_error('Router tidak ditemukan.', 404);
        }

        $api = MikroTik::connect($router);

        try {
            if (!routing_owned_ros_route($api, $routeId, $rosId)) {
                $db->prepare('UPDATE network_routes SET mikrotik_id=NULL WHERE id=? AND organization_id=?')->execute([$routeId, $organizationId]);
                routing_error('ID RouterOS stale atau bukan milik route BAJAMA ini. Route tidak dihapus.', 409);
            }
            $api->command(
                '/ip/route/remove',
                ['.id' => $rosId]
            );

            /*
             * Route BAJAMA tetap disimpan.
             * Hanya hubungan dengan RouterOS yang dilepas.
             */
            $stmt = $db->prepare(
                "UPDATE network_routes
                 SET mikrotik_id = NULL
                 WHERE id = ?
                   AND organization_id = ?"
            );

            $stmt->execute([
                $routeId,
                $organizationId,
            ]);

            routing_json([
                'ok' => true,
                'action' => 'remove',
                'route_id' => $routeId,
                'status' => 'INACTIVE',
            ]);
        } finally {
            $api->disconnect();
        }
    }

    routing_error(
        'Action tidak dikenal: ' . $action,
        400
    );

} catch (Throwable $e) {
    error_log(
        'BAJAMA network_routing_api error: ' .
        $e->getMessage()
    );

    routing_json([
        'ok' => false,
        'error' => 'Operasi routing gagal.',
    ], 500);
}
