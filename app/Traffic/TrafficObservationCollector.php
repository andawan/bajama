<?php

namespace BAJAMA\Traffic;

use BAJAMA\Network\MikroTik;
use PDO;
use Throwable;

class TrafficObservationCollector
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function collect(array $router, bool $dryRun = true): array
    {
        $routerId = (int)($router['id'] ?? 0);
        $organizationId = (int)($router['organization_id'] ?? 0);

        if ($routerId <= 0) {
            throw new \RuntimeException('Router ID tidak valid.');
        }

        if ($organizationId <= 0) {
            throw new \RuntimeException('Organization ID router tidak valid.');
        }

        $api = MikroTik::connect($router);

        try {
            /*
             * DNS evidence dibaca sekali per collection.
             *
             * Tetap read-only terhadap MikroTik.
             */
            $dnsResolver = new TrafficDnsEvidenceResolver();

            /*
             * Resolver membutuhkan koneksi MikroTik sendiri.
             * Kita lakukan setelah koneksi connection-tracking
             * berhasil dibuat agar kegagalan DNS tidak membuat
             * collector kehilangan seluruh connection data.
             */
            $dnsEvidence = [
                'success' => false,
                'stats' => [],
                'index' => [],
            ];

            try {
                $dnsEvidence = $dnsResolver->resolve($router);
            } catch (Throwable $e) {
                $dnsEvidence = [
                    'success' => false,
                    'stats' => [],
                    'index' => [],
                    'error' => $e->getMessage(),
                ];
            }

            $dnsIndex = $dnsEvidence['index'] ?? [];

            $connections = $api->raw(
                '/ip/firewall/connection/print'
            );

            $stats = [
                'router_id' => $routerId,
                'organization_id' => $organizationId,
                'connections' => count($connections),
                'valid' => 0,
                'invalid' => 0,
                'with_hostname_candidate' => 0,
                'with_connection_mark' => 0,
                'with_bytes' => 0,
                'dns_success' => !empty($dnsEvidence['success']),
                'dns_entries' => (int)($dnsEvidence['stats']['entries'] ?? 0),
                'dns_indexed_ips' => (int)($dnsEvidence['stats']['indexed_ips'] ?? 0),
                'with_hostname' => 0,
                'without_hostname' => 0,
                'dry_run' => $dryRun,
                'inserted' => 0,
                'updated' => 0,
            ];

            $samples = [];

            foreach ($connections as $row) {
                $parsed = $this->normalizeConnection(
                    $row,
                    $routerId,
                    $organizationId,
                    $dnsIndex
                );

                if ($parsed === null) {
                    $stats['invalid']++;
                    continue;
                }

                $stats['valid']++;

                if (!empty($parsed['connection_mark'])) {
                    $stats['with_connection_mark']++;
                }

                if (
                    ($parsed['orig_bytes'] ?? 0) > 0 ||
                    ($parsed['repl_bytes'] ?? 0) > 0
                ) {
                    $stats['with_bytes']++;
                }

                if (
                    $parsed['destination_ip'] !== null &&
                    $parsed['destination_port'] !== null
                ) {
                    $stats['with_hostname_candidate']++;
                }

                if (!empty($parsed['hostname'])) {
                    $stats['with_hostname']++;
                } else {
                    $stats['without_hostname']++;
                }

                if (count($samples) < 10) {
                    $samples[] = $parsed;
                }

                if (!$dryRun) {
                    $this->upsert($parsed, $stats);
                }
            }

            return [
                'success' => true,
                'stats' => $stats,
                'dns' => [
                    'success' => !empty($dnsEvidence['success']),
                    'stats' => $dnsEvidence['stats'] ?? [],
                    'error' => $dnsEvidence['error'] ?? null,
                ],
                'samples' => $samples,
            ];

        } finally {
            $api->disconnect();
        }
    }

    private function normalizeConnection(
        array $row,
        int $routerId,
        int $organizationId,
        array $dnsIndex = []
    ): ?array {
        $source = $this->splitEndpoint($row['src-address'] ?? '');
        $destination = $this->splitEndpoint($row['dst-address'] ?? '');

        if (
            $source['ip'] === null ||
            $destination['ip'] === null
        ) {
            return null;
        }

        $now = date('Y-m-d H:i:s');

        $protocol = strtolower(
            trim((string)($row['protocol'] ?? ''))
        );

        /*
         * DNS evidence:
         *
         * destination IP -> hostname
         *
         * Tidak melakukan DNS lookup dari server BAJAMA.
         * Hanya menggunakan cache DNS yang sudah dibaca
         * dari MikroTik.
         */
        $hostname = null;

        if ($destination['ip'] !== null) {
            $hostname = $this->hostnameForIp(
                $dnsIndex,
                $destination['ip']
            );
        }

        $hostnameSource = $hostname !== null
            ? 'MIKROTIK_DNS_CACHE'
            : null;

        $observationKey = hash(
            'sha256',
            implode('|', [
                $routerId,
                $source['ip'],
                $source['port'] ?? '',
                $destination['ip'],
                $destination['port'] ?? '',
                $protocol,
            ])
        );

        return [
            'organization_id' => $organizationId,
            'router_id' => $routerId,

            'customer_ip' => $source['ip'],
            'customer_port' => $source['port'],

            'destination_ip' => $destination['ip'],
            'destination_port' => $destination['port'],

            'protocol' => $protocol !== '' ? $protocol : null,
            'tcp_state' => $row['tcp-state'] ?? null,

            'hostname' => $hostname,
            'hostname_source' => $hostnameSource,

            'connection_mark' => $row['connection-mark'] ?? null,
            'packet_mark' => $row['packet-mark'] ?? null,

            'orig_bytes' => $this->toInt($row['orig-bytes'] ?? null),
            'orig_packets' => $this->toInt($row['orig-packets'] ?? null),

            'repl_bytes' => $this->toInt($row['repl-bytes'] ?? null),
            'repl_packets' => $this->toInt($row['repl-packets'] ?? null),

            'orig_rate' => $this->toInt($row['orig-rate'] ?? null),
            'repl_rate' => $this->toInt($row['repl-rate'] ?? null),

            'seen_reply' => $this->toBool($row['seen-reply'] ?? null),
            'assured' => $this->toBool($row['assured'] ?? null),
            'confirmed' => $this->toBool($row['confirmed'] ?? null),
            'fasttrack' => $this->toBool($row['fasttrack'] ?? null),
            'srcnat' => $this->toBool($row['srcnat'] ?? null),
            'dstnat' => $this->toBool($row['dstnat'] ?? null),

            'connection_id' => $row['.id'] ?? null,

            'observation_key' => $observationKey,

            'first_seen_at' => $now,
            'last_seen_at' => $now,

            'metadata' => json_encode(
                $row,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
        ];
    }

    private function hostnameForIp(
        array $index,
        ?string $ip
    ): ?string {
        $ip = trim((string)$ip);

        if (
            $ip === '' ||
            empty($index[$ip])
        ) {
            return null;
        }

        $hostnames = array_values(
            array_filter(
                $index[$ip],
                function ($hostname) {
                    $hostname = strtolower(
                        trim((string)$hostname)
                    );

                    return $hostname !== ''
                        && $hostname !== 'localhost'
                        && strpos(
                            $hostname,
                            'in-addr.arpa'
                        ) === false
                        && strpos(
                            $hostname,
                            'ip6.arpa'
                        ) === false;
                }
            )
        );

        if (!$hostnames) {
            return null;
        }

        usort(
            $hostnames,
            function ($a, $b) {
                $aParts = substr_count($a, '.');
                $bParts = substr_count($b, '.');

                if ($aParts !== $bParts) {
                    return $bParts <=> $aParts;
                }

                return strlen($b) <=> strlen($a);
            }
        );

        return $hostnames[0];
    }

    private function splitEndpoint(string $endpoint): array
    {
        $endpoint = trim($endpoint);

        if ($endpoint === '') {
            return [
                'ip' => null,
                'port' => null,
            ];
        }

        if (substr($endpoint, 0, 1) === '[') {
            $close = strpos($endpoint, ']');

            if ($close !== false) {
                $ip = substr($endpoint, 1, $close - 1);
                $port = null;

                if (
                    isset($endpoint[$close + 1]) &&
                    $endpoint[$close + 1] === ':'
                ) {
                    $port = (int)substr(
                        $endpoint,
                        $close + 2
                    );
                }

                return [
                    'ip' => $ip !== '' ? $ip : null,
                    'port' => $port > 0 ? $port : null,
                ];
            }
        }

        $lastColon = strrpos($endpoint, ':');

        if ($lastColon === false) {
            return [
                'ip' => $endpoint,
                'port' => null,
            ];
        }

        $ip = substr($endpoint, 0, $lastColon);
        $port = (int)substr($endpoint, $lastColon + 1);

        return [
            'ip' => $ip !== '' ? $ip : null,
            'port' => $port > 0 ? $port : null,
        ];
    }

    private function toInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value)
            ? (int)$value
            : null;
    }

    private function toBool($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return in_array(
            strtolower((string)$value),
            ['true', 'yes', '1'],
            true
        ) ? 1 : 0;
    }

    private function upsert(array $data, array &$stats): void
    {
        $sql = "
            INSERT INTO traffic_observations (
                organization_id,
                router_id,
                customer_ip,
                customer_port,
                destination_ip,
                destination_port,
                protocol,
                tcp_state,
                hostname,
                hostname_source,
                connection_mark,
                packet_mark,
                orig_bytes,
                orig_packets,
                repl_bytes,
                repl_packets,
                orig_rate,
                repl_rate,
                seen_reply,
                assured,
                confirmed,
                fasttrack,
                srcnat,
                dstnat,
                connection_id,
                observation_key,
                first_seen_at,
                last_seen_at,
                metadata
            ) VALUES (
                :organization_id,
                :router_id,
                :customer_ip,
                :customer_port,
                :destination_ip,
                :destination_port,
                :protocol,
                :tcp_state,
                :hostname,
                :hostname_source,
                :connection_mark,
                :packet_mark,
                :orig_bytes,
                :orig_packets,
                :repl_bytes,
                :repl_packets,
                :orig_rate,
                :repl_rate,
                :seen_reply,
                :assured,
                :confirmed,
                :fasttrack,
                :srcnat,
                :dstnat,
                :connection_id,
                :observation_key,
                :first_seen_at,
                :last_seen_at,
                :metadata
            )
            ON DUPLICATE KEY UPDATE
                connection_id = VALUES(connection_id),
                orig_bytes = VALUES(orig_bytes),
                orig_packets = VALUES(orig_packets),
                repl_bytes = VALUES(repl_bytes),
                repl_packets = VALUES(repl_packets),
                orig_rate = VALUES(orig_rate),
                repl_rate = VALUES(repl_rate),
                seen_reply = VALUES(seen_reply),
                assured = VALUES(assured),
                confirmed = VALUES(confirmed),
                fasttrack = VALUES(fasttrack),
                srcnat = VALUES(srcnat),
                dstnat = VALUES(dstnat),
                last_seen_at = VALUES(last_seen_at),
                metadata = VALUES(metadata)
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($data);

        if ($stmt->rowCount() === 1) {
            $stats['inserted']++;
        } else {
            $stats['updated']++;
        }
    }
}
