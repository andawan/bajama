<?php
declare(strict_types=1);

namespace BAJAMA\License;

use PDO;

final class Engine
{
    public static function validate(
        PDO $db,
        int $organizationId
    ): array {
        $stmt = $db->prepare(
            'SELECT
                l.*,
                lp.code AS plan_code,
                lp.name AS plan_name,
                lp.max_customers,
                lp.max_routers,
                lp.max_olts,
                lp.max_onus
             FROM licenses l
             INNER JOIN license_plans lp
                ON lp.id = l.plan_id
             WHERE l.organization_id = ?
             ORDER BY l.id DESC
             LIMIT 1'
        );

        $stmt->execute([$organizationId]);

        $license = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$license) {
            self::record(
                $db,
                $organizationId,
                null,
                'INVALID',
                'NO_LICENSE'
            );

            return [
                'valid' => false,
                'status' => 'NONE',
                'license' => null
            ];
        }

        $status = strtoupper(
            (string)$license['status']
        );

        $valid = in_array(
            $status,
            ['TRIAL', 'ACTIVE'],
            true
        );

        if (
            !empty($license['expires_at']) &&
            strtotime($license['expires_at']) < time()
        ) {
            $status = 'EXPIRED';
            $valid = false;

            if ($license['status'] !== 'EXPIRED') {
                $update = $db->prepare(
                    'UPDATE licenses
                     SET status = "EXPIRED"
                     WHERE id = ?'
                );

                $update->execute([
                    (int)$license['id']
                ]);
            }
        }

        $validationStatus = 'INVALID';

        if ($status === 'EXPIRED') {
            $validationStatus = 'EXPIRED';
        } elseif ($status === 'SUSPENDED') {
            $validationStatus = 'SUSPENDED';
        } elseif ($valid) {
            $validationStatus = 'VALID';
        }

        self::record(
            $db,
            $organizationId,
            (int)$license['id'],
            $validationStatus,
            $status
        );

        /*
         * Update last validation time.
         */
        try {
            $update = $db->prepare(
                'UPDATE licenses
                 SET last_validated_at = NOW()
                 WHERE id = ?
                   AND organization_id = ?'
            );

            $update->execute([
                (int)$license['id'],
                $organizationId
            ]);
        } catch (\Throwable $e) {
            // Tidak menghentikan request utama.
        }

        return [
            'valid' => $valid,
            'status' => $status,
            'license' => $license
        ];
    }

    private static function record(
        PDO $db,
        int $organizationId,
        ?int $licenseId,
        string $validationStatus,
        string $status
    ): void {
        try {
            $stmt = $db->prepare(
                'INSERT INTO license_validations
                (
                    organization_id,
                    license_id,
                    validation_status,
                    validated_at,
                    details
                )
                VALUES (?, ?, ?, NOW(), ?)'
            );

            $details = json_encode(
                [
                    'status' => $status,
                    'checked_by' => 'BAJAMA\\License\\Engine'
                ],
                JSON_UNESCAPED_SLASHES
            );

            $stmt->execute([
                $organizationId,
                $licenseId,
                $validationStatus,
                $details
            ]);
        } catch (\Throwable $e) {
            /*
             * Logging license tidak boleh menjatuhkan aplikasi.
             */
        }
    }
}
