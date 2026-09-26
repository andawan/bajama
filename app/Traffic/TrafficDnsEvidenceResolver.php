<?php

namespace BAJAMA\Traffic;

use BAJAMA\Network\MikroTik;

class TrafficDnsEvidenceResolver
{
    /**
     * Membaca DNS cache RouterOS secara read-only.
     *
     * Format RouterOS:
     *
     * A:
     *   name = hostname
     *   data = IP
     *
     * AAAA:
     *   name = hostname
     *   data = IPv6
     *
     * CNAME:
     *   name = hostname
     *   data = hostname tujuan
     *
     * Hasil:
     *
     * IP -> daftar hostname
     */
    public function resolve(array $router): array
    {
        $api = MikroTik::connect($router);

        try {
            $rows = $api->raw('/ip/dns/cache/print');

            $nameToAddresses = [];
            $cnameMap = [];

            $stats = [
                'entries' => count($rows),
                'a_records' => 0,
                'aaaa_records' => 0,
                'cname_records' => 0,
                'other_records' => 0,
                'invalid_records' => 0,
                'indexed_ips' => 0,
                'resolved_cname_hosts' => 0,
            ];

            /*
             * Pass 1:
             *
             * A / AAAA langsung:
             *
             * hostname -> IP
             *
             * CNAME:
             *
             * hostname -> hostname target
             */
            foreach ($rows as $row) {
                $type = strtoupper(
                    trim((string)($row['type'] ?? ''))
                );

                $name = strtolower(
                    rtrim(
                        trim((string)($row['name'] ?? '')),
                        '.'
                    )
                );

                $data = trim(
                    (string)($row['data'] ?? '')
                );

                if ($name === '' || $data === '') {
                    $stats['invalid_records']++;
                    continue;
                }

                if ($type === 'A' || $type === 'AAAA') {
                    $ip = trim($data);

                    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                        $stats['invalid_records']++;
                        continue;
                    }

                    if (!isset($nameToAddresses[$name])) {
                        $nameToAddresses[$name] = [];
                    }

                    if (!in_array(
                        $ip,
                        $nameToAddresses[$name],
                        true
                    )) {
                        $nameToAddresses[$name][] = $ip;
                    }

                    if ($type === 'A') {
                        $stats['a_records']++;
                    } else {
                        $stats['aaaa_records']++;
                    }

                    continue;
                }

                if ($type === 'CNAME') {
                    $target = strtolower(
                        rtrim(
                            trim($data),
                            '.'
                        )
                    );

                    if ($target === '') {
                        $stats['invalid_records']++;
                        continue;
                    }

                    $cnameMap[$name] = $target;
                    $stats['cname_records']++;

                    continue;
                }

                /*
                 * TXT, NS, SOA dan record lain tidak dipakai
                 * untuk mapping IP -> hostname.
                 */
                $stats['other_records']++;
            }

            /*
             * Pass 2:
             *
             * Resolve CNAME chain.
             *
             * Contoh:
             *
             * api.tiktok.com
             *   -> edgekey.net
             *      -> akamai.net
             *         -> 1.2.3.4
             */
            $resolvedNames = [];

            foreach ($cnameMap as $name => $target) {
                $addresses = $this->resolveName(
                    $target,
                    $nameToAddresses,
                    $cnameMap
                );

                if (!$addresses) {
                    continue;
                }

                $resolvedNames[$name] = $addresses;
                $stats['resolved_cname_hosts']++;
            }

            /*
             * Gabungkan hostname A/AAAA dan hostname CNAME
             * ke index IP.
             */
            $index = [];

            foreach ($nameToAddresses as $name => $addresses) {
                foreach ($addresses as $ip) {
                    $this->addIndex(
                        $index,
                        $ip,
                        $name
                    );
                }
            }

            foreach ($resolvedNames as $name => $addresses) {
                foreach ($addresses as $ip) {
                    $this->addIndex(
                        $index,
                        $ip,
                        $name
                    );
                }
            }

            /*
             * Urutkan hostname agar hasil konsisten.
             */
            foreach ($index as &$hostnames) {
                sort($hostnames, SORT_STRING);
            }
            unset($hostnames);

            $stats['indexed_ips'] = count($index);

            return [
                'success' => true,
                'stats' => $stats,
                'index' => $index,
            ];

        } finally {
            $api->disconnect();
        }
    }

    /**
     * Resolve hostname melalui A/AAAA atau CNAME chain.
     */
    private function resolveName(
        string $name,
        array $nameToAddresses,
        array $cnameMap,
        array $visited = []
    ): array {
        $name = strtolower(
            rtrim(trim($name), '.')
        );

        if ($name === '') {
            return [];
        }

        /*
         * Hindari loop CNAME.
         */
        if (isset($visited[$name])) {
            return [];
        }

        $visited[$name] = true;

        /*
         * Direct A / AAAA.
         */
        if (!empty($nameToAddresses[$name])) {
            return $nameToAddresses[$name];
        }

        /*
         * CNAME chain.
         */
        if (!empty($cnameMap[$name])) {
            return $this->resolveName(
                $cnameMap[$name],
                $nameToAddresses,
                $cnameMap,
                $visited
            );
        }

        return [];
    }

    /**
     * Masukkan hostname ke index IP.
     */
    private function addIndex(
        array &$index,
        string $ip,
        string $hostname
    ): void {
        $ip = trim($ip);
        $hostname = strtolower(
            rtrim(trim($hostname), '.')
        );

        if (
            $ip === '' ||
            $hostname === '' ||
            filter_var($hostname, FILTER_VALIDATE_IP)
        ) {
            return;
        }

        if (!isset($index[$ip])) {
            $index[$ip] = [];
        }

        if (!in_array(
            $hostname,
            $index[$ip],
            true
        )) {
            $index[$ip][] = $hostname;
        }
    }

    /**
     * Cari hostname terbaik untuk destination IP.
     */
    public function hostnameForIp(
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

        /*
         * Prioritas:
         * 1. hostname paling spesifik
         * 2. hostname paling panjang
         */
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

    /**
     * Semua hostname yang diketahui untuk IP.
     */
    public function hostnamesForIp(
        array $index,
        ?string $ip
    ): array {
        $ip = trim((string)$ip);

        if (
            $ip === '' ||
            empty($index[$ip])
        ) {
            return [];
        }

        return $index[$ip];
    }
}
