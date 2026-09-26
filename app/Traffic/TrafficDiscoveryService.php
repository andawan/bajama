<?php

declare(strict_types=1);

namespace BAJAMA\Traffic;

use PDO;
use RuntimeException;

final class TrafficDiscoveryService
{
    private const DISCOVERY_CATEGORIES = [
        'firewall_filter',
        'firewall_mangle',
        'firewall_nat',
    ];

    private const INTERNAL_HOTSPOT_PORTS = [
        64872,
        64873,
        64874,
        64875,
    ];

    private PDO $db;
    private TrafficCatalog $catalog;
    private TrafficCentralLookupService $centralLookup;

    private int $snapshotInserted = 0;
    private int $snapshotUpdated = 0;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->catalog = new TrafficCatalog($db);
        $this->centralLookup = new TrafficCentralLookupService($db);
    }

    /**
     * Discover traffic intelligence data from existing Deep Scan snapshots.
     *
     * This service NEVER connects to MikroTik directly.
     * Source of truth: mikrotik_sync_snapshots.
     */
    public function discoverRouter(int $routerId): array
    {
        if ($routerId <= 0) {
            throw new RuntimeException('router_id tidak valid.');
        }

        foreach ([
            'game' => 'Game traffic catalog',
            'banking' => 'Banking traffic catalog',
            'e-wallet' => 'E-wallet traffic catalog',
            'browser' => 'Browser and uncategorized observed traffic',
        ] as $slug => $description) {
            $this->catalog->ensureCategory(
                $slug,
                ucwords(str_replace('-', ' ', $slug)),
                $description
            );
        }

        $snapshots = $this->loadLatestSnapshots($routerId);

        $this->snapshotInserted = 0;
        $this->snapshotUpdated = 0;

        $result = [
            'router_id' => $routerId,
            'snapshots' => count($snapshots),
            'rules_scanned' => 0,
            'discovered' => 0,
            'inserted' => 0,
            'updated' => 0,
            'created_or_updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        foreach ($snapshots as $snapshot) {
            $items = $this->decodeSnapshot($snapshot);

            foreach ($items as $rule) {
                if (!is_array($rule)) {
                    $result['skipped']++;
                    continue;
                }

                $result['rules_scanned']++;

                try {
                    $discovered = $this->discoverRule($snapshot, $rule);

                    if ($discovered === 0) {
                        $result['skipped']++;
                    } else {
                        $result['discovered'] += $discovered;
                    }
                } catch (\Throwable $e) {
                    $result['errors']++;
                }
            }
        }

        $result['inserted'] = $this->snapshotInserted;
        $result['updated'] = $this->snapshotUpdated;
        $result['created_or_updated'] =
            $this->snapshotInserted + $this->snapshotUpdated;

        /*
         * Runtime traffic discovery.
         *
         * traffic_observations berisi hasil observasi connection tracking
         * + bukti hostname dari RouterOS DNS cache. Data ini dipakai sebagai
         * evidence runtime dan hanya hostname yang valid yang dipromosikan
         * menjadi traffic catalog.
         */
        $observationResult = $this->discoverObservations($routerId);

        $result['observation_discovery'] = $observationResult;

        $result['discovered'] += (int)$observationResult['discovered'];
        $result['created_or_updated'] += (int)$observationResult['created_or_updated'];
        $result['skipped'] += (int)$observationResult['skipped'];
        $result['errors'] += (int)$observationResult['errors'];

        $portResult = $this->discoverObservedPorts($routerId);
        $result['observation_discovery']['ports'] = $portResult;
        $result['discovered'] += (int)$portResult['discovered'];
        $result['created_or_updated'] += (int)$portResult['created_or_updated'];
        $result['skipped'] += (int)$portResult['skipped'];
        $result['errors'] += (int)$portResult['errors'];

        return $result;
    }

    /** Simpan port yang benar-benar terlihat, termasuk koneksi tanpa DNS hostname. */
    private function discoverObservedPorts(int $routerId): array
    {
        $result = ['discovered' => 0, 'created_or_updated' => 0, 'skipped' => 0, 'errors' => 0];
        $stmt = $this->db->prepare(
            "SELECT destination_port, protocol, COUNT(*) AS hits
             FROM traffic_observations
             WHERE router_id = ? AND destination_port BETWEEN 1 AND 65535
             GROUP BY destination_port, protocol
             ORDER BY hits DESC"
        );
        $stmt->execute([$routerId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            try {
                $protocol = strtoupper(trim((string)($row['protocol'] ?? 'ANY')));
                if (!in_array($protocol, ['TCP', 'UDP', 'ICMP'], true)) {
                    $protocol = 'ANY';
                }
                $saved = $this->catalog->upsert([
                    'category_slug' => 'browser',
                    'name' => 'Observed PORT ' . (int)$row['destination_port'],
                    'entry_type' => 'PORT',
                    'value' => (string)(int)$row['destination_port'],
                    'port' => (int)$row['destination_port'],
                    'protocol' => $protocol,
                    'source_type' => 'DISCOVERED',
                    'confidence' => min(95, 50 + (int)$row['hits']),
                    'description' => 'Port yang terlihat langsung dari connection tracking MikroTik.',
                    'metadata' => ['router_id' => $routerId, 'hits' => (int)$row['hits']],
                ]);
                $action = strtoupper((string)($saved['action'] ?? ''));
                if ($action === 'INSERT' || $action === 'UPDATE') {
                    $result['created_or_updated']++;
                    $result['discovered']++;
                } else {
                    $result['skipped']++;
                }
            } catch (Throwable $e) {
                $result['errors']++;
            }
        }
        return $result;
    }

    /**
     * Discover reusable traffic definitions from runtime observations.
     *
     * Only observations with hostname evidence are promoted to the catalog.
     * Raw connection/IP data remains in traffic_observations.
     */
    /**
     * Classify observed hostname into reusable traffic intelligence.
     *
     * This is intentionally evidence-based:
     * - known application domains get an application classification
     * - CDN hostnames remain CDN evidence
     * - generic infrastructure remains infrastructure
     * - unknown hostnames remain APPLICATION
     */
    private function stringEndsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return substr($haystack, -strlen($needle)) === $needle;
    }

    private function stringStartsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return substr($haystack, 0, strlen($needle)) === $needle;
    }

    private function stringContains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return strpos($haystack, $needle) !== false;
    }

    private function classifyHostname(string $hostname): array
    {
        $host = strtolower(trim($hostname));
        $host = rtrim($host, '.');

        $result = [
            'classification' => 'APPLICATION',
            'provider' => null,
            'application' => null,
            'normalized_domain' => $host,
            'confidence_bonus' => 0,
        ];

        if ($host === '') {
            return $result;
        }

        /*
         * DNS services.
         */
        $dnsRules = [
            'dns.google' => ['Google', 'Google DNS'],
            'dns.adguard.com' => ['AdGuard', 'AdGuard DNS'],
            'one.one.one.one' => ['Cloudflare', 'Cloudflare DNS'],
        ];

        foreach ($dnsRules as $domain => $info) {
            if ($host === $domain) {
                $result['classification'] = 'DNS';
                $result['provider'] = $info[0];
                $result['application'] = $info[1];
                $result['confidence_bonus'] = 5;
                return $result;
            }
        }

        /*
         * Application signatures.
         */
        $applicationRules = [
            [
                'classification' => 'SOCIAL_MEDIA',
                'provider' => 'Meta',
                'application' => 'Facebook',
                'domains' => [
                    'facebook.com',
                    'facebook.net',
                    'fbcdn.net',
                ],
            ],
            [
                'classification' => 'SOCIAL_MEDIA',
                'provider' => 'Meta',
                'application' => 'Instagram',
                'domains' => [
                    'instagram.com',
                    'instagram.c10r.instagram.com',
                ],
            ],
            [
                'classification' => 'SOCIAL_MEDIA',
                'provider' => 'Meta',
                'application' => 'WhatsApp',
                'domains' => [
                    'whatsapp.com',
                    'whatsapp.net',
                ],
            ],
            [
                'classification' => 'SOCIAL_MEDIA',
                'provider' => 'TikTok',
                'application' => 'TikTok',
                'domains' => [
                    'tiktok.com',
                    'tiktokcdn.com',
                    'tiktokv.com',
                    'byteoversea.com',
                    'ibytedtos.com',
                ],
            ],
            [
                'classification' => 'STREAMING',
                'provider' => 'Google',
                'application' => 'YouTube',
                'domains' => [
                    'youtube.com',
                    'youtubei.googleapis.com',
                    'googlevideo.com',
                    'ytimg.com',
                ],
            ],
            [
                'classification' => 'STREAMING',
                'provider' => 'Netflix',
                'application' => 'Netflix',
                'domains' => [
                    'netflix.com',
                    'nflxvideo.net',
                    'nflximg.net',
                    'nflxso.net',
                    'nflxext.com',
                ],
            ],
            [
                'classification' => 'ECOMMERCE',
                'provider' => 'Coupang',
                'application' => 'Coupang',
                'domains' => [
                    'coupang.com',
                    'coupangcdn.com',
                ],
            ],
            [
                'classification' => 'SECURITY',
                'provider' => "Let's Encrypt",
                'application' => 'Certificate / PKI',
                'domains' => [
                    'letsencrypt.org',
                    'lencr.org',
                ],
            ],
            [
                'classification' => 'SECURITY',
                'provider' => 'McAfee',
                'application' => 'McAfee Security',
                'domains' => [
                    'mcafee.com',
                ],
            ],
            [
                'classification' => 'CLOUD',
                'provider' => 'Google',
                'application' => 'Google Services',
                'domains' => [
                    'google.com',
                    'googleapis.com',
                    'gstatic.com',
                ],
            ],
            [
                'classification' => 'CLOUD',
                'provider' => 'Meta',
                'application' => 'Meta Services',
                'domains' => [
                    'c10r.facebook.com',
                    'c10r.instagram.com',
                ],
            ],
        ];

        foreach ($applicationRules as $rule) {
            foreach ($rule['domains'] as $domain) {
                if (
                    $host === $domain ||
                    $this->stringEndsWith($host, '.' . $domain)
                ) {
                    $result['classification'] = $rule['classification'];
                    $result['provider'] = $rule['provider'];
                    $result['application'] = $rule['application'];
                    $result['confidence_bonus'] = 5;

                    return $result;
                }
            }
        }

        /*
         * CDN / edge infrastructure.
         */
        $cdnRules = [
            'Akamai' => [
                'akamaiedge.net',
                'akamai.net',
                'akamaized.net',
                'edgekey.net',
                'edgesuite.net',
            ],
            'Cloudflare' => [
                'cloudflare.com',
                'cloudflare.net',
            ],
            'Fastly' => [
                'fastly.net',
                'fastlylb.net',
            ],
            'Amazon CloudFront' => [
                'cloudfront.net',
            ],
        ];

        foreach ($cdnRules as $provider => $domains) {
            foreach ($domains as $domain) {
                if (
                    $host === $domain ||
                    $this->stringEndsWith($host, '.' . $domain)
                ) {
                    $result['classification'] = 'CDN';
                    $result['provider'] = $provider;
                    $result['application'] = 'CDN / Edge Infrastructure';
                    $result['confidence_bonus'] = 2;

                    /*
                     * If the CDN hostname visibly contains a known
                     * application domain, keep that application as evidence.
                     *
                     * Example:
                     * shop.tiktok.com.edgekey.net
                     */
                    foreach ($applicationRules as $rule) {
                        foreach ($rule['domains'] as $appDomain) {
                            if (
                                $this->stringContains(
                                    $host,
                                    '.' . $appDomain . '.'
                                ) ||
                                $this->stringStartsWith(
                                    $host,
                                    $appDomain . '.'
                                )
                            ) {
                                $result['provider'] = $rule['provider'];
                                $result['application'] =
                                    $rule['application'] .
                                    ' via ' .
                                    $provider;
                                $result['confidence_bonus'] = 4;

                                return $result;
                            }
                        }
                    }

                    return $result;
                }
            }
        }

        /*
         * Generic service infrastructure.
         */
        $infrastructureTokens = [
            'api.',
            'cdn.',
            'static.',
            'assets.',
            'telemetry.',
            'metrics.',
            'crl.',
            'ocsp.',
            'download.',
            'update.',
            'push.',
        ];

        foreach ($infrastructureTokens as $token) {
            if ($this->stringContains($host, $token)) {
                $result['classification'] = 'INFRASTRUCTURE';
                $result['application'] = 'Service Infrastructure';
                $result['confidence_bonus'] = 1;

                return $result;
            }
        }

        return $result;
    }

    /**
     * Derive the reusable parent domain for known applications.
     *
     * Unknown/CDN hostnames remain untouched so we do not invent
     * relationships that are not supported by evidence.
     */
    private function normalizedCatalogDomain(string $hostname): string
    {
        $host = strtolower(trim($hostname));
        $host = rtrim($host, '.');

        if ($host === '') {
            return '';
        }

        $knownBases = [
            'facebook.com',
            'facebook.net',
            'instagram.com',
            'whatsapp.com',
            'whatsapp.net',
            'tiktok.com',
            'youtube.com',
            'googleapis.com',
            'google.com',
            'coupang.com',
            'mcafee.com',
            'letsencrypt.org',
            'lencr.org',
        ];

        foreach ($knownBases as $base) {
            if (
                $host === $base ||
                $this->stringEndsWith($host, '.' . $base)
            ) {
                return $base;
            }
        }

        return $host;
    }

    private function discoverObservations(int $routerId): array
    {
        $result = [
            'router_id' => $routerId,
            'observations_scanned' => 0,
            'hostname_observations' => 0,
            'unique_hostnames' => 0,
            'discovered' => 0,
            'created_or_updated' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'error_details' => [],
        ];

        $stmt = $this->db->prepare(
            "SELECT *
             FROM traffic_observations
             WHERE router_id = ?
               AND hostname IS NOT NULL
               AND TRIM(hostname) <> ''
             ORDER BY last_seen_at DESC, id DESC
             LIMIT 5000"
        );

        $stmt->execute([$routerId]);
        $observations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /*
         * Satu hostname = satu knowledge item.
         *
         * Kita tidak memasukkan setiap connection tracking
         * sebagai entry catalog.
         */
        $aggregated = [];

        foreach ($observations as $observation) {
            $result['observations_scanned']++;
            $result['hostname_observations']++;

            $hostname = $this->normalizeHostname(
                (string)($observation['hostname'] ?? '')
            );

            if ($hostname === null) {
                $result['skipped']++;
                continue;
            }

            if (!isset($aggregated[$hostname])) {
                $aggregated[$hostname] = [
                    'hostname' => $hostname,
                    'observation_ids' => [],
                    'customer_ips' => [],
                    'destination_ips' => [],
                    'ports' => [],
                    'protocols' => [],
                    'connection_marks' => [],
                    'packet_marks' => [],
                    'hostname_sources' => [],
                    'observation_count' => 0,
                    'total_orig_bytes' => 0,
                    'total_repl_bytes' => 0,
                    'total_orig_packets' => 0,
                    'total_repl_packets' => 0,
                    'first_seen_at' => null,
                    'last_seen_at' => null,
                ];
            }

            $item =& $aggregated[$hostname];

            $item['observation_count']++;

            $observationId = (int)($observation['id'] ?? 0);
            if ($observationId > 0) {
                $item['observation_ids'][$observationId] = true;
            }

            $customerIp = trim(
                (string)($observation['customer_ip'] ?? '')
            );
            if ($customerIp !== '') {
                $item['customer_ips'][$customerIp] = true;
            }

            $destinationIp = trim(
                (string)($observation['destination_ip'] ?? '')
            );
            if ($destinationIp !== '') {
                $item['destination_ips'][$destinationIp] = true;
            }

            $port = (int)($observation['destination_port'] ?? 0);
            if ($port > 0 && $port <= 65535) {
                $item['ports'][$port] = true;
            }

            $protocol = strtoupper(
                trim((string)($observation['protocol'] ?? ''))
            );
            if ($protocol !== '') {
                $item['protocols'][$protocol] = true;
            }

            $connectionMark = trim(
                (string)($observation['connection_mark'] ?? '')
            );
            if ($connectionMark !== '') {
                $item['connection_marks'][$connectionMark] = true;
            }

            $packetMark = trim(
                (string)($observation['packet_mark'] ?? '')
            );
            if ($packetMark !== '') {
                $item['packet_marks'][$packetMark] = true;
            }

            $hostnameSource = trim(
                (string)($observation['hostname_source'] ?? '')
            );
            if ($hostnameSource !== '') {
                $item['hostname_sources'][$hostnameSource] = true;
            }

            $item['total_orig_bytes'] += max(
                0,
                (int)($observation['orig_bytes'] ?? 0)
            );

            $item['total_repl_bytes'] += max(
                0,
                (int)($observation['repl_bytes'] ?? 0)
            );

            $item['total_orig_packets'] += max(
                0,
                (int)($observation['orig_packets'] ?? 0)
            );

            $item['total_repl_packets'] += max(
                0,
                (int)($observation['repl_packets'] ?? 0)
            );

            $firstSeen = $observation['first_seen_at'] ?? null;
            $lastSeen = $observation['last_seen_at'] ?? null;

            if (
                $firstSeen !== null &&
                (
                    $item['first_seen_at'] === null ||
                    $firstSeen < $item['first_seen_at']
                )
            ) {
                $item['first_seen_at'] = $firstSeen;
            }

            if (
                $lastSeen !== null &&
                (
                    $item['last_seen_at'] === null ||
                    $lastSeen > $item['last_seen_at']
                )
            ) {
                $item['last_seen_at'] = $lastSeen;
            }

            unset($item);
        }

        $result['unique_hostnames'] = count($aggregated);

        foreach ($aggregated as $item) {
            try {
                $hostname = $item['hostname'];

                /*
                 * ==========================================================
                 * CENTRAL LOOKUP V3
                 * ==========================================================
                 *
                 * Semua hostname hasil Traffic Observation sekarang
                 * diklasifikasikan oleh Central Lookup.
                 *
                 * Classifier lama tidak lagi menjadi sumber utama
                 * business category untuk hostname observation.
                 */
                $lookup = $this->centralLookup->lookupHostname(
                    $hostname
                );

                if (empty($lookup['success'])) {
                    $lookup = [
                        'success' => false,
                        'hostname' => $hostname,
                        'provider' => null,
                        'application' => null,
                        'service' => null,
                        'technical_classification' => 'APPLICATION',
                        'business_category' => 'browser',
                        'confidence' => 40,
                        'normalized_domain' => $hostname,
                        'evidence' => [],
                    ];
                }

                $categorySlug = strtolower(
                    trim(
                        (string)(
                            $lookup['business_category']
                                ?? 'browser'
                        )
                    )
                );

                if ($categorySlug === '') {
                    $categorySlug = 'browser';
                }

                /*
                 * Simpan knowledge Central Lookup.
                 * Idempotent berdasarkan hostname_pattern.
                 */
                $this->centralLookup->persistResult($lookup);

                /*
                 * Convert associative sets into compact arrays.
                 */
                $customerIps = array_keys(
                    $item['customer_ips']
                );

                $destinationIps = array_keys(
                    $item['destination_ips']
                );

                $ports = array_map(
                    'intval',
                    array_keys($item['ports'])
                );

                $protocols = array_values(
                    $item['protocols']
                );

                $connectionMarks = array_values(
                    $item['connection_marks']
                );

                $packetMarks = array_values(
                    $item['packet_marks']
                );

                $hostnameSources = array_values(
                    $item['hostname_sources']
                );

                sort($customerIps);
                sort($destinationIps);
                sort($ports);
                sort($protocols);
                sort($connectionMarks);
                sort($packetMarks);
                sort($hostnameSources);

                /*
                 * ==========================================================
                 * CENTRAL LOOKUP INTELLIGENCE
                 * ==========================================================
                 *
                 * Confidence dari Central Lookup menjadi confidence
                 * utama. Runtime observation tetap disimpan sebagai
                 * evidence di metadata.
                 */
                $intelligence = [
                    'classification' =>
                        (string)(
                            $lookup['technical_classification']
                                ?? 'APPLICATION'
                        ),

                    'provider' =>
                        $lookup['provider'] ?? null,

                    'application' =>
                        $lookup['application'] ?? null,

                    'service' =>
                        $lookup['service'] ?? null,

                    'confidence_bonus' => 0,
                ];

                $normalizedDomain = (string)(
                    $lookup['normalized_domain']
                        ?? $hostname
                );

                $confidence = min(
                    99,
                    max(
                        0,
                        (float)(
                            $lookup['confidence'] ?? 40
                        )
                    )
                );

                $metadata = [
                    'source' => 'TRAFFIC_OBSERVATION_AGGREGATE',
                    'intelligence_version' => 3,
                    'central_lookup' => true,
                    'lookup_confidence' =>
                        $lookup['confidence'] ?? null,
                    'business_category' =>
                        $lookup['business_category'] ?? 'browser',
                    'classification' =>
                        $intelligence['classification'],
                    'provider' =>
                        $intelligence['provider'],
                    'application' =>
                        $intelligence['application'],
                    'service' =>
                        $intelligence['service'],
                    'normalized_domain' =>
                        $normalizedDomain,
                    'lookup_evidence' =>
                        $lookup['evidence'] ?? [],
                    'router_id' => $routerId,
                    'hostname' => $hostname,
                    'observation_count' => $item['observation_count'],
                    'observation_ids' => array_map(
                        'intval',
                        array_keys($item['observation_ids'])
                    ),
                    'customer_ips' => $customerIps,
                    'destination_ips' => $destinationIps,
                    'ports' => $ports,
                    'protocols' => $protocols,
                    'connection_marks' => $connectionMarks,
                    'packet_marks' => $packetMarks,
                    'hostname_sources' => $hostnameSources,
                    'total_orig_bytes' => $item['total_orig_bytes'],
                    'total_repl_bytes' => $item['total_repl_bytes'],
                    'total_bytes' =>
                        $item['total_orig_bytes'] +
                        $item['total_repl_bytes'],
                    'total_orig_packets' => $item['total_orig_packets'],
                    'total_repl_packets' => $item['total_repl_packets'],
                    'total_packets' =>
                        $item['total_orig_packets'] +
                        $item['total_repl_packets'],
                    'first_seen_at' => $item['first_seen_at'],
                    'last_seen_at' => $item['last_seen_at'],
                ];

                $resultRow = $this->catalog->upsert([
                    'category_slug' => $categorySlug,
                    'name' =>
                        'Observed ' .
                        (
                            $intelligence['application']
                                ? $intelligence['application'] . ' — '
                                : (
                                    $intelligence['service']
                                        ? $intelligence['service'] . ' — '
                                        : ''
                                )
                        ) .
                        $hostname,
                    'entry_type' => 'DOMAIN',
                    'value' => $hostname,
                    'port' => 0,
                    'protocol' => 'ANY',
                    'source_type' => 'DISCOVERED',
                    'source_organization_id' => null,
                    'source_user_id' => null,
                    'confidence' => $confidence,
                    'status' => 'ACTIVE',
                    'description' =>
                        'Aggregated runtime traffic observed from MikroTik connection tracking with DNS evidence. ' .
                        'Classification: ' .
                        $intelligence['classification'] .
                        (
                            $intelligence['provider']
                                ? '; Provider: ' . $intelligence['provider']
                                : ''
                        ) .
                        (
                            $intelligence['application']
                                ? '; Application: ' . $intelligence['application']
                                : ''
                        ) .
                        (
                            $intelligence['service']
                                ? '; Service: ' . $intelligence['service']
                                : ''
                        ) .
                        '; Business Category: ' .
                        ($lookup['business_category'] ?? 'browser') .
                        '.',
                    'metadata' => $metadata,
                ]);

                $action = strtoupper(
                    (string)($resultRow['action'] ?? '')
                );

                if ($action === 'INSERT') {
                    $result['inserted']++;
                    $result['created_or_updated']++;
                    $result['discovered']++;
                } elseif ($action === 'UPDATE') {
                    $result['updated']++;
                    $result['created_or_updated']++;
                    $result['discovered']++;
                } else {
                    $result['skipped']++;
                }

                /*
                 * Runtime observations juga membawa destination port.
                 * Simpan port sebagai item catalog terpisah agar policy
                 * dapat memilihnya tanpa harus menunggu firewall mangle.
                 */
                foreach ($ports as $observedPort) {
                    $portProtocol = count($protocols) === 1
                        ? (string)$protocols[0]
                        : 'ANY';

                    $portResult = $this->catalog->upsert([
                        'category_slug' => $categorySlug,
                        'name' => 'Observed PORT ' . $observedPort . ' — ' . $hostname,
                        'entry_type' => 'PORT',
                        'value' => (string)$observedPort,
                        'port' => $observedPort,
                        'protocol' => $portProtocol,
                        'source_type' => 'DISCOVERED',
                        'source_organization_id' => null,
                        'source_user_id' => null,
                        'confidence' => $confidence,
                        'status' => 'ACTIVE',
                        'description' => 'Destination port observed bersama hostname ' . $hostname . '.',
                        'metadata' => $metadata,
                    ]);

                    $portAction = strtoupper((string)($portResult['action'] ?? ''));
                    if ($portAction === 'INSERT' || $portAction === 'UPDATE') {
                        $result['created_or_updated']++;
                        $result['discovered']++;
                    } else {
                        $result['skipped']++;
                    }
                }
            } catch (\Throwable $e) {
                $result['errors']++;

                $result['error_details'][] = [
                    'hostname' => $item['hostname'] ?? null,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ];
            }
        }

        return $result;
    }

    private function normalizeHostname(string $hostname): ?string
    {
        $hostname = strtolower(trim($hostname));
        $hostname = rtrim($hostname, '.');

        if ($hostname === '') {
            return null;
        }

        if (strlen($hostname) > 253) {
            return null;
        }

        if (strpos($hostname, '.') === false) {
            return null;
        }

        if (filter_var($hostname, FILTER_VALIDATE_IP)) {
            return null;
        }

        if (strpos($hostname, '..') !== false) {
            return null;
        }

        if (!preg_match('/^[a-z0-9][a-z0-9._-]*[a-z0-9]$/i', $hostname)) {
            return null;
        }

        return $hostname;
    }

    private function inferObservationCategory(string $hostname): string
    {
        $haystack = strtolower($hostname);

        $map = [
            'streaming' => [
                'youtube',
                'youtubei',
                'googlevideo',
                'ytimg',
                'googleapis.com',
                'netflix',
                'spotify',
                'twitch',
                'vidio',
                'disney',
                'primevideo',
                'hbo',
            ],
            'social' => [
                'facebook',
                'instagram',
                'tiktok',
                'twitter',
                'x.com',
                'whatsapp',
                'telegram',
            ],
            'chat' => [
                'messenger',
                'discord',
                'line.me',
                'signal',
            ],
            'game' => [
                'steam',
                'epicgames',
                'playstation',
                'xbox',
                'riotgames',
                'garena',
                'mobilelegends',
                'pubg',
                'freefire',
            ],
            'banking' => [
                'bank',
                'bca',
                'bri',
                'mandiri',
                'bni',
                'cimb',
                'danamon',
                'permata',
            ],
            'e-wallet' => [
                'gopay',
                'ovo',
                'dana',
                'shopeepay',
                'linkaja',
            ],
        ];

        foreach ($map as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($haystack, $keyword) !== false) {
                    return $category;
                }
            }
        }

        return 'browser';
    }

    /**
     * Load the newest successful snapshot for each discovery category.
     */
    private function loadLatestSnapshots(int $routerId): array
    {
        $placeholders = implode(
            ',',
            array_fill(0, count(self::DISCOVERY_CATEGORIES), '?')
        );

        $sql = "
            SELECT
                id,
                router_id,
                category,
                status,
                item_count,
                data_json,
                scanned_at,
                duration_ms
            FROM mikrotik_sync_snapshots
            WHERE router_id = ?
              AND status = 'SUCCESS'
              AND category IN ($placeholders)
            ORDER BY scanned_at DESC, id DESC
        ";

        $stmt = $this->db->prepare($sql);

        $params = [$routerId];
        foreach (self::DISCOVERY_CATEGORIES as $category) {
            $params[] = $category;
        }

        $stmt->execute($params);

        $latest = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $category = (string)$row['category'];

            if (isset($latest[$category])) {
                continue;
            }

            $latest[$category] = $row;
        }

        return array_values($latest);
    }

    private function decodeSnapshot(array $snapshot): array
    {
        $json = (string)($snapshot['data_json'] ?? '');

        if ($json === '') {
            return [];
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            return [];
        }

        if ($this->isSequentialArray($data)) {
            return $data;
        }

        return [$data];
    }

    private function discoverRule(array $snapshot, array $rule): int
    {
        if ($this->isIgnoredRule($rule)) {
            return 0;
        }

        $count = 0;

        /*
         * 1. MikroTik firewall "content" is the strongest
         *    direct domain/host evidence available in the snapshot.
         */
        $content = trim((string)($rule['content'] ?? ''));

        if ($content !== '') {
            $domain = $this->normalizeDomainCandidate($content);

            if ($domain !== null) {
                $count += $this->upsertDiscovery(
                    $snapshot,
                    $rule,
                    'DOMAIN',
                    $domain
                );
            }
        }

        /*
         * 2. dst-address can provide IP/CIDR intelligence.
         *    Private management targets are deliberately excluded.
         */
        $dstAddress = trim((string)($rule['dst-address'] ?? ''));

        if ($dstAddress !== '') {
            $ipType = $this->classifyAddress($dstAddress);

            if ($ipType !== null) {
                $count += $this->upsertDiscovery(
                    $snapshot,
                    $rule,
                    $ipType,
                    $dstAddress
                );
            }
        }

        /*
         * 3. Meaningful application ports.
         *    RouterOS internal Hotspot ports are excluded.
         */
        $port = $this->normalizePort($rule['dst-port'] ?? null);

        if ($port !== null) {
            $count += $this->upsertDiscovery(
                $snapshot,
                $rule,
                'PORT',
                (string)$port,
                $port
            );
        }

        /*
         * 4. Protocol-only discovery.
         *    Only create this when the rule contains meaningful
         *    traffic evidence and no domain/IP/port was extracted.
         */
        if (
            $count === 0 &&
            $this->normalizeProtocol($rule['protocol'] ?? null) !== null
        ) {
            $protocol = $this->normalizeProtocol($rule['protocol']);

            $count += $this->upsertDiscovery(
                $snapshot,
                $rule,
                'PROTOCOL',
                $protocol
            );
        }

        return $count;
    }

    private function upsertDiscovery(
        array $snapshot,
        array $rule,
        string $entryType,
        string $value,
        ?int $port = null
    ): int {
        $protocol = $this->normalizeProtocol($rule['protocol'] ?? null);
        $categorySlug = $this->inferCategorySlug($rule);

        if ($categorySlug === null) {
            /*
             * Use Browser / General as the neutral bucket for
             * discovered traffic when no stronger category signal exists.
             */
            $categorySlug = 'browser';
        }

        // Firewall discovery may run before Central Lookup has seen a
        // hostname. Keep game/banking/e-wallet entries catalogable.
        $this->catalog->ensureCategory(
            $categorySlug,
            ucwords(str_replace('-', ' ', $categorySlug)),
            'Traffic category discovered from MikroTik evidence.'
        );

        $metadata = [
            'source' => 'MIKROTIK_DEEP_SCAN',
            'router_id' => (int)$snapshot['router_id'],
            'snapshot_id' => (int)$snapshot['id'],
            'snapshot_category' => (string)$snapshot['category'],
            'scanned_at' => (string)$snapshot['scanned_at'],
            'rule' => [
                'id' => $rule['.id'] ?? null,
                'chain' => $rule['chain'] ?? null,
                'action' => $rule['action'] ?? null,
                'content' => $rule['content'] ?? null,
                'dst-address' => $rule['dst-address'] ?? null,
                'dst-address-list' => $rule['dst-address-list'] ?? null,
                'dst-port' => $rule['dst-port'] ?? null,
                'protocol' => $rule['protocol'] ?? null,
                'src-address' => $rule['src-address'] ?? null,
                'src-address-list' => $rule['src-address-list'] ?? null,
                'in-interface' => $rule['in-interface'] ?? null,
                'out-interface' => $rule['out-interface'] ?? null,
                'connection-mark' => $rule['connection-mark'] ?? null,
                'new-connection-mark' => $rule['new-connection-mark'] ?? null,
                'new-packet-mark' => $rule['new-packet-mark'] ?? null,
                'layer7-protocol' => $rule['layer7-protocol'] ?? null,
                'comment' => $rule['comment'] ?? null,
                'bytes' => $rule['bytes'] ?? null,
                'packets' => $rule['packets'] ?? null,
                'dynamic' => $rule['dynamic'] ?? null,
                'disabled' => $rule['disabled'] ?? null,
            ],
        ];

        $confidence = $this->calculateConfidence(
            $entryType,
            $rule,
            $port
        );

        $name = $this->buildName(
            $entryType,
            $value,
            $rule
        );

        $result = $this->catalog->upsert([
            'category_slug' => $categorySlug,
            'name' => $name,
            'entry_type' => $entryType,
            'value' => $value,
            'port' => $port ?? 0,
            'protocol' => $protocol ?? 'ANY',
            'source_type' => 'DISCOVERED',
            'source_organization_id' => null,
            'source_user_id' => null,
            'confidence' => $confidence,
            'status' => 'ACTIVE',
            'description' => $this->buildDescription($rule),
            'metadata' => $metadata,
        ]);

        $action = strtoupper((string)($result['action'] ?? ''));

        if ($action === 'INSERT') {
            $this->snapshotInserted++;
            return 1;
        }

        if ($action === 'UPDATE') {
            $this->snapshotUpdated++;
            return 1;
        }

        return 0;
    }

    private function isIgnoredRule(array $rule): bool
    {
        if ($this->toBool($rule['dynamic'] ?? false)) {
            return true;
        }

        if ($this->toBool($rule['disabled'] ?? false)) {
            return true;
        }

        $action = strtolower(trim((string)($rule['action'] ?? '')));

        /*
         * Pure RouterOS control actions are not application traffic.
         */
        if (in_array($action, [
            'jump',
            'passthrough',
        ], true)) {
            return true;
        }

        /*
         * NAT management redirect:
         * :22 -> :8728 is BAJAMA/MikroTik management,
         * not an application traffic destination.
         */
        $toPorts = trim((string)($rule['to-ports'] ?? ''));
        $dstPort = trim((string)($rule['dst-port'] ?? ''));

        if (
            $toPorts === '8728' &&
            $dstPort === '22'
        ) {
            return true;
        }

        /*
         * Hotspot internal service ports.
         */
        if ($this->containsInternalHotspotPort($dstPort)) {
            return true;
        }

        return false;
    }

    private function normalizeDomainCandidate(string $value): ?string
    {
        $value = trim(strtolower($value));

        $value = preg_replace(
            '#^https?://#i',
            '',
            $value
        );

        $value = preg_replace(
            '#^[*.]+#',
            '',
            $value
        );

        $value = explode('/', $value, 2)[0];
        $value = explode(':', $value, 2)[0];
        $value = rtrim($value, '.');

        if ($value === '') {
            return null;
        }

        /*
         * RouterOS content can contain arbitrary text.
         * Only accept values that actually look like host/domain names.
         */
        if (
            filter_var($value, FILTER_VALIDATE_IP) !== false ||
            strpos($value, '.') === false
        ) {
            return null;
        }

        if (!preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i',
            $value
        )) {
            return null;
        }

        return $value;
    }

    private function classifyAddress(string $value): ?string
    {
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            /*
             * Do not automatically catalogue private/internal addresses.
             */
            if ($this->isPrivateOrReservedIp($value)) {
                return null;
            }

            return 'IP';
        }

        if (strpos($value, '/') !== false) {
            $parts = explode('/', $value, 2);

            if (
                count($parts) === 2 &&
                filter_var($parts[0], FILTER_VALIDATE_IP) !== false
            ) {
                if ($this->isPrivateOrReservedIp($parts[0])) {
                    return null;
                }

                return 'CIDR';
            }
        }

        return null;
    }

    private function normalizePort($value): ?int
    {
        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        /*
         * Ranges are kept as rule evidence in metadata.
         * TrafficCatalog currently accepts only one numeric port.
         */
        if (strpos($value, '-') !== false) {
            return null;
        }

        if (!ctype_digit($value)) {
            return null;
        }

        $port = (int)$value;

        if ($port < 1 || $port > 65535) {
            return null;
        }

        if (in_array($port, self::INTERNAL_HOTSPOT_PORTS, true)) {
            return null;
        }

        return $port;
    }

    private function normalizeProtocol($value): ?string
    {
        $value = strtoupper(trim((string)$value));

        if ($value === '') {
            return null;
        }

        if (in_array($value, [
            'TCP',
            'UDP',
            'ICMP',
        ], true)) {
            return $value;
        }

        return null;
    }

    private function inferCategorySlug(array $rule): ?string
    {
        $haystack = strtolower(implode(' ', [
            (string)($rule['dst-address-list'] ?? ''),
            (string)($rule['src-address-list'] ?? ''),
            (string)($rule['connection-mark'] ?? ''),
            (string)($rule['new-connection-mark'] ?? ''),
            (string)($rule['new-packet-mark'] ?? ''),
            (string)($rule['comment'] ?? ''),
            (string)($rule['content'] ?? ''),
        ]));

        $patterns = [
            'streaming' => [
                'stream',
                'youtube',
                'netflix',
                'video',
                'tiktok',
            ],
            'social' => [
                'sosmed',
                'social',
                'facebook',
                'instagram',
                'whatsapp',
                'telegram',
            ],
            'chat' => [
                'chat',
                'messenger',
            ],
            'game' => [
                'game',
                'gaming',
            ],
            'banking' => [
                'bank',
                'banking',
            ],
            'e-wallet' => [
                'ewallet',
                'e-wallet',
                'wallet',
            ],
        ];

        foreach ($patterns as $slug => $needles) {
            foreach ($needles as $needle) {
                if (strpos($haystack, $needle) !== false) {
                    return $slug;
                }
            }
        }

        return null;
    }

    private function calculateConfidence(
        string $entryType,
        array $rule,
        ?int $port
    ): float {
        $confidence = 50.0;

        if ($entryType === 'DOMAIN' || $entryType === 'HOST') {
            $confidence += 30.0;
        }

        if (!empty($rule['content'])) {
            $confidence += 10.0;
        }

        if (!empty($rule['comment'])) {
            $confidence += 5.0;
        }

        if (!empty($rule['dst-address-list'])) {
            $confidence += 5.0;
        }

        if ($port !== null) {
            $confidence += 3.0;
        }

        return min(100.0, $confidence);
    }

    private function buildName(
        string $entryType,
        string $value,
        array $rule
    ): string {
        $comment = trim((string)($rule['comment'] ?? ''));

        if ($comment !== '') {
            return $comment . ' — ' . $value;
        }

        $mark = trim((string)(
            $rule['new-connection-mark']
            ?? $rule['connection-mark']
            ?? ''
        ));

        if ($mark !== '') {
            return $mark . ' — ' . $value;
        }

        return 'Discovered ' . $entryType . ' — ' . $value;
    }

    private function buildDescription(array $rule): string
    {
        $parts = [];

        if (!empty($rule['comment'])) {
            $parts[] = 'Comment: ' . trim((string)$rule['comment']);
        }

        if (!empty($rule['chain'])) {
            $parts[] = 'Chain: ' . trim((string)$rule['chain']);
        }

        if (!empty($rule['action'])) {
            $parts[] = 'Action: ' . trim((string)$rule['action']);
        }

        if (!empty($rule['dst-address-list'])) {
            $parts[] = 'Address-list: ' . trim((string)$rule['dst-address-list']);
        }

        if (!empty($rule['connection-mark'])) {
            $parts[] = 'Connection-mark: ' . trim((string)$rule['connection-mark']);
        }

        return implode(' | ', $parts);
    }

    private function containsInternalHotspotPort(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        foreach (self::INTERNAL_HOTSPOT_PORTS as $port) {
            if ($value === (string)$port) {
                return true;
            }
        }

        if (preg_match('/^(\d+)-(\d+)$/', $value, $m)) {
            $start = (int)$m[1];
            $end = (int)$m[2];

            foreach (self::INTERNAL_HOTSPOT_PORTS as $port) {
                if ($port >= $start && $port <= $end) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isPrivateOrReservedIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    private function isSequentialArray(array $array): bool
    {
        if ($array === []) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }

    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(
            strtolower(trim((string)$value)),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }
}
