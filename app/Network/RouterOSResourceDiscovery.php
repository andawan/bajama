<?php

declare(strict_types=1);

namespace BAJAMA\Network;

use RuntimeException;

final class RouterOSResourceDiscovery
{
    public function list(RouterOSApi $api, string $sourceType): array
    {
        $paths = [
            'network_isp' => '/ip/route',
            'network_lan' => '/ip/address',
            'network_route' => '/ip/route',
            'network_firewall' => '/ip/firewall/filter',
            'network_load_balance' => '/ip/firewall/mangle',
        ];
        if (!isset($paths[$sourceType])) {
            throw new RuntimeException('Discovery modul belum didukung.');
        }
        $rows = $api->raw($paths[$sourceType] . '/print');
        return ['path' => $paths[$sourceType], 'rows' => $rows];
    }
}
