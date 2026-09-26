<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\RBAC;

$db = db();

Auth::requireLogin(true);
$currentUserId = (int)Auth::userId();
$currentOrganizationId = (int)Auth::organizationId();
$currentRoles = RBAC::roles($db);
$isSuperAdmin = in_array('SUPER_ADMIN', $currentRoles, true);

try {
    $db->query("SHOW COLUMNS FROM `license_registrations` LIKE 'payment_proof'");
} catch (Throwable $e) {
    $db->exec('ALTER TABLE license_registrations ADD COLUMN payment_proof VARCHAR(255) NULL AFTER payment_reference');
}

try {
    $db->query("SHOW COLUMNS FROM `license_registrations` LIKE 'payment_review_status'");
} catch (Throwable $e) {
    $db->exec("ALTER TABLE `license_registrations` ADD COLUMN `payment_review_status` ENUM('WAITING_REVIEW','VERIFIED','REJECTED') NOT NULL DEFAULT 'WAITING_REVIEW' AFTER `payment_proof`");
}

$registrationId = (int)($_GET['registration_id'] ?? 0);
$registration = null;
$paymentMethods = [];
$selectedPaymentMethod = null;
$selectedPaymentMethodId = 0;
$paymentReferenceValue = trim((string)($_POST['payment_reference'] ?? ''));

if ($registrationId > 0) {
    try {
        $sql = 'SELECT r.*, p.name AS plan_name, p.price_monthly AS plan_price FROM license_registrations r LEFT JOIN license_plans p ON p.id = r.plan_id LEFT JOIN users u ON u.email = r.email WHERE r.id = ? AND (u.id = ? OR u.organization_id = ?)' . ($isSuperAdmin ? ' OR 1=1' : '') . ' LIMIT 1';
        $stmt = $db->prepare($sql);
        $stmt->execute([$registrationId, $currentUserId, $currentOrganizationId]);
        $registration = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $registration = null;
    }
}

if (!$registration) {
    http_response_code(404);
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Pendaftaran Tidak Ditemukan</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><div class="container py-5"><div class="card shadow-sm"><div class="card-body text-center p-5"><h1 class="display-5 fw-bold">404</h1><p class="text-muted">Data pendaftaran tidak ditemukan.</p><a href="register.php" class="btn btn-primary">Kembali ke pendaftaran</a></div></div></div></body></html>';
    exit;
}

try {
    $paymentMethods = $db->query('SELECT * FROM payment_methods WHERE active = 1 AND organization_id IS NULL ORDER BY type, name')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $paymentMethods = [];
}

if ($registration && !empty($registration['payment_method_id'])) {
    $selectedPaymentMethodId = (int)$registration['payment_method_id'];
    try {
        $selectedMethodStmt = $db->prepare('SELECT * FROM payment_methods WHERE id = ? AND organization_id IS NULL LIMIT 1');
        $selectedMethodStmt->execute([$selectedPaymentMethodId]);
        $selectedPaymentMethod = $selectedMethodStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $selectedPaymentMethod = null;
    }
}

if ($selectedPaymentMethodId === 0 && !empty($_POST['payment_method_id'])) {
    $selectedPaymentMethodId = (int)$_POST['payment_method_id'];
    foreach ($paymentMethods as $method) {
        if ((int)$method['id'] === $selectedPaymentMethodId) {
            $selectedPaymentMethod = $method;
            break;
        }
    }
}

