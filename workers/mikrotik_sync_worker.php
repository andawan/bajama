<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Network\MikroTikSyncService;
use BAJAMA\Traffic\TrafficObservationCollector;
use BAJAMA\Traffic\TrafficDiscoveryService;

$db = db();

/*
 * Traffic/catalog discovery sudah dihentikan.
 * Halaman baru membaca data live dari MikroTik saat dibuka sehingga
 * scheduler lama tidak boleh melakukan scan berkala yang berat.
 */
echo "BAJAMA: worker traffic dinonaktifkan; gunakan halaman live MikroTik.\n";
exit(0);

$lockName = 'bajama_mikrotik_sync_worker';

$lockStmt = $db->prepare('SELECT GET_LOCK(?, 0)');
$lockStmt->execute([$lockName]);

if ((int) $lockStmt->fetchColumn() !== 1) {
    echo "WORKER: sudah berjalan, proses ini dihentikan.\n";
    exit(0);
}

/*
 * FAST  : sekitar 1 menit
 * NORMAL: sekitar 2 menit
 * DEEP  : sekitar 15 menit
 *
 * Semua kategori bersifat READ-ONLY dari MikroTik.
 */
$fastCategories = [
    'health',
    'resource',
    'interfaces',
    'ppp_active',
    'hotspot_active',
];

$normalCategories = [
    'arp',
    'dhcp_leases',
    'ip_addresses',
    'simple_queues',
];

$deepCategories = [
    'identity',
    'routes',
    'dhcp_servers',
    'ppp_profiles',
    'pppoe_secrets',
    'hotspot_servers',
    'hotspot_users',
    'firewall_filter',
    'firewall_nat',
    'firewall_mangle',
];

