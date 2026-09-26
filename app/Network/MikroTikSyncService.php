<?php

declare(strict_types=1);

namespace BAJAMA\Network;

require_once __DIR__ . '/../Traffic/TrafficCatalog.php';
require_once __DIR__ . '/../Traffic/TrafficDiscoveryService.php';

use PDO;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use BAJAMA\Traffic\TrafficDiscoveryService;

final class MikroTikSyncService
{
    /**
     * Semua kategori yang dibaca dari MikroTik.
     *
     * PENTING:
     * Service ini hanya menggunakan method READ dari RouterOSApi.
     * Tidak ada command konfigurasi/write ke MikroTik.
     */
    private static function categories(): array
    {
        return [
            'identity' => 'identity',
            'resource' => 'resource',
            'health' => 'health',
            'interfaces' => 'interfaces',
            'ip_addresses' => 'ipAddresses',
            'routes' => 'routes',
            'arp' => 'arp',
            'dhcp_servers' => 'dhcpServers',
            'dhcp_leases' => 'dhcpLeases',
            'ppp_profiles' => 'pppProfiles',
            'pppoe_secrets' => 'pppoeSecrets',
            'ppp_active' => 'pppActive',
            'hotspot_servers' => 'hotspotServers',
            'hotspot_users' => 'hotspotUsers',
            'hotspot_active' => 'hotspotActive',
            'simple_queues' => 'simpleQueues',
            'firewall_filter' => 'firewallFilter',
            'firewall_nat' => 'firewallNat',
            'firewall_mangle' => 'firewallMangle',
        ];
    }

    /**
     * Hilangkan password/secret dari data hasil discovery.
     */
    private static function sanitizeSecrets($data)
    {
        if (is_array($data)) {
            $result = [];

            foreach ($data as $key => $value) {
                $lowerKey = strtolower((string) $key);

                if (
                    $lowerKey === 'password' ||
                    $lowerKey === 'passwd' ||
                    $lowerKey === 'secret' ||
                    $lowerKey === 'private-key' ||
                    $lowerKey === 'private_key'
                ) {
                    $result[$key] = '[REDACTED]';
                    continue;
                }

                $result[$key] = self::sanitizeSecrets($value);
            }

            return $result;
        }

        return $data;
    }

    /**
     * Hitung jumlah item secara aman.
     */
    private static function itemCount($data): int
    {
        if (!is_array($data)) {
            return 1;
        }

        /*
         * identity/resource/health biasanya berupa associative array
         * tunggal, sedangkan interfaces/PPP/hotspot berupa list.
         */
        if (empty($data)) {
            return 0;
        }

        $keys = array_keys($data);
        $isSequential = ($keys === range(0, count($keys) - 1));

        return $isSequential ? count($data) : 1;
    }

    /**
     * Pastikan state sync tersedia.
     */
    private static function ensureState(
        PDO $db,
        int $organizationId,
        int $routerId
    ): void {
        $stmt = $db->prepare(
            'INSERT INTO mikrotik_sync_state
                (organization_id, router_id, status)
             VALUES
                (?, ?, "IDLE")
             ON DUPLICATE KEY UPDATE
                updated_at = CURRENT_TIMESTAMP'
        );

        $stmt->execute([
            $organizationId,
            $routerId
        ]);
    }

    /**
     * Update state menjadi SYNCING.
     */
    private static function markSyncing(
        PDO $db,
        int $organizationId,
        int $routerId
    ): void {
        $stmt = $db->prepare(
            'UPDATE mikrotik_sync_state
             SET
                status = "SYNCING",
                last_sync_started_at = NOW(),
                total_scans = total_scans + 1,
                updated_at = CURRENT_TIMESTAMP
             WHERE organization_id = ?
               AND router_id = ?'
        );

        $stmt->execute([
            $organizationId,
            $routerId
        ]);
    }

