<?php

declare(strict_types=1);

function env(string $key, ?string $default = null): ?string
{
    static $env = null;

    $processValue = getenv($key);

    if ($processValue !== false) {
        return (string)$processValue;
    }

    if ($env === null) {
        $env = [];

        $file = dirname(__DIR__) . '/.env';

        if (is_readable($file)) {
            $lines = @file(
                $file,
                FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
            );

            foreach (is_array($lines) ? $lines : [] as $line) {
                $line = trim($line);

                if ($line === '' || strpos($line, '#') === 0) {
                    continue;
                }

                [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
                $value = trim($v);

                if (
                    strlen($value) >= 2
                    && (($value[0] === '"' && substr($value, -1) === '"')
                        || ($value[0] === "'" && substr($value, -1) === "'"))
                ) {
                    $value = substr($value, 1, -1);
                }

                $env[trim($k)] = $value;
            }
        }
    }

    return $env[$key] ?? $default;
}

$debug = filter_var(
    env('APP_DEBUG', 'false'),
    FILTER_VALIDATE_BOOLEAN,
    FILTER_NULL_ON_FAILURE
);

if ($debug !== true) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env('DB_HOST', '127.0.0.1'),
        env('DB_PORT', '3306'),
        env('DB_DATABASE', 'bajama')
    );

    $pdo = new PDO(
        $dsn,
        env('DB_USERNAME'),
        env('DB_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

function app_key(): string
{
    $key = trim((string)env('APP_KEY', ''));

    if ($key === '' || $key === 'replace-with-a-random-64-character-secret') {
        throw new RuntimeException('APP_KEY belum dikonfigurasi.');
    }

    if (strlen($key) < 32) {
        throw new RuntimeException('APP_KEY terlalu pendek.');
    }

    return $key;
}

function encrypt_app_value(string $value): string
{
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($value, 'AES-256-CBC', hash('sha256', app_key(), true), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . (string)$cipher);
}

function decrypt_app_value(string $value): string
{
    $raw = base64_decode($value, true);
    if ($raw === false || strlen($raw) < 17) return '';
    return (string)openssl_decrypt(substr($raw, 16), 'AES-256-CBC', hash('sha256', app_key(), true), OPENSSL_RAW_DATA, substr($raw, 0, 16));
}
