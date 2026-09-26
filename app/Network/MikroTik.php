<?php
declare(strict_types=1);

namespace BAJAMA\Network;

use PDO;

final class MikroTik
{
    public static function encryptPassword(
        string $password
    ): string {
        $key = hash(
            'sha256',
            (string)\app_key(),
            true
        );

        $iv = random_bytes(16);

        $cipher = openssl_encrypt(
            $password,
            'AES-256-CBC',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($cipher === false) {
            throw new \RuntimeException(
                'Gagal mengenkripsi password MikroTik.'
            );
        }

        return base64_encode($iv . $cipher);
    }

    public static function decryptPassword(
        string $payload
    ): string {
        $raw = base64_decode($payload, true);

        if (
            $raw === false ||
            strlen($raw) < 17
        ) {
            throw new \RuntimeException(
                'Password MikroTik tersimpan tidak valid.'
            );
        }

        $key = hash(
            'sha256',
            (string)\app_key(),
            true
        );

        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);

        $plain = openssl_decrypt(
            $cipher,
            'AES-256-CBC',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($plain === false) {
            throw new \RuntimeException(
                'Gagal membuka password MikroTik.'
            );
        }

        return $plain;
    }

    public static function connect(
        array $router
    ): RouterOSApi {
        $api = new RouterOSApi(
            (float)($router['timeout_seconds'] ?? 8)
        );

        $api->connect(
            (string)$router['host'],
            (int)$router['port'],
            (string)$router['username'],
            self::decryptPassword(
                (string)$router['password_encrypted']
            ),
            !empty($router['use_ssl'])
        );

        return $api;
    }

    public static function all(
        PDO $db,
        int $organizationId
    ): array {
        $stmt = $db->prepare(
            'SELECT
                id,
                name,
                host,
                port,
                username,
                use_ssl,
                timeout_seconds,
                status,
                last_tested_at,
                last_error,
                created_at,
                updated_at
             FROM mikrotik_routers
             WHERE organization_id=?
             ORDER BY name'
        );

        $stmt->execute([$organizationId]);

        return $stmt->fetchAll();
    }

    public static function find(
        PDO $db,
        int $organizationId,
        int $id
    ): ?array {
        $stmt = $db->prepare(
            'SELECT *
             FROM mikrotik_routers
             WHERE id=?
               AND organization_id=?
             LIMIT 1'
        );

        $stmt->execute([
            $id,
            $organizationId
        ]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function test(
        PDO $db,
        array $router
    ): array {
        $api = null;
        try {
            $api = self::connect($router);

            $resource =
                $api->resource()[0] ?? [];

            $identity =
                $api->identity()[0] ?? [];

            $stmt = $db->prepare(
                "UPDATE mikrotik_routers
                 SET status='ONLINE',
                     last_tested_at=NOW(),
                     last_error=NULL
                 WHERE id=?
                   AND organization_id=?"
            );

            $stmt->execute([
                (int)$router['id'],
                (int)$router['organization_id']
            ]);

            return [
                'ok' => true,
                'message' =>
                    'Terhubung ke MikroTik.',
                'resource' => $resource,
                'identity' => $identity,
            ];
        } catch (\Throwable $e) {
            try {
                $errorMessage = self::safeErrorMessage($e);

                $stmt = $db->prepare(
                    "UPDATE mikrotik_routers
                     SET status='ERROR',
                         last_tested_at=NOW(),
                         last_error=?
                     WHERE id=?
                       AND organization_id=?"
                );

                $stmt->execute([
                    $errorMessage,
                    (int)$router['id'],
                    (int)$router['organization_id']
                ]);
            } catch (\Throwable $ignore) {
            }

            return [
                'ok' => false,
                'message' => 'Tidak dapat terhubung ke MikroTik.',
                'status' => self::errorStatus($e),
            ];
        } finally {
            if ($api instanceof RouterOSApi) {
                $api->disconnect();
            }
        }
    }

    public static function safeErrorMessage(\Throwable $error): string
    {
        return substr(
            preg_replace(
                '/(password|passwd|secret)=?[^\s,)]*/i',
                '$1=[REDACTED]',
                $error->getMessage()
            ) ?: 'MikroTik connection error.',
            0,
            1000
        );
    }

    public static function errorStatus(\Throwable $error): string
    {
        $message = strtolower($error->getMessage());

        if (
            strpos($message, 'login') !== false
            || strpos($message, 'authentication') !== false
        ) {
            return 'AUTHENTICATION_FAILED';
        }

        if (
            strpos($message, 'timed out') !== false
            || strpos($message, 'timeout') !== false
        ) {
            return 'TIMEOUT';
        }

        if (
            strpos($message, 'connection refused') !== false
            || strpos($message, 'failed') !== false
        ) {
            return 'UNREACHABLE';
        }

        return 'ERROR';
    }
}