    /**
     * Simpan hasil kategori.
     *
     * Snapshot dibuat sebagai history. Jadi perubahan langsung di MikroTik
     * tidak akan menghapus hasil scan sebelumnya.
     */
    private static function saveSnapshot(
        PDO $db,
        int $organizationId,
        int $routerId,
        string $category,
        $data,
        int $durationMs
    ): void {
        $data = self::sanitizeSecrets($data);

        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        if ($json === false) {
            throw new RuntimeException(
                'Gagal encode JSON kategori ' . $category . ': ' .
                json_last_error_msg()
            );
        }

        $stmt = $db->prepare(
            'INSERT INTO mikrotik_sync_snapshots
                (
                    organization_id,
                    router_id,
                    category,
                    status,
                    item_count,
                    data_json,
                    scanned_at,
                    duration_ms,
                    error_message
                )
             VALUES
                (?, ?, ?, "SUCCESS", ?, ?, NOW(), ?, NULL)'
        );

        $stmt->execute([
            $organizationId,
            $routerId,
            $category,
            self::itemCount($data),
            $json,
            $durationMs
        ]);
    }

    /**
     * Simpan error kategori.
     */
    private static function saveSnapshotError(
        PDO $db,
        int $organizationId,
        int $routerId,
        string $category,
        string $error,
        int $durationMs
    ): void {
        $stmt = $db->prepare(
            'INSERT INTO mikrotik_sync_snapshots
                (
                    organization_id,
                    router_id,
                    category,
                    status,
                    item_count,
                    data_json,
                    scanned_at,
                    duration_ms,
                    error_message
                )
             VALUES
                (?, ?, ?, "ERROR", 0, ?, NOW(), ?, ?)'
        );

        $stmt->execute([
            $organizationId,
            $routerId,
            $category,
            '{}',
            $durationMs,
            $error
        ]);
    }

    /**
     * Tandai sync berhasil.
     */
    private static function markSuccess(
        PDO $db,
        int $organizationId,
        int $routerId,
        int $durationMs
    ): void {
        $stmt = $db->prepare(
            'UPDATE mikrotik_sync_state
             SET
                status = "ONLINE",
                first_sync_at =
                    CASE
                        WHEN first_sync_at IS NULL THEN NOW()
                        ELSE first_sync_at
                    END,
                last_sync_at = NOW(),
                last_success_at = NOW(),
                last_duration_ms = ?,
                successful_scans = successful_scans + 1,
                last_error = NULL,
                updated_at = CURRENT_TIMESTAMP
             WHERE organization_id = ?
               AND router_id = ?'
        );

        $stmt->execute([
            $durationMs,
            $organizationId,
            $routerId
        ]);

        $stmt = $db->prepare(
            'UPDATE mikrotik_routers
             SET
                status = "ONLINE",
                last_tested_at = NOW(),
                last_error = NULL
             WHERE id = ?
               AND organization_id = ?'
        );

        $stmt->execute([
            $routerId,
            $organizationId
        ]);
    }

    /**
     * Tandai sync gagal.
     *
     * Snapshot sukses sebelumnya TIDAK dihapus.
     */
    private static function markError(
        PDO $db,
        int $organizationId,
        int $routerId,
        int $durationMs,
        string $error
    ): void {
        $stmt = $db->prepare(
            'UPDATE mikrotik_sync_state
             SET
                status = "ERROR",
                last_sync_at = NOW(),
                last_error_at = NOW(),
                last_duration_ms = ?,
                failed_scans = failed_scans + 1,
                last_error = ?,
                updated_at = CURRENT_TIMESTAMP
             WHERE organization_id = ?
               AND router_id = ?'
        );

        $stmt->execute([
            $durationMs,
            $error,
            $organizationId,
            $routerId
        ]);

        $stmt = $db->prepare(
            'UPDATE mikrotik_routers
             SET
                status = "ERROR",
                last_tested_at = NOW(),
                last_error = ?
             WHERE id = ?
               AND organization_id = ?'
        );

        $stmt->execute([
            $error,
            $routerId,
            $organizationId
        ]);
    }

    /**
     * Full discovery satu MikroTik.
     *
     * Return:
     * [
     *   'router_id' => ...,
     *   'success' => true/false,
     *   'categories' => ...,
     *   'duration_ms' => ...
     * ]
     */

