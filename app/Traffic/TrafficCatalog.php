<?php

declare(strict_types=1);

namespace BAJAMA\Traffic;

use PDO;
use InvalidArgumentException;
use RuntimeException;

final class TrafficCatalog
{
    private PDO $db;

    private const ENTRY_TYPES = [
        'DOMAIN',
        'HOST',
        'IP',
        'CIDR',
        'PORT',
        'PROTOCOL',
    ];

    private const PROTOCOLS = [
        'ANY',
        'TCP',
        'UDP',
        'ICMP',
    ];

    private const SOURCE_TYPES = [
        'SYSTEM',
        'OWNER',
        'CUSTOMER',
        'IMPORT',
        'DISCOVERED',
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Tambahkan atau update data ke Global Traffic Catalog.
     *
     * Return:
     * [
     *   'action' => INSERT|UPDATE|SKIP,
     *   'id' => int,
     *   'dedupe_key' => string
     * ]
     */
    public function upsert(array $data): array
    {
        $categoryId = $this->resolveCategoryId($data);

        $entryType = strtoupper(trim((string)($data['entry_type'] ?? '')));
        if (!in_array($entryType, self::ENTRY_TYPES, true)) {
            throw new InvalidArgumentException(
                'entry_type tidak valid.'
            );
        }

        $value = $this->normalizeValue(
            $entryType,
            (string)($data['value'] ?? '')
        );

        if ($value === '') {
            throw new InvalidArgumentException(
                'Value traffic tidak boleh kosong.'
            );
        }

        $port = $this->normalizePort($data['port'] ?? 0);

        $protocol = strtoupper(
            trim((string)($data['protocol'] ?? 'ANY'))
        );

        if ($protocol === '') {
            $protocol = 'ANY';
        }

        if (!in_array($protocol, self::PROTOCOLS, true)) {
            throw new InvalidArgumentException(
                'Protocol tidak valid.'
            );
        }

        $sourceType = strtoupper(
            trim((string)($data['source_type'] ?? 'SYSTEM'))
        );

        if (!in_array($sourceType, self::SOURCE_TYPES, true)) {
            throw new InvalidArgumentException(
                'source_type tidak valid.'
            );
        }

        $name = trim(
            (string)($data['name'] ?? $value)
        );

        if ($name === '') {
            $name = $value;
        }

        $dedupeKey = $this->buildDedupeKey(
            $categoryId,
            $entryType,
            $value,
            $port,
            $protocol
        );

        $requestedId = $this->nullableInt($data['id'] ?? null);

        $organizationId = $this->nullableInt(
            $data['source_organization_id'] ?? null
        );

        $userId = $this->nullableInt(
            $data['source_user_id'] ?? null
        );

        $confidence = $this->normalizeConfidence(
            $data['confidence'] ?? 100
        );

        $description = trim(
            (string)($data['description'] ?? '')
        );

        $metadata = $this->normalizeMetadata(
            $data['metadata'] ?? null
        );

        if ($requestedId !== null) {
            $targetStmt = $this->db->prepare(
                'SELECT id
                 FROM traffic_catalog
                 WHERE id = ?
                 LIMIT 1'
            );
            $targetStmt->execute([$requestedId]);
            if (!$targetStmt->fetchColumn()) {
                throw new InvalidArgumentException('Traffic catalog tidak ditemukan.');
            }

            $duplicateStmt = $this->db->prepare(
                'SELECT id
                 FROM traffic_catalog
                 WHERE dedupe_key = ?
                   AND id <> ?
                 LIMIT 1'
            );
            $duplicateStmt->execute([$dedupeKey, $requestedId]);
            if ($duplicateStmt->fetchColumn()) {
                throw new InvalidArgumentException('Data traffic dengan kategori, value, port, dan protocol tersebut sudah ada.');
            }

            $updateStmt = $this->db->prepare(
                "UPDATE traffic_catalog
                 SET category_id = ?,
                     name = ?,
                     entry_type = ?,
                     value = ?,
                     normalized_value = ?,
                     port = ?,
                     protocol = ?,
                     dedupe_key = ?,
                     source_type = ?,
                     source_organization_id = ?,
                     source_user_id = ?,
                     confidence = ?,
                     status = 'ACTIVE',
                     description = ?,
                     metadata = ?,
                     last_seen_at = NOW(),
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $updateStmt->execute([
                $categoryId,
                $name,
                $entryType,
                $value,
                $value,
                $port,
                $protocol,
                $dedupeKey,
                $sourceType,
                $organizationId,
                $userId,
                $confidence,
                $description !== '' ? $description : null,
                $metadata,
                $requestedId,
            ]);

            return [
                'action' => 'UPDATE',
                'id' => $requestedId,
                'dedupe_key' => $dedupeKey,
            ];
        }

        $existing = $this->findByDedupeKey($dedupeKey);

        if (!$existing) {
            $sql = "
                INSERT INTO traffic_catalog (
                    category_id,
                    name,
                    entry_type,
                    value,
                    normalized_value,
                    port,
                    protocol,
                    dedupe_key,
                    source_type,
                    source_organization_id,
                    source_user_id,
                    confidence,
                    status,
                    description,
                    metadata,
                    first_seen_at,
                    last_seen_at,
                    created_at,
                    updated_at
                ) VALUES (
                    :category_id,
                    :name,
                    :entry_type,
                    :value,
                    :normalized_value,
                    :port,
                    :protocol,
                    :dedupe_key,
                    :source_type,
                    :source_organization_id,
                    :source_user_id,
                    :confidence,
                    'ACTIVE',
                    :description,
                    :metadata,
                    NOW(),
                    NOW(),
                    NOW(),
                    NOW()
                )
            ";

            $stmt = $this->db->prepare($sql);

            $stmt->execute([
                ':category_id' => $categoryId,
                ':name' => $name,
                ':entry_type' => $entryType,
                ':value' => $value,
                ':normalized_value' => $value,
                ':port' => $port,
                ':protocol' => $protocol,
                ':dedupe_key' => $dedupeKey,
                ':source_type' => $sourceType,
                ':source_organization_id' => $organizationId,
                ':source_user_id' => $userId,
                ':confidence' => $confidence,
                ':description' => $description !== ''
                    ? $description
                    : null,
                ':metadata' => $metadata,
            ]);

            return [
                'action' => 'INSERT',
                'id' => (int)$this->db->lastInsertId(),
                'dedupe_key' => $dedupeKey,
            ];
        }

        /*
         * Record sudah ada.
         *
         * Kita tidak membuat duplicate.
         *
         * Jika data baru memiliki informasi yang lebih baik,
         * update record yang sama.
         */
        $shouldUpdate = $this->shouldUpdate(
            $existing,
            $sourceType,
            $confidence,
            $description,
            $metadata
        );

        if (!$shouldUpdate) {
            $stmt = $this->db->prepare("
                UPDATE traffic_catalog
                SET last_seen_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
            ");

            $stmt->execute([
                ':id' => (int)$existing['id'],
            ]);

            return [
                'action' => 'SKIP',
                'id' => (int)$existing['id'],
                'dedupe_key' => $dedupeKey,
            ];
        }

        $update = "
            UPDATE traffic_catalog
            SET
                name = :name,
                value = :value,
                normalized_value = :normalized_value,
                source_type = :source_type,
                source_organization_id = :source_organization_id,
                source_user_id = :source_user_id,
                confidence = :confidence,
                description = :description,
                metadata = :metadata,
                status = 'ACTIVE',
                last_seen_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
        ";

        $stmt = $this->db->prepare($update);

        $stmt->execute([
            ':name' => $name,
            ':value' => $value,
            ':normalized_value' => $value,
            ':source_type' => $sourceType,
            ':source_organization_id' => $organizationId,
            ':source_user_id' => $userId,
            ':confidence' => $confidence,
            ':description' => $description !== ''
                ? $description
                : null,
            ':metadata' => $metadata,
            ':id' => (int)$existing['id'],
        ]);

        return [
            'action' => 'UPDATE',
            'id' => (int)$existing['id'],
            'dedupe_key' => $dedupeKey,
        ];
    }

    /**
     * Cari kategori berdasarkan ID atau slug.
     */
    private function resolveCategoryId(array $data): int
    {
        if (!empty($data['category_id'])) {
            $stmt = $this->db->prepare("
                SELECT id
                FROM traffic_categories
                WHERE id = :id
                  AND enabled = 1
                LIMIT 1
            ");

            $stmt->execute([
                ':id' => (int)$data['category_id'],
            ]);

            $id = $stmt->fetchColumn();

            if ($id === false) {
                throw new InvalidArgumentException(
                    'Category tidak ditemukan atau disabled.'
                );
            }

            return (int)$id;
        }

        $slug = strtolower(
            trim((string)($data['category_slug'] ?? ''))
        );

        if ($slug === '') {
            throw new InvalidArgumentException(
                'category_id atau category_slug wajib diisi.'
            );
        }

        $stmt = $this->db->prepare("
            SELECT id
            FROM traffic_categories
            WHERE slug = :slug
              AND enabled = 1
            LIMIT 1
        ");

        $stmt->execute([
            ':slug' => $slug,
        ]);

        $id = $stmt->fetchColumn();

        if ($id === false) {
            throw new InvalidArgumentException(
                'Category tidak ditemukan: ' . $slug
            );
        }

        return (int)$id;
    }

    /**
     * Pastikan business category tersedia dan aktif.
     *
     * Dipakai Central Lookup agar kategori baru seperti:
     * - e-commerce
     * - content-creation
     * - advertising
     * - infrastructure
     * dapat dibuat otomatis ketika intelligence memiliki
     * bukti yang cukup.
     */
    public function ensureCategory(
        string $slug,
        ?string $name = null,
        ?string $description = null
    ): int {
        $slug = strtolower(trim($slug));

        if ($slug === '') {
            throw new InvalidArgumentException(
                'Category slug tidak boleh kosong.'
            );
        }

        $name = trim(
            (string)($name ?? '')
        );

        if ($name === '') {
            $name = ucwords(
                str_replace(
                    ['-', '_'],
                    ' ',
                    $slug
                )
            );
        }

        $stmt = $this->db->prepare("
            SELECT id
            FROM traffic_categories
            WHERE slug = :slug
            LIMIT 1
        ");

        $stmt->execute([
            ':slug' => $slug,
        ]);

        $existing = $stmt->fetchColumn();

        if ($existing !== false) {
            $update = $this->db->prepare("
                UPDATE traffic_categories
                SET
                    name = :name,
                    description = COALESCE(
                        NULLIF(:description, ''),
                        description
                    ),
                    enabled = 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");

            $update->execute([
                ':name' => $name,
                ':description' => (string)($description ?? ''),
                ':id' => (int)$existing,
            ]);

            return (int)$existing;
        }

        $sortStmt = $this->db->query("
            SELECT COALESCE(MAX(sort_order), 0)
            FROM traffic_categories
        ");

        $sortOrder = ((int)$sortStmt->fetchColumn()) + 10;

        $insert = $this->db->prepare("
            INSERT INTO traffic_categories (
                name,
                slug,
                description,
                icon,
                enabled,
                sort_order
            )
            VALUES (
                :name,
                :slug,
                :description,
                'bi-diagram-3',
                1,
                :sort_order
            )
        ");

        $insert->execute([
            ':name' => $name,
            ':slug' => $slug,
            ':description' => (string)($description ?? ''),
            ':sort_order' => $sortOrder,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Normalisasi value berdasarkan tipe entry.
     */
    private function normalizeValue(
        string $entryType,
        string $value
    ): string {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        switch ($entryType) {
            case 'DOMAIN':
            case 'HOST':
                $value = strtolower($value);

                // Hilangkan protocol jika user memasukkan URL.
                $value = preg_replace(
                    '#^https?://#i',
                    '',
                    $value
                );

                // Hilangkan path.
                $value = explode('/', $value, 2)[0];

                // Hilangkan trailing dot.
                $value = rtrim($value, '.');

                // Hilangkan wildcard awal.
                $value = preg_replace(
                    '/^\*\.\s*/',
                    '',
                    $value
                );

                return strtolower(trim($value));

            case 'IP':
                $ip = trim($value);

                if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    throw new InvalidArgumentException(
                        'IP address tidak valid: ' . $value
                    );
                }

                return $ip;

            case 'CIDR':
                return $this->normalizeCidr($value);

            case 'PORT':
                if (!preg_match('/^(\d{1,5})(?:-(\d{1,5}))?$/', $value, $matches)) {
                    throw new InvalidArgumentException(
                        'Port harus berupa angka atau range, contoh 443 atau 27000-27250.'
                    );
                }

                $firstPort = (int)$matches[1];
                $lastPort = isset($matches[2]) && $matches[2] !== ''
                    ? (int)$matches[2]
                    : $firstPort;

                if ($firstPort < 1 || $lastPort > 65535 || $firstPort > $lastPort) {
                    throw new InvalidArgumentException('Port/range harus berada antara 1-65535 dan urut naik.');
                }

                return $firstPort === $lastPort
                    ? (string)$firstPort
                    : $firstPort . '-' . $lastPort;

            case 'PROTOCOL':
                return strtoupper($value);

            default:
                return trim($value);
        }
    }

    /**
     * Normalisasi CIDR IPv4/IPv6.
     */
    private function normalizeCidr(string $value): string
    {
        $value = trim($value);

        if (strpos($value, '/') === false) {
            throw new InvalidArgumentException(
                'CIDR harus memiliki prefix.'
            );
        }

        [$ip, $prefix] = explode('/', $value, 2);

        $ip = trim($ip);
        $prefix = trim($prefix);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException(
                'IP CIDR tidak valid.'
            );
        }

        if (!ctype_digit($prefix)) {
            throw new InvalidArgumentException(
                'Prefix CIDR tidak valid.'
            );
        }

        $prefix = (int)$prefix;

        $maxPrefix = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4
        ) !== false ? 32 : 128;

        if ($prefix < 0 || $prefix > $maxPrefix) {
            throw new InvalidArgumentException(
                'Prefix CIDR di luar range.'
            );
        }

        return $ip . '/' . $prefix;
    }

    /**
     * Normalisasi port.
     */
    private function normalizePort($port): int
    {
        if ($port === null || $port === '') {
            return 0;
        }

        if (!is_numeric($port)) {
            throw new InvalidArgumentException(
                'Port harus berupa angka.'
            );
        }

        $port = (int)$port;

        if ($port < 0 || $port > 65535) {
            throw new InvalidArgumentException(
                'Port harus antara 0-65535.'
            );
        }

        return $port;
    }

    /**
     * Normalisasi confidence.
     */
    private function normalizeConfidence($confidence): float
    {
        if (!is_numeric($confidence)) {
            return 100.0;
        }

        $confidence = (float)$confidence;

        if ($confidence < 0) {
            $confidence = 0;
        }

        if ($confidence > 100) {
            $confidence = 100;
        }

        return round($confidence, 2);
    }

    /**
     * Normalisasi metadata menjadi JSON.
     */
    private function normalizeMetadata($metadata): ?string
    {
        if ($metadata === null || $metadata === '') {
            return null;
        }

        if (is_string($metadata)) {
            json_decode($metadata, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException(
                    'Metadata harus berupa JSON valid.'
                );
            }

            return $metadata;
        }

        if (!is_array($metadata)) {
            throw new InvalidArgumentException(
                'Metadata harus berupa array atau JSON.'
            );
        }

        return json_encode(
            $metadata,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * Buat dedupe key stabil.
     */
    private function buildDedupeKey(
        int $categoryId,
        string $entryType,
        string $value,
        int $port,
        string $protocol
    ): string {
        return hash(
            'sha256',
            implode('|', [
                $categoryId,
                $entryType,
                $value,
                $port,
                $protocol,
            ])
        );
    }

    /**
     * Cari record berdasarkan dedupe key.
     */
    private function findByDedupeKey(
        string $dedupeKey
    ): ?array {
        $stmt = $this->db->prepare("
            SELECT *
            FROM traffic_catalog
            WHERE dedupe_key = :dedupe_key
            LIMIT 1
        ");

        $stmt->execute([
            ':dedupe_key' => $dedupeKey,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Tentukan apakah existing record layak diperbarui.
     */
    private function shouldUpdate(
        array $existing,
        string $sourceType,
        float $confidence,
        string $description,
        ?string $metadata
    ): bool {
        $existingConfidence = (float)(
            $existing['confidence'] ?? 0
        );

        /*
         * Confidence lebih tinggi = informasi lebih baik.
         */
        if ($confidence > $existingConfidence) {
            return true;
        }

        /*
         * Source OWNER/SYSTEM lebih dipercaya daripada
         * source biasa.
         */
        $priority = [
            'SYSTEM' => 5,
            'OWNER' => 5,
            'IMPORT' => 4,
            'DISCOVERED' => 3,
            'CUSTOMER' => 2,
        ];

        $oldSource = strtoupper(
            (string)($existing['source_type'] ?? '')
        );

        $newPriority = $priority[$sourceType] ?? 0;
        $oldPriority = $priority[$oldSource] ?? 0;

        if ($newPriority > $oldPriority) {
            return true;
        }

        /*
         * Informasi tambahan baru.
         */
        if (
            $description !== '' &&
            trim((string)($existing['description'] ?? '')) === ''
        ) {
            return true;
        }

        if ($metadata !== null) {
            $oldMetadata = trim(
                (string)($existing['metadata'] ?? '')
            );

            if ($oldMetadata === '') {
                return true;
            }

            /*
             * Metadata runtime dapat berubah walaupun
             * confidence dan source tetap sama.
             *
             * Contoh:
             * - customer baru memakai hostname
             * - destination IP baru
             * - port baru
             * - connection mark baru
             * - bytes/packets bertambah
             * - last_seen berubah
             */
            $oldDecoded = json_decode($oldMetadata, true);
            $newDecoded = json_decode($metadata, true);

            if (
                is_array($oldDecoded) &&
                is_array($newDecoded)
            ) {
                /*
                 * Metadata observasi mempunyai dua kelompok:
                 *
                 * 1. Intelligence / identity:
                 *    perubahan di sini memang berarti catalog
                 *    perlu diperbarui.
                 *
                 * 2. Runtime observation:
                 *    berubah hampir setiap siklus dan tidak boleh
                 *    memicu UPDATE catalog.
                 */
                $volatileKeys = [
                    'observation_ids',
                    'observation_count',
                    'customer_ips',
                    'destination_ips',
                    'ports',
                    'protocols',
                    'connection_marks',
                    'packet_marks',
                    'hostname_sources',
                    'total_orig_bytes',
                    'total_repl_bytes',
                    'total_bytes',
                    'total_orig_packets',
                    'total_repl_packets',
                    'total_packets',
                    'first_seen_at',
                    'last_seen_at',
                ];

                foreach ($volatileKeys as $key) {
                    unset($oldDecoded[$key], $newDecoded[$key]);
                }

                /*
                 * Urutan array runtime tidak boleh menyebabkan
                 * perubahan intelligence palsu.
                 */
                $normalize = static function ($value) use (&$normalize) {
                    if (!is_array($value)) {
                        return $value;
                    }

                    foreach ($value as $key => &$item) {
                        $item = is_array($item)
                            ? $normalize($item)
                            : $item;
                    }
                    unset($item);

                    if (array_keys($value) !== range(0, count($value) - 1)) {
                        ksort($value);
                    }

                    return $value;
                };

                $oldDecoded = $normalize($oldDecoded);
                $newDecoded = $normalize($newDecoded);

                if ($oldDecoded != $newDecoded) {
                    return true;
                }
            } elseif ($oldMetadata !== $metadata) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nullable integer helper.
     */
    private function nullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            throw new InvalidArgumentException(
                'ID harus berupa angka.'
            );
        }

        $value = (int)$value;

        return $value > 0 ? $value : null;
    }
}
