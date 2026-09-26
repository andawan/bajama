<?php
declare(strict_types=1);

namespace BAJAMA\Network;

use BAJAMA\Core\Audit;
use PDO;
use RuntimeException;

/**
 * Menerapkan entitas konfigurasi BAJAMA ke RouterOS secara idempoten.
 * Semua target dicari menggunakan marker komentar yang dikelola BAJAMA.
 */
final class RouterOSProvisioningService
{
    private PDO $db;
    private int $organizationId;

    public function __construct(PDO $db, int $organizationId)
    {
        $this->db = $db;
        $this->organizationId = $organizationId;
    }

    public function apply(string $sourceType, int $sourceId): array
    {
        $entity = $this->loadEntity($sourceType, $sourceId);
        $routerId = (int)($entity['router_id'] ?? 0);
        if ($routerId <= 0) {
            throw new RuntimeException('Entitas belum memiliki router MikroTik.');
        }

        $router = MikroTik::find($this->db, $this->organizationId, $routerId);
        if (!$router) {
            throw new RuntimeException('Router MikroTik tidak ditemukan dalam tenant ini.');
        }

        $definitions = $this->definitions($sourceType, $entity);
        if (!$definitions) {
            throw new RuntimeException(
                'Entitas ' . $sourceType . ' memerlukan adapter khusus atau belum didukung.'
            );
        }

        $api = MikroTik::connect($router);
        $links = new MikroTikResourceLinkService($this->db);
        $previousLinks = $this->sourceLinks($sourceType, (int)$entity['id'], $routerId);
        $results = [];
        try {
            foreach ($definitions as $definition) {
                $marker = $links->marker($sourceType, (int)$entity['id']) . ':' . $definition['resource'];
                $attributes = $definition['attributes'];
                if ($definition['path'] !== '/ip/pool') {
                    $attributes['comment'] = $this->withMarker(
                        (string)($attributes['comment'] ?? ''),
                        $marker
                    );
                }
                $existing = $this->findByMarker($api, $definition['path'], $marker, $attributes);

                if ($existing && !empty($existing['.id'])) {
                    $attributes['.id'] = $existing['.id'];
                    $api->raw($definition['path'] . '/set', $attributes);
                    $routerosId = (string)$existing['.id'];
                    $operation = 'updated';
                } else {
                    $conflict = $this->findUserConflict($api, $definition['path'], $attributes);
                    if ($conflict) {
                        throw new RuntimeException(
                            'Apply dibatalkan: resource user sudah ada pada ' . $definition['path'] .
                            ' (' . $this->conflictSummary($conflict) . '). BAJAMA tidak menimpa atau menghapus konfigurasi user.'
                        );
                    }
                    $created = $api->raw($definition['path'] . '/add', $attributes);
                    $routerosId = (string)($created[0]['.id'] ?? '');
                    if ($routerosId === '') {
                        throw new RuntimeException('RouterOS tidak mengembalikan ID objek baru.');
                    }
                    $operation = 'created';
                }

                $links->save(
                    $this->organizationId,
                    $routerId,
                    $sourceType,
                    (int)$entity['id'],
                    $definition['resource'],
                    $routerosId,
                    $marker,
                    true
                );
                $results[] = [
                    'resource' => $definition['resource'],
                    'routeros_id' => $routerosId,
                    'operation' => $operation,
                ];
            }

            $activeResources = array_column($results, 'resource');
            foreach ($previousLinks as $previousLink) {
                if (in_array((string)$previousLink['resource'], $activeResources, true)) {
                    continue;
                }
                $path = $this->resourcePath((string)$previousLink['resource']);
                if ($path !== null) {
                    if ($this->ownsResource($api, $path, (string)$previousLink['routeros_id'], (string)$previousLink['marker'])) {
                        $api->raw($path . '/remove', ['.id' => $previousLink['routeros_id']]);
                    }
                }
                $links->delete(
                    $this->organizationId,
                    $routerId,
                    (string)$previousLink['marker']
                );
            }
        } finally {
            $api->disconnect();
        }

        Audit::log(
            $this->db,
            'routeros_apply',
            'network',
            $sourceType,
            (int)$entity['id'],
            ['resources' => array_column($results, 'resource')]
        );
        return ['source_type' => $sourceType, 'source_id' => (int)$entity['id'], 'resources' => $results];
    }