    /**
     * Adaptive sync berdasarkan daftar kategori.
     *
     * Hanya membaca data dari MikroTik.
     * Tidak melakukan perubahan konfigurasi RouterOS.
     */
    public static function syncRouterCategories(
        PDO $db,
        array $router,
        array $categories
    ): array {
        $organizationId = (int) ($router['organization_id'] ?? 0);
        $routerId = (int) ($router['id'] ?? 0);

        if ($organizationId <= 0) {
            throw new InvalidArgumentException(
                'organization_id router tidak valid.'
            );
        }

        if ($routerId <= 0) {
            throw new InvalidArgumentException(
                'router_id tidak valid.'
            );
        }

        $available = self::categories();
        $selected = [];

        foreach ($categories as $category) {
            $category = (string) $category;

            if (isset($available[$category])) {
                $selected[$category] = $available[$category];
            }
        }

        if (!$selected) {
            throw new InvalidArgumentException(
                'Tidak ada kategori sync yang valid.'
            );
        }

        $started = microtime(true);

        self::ensureState(
            $db,
            $organizationId,
            $routerId
        );

        self::markSyncing(
            $db,
            $organizationId,
            $routerId
        );

        $api = null;
        $results = [];
        $errors = [];

        try {
            $api = MikroTik::connect($router);

            foreach ($selected as $category => $method) {
                $categoryStarted = microtime(true);

                try {
                    $data = $api->{$method}();

                    $durationMs = (int) round(
                        (microtime(true) - $categoryStarted) * 1000
                    );

                    self::saveSnapshot(
                        $db,
                        $organizationId,
                        $routerId,
                        $category,
                        $data,
                        $durationMs
                    );

                    $results[$category] = [
                        'success' => true,
                        'item_count' => self::itemCount($data),
                        'duration_ms' => $durationMs
                    ];
                } catch (Throwable $e) {
                    $durationMs = (int) round(
                        (microtime(true) - $categoryStarted) * 1000
                    );

                    $message = MikroTik::safeErrorMessage($e);

                    self::saveSnapshotError(
                        $db,
                        $organizationId,
                        $routerId,
                        $category,
                        $message,
                        $durationMs
                    );

                    $errors[$category] = $message;

                    $results[$category] = [
                        'success' => false,
                        'item_count' => 0,
                        'duration_ms' => $durationMs,
                        'error' => $message
                    ];
                }
            }

            $durationMs = (int) round(
                (microtime(true) - $started) * 1000
            );

            if ($errors) {
                self::markError(
                    $db,
                    $organizationId,
                    $routerId,
                    $durationMs,
                    'Sebagian kategori gagal: ' . implode('; ', $errors)
                );
            } else {
                self::markSuccess(
                    $db,
                    $organizationId,
                    $routerId,
                    $durationMs
                );
            }

            /*
             * Traffic Intelligence membaca snapshot Deep Scan yang baru
             * saja tersimpan. Discovery tidak pernah melakukan scan MikroTik.
             *
             * Hanya jalankan apabila salah satu sumber traffic ikut sync.
             * Kegagalan Discovery tidak boleh menggagalkan Auto Sync.
             */
            $trafficDiscovery = null;

            if (
                isset($selected['firewall_filter']) ||
                isset($selected['firewall_nat']) ||
                isset($selected['firewall_mangle'])
            ) {
                try {
                    $discovery = new TrafficDiscoveryService($db);
                    $trafficDiscovery = $discovery->discoverRouter($routerId);
                } catch (Throwable $e) {
                    $trafficDiscovery = [
                        'router_id' => $routerId,
                        'success' => false,
                        'error' => MikroTik::safeErrorMessage($e)
                    ];
                }
            }

            return [
                'success' => !$errors,
                'router_id' => $routerId,
                'duration_ms' => $durationMs,
                'categories' => $results,
                'errors' => $errors,
                'traffic_discovery' => $trafficDiscovery
            ];
        } catch (Throwable $e) {
            $durationMs = (int) round(
                (microtime(true) - $started) * 1000
            );

            self::markError(
                $db,
                $organizationId,
                $routerId,
                $durationMs,
                MikroTik::safeErrorMessage($e)
            );

            return [
                'success' => false,
                'router_id' => $routerId,
                'duration_ms' => $durationMs,
                'categories' => $results,
                'errors' => [
                    '_connection' => MikroTik::safeErrorMessage($e)
                ]
            ];
        } finally {
            if ($api && method_exists($api, 'disconnect')) {
                try {
                    $api->disconnect();
                } catch (Throwable $ignored) {
                    // Cleanup tidak boleh mengubah hasil sync.
                }
            }
        }
    }

