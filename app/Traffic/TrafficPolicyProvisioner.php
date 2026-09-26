<?php

declare(strict_types=1);

namespace BAJAMA\Traffic;

use BAJAMA\Network\MikroTik;
use PDO;
use RuntimeException;

final class TrafficPolicyProvisioner
{
    public function apply(PDO $db, int $organizationId, int $routerId): array
    {
        $router = MikroTik::find($db, $organizationId, $routerId);
        if (!$router) {
            throw new RuntimeException('Router MikroTik tidak ditemukan.');
        }

        $policyStmt = $db->prepare(
                        'SELECT tp.id, tp.category_id, tp.isp_id, tp.enabled, tp.priority,
                                        c.slug AS category_slug, c.name AS category_name,
                                        i.interface_name AS selected_interface
             FROM traffic_policies tp
             INNER JOIN traffic_categories c ON c.id = tp.category_id
                         LEFT JOIN network_isps i
                             ON i.id = tp.isp_id
                            AND i.organization_id = tp.organization_id
             WHERE tp.organization_id = ? AND tp.router_id = ?
             ORDER BY tp.priority ASC, tp.id ASC'
        );
        $policyStmt->execute([$organizationId, $routerId]);
        $policies = $policyStmt->fetchAll(PDO::FETCH_ASSOC);

        $api = MikroTik::connect($router);
        $created = 0;
        $skipped = 0;
        $errors = [];

        try {
            $this->removeManagedRules($api);

            $version = $this->routerVersion($api);
            $routes = [];
            $interfaces = $this->activeInterfaces($api);
            $defaultRoutes = $this->defaultRoutesByInterface($api);

            foreach ($policies as $policy) {
                $categorySlug = $this->slug((string)$policy['category_slug']);
                $listName = 'BAJAMA-' . strtoupper($categorySlug);
                $interface = $this->resolveInterface(
                    (string)($policy['selected_interface'] ?? ''),
                    $interfaces,
                    $defaultRoutes
                );
                $markName = 'BAJAMA-' . $categorySlug . '-' . (int)$policy['id'];
                $commentPrefix = 'BAJAMA:TRAFFIC:' . (int)$policy['id'];

                $gateway = $this->gatewayForInterface($interface, $defaultRoutes);
                if ($gateway === '' || $interface === '') {
                    $errors[] = 'Policy ' . (string)$policy['category_name'] . ': interface/default route aktif tidak ditemukan di MikroTik.';
                    continue;
                }

                $routes[$markName] = [
                    'mark' => $markName,
                    'gateway' => $gateway,
                    'interface' => $interface,
                    'isp_id' => (int)$policy['isp_id'],
                ];

                $catalogStmt = $db->prepare(
                    'SELECT id, entry_type, value, normalized_value, port, protocol
                     FROM traffic_catalog
                     WHERE category_id = ? AND status = "ACTIVE"
                     ORDER BY id'
                );
                $catalogStmt->execute([(int)$policy['category_id']]);
                $entries = $catalogStmt->fetchAll(PDO::FETCH_ASSOC);
                $addressCount = 0;

                foreach ($entries as $entry) {
                    $entryType = strtoupper((string)$entry['entry_type']);
                    $value = trim((string)($entry['normalized_value'] ?: $entry['value']));
                    if ($value === '') {
                        continue;
                    }

                    if (in_array($entryType, ['DOMAIN', 'HOST', 'IP', 'CIDR'], true)) {
                        $api->command('/ip/firewall/address-list/add', [
                            'list' => $listName,
                            'address' => $value,
                            'comment' => $commentPrefix . ':CATALOG:' . (int)$entry['id'],
                        ]);
                        $created++;
                        $addressCount++;
                    }
                }

                if ($addressCount > 0) {
                    $api->command('/ip/firewall/mangle/add', [
                        'chain' => 'prerouting',
                        'dst-address-list' => $listName,
                        'action' => 'mark-routing',
                        'new-routing-mark' => $markName,
                        'passthrough' => 'no',
                        'comment' => $commentPrefix . ':MANGLE',
                    ]);
                    $created++;
                }

                foreach ($entries as $entry) {
                    if (strtoupper((string)$entry['entry_type']) !== 'PORT') {
                        continue;
                    }
                    $portValue = trim((string)($entry['normalized_value'] ?: $entry['value']));
                    if (!preg_match('/^(\d{1,5})(?:-(\d{1,5}))?$/', $portValue, $portMatch)) {
                        $skipped++;
                        continue;
                    }
                    $firstPort = (int)$portMatch[1];
                    $lastPort = isset($portMatch[2]) && $portMatch[2] !== ''
                        ? (int)$portMatch[2]
                        : $firstPort;
                    if ($firstPort < 1 || $lastPort > 65535 || $firstPort > $lastPort) {
                        $skipped++;
                        continue;
                    }

                    $attributes = [
                        'chain' => 'prerouting',
                        'dst-port' => $firstPort === $lastPort
                            ? (string)$firstPort
                            : $firstPort . '-' . $lastPort,
                        'dst-address-list' => $listName,
                        'action' => 'mark-routing',
                        'new-routing-mark' => $markName,
                        'passthrough' => 'no',
                        'comment' => $commentPrefix . ':PORT:' . (int)$entry['id'],
                    ];
                    $protocol = strtoupper((string)($entry['protocol'] ?? 'ANY'));
                    if ($protocol !== 'ANY') {
                        $attributes['protocol'] = strtolower($protocol);
                    }
                    $api->command('/ip/firewall/mangle/add', $attributes);
                    $created++;
                }

            }

            foreach ($routes as $route) {
                $this->ensureRoutingTable(
                    $api,
                    (string)$route['mark'],
                    'BAJAMA:TRAFFIC:TABLE:' . (string)$route['mark'],
                    $version
                );

                $this->ensureMarkedDefaultRoute(
                    $api,
                    (string)$route['mark'],
                    (string)$route['gateway'],
                    (string)$route['interface'],
                    $version
                );
                $created++;
            }
        } finally {
            $api->disconnect();
        }

        return [
            'policies' => count($policies),
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    private function removeManagedRules($api): void
    {
        foreach ([
            ['/ip/firewall/address-list/print', '/ip/firewall/address-list/remove'],
            ['/ip/firewall/mangle/print', '/ip/firewall/mangle/remove'],
            ['/ip/route/print', '/ip/route/remove'],
        ] as [$printPath, $removePath]) {
            foreach ($api->command($printPath) as $row) {
                if (strpos((string)($row['comment'] ?? ''), 'BAJAMA:TRAFFIC:') !== 0) {
                    continue;
                }
                if (!empty($row['.id'])) {
                    $api->command($removePath, ['.id' => (string)$row['.id']]);
                }
            }
        }
    }

    private function activeInterfaces($api): array
    {
        $result = [];
        foreach ($api->command('/interface/print') as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name !== '' && strtolower((string)($row['disabled'] ?? 'no')) !== 'yes') {
                $result[strtolower($name)] = $name;
            }
        }
        return $result;
    }

    private function defaultRoutesByInterface($api): array
    {
        $result = [];
        foreach ($api->command('/ip/route/print') as $row) {
            if ((string)($row['dst-address'] ?? '') !== '0.0.0.0/0'
                || strtolower((string)($row['disabled'] ?? 'no')) === 'yes') {
                continue;
            }
            $gateway = trim((string)($row['gateway'] ?? $row['immediate-gw'] ?? ''));
            $interface = trim((string)($row['interface'] ?? ''));
            if ($interface === '' && strpos((string)($row['immediate-gw'] ?? ''), '%') !== false) {
                [, $interface] = explode('%', (string)$row['immediate-gw'], 2);
            }
            if ($interface === '' && strpos($gateway, '%') !== false) {
                [, $interface] = explode('%', $gateway, 2);
            }
            if ($interface !== '') {
                $result[strtolower($interface)] = [
                    'interface' => $interface,
                    'gateway' => $gateway !== '' ? $gateway : $interface,
                ];
            }
        }
        return $result;
    }

    private function resolveInterface(string $requested, array $interfaces, array $routes): string
    {
        $requested = strtolower(trim($requested));
        if ($requested !== '' && isset($interfaces[$requested])) {
            return $interfaces[$requested];
        }
        foreach ($routes as $route) {
            $key = strtolower((string)$route['interface']);
            if (isset($interfaces[$key])) {
                return $interfaces[$key];
            }
        }
        return '';
    }

    private function gatewayForInterface(string $interface, array $routes): string
    {
        $route = $routes[strtolower($interface)] ?? null;
        if (!$route) {
            return $interface;
        }
        $gateway = trim((string)$route['gateway']);
        return strpos($gateway, '%') === false && filter_var($gateway, FILTER_VALIDATE_IP)
            ? $gateway . '%' . $interface
            : $gateway;
    }

    private function routerVersion($api): string
    {
        return (string)($api->command('/system/resource/print')[0]['version'] ?? '6.0');
    }

    private function ensureRoutingTable(
        $api,
        string $mark,
        string $comment,
        string $version
    ): void {
        if (version_compare($version, '7.0', '<')) {
            return;
        }

        foreach ($api->command('/routing/table/print') as $row) {
            if ((string)($row['name'] ?? '') === $mark) {
                return;
            }
        }

        $api->command('/routing/table/add', [
            'name' => $mark,
            'fib' => 'yes',
            'comment' => $comment,
        ]);
    }

    private function ensureMarkedDefaultRoute(
        $api,
        string $mark,
        string $gateway,
        string $interface,
        string $version
    ): void {
        $routeGateway = strpos($gateway, '%') === false
            ? $gateway . '%' . $interface
            : $gateway;

        $comment = 'BAJAMA:TRAFFIC:ROUTE:' . $mark;
        foreach ($api->command('/ip/route/print') as $row) {
            $rowMark = (string)($row['routing-mark'] ?? $row['routing-table'] ?? '');
            $sameTarget = (string)($row['dst-address'] ?? '') === '0.0.0.0/0'
                && ($rowMark === $mark || strpos((string)($row['comment'] ?? ''), $comment) !== false);
            if ($sameTarget && !empty($row['.id'])) {
                $api->command('/ip/route/remove', ['.id' => (string)$row['.id']]);
            }
        }

        $attributes = [
            'dst-address' => '0.0.0.0/0',
            'gateway' => $routeGateway,
            'distance' => '1',
            'comment' => $comment,
        ];

        if (version_compare($version, '7.0', '>=')) {
            $attributes['routing-table'] = $mark;
        } else {
            $attributes['routing-mark'] = $mark;
        }

        $api->command('/ip/route/add', $attributes);
    }

    private function slug(string $value): string
    {
        $slug = strtoupper(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $value), '-'));
        return $slug !== '' ? $slug : 'TRAFFIC';
    }
}
