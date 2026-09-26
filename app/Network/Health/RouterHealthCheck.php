<?php
declare(strict_types=1);

namespace BAJAMA\Network\Health;

use BAJAMA\Network\RouterOSApi;

final class RouterHealthCheck
{
    /**
     * Health Check v1.
     *
     * READ-ONLY.
     *
     * Tidak menjalankan:
     * add
     * set
     * remove
     * enable
     * disable
     *
     * Semua command di bawah hanya menggunakan endpoint /print.
     */
    public static function run(
        RouterOSApi $api
    ): array {
        $result = [
            'ok' => true,
            'mode' => 'READ_ONLY',
            'started_at' => date('Y-m-d H:i:s'),
            'finished_at' => null,
            'sections' => [],
            'summary' => [
                'healthy' => 0,
                'warning' => 0,
                'error' => 0,
                'unknown' => 0,
            ],
        ];

        $checks = [
            'identity' => [
                'label' => 'Identity',
                'callback' => static function () use ($api): array {
                    return $api->identity();
                },
            ],

            'resource' => [
                'label' => 'System Resource',
                'callback' => static function () use ($api): array {
                    return $api->resource();
                },
            ],

            'clock' => [
                'label' => 'System Clock',
                'callback' => static function () use ($api): array {
                    return $api->clock();
                },
            ],

            'interfaces' => [
                'label' => 'Interfaces',
                'callback' => static function () use ($api): array {
                    return $api->interfaces();
                },
            ],

            'ip_addresses' => [
                'label' => 'IP Addresses',
                'callback' => static function () use ($api): array {
                    return $api->ipAddresses();
                },
            ],

            'routes' => [
                'label' => 'Routes',
                'callback' => static function () use ($api): array {
                    return $api->routes();
                },
            ],

            'arp' => [
                'label' => 'ARP',
                'callback' => static function () use ($api): array {
                    return $api->arp();
                },
            ],

            'dns' => [
                'label' => 'DNS',
                'callback' => static function () use ($api): array {
                    return $api->dns();
                },
            ],

            'firewall_filter' => [
                'label' => 'Firewall Filter',
                'callback' => static function () use ($api): array {
                    return $api->firewallFilter();
                },
            ],

            'firewall_nat' => [
                'label' => 'Firewall NAT',
                'callback' => static function () use ($api): array {
                    return $api->firewallNat();
                },
            ],

            'firewall_mangle' => [
                'label' => 'Firewall Mangle',
                'callback' => static function () use ($api): array {
                    return $api->firewallMangle();
                },
            ],

            'firewall_address_list' => [
                'label' => 'Firewall Address List',
                'callback' => static function () use ($api): array {
                    return $api->firewallAddressList();
                },
            ],

            'firewall_raw' => [
                'label' => 'Firewall RAW',
                'callback' => static function () use ($api): array {
                    return $api->firewallRaw();
                },
            ],

            'vlans' => [
                'label' => 'VLAN',
                'callback' => static function () use ($api): array {
                    return $api->vlans();
                },
            ],

            'dhcp_servers' => [
                'label' => 'DHCP Servers',
                'callback' => static function () use ($api): array {
                    return $api->dhcpServers();
                },
            ],

            'dhcp_leases' => [
                'label' => 'DHCP Leases',
                'callback' => static function () use ($api): array {
                    return $api->dhcpLeases();
                },
            ],

            'pppoe_secrets' => [
                'label' => 'PPPoE Secrets',
                'callback' => static function () use ($api): array {
                    return $api->pppoeSecrets();
                },
            ],

            'ppp_active' => [
                'label' => 'PPP Active',
                'callback' => static function () use ($api): array {
                    return $api->pppActive();
                },
            ],

            'hotspot_users' => [
                'label' => 'Hotspot Users',
                'callback' => static function () use ($api): array {
                    return $api->hotspotUsers();
                },
            ],

            'hotspot_active' => [
                'label' => 'Hotspot Active',
                'callback' => static function () use ($api): array {
                    return $api->hotspotActive();
                },
            ],

            'hotspot_servers' => [
                'label' => 'Hotspot Servers',
                'callback' => static function () use ($api): array {
                    return $api->hotspotServers();
                },
            ],

            'simple_queues' => [
                'label' => 'Simple Queues',
                'callback' => static function () use ($api): array {
                    return $api->simpleQueues();
                },
            ],
        ];

        foreach ($checks as $key => $check) {
            try {
                $data = ($check['callback'])();

                $result['sections'][$key] = [
                    'label' => $check['label'],
                    'status' => 'HEALTHY',
                    'count' => count($data),
                    'data' => $data,
                    'error' => null,
                ];

                $result['summary']['healthy']++;
            } catch (\Throwable $e) {
                $result['ok'] = false;

                $result['sections'][$key] = [
                    'label' => $check['label'],
                    'status' => 'ERROR',
                    'count' => 0,
                    'data' => [],
                    'error' => $e->getMessage(),
                ];

                $result['summary']['error']++;
            }
        }

        /*
         * Analisis dasar.
         *
         * Belum memperbaiki apa pun.
         */
        self::analyze($result);

        $result['finished_at'] = date('Y-m-d H:i:s');

        return $result;
    }

