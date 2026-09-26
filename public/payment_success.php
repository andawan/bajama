<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\RBAC;

$db = db();
Auth::requireLogin(true);
$registrationId = (int)($_GET['registration_id'] ?? 0);
$registration = null;

if ($registrationId > 0) {
    try {
        $roles = RBAC::roles($db);
        $isSuperAdmin = in_array('SUPER_ADMIN', $roles, true);
        $stmt = $db->prepare('SELECT r.*, p.name AS plan_name, p.price_monthly AS plan_price FROM license_registrations r LEFT JOIN license_plans p ON p.id = r.plan_id LEFT JOIN users u ON u.email = r.email WHERE r.id = ? AND (u.id = ? OR u.organization_id = ?' . ($isSuperAdmin ? ' OR 1=1' : '') . ') LIMIT 1');
        $stmt->execute([$registrationId, (int)Auth::userId(), (int)Auth::organizationId()]);
        $registration = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $registration = null;
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pembayaran Berhasil</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            background: radial-gradient(circle at top left, rgba(16,185,129,0.18), transparent 22%),
                        linear-gradient(135deg, #ecfdf5 0%, #f8fafc 100%);
        }
        .success-card {
            border-radius: 28px;
            overflow: hidden;
            border: 0;
            box-shadow: 0 28px 80px rgba(15, 23, 42, 0.12);
        }
        .success-header {
            background: linear-gradient(135deg, #0f172a 0%, #0f766e 100%);
            color: white;
            padding: 28px 24px 18px;
        }
        .success-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: rgba(255,255,255,0.12);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin-bottom: 16px;
        }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-7">
                <div class="card success-card">
                    <div class="success-header text-center">
                        <div class="success-icon"><i class="bi bi-check2-circle"></i></div>
                        <div class="badge bg-white text-success rounded-pill px-3 py-2 mb-3">Pembayaran dikonfirmasi</div>
                        <h1 class="h2 fw-bold mb-2">Terima kasih, pembayaran Anda telah kami terima.</h1>
                        <p class="mb-0 text-white-50">Data pendaftaran Anda sudah masuk ke sistem dan menunggu proses verifikasi dari superadmin.</p>
                    </div>
                    <div class="card-body p-4 p-lg-5 text-center">
                        <?php if ($registration): ?>
                            <div class="card bg-light text-start mb-4 border-0">
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <div class="text-muted small">Perusahaan</div>
                                            <div class="fw-semibold"><?= htmlspecialchars((string)($registration['company_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="text-muted small">Paket</div>
                                            <div class="fw-semibold"><?= htmlspecialchars((string)($registration['plan_name'] ?? $registration['package_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="text-muted small">Status</div>
                                            <div class="fw-semibold"><?= htmlspecialchars((string)($registration['status'] ?? 'PAID'), ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="text-muted small">Referensi</div>
                                            <div class="fw-semibold"><?= htmlspecialchars((string)($registration['payment_reference'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex justify-content-center gap-3 flex-wrap">
                            <a href="index.php" class="btn btn-primary">Kembali ke beranda</a>
                            <a href="login.php" class="btn btn-outline-dark">Login portal</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
