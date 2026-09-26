<?php

declare(strict_types=1);

namespace BAJAMA\Network;

use PDO;
use RuntimeException;

/** Builds a complete, marker-owned load-balance policy on RouterOS. */
final class LoadBalanceProvisioner
{
    private PDO $db;
    private int $organizationId;

    public function __construct(PDO $db, int $organizationId)
    {
        $this->db = $db;
        $this->organizationId = $organizationId;
    }

    public function apply(int $loadBalanceId): array
    {
        $config = $this->loadConfig($loadBalanceId);
        $router = MikroTik::find($this->db, $this->organizationId, (int)$config['router_id']);
        if (!$router) throw new RuntimeException('Router MikroTik tidak ditemukan.');
        $isps = $this->loadIsps((int)$config['router_id']);
        $lans = $this->loadLans((int)$config['router_id']);
        if (!$isps) throw new RuntimeException('Belum ada WAN aktif pada router ini.');

        $api = MikroTik::connect($router);
        $marker = 'BAJAMA_LB:' . $loadBalanceId;
        try {
            $version = $this->routerVersion($api);
            if (!$lans) {
                $lans = $this->discoverRouterLan($api, $isps);
            }
            if (!$lans) throw new RuntimeException('Belum ada LAN/VLAN/bridge aktif untuk client pada router ini.');
            $checks = $this->preflight($api, $isps, $lans, $version);
            $this->removeManaged($api, $marker);
            $mode = strtoupper((string)$config['mode']);
            $algorithm = $this->algorithm((string)($config['algorithm'] ?? 'both-addresses-and-ports'));
            $health = (int)$config['health_check'] === 1 ? 'ping' : 'none';
            $tables = [];
            $created = 0;
            $lanList = $this->lanListName($loadBalanceId);
            $localList = $this->localListName($loadBalanceId);

            foreach ($lans as $lan) {
                $address = $this->networkAddress((string)$lan['ip_address'], (string)$lan['subnet']);
                if ($address !== '') {
                    $this->ensureAddressList($api, $lanList, $address, $marker . ':LAN:' . (int)$lan['id'], $created);
                    $this->ensureAddressList($api, $localList, $address, $marker . ':LOCAL_LAN:' . (int)$lan['id'], $created);
                }
            }
            foreach ($isps as $isp) {
                $address = $this->networkAddress((string)($isp['ip_address'] ?? ''), (string)($isp['subnet'] ?? ''));
                if ($address !== '') {
                    $this->ensureAddressList($api, $localList, $address, $marker . ':LOCAL_WAN:' . (int)$isp['id'], $created);
                }
            }
            foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'] as $privateNetwork) {
                $this->ensureAddressList($api, $localList, $privateNetwork, $marker . ':LOCAL_PRIVATE:' . str_replace('/', '_', $privateNetwork), $created);
            }
            foreach ($this->loadStaticIps((int)$config['router_id']) as $static) {
                $this->ensureAddressList($api, $lanList, $this->addressWithPrefix((string)$static['ip_address'], null), $marker . ':STATIC:' . (int)$static['id'], $created);
            }

            if (in_array($mode, ['PCC', 'NTH'], true)) {
                foreach ($isps as $index => $isp) {
                    $table = $this->tableName($loadBalanceId, $index);
                    $tables[$index] = $table;
                    if (version_compare($version, '7.0', '>=')) {
                        $this->ensureRoutingTable($api, $table, $marker . ':TABLE:' . $index, $created);
                    }
                }
            }

            foreach ($isps as $index => $isp) {
                $table = $tables[$index] ?? 'main';
                $gateway = version_compare($version, '7.0', '>=')
                    ? $this->gateway((string)$isp['gateway'], (string)$isp['interface_name'])
                    : (string)$isp['gateway'];
                $recursiveGateway = $gateway;
                $recursive = $mode === 'PCC'
                    && (int)$config['health_check'] === 1
                    && filter_var((string)$isp['gateway'], FILTER_VALIDATE_IP);
                if ($recursive) {
                    $probe = $this->probeAddress($index);
                    if ($this->ensureProbeRoute($api, $probe, $gateway, $marker . ':PROBE:' . (int)$isp['id'], $version)) {
                        $created++;
                    }
                    $recursiveGateway = $probe;
                }
                if (in_array($mode, ['PCC', 'NTH'], true) && $table !== 'main') {
                    $connected = $this->networkAddress((string)($isp['ip_address'] ?? ''), (string)($isp['subnet'] ?? ''));
                    if ($connected !== '') {
                        $this->route($api, $connected, (string)$isp['interface_name'], $table, 1, 'none', $marker . ':CONNECTED:' . (int)$isp['id'], $version);
                        $created++;
                    }
                }
                $distance = $mode === 'FAILOVER' ? (int)$isp['distance'] + $index : 1;
                $this->route($api, '0.0.0.0/0', $recursiveGateway, $table, max(1, $distance), $health, $marker . ':ROUTE:' . (int)$isp['id'], $version, $recursive ? 11 : null);
                $created++;
                if ($this->ensureMasquerade($api, (string)$isp['interface_name'], $lanList, $marker . ':NAT:' . (int)$isp['id'])) {
                    $created++;
                }
            }

            if (in_array($mode, ['PCC', 'NTH'], true)) {
                $count = count($isps);

                // Bilhanet-style local bypass: local-to-local traffic must
                // never be forced into a WAN policy route.
                $this->add($api, '/ip/firewall/mangle', [
                    'chain' => 'prerouting',
                    'src-address-list' => $lanList,
                    'dst-address-list' => $localList,
                    'action' => 'accept',
                    'comment' => $marker . ':BYPASS_LOCAL:PREROUTING',
                ]);
                $created++;
                $this->add($api, '/ip/firewall/mangle', [
                    'chain' => 'output',
                    'dst-address-list' => $localList,
                    'action' => 'accept',
                    'comment' => $marker . ':BYPASS_LOCAL:OUTPUT',
                ]);
                $created++;

                // Preserve the return path for connections arriving from each WAN.
                foreach ($isps as $index => $isp) {
                    $this->add($api, '/ip/firewall/mangle', [
                        'chain' => 'prerouting',
                        'in-interface' => (string)$isp['interface_name'],
                        'connection-state' => 'new',
                        'connection-mark' => 'no-mark',
                        'action' => 'mark-connection',
                        'new-connection-mark' => $marker . '-CONN-' . $index,
                        'passthrough' => 'yes',
                        'comment' => $marker . ':INBOUND:' . $index,
                    ]);
                    $created++;
                }

                foreach ($isps as $index => $isp) {
                    $connection = $marker . '-CONN-' . $index;
                    $class = $mode === 'PCC' ? $algorithm . ':' . $count . '/' . $index : $count . ',' . $index;
                    $match = $mode === 'PCC' ? ['per-connection-classifier' => $class] : ['nth' => $class];

                    // Router-originated connections also need a deterministic WAN.
                    $this->add($api, '/ip/firewall/mangle', array_merge([
                        'chain' => 'output',
                        'connection-state' => 'new',
                        'connection-mark' => 'no-mark',
                        'dst-address-type' => '!local',
                        'action' => 'mark-connection',
                        'new-connection-mark' => $connection,
                        'passthrough' => 'yes',
                        'comment' => $marker . ':OUTPUT_MARK:' . $index,
                    ], $match));
                    $created++;

                    foreach ($this->markingInterfaces($lans) as $interface) {
                        $this->add($api, '/ip/firewall/mangle', array_merge(['chain' => 'prerouting', 'in-interface' => $interface, 'src-address-list' => $lanList, 'dst-address-list' => '!' . $localList, 'dst-address-type' => '!local', 'connection-state' => 'new', 'connection-mark' => 'no-mark', 'action' => 'mark-connection', 'new-connection-mark' => $connection, 'passthrough' => 'yes', 'comment' => $marker . ':MARK:' . $index . ':' . sha1($interface)], $match));
                        $created++;
                    }
                    $routingMark = $tables[$index] ?? $this->tableName($loadBalanceId, $index);
                    foreach ($this->markingInterfaces($lans) as $interface) {
                        $this->add($api, '/ip/firewall/mangle', ['chain' => 'prerouting', 'in-interface' => $interface, 'connection-mark' => $connection, 'action' => 'mark-routing', 'new-routing-mark' => $routingMark, 'passthrough' => 'no', 'comment' => $marker . ':ROUTING:' . $index . ':' . sha1($interface)]);
                        $created++;
                    }
                    $this->add($api, '/ip/firewall/mangle', ['chain' => 'output', 'connection-mark' => $connection, 'action' => 'mark-routing', 'new-routing-mark' => $routingMark, 'passthrough' => 'no', 'comment' => $marker . ':ROUTING:' . $index . ':output']);
                    $created++;
                }
            }
            return ['mode' => $mode, 'isps' => count($isps), 'lans' => count($lans), 'created' => $created, 'checks' => $checks];
        } finally {
            $api->disconnect();
        }
    }

    public function remove(int $loadBalanceId): int
    {
        $config = $this->loadConfig($loadBalanceId);
        $router = MikroTik::find($this->db, $this->organizationId, (int)$config['router_id']);
        if (!$router) return 0;
        $api = MikroTik::connect($router);
        try { return $this->removeManaged($api, 'BAJAMA_LB:' . $loadBalanceId); } finally { $api->disconnect(); }
    }

    /** Tests a WAN from the MikroTik itself, not from the application server. */
    public function testWan(int $ispId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM network_isps WHERE id=? AND organization_id=? LIMIT 1');
        $stmt->execute([$ispId, $this->organizationId]);
        $isp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$isp) throw new RuntimeException('WAN tidak ditemukan.');
        $router = MikroTik::find($this->db, $this->organizationId, (int)$isp['router_id']);
        if (!$router) throw new RuntimeException('Router WAN tidak ditemukan.');
        $api = MikroTik::connect($router);
        try {
            $rows = $api->raw('/ping', ['address' => (string)$isp['gateway'], 'count' => '3', 'interface' => (string)$isp['interface_name']]);
            $received = 0; $latency = null;
            foreach ($rows as $row) if (isset($row['time'])) { $received++; $latency = (float)preg_replace('/[^0-9.].*/', '', (string)$row['time']); }
            return ['online' => $received > 0, 'latency' => $latency, 'received' => $received];
        } finally { $api->disconnect(); }
    }

    private function loadConfig(int $id): array { $s = $this->db->prepare('SELECT * FROM network_load_balances WHERE id=? AND organization_id=? LIMIT 1'); $s->execute([$id, $this->organizationId]); $r = $s->fetch(PDO::FETCH_ASSOC); if (!$r) throw new RuntimeException('Load balance tidak ditemukan.'); return $r; }
    private function loadIsps(int $routerId): array { $s = $this->db->prepare('SELECT * FROM network_isps WHERE organization_id=? AND router_id=? AND enabled=1 ORDER BY distance,id'); $s->execute([$this->organizationId, $routerId]); return $s->fetchAll(PDO::FETCH_ASSOC); }
    private function loadLans(int $routerId): array { $s = $this->db->prepare('SELECT * FROM network_lans WHERE organization_id=? AND router_id=? AND enabled=1 ORDER BY id'); $s->execute([$this->organizationId, $routerId]); return $s->fetchAll(PDO::FETCH_ASSOC); }
    private function loadStaticIps(int $routerId): array { $s = $this->db->prepare('SELECT id,ip_address FROM network_static_ips WHERE organization_id=? AND enabled=1 AND interface_name IS NOT NULL AND interface_name<>""'); $s->execute([$this->organizationId]); return $s->fetchAll(PDO::FETCH_ASSOC); }

    /**
     * Existing RouterOS LANs do not need to be duplicated in BAJAMA.
     * Discover static IPs on non-WAN interfaces and use them as client sources.
     */
    private function discoverRouterLan(RouterOSApi $api, array $isps): array
    {
        $wanInterfaces = [];
        foreach ($isps as $isp) {
            $wanInterfaces[(string)$isp['interface_name']] = true;
        }
        $result = [];
        foreach ($api->raw('/ip/address/print') as $address) {
            $interface = (string)($address['interface'] ?? $address['actual-interface'] ?? '');
            $value = (string)($address['address'] ?? '');
            if ($interface === '' || $value === '' || isset($wanInterfaces[$interface])) continue;
            if (!empty($address['dynamic']) && $address['dynamic'] === 'true') continue;
            [$ip, $prefix] = array_pad(explode('/', $value, 2), 2, '');
            if (!filter_var($ip, FILTER_VALIDATE_IP) || $prefix === '') continue;
            $candidate = [
                'id' => 0,
                'name' => 'RouterOS ' . $interface,
                'interface_name' => $interface,
                'ip_address' => $ip,
                'subnet' => $this->networkCidr($ip, (int)$prefix),
                'network_type' => stripos($interface, 'bridge') !== false ? 'BRIDGE' : 'LAN',
                'enabled' => 1,
            ];
            $existing = null;
            foreach ($result as $position => $item) {
                if ($item['interface_name'] === $interface && $item['ip_address'] === $ip) {
                    $existing = $position;
                    break;
                }
            }
            if ($existing === null) {
                $result[] = $candidate;
            } elseif ((int)$prefix < (int)preg_replace('/^.*\//', '', (string)$result[$existing]['subnet'])) {
                $result[$existing] = $candidate;
            }
        }
        return $result;
    }

    private function networkCidr(string $ip, int $prefix): string
    {
        if (strpos($ip, ':') !== false) return $ip . '/' . $prefix;
        $long = ip2long($ip);
        if ($long === false) return $ip . '/' . $prefix;
        $mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix));
        return long2ip($long & $mask) . '/' . $prefix;
    }

    private function preflight(RouterOSApi $api, array $isps, array $lans, string $version): array
    {
        $interfaces = [];
        foreach ($api->raw('/interface/print') as $row) $interfaces[(string)($row['name'] ?? '')] = $row;
        foreach ($isps as $isp) {
            $name = (string)$isp['interface_name'];
            if (!isset($interfaces[$name])) throw new RuntimeException('WAN interface tidak ditemukan di MikroTik: ' . $name);
            if (trim((string)$isp['gateway']) === '') throw new RuntimeException('WAN ' . $isp['name'] . ' belum memiliki gateway.');
        }
        foreach ($lans as $lan) { $name = $this->lanInterface($lan); if (!isset($interfaces[$name])) throw new RuntimeException('LAN/VLAN interface tidak ditemukan di MikroTik: ' . $name); }
        return ['routeros_version' => $version, 'wan_interfaces_checked' => count($isps), 'lan_interfaces_checked' => count($lans), 'existing_routes' => count($api->raw('/ip/route/print')), 'existing_addresses' => count($api->raw('/ip/address/print')), 'existing_mangle' => count($api->raw('/ip/firewall/mangle/print')), 'existing_address_lists' => count($api->raw('/ip/firewall/address-list/print'))];
    }

    private function routerVersion(RouterOSApi $api): string { return (string)($api->raw('/system/resource/print')[0]['version'] ?? '6.0'); }
    private function add(RouterOSApi $api, string $path, array $attributes): void
    {
        try {
            $api->raw($path . '/add', $attributes);
        } catch (\Throwable $e) {
            $details = [];
            foreach (['address', 'interface', 'list', 'dst-address', 'gateway', 'routing-mark', 'out-interface'] as $key) {
                if (isset($attributes[$key])) $details[] = $key . '=' . (string)$attributes[$key];
            }
            throw new RuntimeException(
                'Gagal membuat resource RouterOS ' . $path .
                ($details ? ' (' . implode(', ', $details) . ')' : '') .
                ': ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }
    private function ensureAddressList(RouterOSApi $api, string $list, string $address, string $comment, int &$created): void
    {
        if ($address === '') return;
        foreach ($api->raw('/ip/firewall/address-list/print') as $row) {
            if ((string)($row['list'] ?? '') === $list && (string)($row['address'] ?? '') === $address) return;
        }
        $this->add($api, '/ip/firewall/address-list', ['list' => $list, 'address' => $address, 'comment' => $comment]);
        $created++;
    }

    private function ensureMasquerade(RouterOSApi $api, string $outInterface, string $lanList, string $comment): bool
    {
        foreach ($api->raw('/ip/firewall/nat/print') as $row) {
            if ((string)($row['chain'] ?? '') !== 'srcnat' || (string)($row['action'] ?? '') !== 'masquerade') continue;
            if ((string)($row['out-interface'] ?? '') === $outInterface) return false;
        }
        $this->add($api, '/ip/firewall/nat', ['chain' => 'srcnat', 'src-address-list' => $lanList, 'out-interface' => $outInterface, 'action' => 'masquerade', 'comment' => $comment]);
        return true;
    }

    private function ensureRoutingTable(RouterOSApi $api, string $name, string $comment, int &$created): void
    {
        foreach ($api->raw('/routing/table/print') as $row) {
            if ((string)($row['name'] ?? '') === $name) return;
        }
        $this->add($api, '/routing/table', ['name' => $name, 'fib' => 'yes', 'comment' => $comment]);
        $created++;
    }
    private function route(RouterOSApi $api, string $destination, string $gateway, string $table, int $distance, string $check, string $comment, string $version, ?int $targetScope = null): void
    {
        $a = [
            'dst-address' => $destination,
            'gateway' => $gateway,
            'distance' => (string)$distance,
            'comment' => $comment,
        ];
        // RouterOS rejects an explicit "none" on some v6 builds. Omitting
        // the optional property has the same meaning and is version-safe.
        if (in_array(strtolower($check), ['ping', 'arp'], true)) {
            $a['check-gateway'] = strtolower($check);
        }
        if ($targetScope !== null) {
            $a['target-scope'] = (string)$targetScope;
        }
        if (version_compare($version, '7.0', '>=') && $table !== 'main') {
            $a['routing-table'] = $table;
        } elseif ($table !== 'main') {
            $a['routing-mark'] = $table;
        }
        $this->add($api, '/ip/route', $a);
    }

    private function ensureProbeRoute(RouterOSApi $api, string $probe, string $gateway, string $comment, string $version): bool
    {
        foreach ($api->raw('/ip/route/print') as $row) {
            if ((string)($row['dst-address'] ?? '') === $probe . '/32'
                && (string)($row['gateway'] ?? '') === $gateway
                && empty($row['routing-mark'])) {
                return false;
            }
        }
        $this->add($api, '/ip/route', [
            'dst-address' => $probe . '/32',
            'gateway' => $gateway,
            'scope' => '10',
            'comment' => $comment,
        ]);
        return true;
    }

    private function removeManaged(RouterOSApi $api, string $marker): int
    {
        $removed = 0;
        foreach (['/ip/firewall/mangle', '/ip/firewall/nat', '/ip/firewall/address-list', '/ip/route', '/ip/address', '/routing/table'] as $path) {
            try { foreach ($api->raw($path . '/print') as $row) { if (strpos((string)($row['comment'] ?? ''), $marker . ':') !== 0 || empty($row['.id'])) continue; $api->raw($path . '/remove', ['.id' => (string)$row['.id']]); $removed++; } } catch (\Throwable $e) { if ($path !== '/routing/table') throw $e; }
        }
        return $removed;
    }

    private function algorithm(string $value): string { $allowed = ['both-addresses', 'both-addresses-and-ports', 'src-address', 'dst-address', 'src-port', 'dst-port']; return in_array($value, $allowed, true) ? $value : 'both-addresses-and-ports'; }
    private function tableName(int $id, int $index): string { return 'BAJAMA-LB-' . $id . '-' . ($index + 1); }
    private function lanListName(int $id): string { return 'BAJAMA-LB-LAN-' . $id; }
    private function localListName(int $id): string { return 'BAJAMA-LB-LOCAL-' . $id; }
    private function probeAddress(int $index): string
    {
        $probes = ['8.8.8.8', '8.8.4.4', '1.1.1.1', '1.0.0.1', '208.67.222.222', '208.67.220.220'];
        return $probes[$index % count($probes)];
    }
    private function gateway(string $gateway, string $interface): string { return strpos($gateway, '%') === false ? $gateway . '%' . $interface : $gateway; }
    private function lanInterface(array $lan): string { return strtoupper((string)$lan['network_type']) === 'VLAN' ? 'BAJAMA-LAN-' . (int)$lan['id'] : (string)$lan['interface_name']; }
    private function markingInterfaces(array $lans): array { return array_values(array_unique(array_map(fn(array $lan): string => $this->lanInterface($lan), $lans))); }
    private function addressWithPrefix(string $ip, ?string $subnet): string { if ($ip === '') return ''; if (strpos($ip, '/') !== false) return $ip; if ($subnet && preg_match('/\/(\d{1,3})$/', $subnet, $m)) return $ip . '/' . $m[1]; return $ip . (strpos($ip, ':') !== false ? '/128' : '/32'); }
    private function networkAddress(string $ip, string $subnet): string { $prefix = null; if (preg_match('/\/(\d{1,3})$/', $subnet, $m)) $prefix = (int)$m[1]; if ($prefix === null && filter_var($subnet, FILTER_VALIDATE_IP)) { $binary = inet_pton($subnet); if ($binary !== false) { $prefix = 0; foreach (str_split($binary) as $byte) $prefix += substr_count(str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT), '1'); } } if ($prefix === null || !filter_var($ip, FILTER_VALIDATE_IP)) return ''; if (strpos($ip, ':') !== false) return $ip . '/' . $prefix; $long = ip2long($ip); if ($long === false) return ''; $mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix)); return long2ip($long & $mask) . '/' . $prefix; }
}