try {
    echo "===== BAJAMA ADAPTIVE MIKROTIK SYNC WORKER =====\n";
    echo "Mulai : " . date('Y-m-d H:i:s') . "\n";

    $stmt = $db->query(
        "SELECT
            r.*,
            s.first_sync_at,
            s.last_success_at
         FROM mikrotik_routers r
         LEFT JOIN mikrotik_sync_state s
           ON s.router_id = r.id
          AND s.organization_id = r.organization_id
         WHERE r.status <> 'DISABLED'
         ORDER BY r.id ASC"
    );

    $routers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Router ditemukan : " . count($routers) . "\n";

    foreach ($routers as $router) {
        $routerId = (int) $router['id'];
        $routerName = (string) $router['name'];

        echo "\n--- Router #{$routerId} {$routerName} ---\n";

        /*
         * Router baru belum pernah berhasil full-sync.
         * Prioritas pertama selalu FULL.
         */
        if (empty($router['first_sync_at'])) {
            echo "MODE : NEW ROUTER / FULL DISCOVERY\n";

            $started = microtime(true);

            try {
                $result = MikroTikSyncService::syncRouter(
                    $db,
                    $router
                );

                $duration = (int) round(
                    (microtime(true) - $started) * 1000
                );

                echo "SYNC : "
                    . (!empty($result['success']) ? 'SUCCESS' : 'FAILED')
                    . "\n";

                echo "Durasi : {$duration} ms\n";
            } catch (Throwable $e) {
                echo "SYNC : ERROR\n";
                echo "Pesan : " . $e->getMessage() . "\n";
            }

            continue;
        }

        $now = time();

        $lastSuccess = !empty($router['last_success_at'])
            ? strtotime((string) $router['last_success_at'])
            : 0;

        /*
         * Jika state belum memiliki last_success_at,
         * lakukan full discovery sebagai recovery.
         */
        if ($lastSuccess <= 0) {
            echo "MODE : RECOVERY / FULL DISCOVERY\n";

            try {
                $result = MikroTikSyncService::syncRouter(
                    $db,
                    $router
                );

                echo "SYNC : "
                    . (!empty($result['success']) ? 'SUCCESS' : 'FAILED')
                    . "\n";
            } catch (Throwable $e) {
                echo "SYNC : ERROR\n";
                echo "Pesan : " . $e->getMessage() . "\n";
            }

            continue;
        }

        /*
         * FAST: setiap worker cycle.
         * Worker scheduler nantinya berjalan sekitar 1 menit sekali.
         */
        echo "MODE : FAST\n";

        try {
            $result = MikroTikSyncService::syncRouterCategories(
                $db,
                $router,
                $fastCategories
            );

            echo "FAST : "
                . (!empty($result['success']) ? 'SUCCESS' : 'FAILED')
                . "\n";
        } catch (Throwable $e) {
            echo "FAST : ERROR\n";
            echo "Pesan : " . $e->getMessage() . "\n";
        }

        /*
         * NORMAL: hanya jika minimal 2 menit sejak worker sukses
         * terakhir pada router.
         *
         * Catatan:
         * last_success_at diperbarui oleh FAST sehingga tidak bisa
         * dipakai sebagai timer NORMAL/DEEP.
         *
         * Karena itu kita menggunakan timestamp snapshot kategori
         * sebagai sumber jadwal per kategori.
         */
        $normalDue = false;

        $normalStmt = $db->prepare(
            "SELECT COUNT(*)
             FROM mikrotik_sync_snapshots
             WHERE router_id = ?
               AND organization_id = ?
               AND category IN ('arp','dhcp_leases','ip_addresses','simple_queues')
               AND status = 'SUCCESS'
               AND scanned_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)"
        );

        $normalStmt->execute([
            (int) $router['id'],
            (int) $router['organization_id']
        ]);

        if ((int) $normalStmt->fetchColumn() < count($normalCategories)) {
            $normalDue = true;
        }

        if ($normalDue) {
            echo "MODE : NORMAL\n";

            try {
                $result = MikroTikSyncService::syncRouterCategories(
                    $db,
                    $router,
                    $normalCategories
                );

                echo "NORMAL : "
                    . (!empty($result['success']) ? 'SUCCESS' : 'FAILED')
                    . "\n";
            } catch (Throwable $e) {
                echo "NORMAL : ERROR\n";
                echo "Pesan : " . $e->getMessage() . "\n";
            }
        } else {
            echo "NORMAL : belum jatuh tempo\n";
        }

        /*
         * DEEP: setiap 15 menit.
         */
        $deepDue = false;

        $deepStmt = $db->prepare(
            "SELECT COUNT(*)
             FROM mikrotik_sync_snapshots
             WHERE router_id = ?
               AND organization_id = ?
               AND category IN (
                    'identity',
                    'routes',
                    'dhcp_servers',
                    'ppp_profiles',
                    'pppoe_secrets',
                    'hotspot_servers',
                    'hotspot_users',
                    'firewall_filter',
                    'firewall_nat',
                    'firewall_mangle'
               )
               AND status = 'SUCCESS'
               AND scanned_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );

        $deepStmt->execute([
            (int) $router['id'],
            (int) $router['organization_id']
        ]);

        if ((int) $deepStmt->fetchColumn() < count($deepCategories)) {
            $deepDue = true;
        }

        if ($deepDue) {
            echo "MODE : DEEP\n";

            try {
                $result = MikroTikSyncService::syncRouterCategories(
                    $db,
                    $router,
                    $deepCategories
                );

                echo "DEEP : "
                    . (!empty($result['success']) ? 'SUCCESS' : 'FAILED')
                    . "\n";
            } catch (Throwable $e) {
                echo "DEEP : ERROR\n";
                echo "Pesan : " . $e->getMessage() . "\n";
            }
        } else {
            echo "DEEP : belum jatuh tempo\n";
        }

        /*
         * TRAFFIC OBSERVATION:
         * Jalankan untuk SEMUA router aktif.
         *
         * Read-only terhadap MikroTik.
         * Data observation disimpan ke BAJAMA.
         * Collector memakai observation_key sehingga koneksi
         * yang sama tidak dibuat sebagai baris duplikat.
         */
        echo "TRAFFIC OBSERVATION : mulai\n";

        try {
            $collector = new TrafficObservationCollector($db);

            $observationResult = $collector->collect(
                $router,
                false
            );

            if (!empty($observationResult['success'])) {
                echo "TRAFFIC OBSERVATION : SUCCESS\n";

                if (isset($observationResult['stats'])) {
                    $stats = $observationResult['stats'];

                    echo "  Connections      : "
                        . (int)($stats['connections'] ?? 0) . "\n";

                    echo "  Valid             : "
                        . (int)($stats['valid'] ?? 0) . "\n";

                    echo "  With hostname     : "
                        . (int)($stats['with_hostname'] ?? 0) . "\n";

                    echo "  Without hostname  : "
                        . (int)($stats['without_hostname'] ?? 0) . "\n";

                    echo "  Inserted          : "
                        . (int)($stats['inserted'] ?? 0) . "\n";

                    echo "  Updated           : "
                        . (int)($stats['updated'] ?? 0) . "\n";
                }
            } else {
                echo "TRAFFIC OBSERVATION : FAILED\n";

                if (!empty($observationResult['error'])) {
                    echo "  Pesan : "
                        . $observationResult['error'] . "\n";
                }
            }
        } catch (Throwable $e) {
            echo "TRAFFIC OBSERVATION : ERROR\n";
            echo "  Pesan : " . $e->getMessage() . "\n";
        }

        /*
         * TRAFFIC DISCOVERY:
         * Setelah observation selesai, proses discovery untuk
         * router yang sama agar hostname baru dapat masuk Catalog.
         *
         * Discovery juga read-only terhadap MikroTik.
         */
        echo "TRAFFIC DISCOVERY : mulai\n";

        try {
            $trafficDiscovery = new TrafficDiscoveryService($db);

            $discoveryResult = $trafficDiscovery->discoverRouter(
                (int)$router['id']
            );

            if ((int)($discoveryResult['errors'] ?? 0) === 0) {
                echo "TRAFFIC DISCOVERY : SUCCESS\n";
                echo "  Discovered       : "
                    . (int)($discoveryResult['discovered'] ?? 0) . "\n";
                echo "  Created/Updated  : "
                    . (int)($discoveryResult['created_or_updated'] ?? 0) . "\n";
                echo "  Skipped          : "
                    . (int)($discoveryResult['skipped'] ?? 0) . "\n";
            } else {
                echo "TRAFFIC DISCOVERY : FAILED\n";

                if (!empty($discoveryResult['error'])) {
                    echo "  Pesan : "
                        . $discoveryResult['error'] . "\n";
                }
            }
        } catch (Throwable $e) {
            echo "TRAFFIC DISCOVERY : ERROR\n";
            echo "  Pesan : " . $e->getMessage() . "\n";
        }
    }

    echo "\nSelesai : " . date('Y-m-d H:i:s') . "\n";
} finally {
    $releaseStmt = $db->prepare('SELECT RELEASE_LOCK(?)');
    $releaseStmt->execute([$lockName]);
}