$paymentMethodDisplay = function (array $method): string {
    $type = strtoupper((string)($method['type'] ?? 'BANK'));
    $label = (string)($method['name'] ?? 'Metode pembayaran');
    $primary = (string)($method['account_name'] ?? '');
    $secondary = (string)($method['account_number'] ?? $method['provider_name'] ?? '');

    if ($type === 'BANK') {
        return $label . ' — ' . ($primary !== '' ? $primary : 'Nama rekening') . ' / ' . ($secondary !== '' ? $secondary : 'Nomor rekening');
    }

    if ($type === 'EWALLET') {
        return $label . ' — ' . ($primary !== '' ? $primary : 'Nama pemilik') . ' / ' . ($secondary !== '' ? $secondary : 'Nomor HP aktif');
    }

    return $label . ' — ' . ($primary !== '' ? $primary : 'Nama akun / email') . ' / ' . ($secondary !== '' ? $secondary : 'ID / akun gateway');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf((string)($_POST['_csrf'] ?? ''));
    $paymentReference = trim((string)($_POST['payment_reference'] ?? ''));
    $paymentReferenceValue = $paymentReference;
    $selectedMethodId = (int)($_POST['payment_method_id'] ?? 0);
    $selectedPaymentMethodId = $selectedMethodId;
    $paymentProofUrl = trim((string)($_POST['payment_proof_url'] ?? ''));

    $uploadError = null;
    if (isset($_FILES['payment_proof']) && (int)($_FILES['payment_proof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ((int)($_FILES['payment_proof']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $uploadError = 'Upload bukti pembayaran gagal. Silakan pilih file gambar maksimal 5 MB dan coba lagi.';
        }
    }
    if ($uploadError === null && isset($_FILES['payment_proof']) && is_uploaded_file((string)($_FILES['payment_proof']['tmp_name'] ?? ''))) {
        $uploadDir = __DIR__ . '/uploads/payment_proofs';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $file = $_FILES['payment_proof'];
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
        if ((int)$file['size'] > 5 * 1024 * 1024 || !isset($allowed[$mime])) {
            $uploadError = 'Bukti pembayaran harus JPG, PNG, WEBP, atau PDF maksimal 5 MB.';
        }
        $safeName = 'proof-' . bin2hex(random_bytes(12)) . '.' . ($allowed[$mime] ?? 'bin');
        $targetPath = $uploadDir . '/' . $safeName;
        if ($uploadError === null && move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
            chmod($targetPath, 0644);
            $paymentProofUrl = 'uploads/payment_proofs/' . $safeName;
        } elseif ($uploadError === null) {
            $uploadError = 'File bukti pembayaran tidak dapat disimpan. Periksa permission folder upload.';
        }
    }

    $methodCheck = $db->prepare('SELECT id FROM payment_methods WHERE id = ? AND active = 1 AND organization_id IS NULL LIMIT 1');
    $methodCheck->execute([$selectedMethodId]);
    if ($paymentReference === '' || $selectedMethodId <= 0 || !$methodCheck->fetchColumn()) {
        $message = 'Nomor referensi pembayaran dan metode pembayaran wajib diisi.';
    } elseif ($uploadError !== null) {
        $message = $uploadError;
    } elseif ($paymentProofUrl === '') {
        $message = 'Upload foto/PDF bukti pembayaran atau isi link bukti pembayaran terlebih dahulu.';
    } elseif (strpos($paymentProofUrl, 'uploads/payment_proofs/') !== 0 && !filter_var($paymentProofUrl, FILTER_VALIDATE_URL)) {
        $message = 'Link bukti pembayaran tidak valid.';
    } else {
        try {
            $stmt = $db->prepare('UPDATE license_registrations SET payment_method_id = ?, payment_reference = ?, payment_proof = ?, payment_review_status = "WAITING_REVIEW", status = "PAID" WHERE id = ?');
            $stmt->execute([$selectedMethodId, $paymentReference, $paymentProofUrl !== '' ? $paymentProofUrl : null, $registrationId]);
            header('Location: payment_success.php?registration_id=' . $registrationId);
            exit;
        } catch (Throwable $e) {
            error_log('BAJAMA payment submit error: ' . $e->getMessage());
            $message = 'Pembayaran gagal diproses. Silakan cek data pembayaran dan coba lagi.';
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pembayaran Lisensi BAJAMA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --bg-1: #081120;
            --bg-2: #0f172a;
            --bg-3: #1d4ed8;
            --card: rgba(255,255,255,0.96);
            --line: rgba(148,163,184,0.22);
            --soft: #f8fafc;
        }

        body {
            min-height: 100vh;
            background: radial-gradient(circle at top left, rgba(96,165,250,0.20), transparent 25%),
                        linear-gradient(135deg, #eef4ff 0%, #f8fafc 100%);
            color: #0f172a;
        }

        .payment-shell {
            max-width: 1040px;
            margin: 32px auto;
        }

        .payment-card {
            border: 1px solid var(--line);
            border-radius: 28px;
            box-shadow: 0 28px 80px rgba(15, 23, 42, 0.12);
            overflow: hidden;
            background: var(--card);
        }

        .payment-hero {
            background: linear-gradient(135deg, #0f172a 0%, #122b66 40%, #2563eb 100%);
            color: #fff;
            padding: 34px 28px;
        }

        .eyebrow {
            display: inline-block;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 0.72rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            background: rgba(255,255,255,0.12);
            color: rgba(255,255,255,0.86);
            border: 1px solid rgba(255,255,255,0.12);
        }

        .summary-box {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 20px;
            padding: 18px;
            backdrop-filter: blur(8px);
        }

        .summary-label {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: rgba(255,255,255,0.72);
        }

        .summary-value {
            font-size: 1.5rem;
            font-weight: 800;
            margin-top: 4px;
        }

        .payment-form-wrap {
            padding: 28px;
        }

        .tag {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            background: rgba(37,99,235,0.08);
            color: #1d4ed8;
        }

        .alert-soft {
            background: rgba(37,99,235,0.05);
            border: 1px solid rgba(37,99,235,0.12);
            color: #1e3a8a;
            border-radius: 18px;
        }

        .form-control, .form-select {
            min-height: 52px;
            border-radius: 14px;
            border: 1px solid #dfe8f4;
            background: #f8fafc;
            box-shadow: none;
        }

        .form-control:focus, .form-select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 0.2rem rgba(37,99,235,0.12);
            background: #fff;
        }

        .btn-bajama {
            min-height: 52px;
            border-radius: 14px;
            background: linear-gradient(135deg, #0f172a 0%, #1d4ed8 100%);
            border: none;
            font-weight: 700;
        }

        .btn-bajama:hover {
            filter: brightness(1.04);
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg bg-dark navbar-dark sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">BAJAMA</a>
            <div class="ms-auto d-flex gap-3 small">
                <a class="nav-link" href="index.php">Beranda</a>
                <a class="nav-link" href="license_catalog.php">Daftar Lisensi</a>
                <a class="nav-link" href="login.php">Login</a>
            </div>
        </div>
    </nav>

    <div class="container payment-shell">
        <div class="payment-card row g-0">
            <div class="col-lg-4 payment-hero">
                <span class="eyebrow">Checkout</span>
                <h1 class="h2 fw-bold mt-3 mb-3">Pembayaran lisensi</h1>
                <p class="text-white-50 mb-4">Halo <?= htmlspecialchars((string)($registration['full_name'] ?? 'Pelanggan'), ENT_QUOTES, 'UTF-8') ?>, langkah berikutnya adalah menyelesaikan pembayaran dengan metode yang dipilih.</p>

                <div class="summary-box mb-3">
                    <div class="summary-label">Paket</div>
                    <div class="summary-value"><?= htmlspecialchars((string)($registration['plan_name'] ?? $registration['package_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>

                <div class="summary-box">
                    <div class="summary-label">Total pembayaran</div>
                    <div class="summary-value">Rp <?= number_format((float)($registration['plan_price'] ?? 0), 0, ',', '.') ?></div>
                    <div class="small text-white-50 mt-2"><?= htmlspecialchars((string)($registration['company_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>

            <div class="col-lg-8 payment-form-wrap">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <span class="tag"><i class="bi bi-wallet2"></i> Metode pembayaran</span>
                    <span class="badge bg-light text-dark rounded-pill">Status: <?= htmlspecialchars((string)($registration['status'] ?? 'PENDING'), ENT_QUOTES, 'UTF-8') ?></span>
                </div>

                <?php if (!empty($message)): ?>
                    <div class="alert alert-danger rounded-4"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <?php if ($selectedPaymentMethod): ?>
                    <div class="alert alert-soft mb-4">
                        <div class="fw-semibold mb-1"><?= htmlspecialchars((string)($selectedPaymentMethod['name'] ?? 'Metode pembayaran'), ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string)($selectedPaymentMethod['type'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>)</div>
                        <div class="small text-secondary"><?= htmlspecialchars((string)($selectedPaymentMethod['account_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string)($selectedPaymentMethod['account_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="mt-2"><?= nl2br(htmlspecialchars((string)($selectedPaymentMethod['instructions'] ?? 'Instruksi belum tersedia.'), ENT_QUOTES, 'UTF-8')) ?></div>
                    </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Metode pembayaran</label>
                        <select name="payment_method_id" class="form-select" required>
                            <option value="">Pilih metode</option>
                            <?php foreach ($paymentMethods as $method): ?>
                                <option value="<?= (int)$method['id'] ?>" <?= ((int)$method['id'] === $selectedPaymentMethodId) ? 'selected' : '' ?>><?= htmlspecialchars($paymentMethodDisplay($method), ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string)($method['type'] ?? 'BANK'), ENT_QUOTES, 'UTF-8') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nomor referensi pembayaran</label>
                        <input type="text" name="payment_reference" class="form-control" placeholder="Contoh: INV-20260922-001" value="<?= htmlspecialchars($paymentReferenceValue, ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Upload bukti pembayaran</label>
                            <input type="file" name="payment_proof" class="form-control" accept="image/*,.pdf">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Link bukti pembayaran</label>
                            <input type="url" name="payment_proof_url" class="form-control" placeholder="https://...">
                        </div>
                    </div>

                    <div class="alert alert-info rounded-4">
                        <?= empty($paymentMethods) ? 'Belum ada metode pembayaran aktif. Superadmin perlu menambahkannya di panel superadmin.' : 'Lakukan pembayaran sesuai metode yang dipilih, lalu upload bukti transfer atau lampirkan link bukti pembayaran.' ?>
                    </div>

                    <button type="submit" class="btn btn-bajama btn-lg w-100">Konfirmasi Pembayaran</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
