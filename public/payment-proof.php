<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\PaymentProof;

$registrationId = (int)($_GET['registration_id'] ?? 0);
$registration = $registrationId > 0 ? PaymentProof::authorize(db(), $registrationId) : null;
$storedValue = trim((string)($registration['payment_proof'] ?? ''));
$path = PaymentProof::localPath($storedValue);

if (!$registration || !$path || !is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('Bukti pembayaran tidak ditemukan.');
}

$mime = (string)(mime_content_type($path) ?: 'application/octet-stream');
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
    http_response_code(415);
    exit('Format bukti pembayaran tidak didukung.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);