    public function setEnabled(string $sourceType, int $sourceId, bool $enabled): array
    {
        $entity = $this->loadEntity($sourceType, $sourceId);
        $routerId = (int)($entity['router_id'] ?? 0);
        $router = MikroTik::find($this->db, $this->organizationId, $routerId);
        if (!$router) {
            throw new RuntimeException('Router MikroTik tidak ditemukan dalam tenant ini.');
        }
        $links = $this->sourceLinks($sourceType, $sourceId, $routerId);
        if (!$links) {
            throw new RuntimeException(
                'Entitas belum pernah di-Apply ke MikroTik. Gunakan tombol Apply terlebih dahulu.'
            );
        }
        $api = MikroTik::connect($router);
        $changed = 0;
        try {
            foreach (array_reverse($links) as $link) {
                if (in_array((string)$link['resource'], ['ip_pool', 'dhcp_network'], true)) {
                    continue;
                }
                $path = $this->resourcePath((string)$link['resource']);
                if ($path === null) {
                    continue;
                }
                if (!$this->ownsResource($api, $path, (string)$link['routeros_id'], (string)$link['marker'])) {
                    $linksStmt = $this->db->prepare('DELETE FROM mikrotik_resource_links WHERE organization_id=? AND router_id=? AND source_type=? AND source_id=? AND marker=?');
                    $linksStmt->execute([$this->organizationId, $routerId, $sourceType, $sourceId, $link['marker']]);
                    continue;
                }
                $api->raw($path . ($enabled ? '/enable' : '/disable'), ['.id' => $link['routeros_id']]);
                $changed++;
            }
        } finally {
            $api->disconnect();
        }
        Audit::log($this->db, $enabled ? 'routeros_enable' : 'routeros_disable', 'network', $sourceType, $sourceId);
        return ['changed' => $changed, 'enabled' => $enabled];
    }

    public function remove(string $sourceType, int $sourceId): array
    {
        $entity = $this->loadEntity($sourceType, $sourceId);
        $routerId = (int)($entity['router_id'] ?? 0);
        $router = MikroTik::find($this->db, $this->organizationId, $routerId);
        if (!$router) {
            throw new RuntimeException('Router MikroTik tidak ditemukan dalam tenant ini.');
        }
        $api = MikroTik::connect($router);
        $links = $this->sourceLinks($sourceType, $sourceId, $routerId);
        $removed = 0;
        try {
            foreach (array_reverse($links) as $link) {
                $path = $this->resourcePath((string)$link['resource']);
                if ($path === null) {
                    continue;
                }
                if ($this->ownsResource($api, $path, (string)$link['routeros_id'], (string)$link['marker'])) {
                    $api->raw($path . '/remove', ['.id' => $link['routeros_id']]);
                    $removed++;
                }
            }
        } finally {
            $api->disconnect();
        }
        $stmt = $this->db->prepare(
            'DELETE FROM mikrotik_resource_links
             WHERE organization_id=? AND router_id=? AND source_type=? AND source_id=?'
        );
        $stmt->execute([$this->organizationId, $routerId, $sourceType, $sourceId]);
        Audit::log($this->db, 'routeros_remove', 'network', $sourceType, $sourceId);
        return ['removed' => $removed];
    }

