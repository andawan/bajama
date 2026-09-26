<?php

declare(strict_types=1);

namespace BAJAMA\Network;

use PDO;
use RuntimeException;

final class MikroTikResourceLinkService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function marker(string $sourceType, int $sourceId): string
    {
        $sourceType = trim($sourceType);

        if ($sourceType === '' || $sourceId <= 0) {
            throw new RuntimeException('Sumber resource MikroTik tidak valid.');
        }

        return 'BAJAMA:' . $sourceType . ':' . $sourceId;
    }

    public function findByMarker(
        int $organizationId,
        int $routerId,
        string $marker,
        string $resource
    ): ?array {
        $stmt = $this->db->prepare(
            'SELECT *
             FROM mikrotik_resource_links
             WHERE organization_id = ?
               AND router_id = ?
               AND marker = ?
               AND resource = ?
             LIMIT 1'
        );

        $stmt->execute([
            $organizationId,
            $routerId,
            trim($marker),
            trim($resource),
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function save(
        int $organizationId,
        int $routerId,
        string $sourceType,
        int $sourceId,
        string $resource,
        string $routerosId,
        ?string $marker = null,
        bool $enabled = true
    ): void {
        if ($organizationId <= 0 || $routerId <= 0 || $sourceId <= 0) {
            throw new RuntimeException('Konteks resource MikroTik tidak valid.');
        }

        $resource = trim($resource);
        $routerosId = trim($routerosId);
        $marker = trim($marker ?? $this->marker($sourceType, $sourceId));

        if ($resource === '' || $routerosId === '' || $marker === '') {
            throw new RuntimeException('Identitas objek RouterOS tidak lengkap.');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO mikrotik_resource_links
                (organization_id, router_id, source_type, source_id,
                 resource, routeros_id, marker, enabled, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                organization_id = VALUES(organization_id),
                source_type = VALUES(source_type),
                source_id = VALUES(source_id),
                routeros_id = VALUES(routeros_id),
                enabled = VALUES(enabled),
                last_seen_at = NOW(),
                updated_at = CURRENT_TIMESTAMP'
        );

        $stmt->execute([
            $organizationId,
            $routerId,
            trim($sourceType),
            $sourceId,
            $resource,
            $routerosId,
            $marker,
            $enabled ? 1 : 0,
        ]);
    }

    public function markEnabled(
        int $organizationId,
        int $routerId,
        string $marker,
        bool $enabled
    ): void {
        $stmt = $this->db->prepare(
                'UPDATE mikrotik_resource_links
             SET enabled = ?, last_seen_at = NOW()
             WHERE organization_id = ?
               AND router_id = ?
                    AND marker LIKE CONCAT(?, ":%")'
        );

        $stmt->execute([
            $enabled ? 1 : 0,
            $organizationId,
            $routerId,
            trim($marker),
        ]);
    }

    public function delete(
        int $organizationId,
        int $routerId,
        string $marker
    ): void {
        $stmt = $this->db->prepare(
            'DELETE FROM mikrotik_resource_links
             WHERE organization_id = ?
               AND router_id = ?
               AND marker = ?'
        );

        $stmt->execute([
            $organizationId,
            $routerId,
            trim($marker),
        ]);
    }
}
