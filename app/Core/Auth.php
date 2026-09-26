<?php

declare(strict_types=1);

namespace BAJAMA\Core;

final class Auth
{
    /**
     * Memastikan session login benar-benar menunjuk
     * ke user dan organization yang valid.
     */
    public static function check(): bool
    {
        if (
            empty($_SESSION['user_id']) ||
            empty($_SESSION['organization_id'])
        ) {
            return false;
        }

        $userId = (int) $_SESSION['user_id'];
        $organizationId = (int) $_SESSION['organization_id'];

        if ($userId <= 0 || $organizationId <= 0) {
            self::clearSession();

            return false;
        }

        /*
         * Validasi user -> organization langsung dari database.
         *
         * Jangan hanya mempercayai organization_id dari session.
         */
        try {
            $db = \db();

            $stmt = $db->prepare(
                'SELECT
                    u.id,
                    u.username,
                    u.full_name,
                    u.organization_id,
                    u.status AS user_status,
                    o.status AS organization_status,
                    o.name AS organization_name
                 FROM users u
                 INNER JOIN organizations o
                    ON o.id = u.organization_id
                 WHERE u.id = ?
                   AND u.organization_id = ?
                 LIMIT 1'
            );

            $stmt->execute([
                $userId,
                $organizationId
            ]);

            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$user) {
                self::clearSession();

                return false;
            }

            if ($user['user_status'] !== 'ACTIVE') {
                self::clearSession();

                return false;
            }

            if ($user['organization_status'] !== 'ACTIVE') {
                self::clearSession();

                return false;
            }

            /*
             * Sinkronisasi informasi session dari database.
             * Organization ID tidak boleh berubah dari nilai
             * yang sudah diverifikasi di atas.
             */
            $_SESSION['organization_id'] = (int) $user['organization_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['organization_name'] = $user['organization_name'];

            return true;

        } catch (\Throwable $e) {
            /*
             * Jangan membocorkan detail database ke browser.
             * Jika database tidak dapat diverifikasi,
             * anggap session tidak valid.
             */
            return false;
        }
    }

    public static function userId(): ?int
    {
        return isset($_SESSION['user_id'])
            ? (int) $_SESSION['user_id']
            : null;
    }

    public static function organizationId(): ?int
    {
        return isset($_SESSION['organization_id'])
            ? (int) $_SESSION['organization_id']
            : null;
    }

    public static function username(): ?string
    {
        return $_SESSION['username'] ?? null;
    }

    public static function requireLogin(bool $allowProfileOnboarding = false): void
    {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }

        /*
         * Setiap halaman yang membutuhkan login juga
         * wajib memiliki license BAJAMA yang valid.
         *
         * Urutan:
         * Login -> Tenant -> License
         */
        if ($allowProfileOnboarding) {
            return;
        }

        License::requireValid(\db());
    }

    /**
     * Login + license + feature module.
     *
     * Contoh:
     * Auth::requireFeature($db, 'billing');
     */
    public static function requireFeature(
        \PDO $db,
        string $feature
    ): void {
        self::requireLogin();

        License::requireFeature(
            $db,
            $feature
        );
    }

    public static function logout(): void
    {
        self::clearSession();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    private static function clearSession(): void
    {
        $_SESSION = [];

        if (
            ini_get('session.use_cookies') &&
            session_status() === PHP_SESSION_ACTIVE
        ) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'] ?? '/',
                $params['domain'] ?? '',
                (bool) ($params['secure'] ?? false),
                (bool) ($params['httponly'] ?? true)
            );
        }
    }
}
