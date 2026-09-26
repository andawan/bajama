<?php
declare(strict_types=1);

namespace BAJAMA\Core;

use PDO;
use RuntimeException;

final class Tenant
{
    public static function id(): int
    {
        $id = Auth::organizationId();

        if (!$id) {
            throw new RuntimeException('Organization/tenant tidak ditemukan.');
        }

        return $id;
    }

    public static function query(PDO $db, string $sql, array $params = [])
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    public static function organization(PDO $db): ?array
    {
        $stmt = $db->prepare(
            'SELECT *
             FROM organizations
             WHERE id = ?
             LIMIT 1'
        );

        $stmt->execute([self::id()]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
