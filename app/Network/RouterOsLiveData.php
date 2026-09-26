<?php

declare(strict_types=1);

namespace BAJAMA\Network;

use PDO;
use RuntimeException;

final class RouterOsLiveData
{
    public static function router(PDO $db, int $organizationId, int $routerId): array
    {
        $router = MikroTik::find($db, $organizationId, $routerId);
        if (!$router) {
            throw new RuntimeException('Router MikroTik tidak ditemukan.');
        }
        return $router;
    }

    public static function read(PDO $db, int $organizationId, int $routerId): array
    {
        $router = self::router($db, $organizationId, $routerId);
        $api = MikroTik::connect($router);
        try {
            return [
                'router' => $router,
                'resource' => $api->resource(),
                'interfaces' => $api->interfaces(),
                'routes' => $api->routes(),
                'ppp_active' => $api->pppActive(),
                'hotspot_active' => $api->hotspotActive(),
                'addresses' => $api->ipAddresses(),
            ];
        } finally {
            $api->disconnect();
        }
    }
}