    public static function syncRouter(
        PDO $db,
        array $router
    ): array {
        $organizationId = (int) ($router['organization_id'] ?? 0);
        $routerId = (int) ($router['id'] ?? 0);

        if ($organizationId <= 0) {
            throw new InvalidArgumentException(
                'organization_id router tidak valid.'
            );
        }

        if ($routerId <= 0) {
            throw new InvalidArgumentException(
                'router_id tidak valid.'
            );
        }

        $started = microtime(true);

        self::ensureState(
            $db,
            $organizationId,
            $routerId
        );

        self::markSyncing(
            $db,
            $organizationId,
            $routerId
        );

        $api = null;
        $results = [];
        $errors = [];

        try {
            /*
             * MikroTik::connect() hanya membuka koneksi/API session.
             * Semua pengambilan data di bawah menggunakan method READ.
             */
            $api = MikroTik::connect($router);

            foreach (self::categories() as $category => $method) {
                $categoryStarted = microtime(true);

                try {
                    $data = $api->{$method}();

                    $durationMs = (int) round(
                        (microtime(true) - $categoryStarted) * 1000
                    );

                    self::saveSnapshot(
                        $db,
                        $organizationId,
                        $routerId,
                        $category,
                        $data,
                        $durationMs
                    );

                    $results[$category] = [
                        'success' => true,
                        'item_count' => self::itemCount($data),
                        'duration_ms' => $durationMs
                    ];
                } catch (Throwable $e) {
                    $durationMs = (int) round(
                        (microtime(true) - $categoryStarted) * 1000
                    );

                    $message = MikroTik::safeErrorMessage($e);

                    self::saveSnapshotError(
                        $db,
                        $organizationId,
                        $routerId,
                        $category,
                        $message,
                        $durationMs
                    );

                    $errors[$category] = $message;

                    $results[$category] = [
                        'success' => false,
                        'item_count' => 0,
                        'duration_ms' => $durationMs,
                        'error' => $message
                    ];
                }
            }

            $durationMs = (int) round(
                (microtime(true) - $started) * 1000
            );

            if ($errors) {
                self::markError(
                    $db,
                    $organizationId,
                    $routerId,
                    $durationMs,
                    'Sebagian kategori gagal: ' . implode('; ', $errors)
                );
            } else {
                self::markSuccess(
                    $db,
                    $organizationId,
                    $routerId,
                    $durationMs
                );
            }

            /*
             * Setelah seluruh Deep Scan selesai, teruskan snapshot firewall
             * ke Traffic Intelligence.
             *
             * Discovery hanya membaca mikrotik_sync_snapshots.
             * Tidak ada koneksi/command tambahan ke MikroTik.
             */
            $trafficDiscovery = null;

            try {
                $discovery = new TrafficDiscoveryService($db);
                $trafficDiscovery = $discovery->discoverRouter($routerId);
            } catch (Throwable $e) {
                $trafficDiscovery = [
                    'router_id' => $routerId,
                    'success' => false,
                    'error' => MikroTik::safeErrorMessage($e)
                ];
            }

            return [
                'success' => !$errors,
                'router_id' => $routerId,
                'duration_ms' => $durationMs,
                'categories' => $results,
                'errors' => $errors,
                'traffic_discovery' => $trafficDiscovery
            ];
        } catch (Throwable $e) {
            $durationMs = (int) round(
                (microtime(true) - $started) * 1000
            );

            self::markError(
                $db,
                $organizationId,
                $routerId,
                $durationMs,
                MikroTik::safeErrorMessage($e)
            );

            return [
                'success' => false,
                'router_id' => $routerId,
                'duration_ms' => $durationMs,
                'categories' => $results,
                'errors' => [
                    '_connection' => MikroTik::safeErrorMessage($e)
                ]
            ];
        } finally {
            /*
             * RouterOSApi menyediakan disconnect().
             * Jika implementasi saat ini tidak memiliki method tersebut,
             * jangan biarkan cleanup menyebabkan sync gagal.
             */
            if ($api && method_exists($api, 'disconnect')) {
                try {
                    $api->disconnect();
                } catch (Throwable $e) {
                    // Cleanup error tidak boleh menggagalkan hasil sync.
                }
            }
        }
    }
}