    private function loadEntity(string $sourceType, int $sourceId): array
    {
        $tables = [
            'network_isp' => 'network_isps',
            'network_lan' => 'network_lans',
            'network_route' => 'network_routes',
            'network_firewall' => 'network_firewall_rules',
            'network_static_ip' => 'network_static_ips',
            'network_hotspot_voucher' => 'network_hotspot_vouchers',
            'subscription_hotspot' => 'subscriptions',
            'network_load_balance' => 'network_load_balances',
            'network_ftth' => 'network_ftth_services',
            'network_olt' => 'network_olts',
        ];
        $table = $tables[$sourceType] ?? null;
        if ($table === null || $sourceId <= 0) {
            throw new RuntimeException('Jenis atau ID entitas tidak valid.');
        }
        $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE id=? AND organization_id=? LIMIT 1");
        $stmt->execute([$sourceId, $this->organizationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Entitas jaringan tidak ditemukan.');
        }
        return $row;
    }

    private function definitions(string $sourceType, array $row): array
    {
        switch ($sourceType) {
            case 'network_route':
                return [[
                    'resource' => 'ip_route', 'path' => '/ip/route',
                    'attributes' => array_filter([
                        'dst-address' => (string)$row['destination'],
                        'gateway' => (string)($row['gateway'] ?: ($row['interface_name'] ?? '')),
                        'distance' => (string)(int)$row['distance'],
                        'scope' => (string)(int)$row['scope'],
                        'target-scope' => (string)(int)$row['target_scope'],
                        'check-gateway' => strtolower((string)$row['check_gateway']),
                        'routing-table' => (string)($row['routing_table'] ?? 'main'),
                        'disabled' => (int)$row['enabled'] ? 'no' : 'yes',
                        'comment' => (string)($row['comment'] ?? ''),
                    ], static fn($value) => $value !== ''),
                ]];
            case 'network_lan':
                $interface = (string)$row['interface_name'];
                $definitions = [];
                if (strtoupper((string)$row['network_type']) === 'VLAN') {
                    $vlanInterface = 'BAJAMA-LAN-' . (int)$row['id'];
                    $definitions[] = [
                        'resource' => 'interface_vlan', 'path' => '/interface/vlan',
                        'attributes' => [
                            'name' => $vlanInterface,
                            'vlan-id' => (string)(int)$row['vlan_id'],
                            'interface' => $interface,
                            'disabled' => (int)$row['enabled'] ? 'no' : 'yes',
                            'comment' => (string)($row['description'] ?? $row['name'] ?? ''),
                        ],
                    ];
                }
                $definitions[] = [
                    'resource' => 'ip_address', 'path' => '/ip/address',
                    'attributes' => [
                        'address' => $this->addressWithPrefix((string)$row['ip_address'], (string)$row['subnet']),
                        'interface' => $vlanInterface ?? $interface,
                        'disabled' => (int)$row['enabled'] ? 'no' : 'yes',
                        'comment' => (string)($row['description'] ?? $row['name'] ?? ''),
                    ],
                ];
                if ((int)$row['dhcp_enabled'] === 1) {
                    if (empty($row['dhcp_start']) || empty($row['dhcp_end'])) {
                        throw new RuntimeException('DHCP LAN membutuhkan DHCP start dan DHCP end.');
                    }
                    $poolName = 'BAJAMA-' . (int)$row['id'];
                    $definitions[] = [
                        'resource' => 'ip_pool', 'path' => '/ip/pool',
                        'attributes' => [
                            'name' => $poolName,
                            'ranges' => (string)$row['dhcp_start'] . '-' . (string)$row['dhcp_end'],
                        ],
                    ];
                    $definitions[] = [
                        'resource' => 'dhcp_network', 'path' => '/ip/dhcp-server/network',
                        'attributes' => array_filter([
                            'address' => $this->addressWithPrefix((string)$row['ip_address'], (string)$row['subnet']),
                            'gateway' => (string)($row['gateway'] ?: $row['ip_address']),
                            'dns-server' => implode(',', array_filter([(string)($row['dns_primary'] ?? ''), (string)($row['dns_secondary'] ?? '')])),
                            'comment' => (string)($row['description'] ?? $row['name'] ?? ''),
                        ], static fn($value) => $value !== ''),
                    ];
                    $definitions[] = [
                        'resource' => 'dhcp_server', 'path' => '/ip/dhcp-server',
                        'attributes' => [
                            'name' => 'BAJAMA-' . (int)$row['id'],
                            'interface' => $vlanInterface ?? $interface,
                            'address-pool' => $poolName,
                            'disabled' => (int)$row['enabled'] ? 'no' : 'yes',
                            'comment' => (string)($row['description'] ?? $row['name'] ?? ''),
                        ],
                    ];
                }
                return $definitions;
            case 'network_static_ip':
                if (trim((string)($row['interface_name'] ?? '')) === '') {
                    throw new RuntimeException('Static IP membutuhkan interface MikroTik.');
                }
                $definitions = [[
                    'resource' => 'ip_address', 'path' => '/ip/address',
                    'attributes' => [
                        'address' => $this->addressWithPrefix((string)$row['ip_address'], null),
                        'interface' => (string)($row['interface_name'] ?? ''),
                        'disabled' => (int)$row['enabled'] ? 'no' : 'yes',
                        'comment' => (string)($row['comment'] ?? ''),
                    ],
                ]];
                $speed = $this->subscriptionSpeed((int)($row['subscription_id'] ?? 0));
                if ($speed !== null) {
                    $definitions[] = [
                        'resource' => 'simple_queue', 'path' => '/queue/simple',
                        'attributes' => [
                            'name' => 'BAJAMA-STATIC-' . (int)$row['id'],
                            'target' => $this->addressWithPrefix((string)$row['ip_address'], null),
                            'max-limit' => $speed,
                            'disabled' => (int)$row['enabled'] ? 'no' : 'yes',
                            'comment' => (string)($row['comment'] ?? ''),
                        ],
                    ];
                }
                return $definitions;
            case 'network_firewall':
                return [[
                    'resource' => 'firewall_filter', 'path' => '/ip/firewall/filter',
                    'attributes' => array_filter([
                        'chain' => strtolower((string)$row['chain']),
                        'action' => strtolower((string)$row['action']),
                        'protocol' => strtolower((string)$row['protocol']) === 'any' ? '' : strtolower((string)$row['protocol']),
                        'src-address' => (string)($row['src_address'] ?? ''),
                        'dst-address' => (string)($row['dst_address'] ?? ''),
                        'src-port' => (string)($row['src_port'] ?? ''),
                        'dst-port' => (string)($row['dst_port'] ?? ''),
                        'disabled' => (int)$row['enabled'] ? 'no' : 'yes',
                        'comment' => (string)($row['comment'] ?? ''),
                        'place-before' => (string)max(0, ((int)($row['position'] ?? 1) - 1)),
                    ], static fn($value) => $value !== ''),
                ]];
            case 'network_hotspot_voucher':
                return [[
                    'resource' => 'hotspot_user', 'path' => '/ip/hotspot/user',
                    'attributes' => array_filter([
                        'name' => (string)$row['username'],
                        'password' => (string)$row['password'],
                        'profile' => (string)$row['profile'],
                        'address' => (string)($row['address'] ?? ''),
                        'mac-address' => (string)($row['mac_address'] ?? ''),
                        'limit-uptime' => (string)($row['limit_uptime'] ?? ''),
                        'limit-bytes-total' => (string)($row['limit_bytes_total'] ?? ''),
                        'server' => (string)($row['hotspot_server'] ?? ''),
                        'disabled' => ((int)$row['enabled'] && (string)$row['status'] !== 'DISABLED' && (string)$row['status'] !== 'EXPIRED') ? 'no' : 'yes',
                        'comment' => (string)($row['note'] ?? ''),
                    ], static fn($value) => $value !== ''),
                ]];
            case 'subscription_hotspot':
                if (strtoupper((string)$row['service_type']) !== 'HOTSPOT') {
                    throw new RuntimeException('Subscription ini bukan layanan Hotspot.');
                }
                if (trim((string)$row['username']) === '' || trim((string)$row['password_encrypted']) === '') {
                    throw new RuntimeException('Username dan password Hotspot belum diisi.');
                }
                $password = MikroTik::decryptPassword((string)$row['password_encrypted']);
                $definitions = [[
                    'resource' => 'hotspot_user', 'path' => '/ip/hotspot/user',
                    'attributes' => [
                        'name' => (string)$row['username'],
                        'password' => $password,
                        'profile' => (string)($row['profile'] ?? 'default'),
                        'address' => (string)($row['hotspot_address'] ?? ''),
                        'mac-address' => (string)($row['hotspot_mac_address'] ?? ''),
                        'disabled' => (string)$row['status'] === 'ACTIVE' ? 'no' : 'yes',
                        'comment' => 'BAJAMA-SUB-' . (int)$row['id'],
                    ],
                ]];
                $download = max(0, (int)($row['speed_download'] ?? 0));
                $upload = max(0, (int)($row['speed_upload'] ?? 0));
                if (($download > 0 || $upload > 0) && trim((string)($row['hotspot_address'] ?? '')) !== '') {
                    $definitions[] = [
                        'resource' => 'simple_queue', 'path' => '/queue/simple',
                        'attributes' => [
                            'name' => 'BAJAMA-SUB-' . (int)$row['id'],
                            'target' => $this->addressWithPrefix((string)$row['hotspot_address'], null),
                            'max-limit' => $upload . 'M/' . $download . 'M',
                            'disabled' => (string)$row['status'] === 'ACTIVE' ? 'no' : 'yes',
                            'comment' => 'BAJAMA-SUB-' . (int)$row['id'],
                        ],
                    ];
                }
                return $definitions;
            case 'network_isp':
                if (empty($row['gateway'])) {
                    throw new RuntimeException('WAN/ISP membutuhkan gateway untuk membuat route.');
                }
                $definitions = [[
                    'resource' => 'ip_route', 'path' => '/ip/route',
                    'attributes' => [
                        'dst-address' => '0.0.0.0/0',
                        'gateway' => (string)$row['gateway'],
                        'distance' => (string)(int)$row['distance'],
                        'check-gateway' => strtolower((string)$row['check_gateway']),
                        'disabled' => (int)$row['enabled'] ? 'no' : 'yes',
                        'comment' => (string)$row['name'],
                    ],
                ]];
                if (!empty($row['ip_address']) && !empty($row['interface_name'])) {
                    $definitions[] = [
                        'resource' => 'ip_address', 'path' => '/ip/address',
                        'attributes' => [
                            'address' => $this->addressWithPrefix(
                                (string)$row['ip_address'],
                                (string)($row['subnet'] ?? '')
                            ),
                            'interface' => (string)$row['interface_name'],
                            'disabled' => (int)$row['enabled'] ? 'no' : 'yes',
                            'comment' => (string)$row['name'],
                        ],
                    ];
                }
                return $definitions;
            default:
                return [];
        }
    }

    private function findByMarker(RouterOSApi $api, string $path, string $marker, array $attributes = []): ?array
    {
        foreach ($api->raw($path . '/print') as $row) {
            if (strpos((string)($row['comment'] ?? ''), $marker) !== false) {
                return $row;
            }
            if ($path === '/ip/pool' && isset($attributes['name'])
                && (string)($row['name'] ?? '') === (string)$attributes['name']) {
                return $row;
            }
        }
        return null;
    }

    private function ownsResource(RouterOSApi $api, string $path, string $routerosId, string $marker): bool
    {
        if ($routerosId === '' || $marker === '') {
            return false;
        }
        foreach ($api->raw($path . '/print') as $row) {
            if ((string)($row['.id'] ?? '') === $routerosId) {
                return strpos((string)($row['comment'] ?? ''), $marker) !== false
                    || ($path === '/ip/pool' && strpos((string)($row['name'] ?? ''), 'BAJAMA-') === 0);
            }
        }
        return false;
    }

    private function findUserConflict(RouterOSApi $api, string $path, array $attributes): ?array
    {
        $identityKeys = [
            '/ip/route' => ['dst-address', 'gateway'],
            '/ip/address' => ['address', 'interface'],
            '/interface/vlan' => ['name', 'vlan-id', 'interface'],
            '/ip/firewall/filter' => ['chain', 'action', 'protocol', 'src-address', 'dst-address', 'src-port', 'dst-port'],
            '/ip/firewall/mangle' => ['chain', 'action', 'protocol', 'src-address', 'dst-address', 'dst-port', 'new-routing-mark'],
            '/ip/dhcp-server' => ['name', 'interface'],
            '/ip/dhcp-server/network' => ['address'],
            '/queue/simple' => ['name', 'target'],
        ];
        if (!isset($identityKeys[$path])) {
            return null;
        }
        foreach ($api->raw($path . '/print') as $row) {
            $comment = (string)($row['comment'] ?? '');
            if (strpos($comment, 'BAJAMA_') !== false || strpos($comment, 'BAJAMA:') !== false) {
                continue;
            }
            $matched = true;
            $hasIdentity = false;
            foreach ($identityKeys[$path] as $key) {
                if (!array_key_exists($key, $attributes) || (string)$attributes[$key] === '') {
                    continue;
                }
                $hasIdentity = true;
                if ((string)($row[$key] ?? '') !== (string)$attributes[$key]) {
                    $matched = false;
                    break;
                }
            }
            if ($matched && $hasIdentity) {
                return $row;
            }
        }
        return null;
    }

    private function conflictSummary(array $row): string
    {
        return (string)($row['.id'] ?? $row['name'] ?? $row['dst-address'] ?? 'unknown');
    }

    private function sourceLinks(string $sourceType, int $sourceId, int $routerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM mikrotik_resource_links
             WHERE organization_id=? AND router_id=? AND source_type=? AND source_id=?'
        );
        $stmt->execute([$this->organizationId, $routerId, $sourceType, $sourceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function resourcePath(string $resource): ?string
    {
        return [
            'ip_route' => '/ip/route',
            'ip_address' => '/ip/address',
            'interface_vlan' => '/interface/vlan',
            'ip_pool' => '/ip/pool',
            'dhcp_network' => '/ip/dhcp-server/network',
            'dhcp_server' => '/ip/dhcp-server',
            'simple_queue' => '/queue/simple',
            'firewall_filter' => '/ip/firewall/filter',
            'hotspot_user' => '/ip/hotspot/user',
        ][$resource] ?? null;
    }

    private function withMarker(string $comment, string $marker): string
    {
        return strpos($comment, $marker) === false
            ? trim($comment . ($comment !== '' ? ' | ' : '') . $marker)
            : $comment;
    }

    private function addressWithPrefix(string $ip, ?string $subnet): string
    {
        if (strpos($ip, '/') !== false) {
            return $ip;
        }
        if ($subnet !== null && preg_match('/\/(\d{1,3})$/', $subnet, $match)) {
            return $ip . '/' . $match[1];
        }
        return $ip . (strpos($ip, ':') !== false ? '/128' : '/32');
    }

    private function subscriptionSpeed(int $subscriptionId): ?string
    {
        if ($subscriptionId <= 0) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT sp.speed_download, sp.speed_upload
             FROM subscriptions s
             INNER JOIN service_plans sp
                 ON sp.id = s.service_plan_id
                AND sp.organization_id = s.organization_id
             WHERE s.id=? AND s.organization_id=?
             LIMIT 1'
        );
        $stmt->execute([$subscriptionId, $this->organizationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $download = max(0, (int)$row['speed_download']);
        $upload = max(0, (int)$row['speed_upload']);
        return ($download === 0 && $upload === 0) ? null : $upload . 'M/' . $download . 'M';
    }
}