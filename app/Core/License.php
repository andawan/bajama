<?php
declare(strict_types=1);

namespace BAJAMA\Core;

use PDO;

final class License
{
    /**
     * OWNER adalah pemilik organisasi BAJAMA.
     *
     * OWNER tidak dibatasi oleh paket license organisasi.
     * License tetap disimpan untuk audit, billing, dan administrasi.
     */
    private static function isSuperAdmin(PDO $db): bool
    {
        if (!Auth::userId()) {
            return false;
        }

        return in_array(
            'SUPER_ADMIN',
            RBAC::roles($db),
            true
        );
    }

    private static function isOwner(PDO $db): bool
    {
        if (!Auth::userId()) {
            return false;
        }

        return in_array(
            'OWNER',
            RBAC::roles($db),
            true
        );
    }

    public static function current(PDO $db): ?array
    {
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

        $stmt->execute([Tenant::id()]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function status(PDO $db): string
    {
        $license = self::current($db);

        if (!$license) {
            return 'NONE';
        }

        if (
            !empty($license['expires_at']) &&
            strtotime($license['expires_at']) < time()
        ) {
            return 'EXPIRED';
        }

        return strtoupper((string)$license['status']);
    }

    public static function valid(PDO $db): bool
    {
        if (self::isSuperAdmin($db)) {
            return true;
        }

        return in_array(
            self::status($db),
            ['TRIAL', 'ACTIVE'],
            true
        );
    }

    public static function requireValid(PDO $db): void
    {
        if (self::isSuperAdmin($db)) {
            return;
        }

        if (!self::valid($db)) {
            self::deny(
                'License Tidak Aktif',
                'License BAJAMA untuk organisasi ini tidak aktif atau sudah expired.'
            );
        }
    }

    /**
     * Mengambil feature JSON dari licenses.features.
     *
     * Format yang didukung:
     *
     * {
     *   "dashboard": true,
     *   "billing": true,
     *   "mikrotik": true,
     *   "pppoe": true,
     *   "hotspot": true,
     *   "static": true,
     *   "fiber": true,
     *   "olt": true,
     *   "onu": true,
     *   "noc": true,
     *   "api": true
     * }
     */
    public static function features(PDO $db): array
    {
        if (self::isSuperAdmin($db)) {
            return [
                'dashboard' => true,
                'billing' => true,
                'mikrotik' => true,
                'pppoe' => true,
                'hotspot' => true,
                'static' => true,
                'fiber' => true,
                'olt' => true,
                'onu' => true,
                'noc' => true,
                'api' => true,
            ];
        }

        $license = self::current($db);

        if (!$license || !self::valid($db)) {
            return [];
        }

        $raw = $license['features'] ?? '';

        if ($raw === null || trim((string)$raw) === '') {
            return [];
        }

        $decoded = json_decode(
            (string)$raw,
            true
        );

        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * Cek apakah feature tersedia.
     *
     * Support:
     * true / false
     * 1 / 0
     * "true" / "false"
     */
    public static function hasFeature(
        PDO $db,
        string $feature
    ): bool {
        if (self::isSuperAdmin($db)) {
            return true;
        }

        // OWNER ISP memiliki akses penuh ke seluruh modul MikroTik/network
        // organisasinya; batas organisasi dan validitas license tetap berlaku.
        if (self::isOwner($db) && in_array(strtolower(trim($feature)), ['mikrotik', 'pppoe', 'hotspot', 'static', 'fiber', 'olt', 'onu', 'noc', 'api'], true)) {
            return self::valid($db);
        }

        if (!self::valid($db)) {
            return false;
        }

        $feature = strtolower(trim($feature));

        if ($feature === '') {
            return false;
        }

        $features = self::features($db);

        if (!array_key_exists($feature, $features)) {
            return false;
        }

        $value = $features[$feature];

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int)$value) === 1;
        }

        if (is_string($value)) {
            return in_array(
                strtolower(trim($value)),
                ['1', 'true', 'yes', 'on', 'enabled', 'active'],
                true
            );
        }

        return false;
    }

    /**
     * Feature aliases.
     */
    public static function hasAnyFeature(
        PDO $db,
        array $features
    ): bool {
        foreach ($features as $feature) {
            if (self::hasFeature($db, (string)$feature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wajib feature tertentu.
     */
    public static function requireFeature(
        PDO $db,
        string $feature
    ): void {
        if (self::isSuperAdmin($db)) {
            return;
        }

        self::requireValid($db);

        if (!self::hasFeature($db, $feature)) {
            self::deny(
                'Feature Tidak Aktif',
                'Feature "' . htmlspecialchars(
                    $feature,
                    ENT_QUOTES,
                    'UTF-8'
                ) . '" tidak termasuk dalam license BAJAMA Anda.'
            );
        }
    }

    /**
     * Cek batas resource.
     *
     * current = jumlah resource yang sudah digunakan.
     */
    public static function can(
        PDO $db,
        string $resource,
        int $current = 0
    ): bool {
        if (self::isSuperAdmin($db)) {
            return true;
        }

        if (!self::valid($db)) {
            return false;
        }

        $license = self::current($db);

        if (!$license) {
            return false;
        }

        $limits = [
            'customers' => 'max_customers',
            'routers'   => 'max_routers',
            'olts'      => 'max_olts',
            'onus'      => 'max_onus',
        ];

        if (!isset($limits[$resource])) {
            return false;
        }

        $limit = (int)$license[$limits[$resource]];

        /*
         * Nilai 0 berarti unlimited.
         */
        if ($limit <= 0) {
            return true;
        }

        return $current < $limit;
    }

    /**
     * Wajib masih tersedia quota resource.
     */
    public static function requireLimit(
        PDO $db,
        string $resource,
        int $current = 0
    ): void {
        self::requireValid($db);

        if (!self::can($db, $resource, $current)) {
            self::deny(
                'Batas License Tercapai',
                'Batas license untuk resource "' .
                htmlspecialchars(
                    $resource,
                    ENT_QUOTES,
                    'UTF-8'
                ) .
                '" sudah tercapai.'
            );
        }
    }

    /**
     * HTTP 403 terpusat.
     */
    private static function deny(
        string $title,
        string $message
    ): void {
        http_response_code(403);

        echo '<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>403 - BAJAMA</title>
<link
 href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
 rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
<div class="card shadow-sm border-0">
<div class="card-body text-center p-5">
<div class="display-5 fw-bold text-danger">403</div>
<h3 class="fw-bold mt-3">' .
            htmlspecialchars(
                $title,
                ENT_QUOTES,
                'UTF-8'
            ) .
            '</h3>
<p class="text-muted">' .
            $message .
            '</p>
<a href="core.php" class="btn btn-primary">
Kembali ke Dashboard
</a>
</div>
</div>
</div>
</body>
</html>';

        exit;
    }
}