    private static function analyze(
        array &$result
    ): void {
        self::analyzeInterfaces($result);
        self::analyzeRoutes($result);
        self::analyzeMangle($result);
        self::analyzeNat($result);
        self::analyzeAddressList($result);
        self::analyzeDns($result);
    }

    private static function analyzeInterfaces(
        array &$result
    ): void {
        if (!isset($result['sections']['interfaces'])) {
            return;
        }

        $section = $result['sections']['interfaces'];

        if ($section['status'] !== 'HEALTHY') {
            return;
        }

        $running = 0;
        $disabled = 0;

        foreach ($section['data'] as $row) {
            if (!empty($row['running'])) {
                $running++;
            }

            if (!empty($row['disabled'])) {
                $disabled++;
            }
        }

        $result['sections']['interfaces']['analysis'] = [
            'running' => $running,
            'disabled' => $disabled,
            'total' => count($section['data']),
        ];
    }

    private static function analyzeRoutes(
        array &$result
    ): void {
        if (!isset($result['sections']['routes'])) {
            return;
        }

        $section = $result['sections']['routes'];

        if ($section['status'] !== 'HEALTHY') {
            return;
        }

        $defaultRoutes = [];
        $active = 0;
        $inactive = 0;

        foreach ($section['data'] as $row) {
            $dst = (string)($row['dst-address'] ?? '');

            if ($dst === '0.0.0.0/0') {
                $defaultRoutes[] = $row;
            }

            if (!empty($row['active'])) {
                $active++;
            } else {
                $inactive++;
            }
        }

        $result['sections']['routes']['analysis'] = [
            'total' => count($section['data']),
            'active' => $active,
            'inactive' => $inactive,
            'default_routes' => $defaultRoutes,
        ];
    }

    private static function analyzeMangle(
        array &$result
    ): void {
        if (!isset($result['sections']['firewall_mangle'])) {
            return;
        }

        $section = $result['sections']['firewall_mangle'];

        if ($section['status'] !== 'HEALTHY') {
            return;
        }

        $pcc = [];
        $fastTrack = [];

        foreach ($section['data'] as $row) {
            $blob = strtolower(
                json_encode($row) ?: ''
            );

            if (
                strpos($blob, 'per-connection-classifier') !== false ||
                strpos($blob, 'pcc') !== false
            ) {
                $pcc[] = $row;
            }

            if (
                strpos($blob, 'fasttrack') !== false
            ) {
                $fastTrack[] = $row;
            }
        }

        $result['sections']['firewall_mangle']['analysis'] = [
            'total' => count($section['data']),
            'pcc_candidates' => $pcc,
            'fasttrack_candidates' => $fastTrack,
        ];
    }

    private static function analyzeNat(
        array &$result
    ): void {
        if (!isset($result['sections']['firewall_nat'])) {
            return;
        }

        $section = $result['sections']['firewall_nat'];

        if ($section['status'] !== 'HEALTHY') {
            return;
        }

        $masquerade = [];
        $srcnat = [];

        foreach ($section['data'] as $row) {
            $action = strtolower(
                (string)($row['action'] ?? '')
            );

            if ($action === 'masquerade') {
                $masquerade[] = $row;
            }

            if (
                isset($row['chain']) &&
                strtolower((string)$row['chain']) === 'srcnat'
            ) {
                $srcnat[] = $row;
            }
        }

        $result['sections']['firewall_nat']['analysis'] = [
            'total' => count($section['data']),
            'masquerade' => $masquerade,
            'srcnat' => $srcnat,
        ];
    }

    private static function analyzeAddressList(
        array &$result
    ): void {
        if (!isset($result['sections']['firewall_address_list'])) {
            return;
        }

        $section =
            $result['sections']['firewall_address_list'];

        if ($section['status'] !== 'HEALTHY') {
            return;
        }

        $bajama = [];

        foreach ($section['data'] as $row) {
            $blob = strtoupper(
                json_encode($row) ?: ''
            );

            if (strpos($blob, 'BAJAMA') !== false) {
                $bajama[] = $row;
            }
        }

        $result['sections']['firewall_address_list']['analysis'] = [
            'total' => count($section['data']),
            'bajama_owned_candidates' => $bajama,
        ];
    }

    private static function analyzeDns(
        array &$result
    ): void {
        if (!isset($result['sections']['dns'])) {
            return;
        }

        $section = $result['sections']['dns'];

        if ($section['status'] !== 'HEALTHY') {
            return;
        }

        $dns = $section['data'][0] ?? [];

        $result['sections']['dns']['analysis'] = [
            'servers' => $dns['servers'] ?? '',
            'dynamic_servers' => $dns['dynamic-servers'] ?? '',
            'allow_remote_requests' =>
                $dns['allow-remote-requests'] ?? null,
            'cache_size' => $dns['cache-size'] ?? null,
        ];
    }
}
