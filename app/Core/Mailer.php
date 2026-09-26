<?php

declare(strict_types=1);

namespace BAJAMA\Core;

use PDO;
use RuntimeException;

final class Mailer
{
    public static function send(PDO $db, string $to, string $subject, string $body): void
    {
        $settings = self::settings($db);
        if ((string)($settings['host'] ?? '') === '' || (string)($settings['username'] ?? '') === '') {
            if (!mail($to, $subject, $body, "From: " . ((string)($settings['from_email'] ?? 'no-reply@localhost')) . "\r\n" . "Content-Type: text/plain; charset=UTF-8\r\n")) {
                throw new RuntimeException('SMTP belum dikonfigurasi dan email server lokal gagal mengirim email.');
            }
            return;
        }

        $host = (string)$settings['host'];
        $port = (int)($settings['port'] ?? 587);
        $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $error, 15);
        if (!$socket) {
            throw new RuntimeException('Tidak dapat terhubung ke SMTP: ' . $error);
        }
        stream_set_timeout($socket, 15);
        self::expect($socket, 220);
        self::command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), 250);
        if ((int)($settings['tls'] ?? 1) === 1) {
            self::command($socket, 'STARTTLS', 220);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('TLS SMTP gagal diaktifkan.');
            }
            self::command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), 250);
        }
        self::command($socket, 'AUTH LOGIN', 334);
        self::command($socket, base64_encode((string)$settings['username']), 334);
        self::command($socket, base64_encode((string)$settings['password']), 235);
        $from = (string)($settings['from_email'] ?: $settings['username']);
        self::command($socket, 'MAIL FROM:<' . $from . '>', 250);
        self::command($socket, 'RCPT TO:<' . $to . '>', 250);
        self::command($socket, 'DATA', 354);
        $headers = 'From: ' . ((string)($settings['from_name'] ?? 'BAJAMA')) . ' <' . $from . ">\r\n" . 'To: <' . $to . ">\r\n" . 'Subject: ' . $subject . "\r\n" . "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        fwrite($socket, $headers . $body . "\r\n.\r\n");
        self::expect($socket, 250);
        fwrite($socket, "QUIT\r\n");
        fclose($socket);
    }

    public static function settings(PDO $db): array
    {
        $rows = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'mail_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $password = (string)($rows['mail_password'] ?? '');
        if ($password !== '') {
            try { $password = decrypt_app_value($password); } catch (\Throwable $e) { $password = ''; }
        }
        return [
            'host' => $rows['mail_host'] ?? env('MAIL_HOST', ''),
            'port' => (int)($rows['mail_port'] ?? env('MAIL_PORT', '587')),
            'tls' => (int)($rows['mail_tls'] ?? env('MAIL_TLS', '1')),
            'username' => $rows['mail_username'] ?? env('MAIL_USERNAME', ''),
            'password' => $password !== '' ? $password : (string)env('MAIL_PASSWORD', ''),
            'from_email' => $rows['mail_from_email'] ?? env('MAIL_FROM_EMAIL', ''),
            'from_name' => $rows['mail_from_name'] ?? env('MAIL_FROM_NAME', 'BAJAMA'),
        ];
    }

    private static function command($socket, string $command, int $expected): void { fwrite($socket, $command . "\r\n"); self::expect($socket, $expected); }
    private static function expect($socket, int $expected): void { $response = ''; while (($line = fgets($socket)) !== false) { $response .= $line; if (isset($line[3]) && $line[3] === ' ') break; } if ((int)substr($response, 0, 3) !== $expected) throw new RuntimeException('SMTP error: ' . trim($response)); }
}