<?php
declare(strict_types=1);

namespace BAJAMA\Core;

use PDO;

final class RBAC
{
    private static function isSuperAdmin(PDO $db): bool
    {
        $userId = Auth::userId();

        if (!$userId) {
            return false;
        }

        $stmt = $db->prepare(
            'SELECT 1
             FROM user_roles ur
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ?
               AND r.name = ?
             LIMIT 1'
        );

        $stmt->execute([$userId, 'SUPER_ADMIN']);

        return (bool)$stmt->fetchColumn();
    }

    private static function isPlatformRestrictedPermission(string $permission): bool
    {
        return in_array($permission, ['users.manage', 'license.manage', 'audit.view', 'license_plans.manage'], true);
    }

    private static function isOwnerNetworkPermission(PDO $db, string $permission): bool
    {
        if (!in_array($permission, ['mikrotik.view', 'mikrotik.manage', 'network.view', 'network.manage'], true)) {
            return false;
        }

        $userId = Auth::userId();
        if (!$userId) {
            return false;
        }

        $stmt = $db->prepare(
            'SELECT 1 FROM user_roles ur
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ? AND r.name = "OWNER" LIMIT 1'
        );
        $stmt->execute([$userId]);
        return (bool)$stmt->fetchColumn();
    }

    public static function hasPermission(PDO $db, string $permission): bool
    {
        $userId = Auth::userId();

        if (!$userId) {
            return false;
        }

        if (self::isSuperAdmin($db)) {
            return true;
        }

        if (self::isOwnerNetworkPermission($db, $permission)) {
            return true;
        }

        if (self::isPlatformRestrictedPermission($permission) && !self::isSuperAdmin($db)) {
            return false;
        }

        $stmt = $db->prepare(
            'SELECT 1
             FROM user_roles ur
             INNER JOIN role_permissions rp
                 ON rp.role_id = ur.role_id
             INNER JOIN permissions p
                 ON p.id = rp.permission_id
             WHERE ur.user_id = ?
               AND p.name = ?
             LIMIT 1'
        );

        $stmt->execute([$userId, $permission]);

        return (bool)$stmt->fetchColumn();
    }

    public static function require(PDO $db, string $permission): void
    {
        if (self::isPlatformRestrictedPermission($permission) && !self::isSuperAdmin($db)) {
            http_response_code(403);

            echo '<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>403 - BAJAMA</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
<div class="card shadow-sm">
<div class="card-body text-center p-5">
<h1 class="display-5 fw-bold">403</h1>
<h4>Akses Ditolak</h4>
<p class="text-muted">Akses platform-level hanya tersedia untuk SUPER_ADMIN.</p>
<a href="/dashboard.php" class="btn btn-primary">Kembali ke Dashboard</a>
</div>
</div>
</div>
</body>
</html>';

            exit;
        }

        if (self::isOwnerNetworkPermission($db, $permission)) {
            return;
        }

        if (!self::hasPermission($db, $permission)) {
            http_response_code(403);

            echo '<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>403 - BAJAMA</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
<div class="card shadow-sm">
<div class="card-body text-center p-5">
<h1 class="display-5 fw-bold">403</h1>
<h4>Akses Ditolak</h4>
<p class="text-muted">Anda tidak memiliki permission untuk mengakses halaman ini.</p>
<a href="/dashboard.php" class="btn btn-primary">Kembali ke Dashboard</a>
</div>
</div>
</div>
</body>
</html>';

            exit;
        }
    }

    public static function roles(PDO $db): array
    {
        $userId = Auth::userId();

        if (!$userId) {
            return [];
        }

        $stmt = $db->prepare(
            'SELECT r.name
             FROM user_roles ur
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ?
             ORDER BY r.name'
        );

        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
