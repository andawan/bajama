<?php
declare(strict_types=1);

namespace BAJAMA\Core;

use PDO;

final class Audit
{
    public static function log(
        PDO $db,
        string $action,
        string $module = '',
        string $targetType = '',
        ?int $targetId = null,
        array $details = [],
        ?int $organizationId = null,
        ?int $actorUserId = null
    ): void {
        $organizationId = $organizationId ?? Auth::organizationId();
        $userId = $actorUserId ?? Auth::userId();

        $stmt = $db->prepare(
            'INSERT INTO audit_logs
            (
                organization_id,
                user_id,
                action,
                module,
                target_type,
                target_id,
                details,
                ip_address,
                user_agent,
                created_at
            )
            VALUES
            (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )'
        );

        $stmt->execute([
            $organizationId,
            $userId,
            $action,
            $module,
            $targetType,
            $targetId,
            json_encode(
                $details,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            ),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }
}
