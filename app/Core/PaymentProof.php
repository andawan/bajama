<?php

declare(strict_types=1);

namespace BAJAMA\Core;

use PDO;

final class PaymentProof
{
    public static function isExternal(string $value): bool
    {
        return (bool)preg_match('#^https?://#i', trim($value));
    }

    public static function isImage(string $value, ?string $absolutePath = null): bool
    {
        $value = trim($value);
        $mime = '';
        if ($absolutePath !== null && is_file($absolutePath)) {
            $mime = (string)(mime_content_type($absolutePath) ?: '');
        }

        return strpos($mime, 'image/') === 0
            || preg_match('/\.(png|jpe?g|gif|webp|bmp|svg)(?:\?.*)?$/i', $value) === 1;
    }

    public static function localPath(string $storedValue): ?string
    {
        $storedValue = trim($storedValue);
        if ($storedValue === '' || self::isExternal($storedValue)) {
            return null;
        }

        $relative = ltrim((string)(parse_url($storedValue, PHP_URL_PATH) ?: $storedValue), '/');
        $prefix = 'uploads/payment_proofs/';
        if (strpos($relative, $prefix) !== 0) {
            return null;
        }

        $filename = basename($relative);
        if ($filename === '' || !preg_match('/^proof-[a-f0-9]{24}\.(jpg|jpeg|png|webp|pdf)$/i', $filename)) {
            return null;
        }

        return dirname(__DIR__, 2) . '/public/' . $prefix . $filename;
    }

    public static function url(int $registrationId, string $storedValue): string
    {
        $storedValue = trim($storedValue);
        if ($storedValue === '' || self::isExternal($storedValue)) {
            return $storedValue;
        }

        return 'payment-proof.php?registration_id=' . $registrationId;
    }

    public static function imageUrl(int $registrationId, string $storedValue): string
    {
        $storedValue = trim($storedValue);
        if ($storedValue === '') {
            return '';
        }

        $localPath = self::localPath($storedValue);
        if ($localPath !== null && self::isImage($storedValue, $localPath)) {
            return 'payment-proof.php?registration_id=' . $registrationId;
        }

        // External providers often hide the image extension behind a CDN URL.
        // Let the user open the supplied proof source; the browser decides
        // whether it is an image or a hosted payment page.
        return self::isExternal($storedValue) ? $storedValue : '';
    }

    public static function authorize(PDO $db, int $registrationId): ?array
    {
        $stmt = $db->prepare('SELECT r.id, r.payment_proof, r.email FROM license_registrations r WHERE r.id = ? LIMIT 1');
        $stmt->execute([$registrationId]);
        $registration = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$registration) {
            return null;
        }

        $roles = RBAC::roles($db);
        if (in_array('SUPER_ADMIN', $roles, true)) {
            return $registration;
        }

        $userId = Auth::userId();
        $organizationId = Auth::organizationId();
        $userStmt = $db->prepare('SELECT id FROM users WHERE id = ? AND organization_id = ? AND email = ? LIMIT 1');
        $userStmt->execute([$userId, $organizationId, (string)$registration['email']]);
        return $userStmt->fetchColumn() ? $registration : null;
    }
}
