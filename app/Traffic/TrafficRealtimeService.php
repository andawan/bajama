<?php

declare(strict_types=1);

namespace BAJAMA\Traffic;

use BAJAMA\Network\MikroTik;
use PDO;
use Throwable;

/**
 * Membaca connection tracking langsung dari semua router aktif.
 * Tidak membutuhkan cron/worker dan aman dipanggil dari request apply.
 */
final class TrafficRealtimeService
{
    public function refreshOrganization(PDO $db, int $organizationId): array
    {
        $stmt = $db->prepare(
            "SELECT * FROM mikrotik_routers
             WHERE organization_id = ? AND status <> 'DISABLED'
             ORDER BY id"
        );
        $stmt->execute([$organizationId]);

        $result = ['routers' => 0, 'success' => 0, 'errors' => []];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $router) {
            $result['routers']++;
            try {
                $result['success']++;
                $result['router_' . (int)$router['id']] = $this->refreshRouter($db, $router);
            } catch (Throwable $e) {
                $result['errors'][] = 'Router ' . (int)$router['id'] . ': ' . $e->getMessage();
            }
        }
        return $result;
    }

    public function refreshRouter(PDO $db, array $router): array
    {
        $collector = new TrafficObservationCollector($db);
        $observation = $collector->collect($router, false);
        $discovery = (new TrafficDiscoveryService($db))->discoverRouter((int)$router['id']);

        return [
            'observation' => $observation,
            'discovery' => $discovery,
        ];
    }
}
