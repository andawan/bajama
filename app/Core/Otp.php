<?php

declare(strict_types=1);

namespace BAJAMA\Core;

use PDO;
use RuntimeException;

final class Otp
{
    public static function issue(PDO $db, string $email, string $purpose, array $payload = []): string
    {
        $code = (string)random_int(100000, 999999);
        $db->prepare('UPDATE otp_codes SET consumed_at = NOW() WHERE email = ? AND purpose = ? AND consumed_at IS NULL')->execute([$email, $purpose]);
        $db->prepare('INSERT INTO otp_codes (email, purpose, code_hash, payload, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))')->execute([$email, $purpose, hash('sha256', $code), json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        Mailer::send($db, $email, 'Kode OTP BAJAMA', "Kode OTP Anda: {$code}\n\nKode berlaku selama 10 menit. Jika Anda tidak meminta kode ini, abaikan email ini.");
        return $code;
    }

    public static function consume(PDO $db, string $email, string $purpose, string $code): array
    {
        $stmt = $db->prepare('SELECT * FROM otp_codes WHERE email = ? AND purpose = ? AND consumed_at IS NULL AND expires_at >= NOW() ORDER BY id DESC LIMIT 1');
        $stmt->execute([$email, $purpose]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['attempts'] >= 5 || !hash_equals((string)$row['code_hash'], hash('sha256', trim($code)))) {
            if ($row) $db->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?')->execute([(int)$row['id']]);
            throw new RuntimeException('Kode OTP tidak valid atau sudah kedaluwarsa.');
        }
        $db->prepare('UPDATE otp_codes SET consumed_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
        $payload = json_decode((string)($row['payload'] ?? '{}'), true);
        return is_array($payload) ? $payload : [];
    }
}