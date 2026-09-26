<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\RBAC;
use BAJAMA\Core\Tenant;

Auth::requireLogin();

$db = db();

$organizationId = Tenant::id();
$userId = Auth::userId();

$canSubmit =
    RBAC::hasPermission($db, 'traffic_catalog.submit') ||
    RBAC::hasPermission($db, 'traffic_catalog.manage');

if (!$canSubmit) {
    http_response_code(403);
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Akses Ditolak - BAJAMA</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
    <div class="container py-5">
        <div class="alert alert-danger shadow-sm">
            <h4 class="alert-heading">403 - Akses Ditolak</h4>
            <p class="mb-0">
                Anda belum memiliki permission
                <code>traffic_catalog.submit</code>.
            </p>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$csrf = csrf_token();

function h_submit($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function submit_normalize_value(string $entryType, string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    switch (strtoupper($entryType)) {
        case 'DOMAIN':
        case 'HOST':
            $value = strtolower($value);
            $value = preg_replace('#^https?://#i', '', $value);
            $value = explode('/', $value, 2)[0];
            $value = rtrim($value, '.');
            $value = preg_replace('/^\*\.\s*/', '', $value);
            return strtolower(trim($value));

        case 'IP':
            if (!filter_var($value, FILTER_VALIDATE_IP)) {
                throw new RuntimeException('IP address tidak valid.');
            }
            return $value;

        case 'CIDR':
            if (strpos($value, '/') === false) {
                throw new RuntimeException('CIDR harus memiliki prefix.');
            }

            [$ip, $prefix] = explode('/', $value, 2);

            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new RuntimeException('IP CIDR tidak valid.');
            }

            if (!ctype_digit($prefix)) {
                throw new RuntimeException('Prefix CIDR tidak valid.');
            }

            $prefix = (int)$prefix;

            $maxPrefix = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4
            ) !== false ? 32 : 128;

            if ($prefix < 0 || $prefix > $maxPrefix) {
                throw new RuntimeException('Prefix CIDR tidak valid.');
            }

            return trim($ip) . '/' . $prefix;

        case 'PORT':
            if (!ctype_digit($value)) {
                throw new RuntimeException('Port harus berupa angka.');
            }

            $port = (int)$value;

            if ($port < 1 || $port > 65535) {
                throw new RuntimeException(
                    'Port harus berada antara 1-65535.'
                );
            }

            return (string)$port;

        case 'PROTOCOL':
            return strtoupper($value);

        default:
            return trim($value);
    }
}

$categories = $db->query("
    SELECT id, name, slug, description, icon
    FROM traffic_categories
    WHERE enabled = 1
    ORDER BY sort_order, name
")->fetchAll(PDO::FETCH_ASSOC);

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf((string) ($_POST['csrf'] ?? $_POST['_csrf'] ?? ''));

        $categoryId = (int)($_POST['category_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $entryType = strtoupper(
            trim((string)($_POST['entry_type'] ?? ''))
        );
        $value = trim((string)($_POST['value'] ?? ''));
        $port = (int)($_POST['port'] ?? 0);
        $protocol = strtoupper(
            trim((string)($_POST['protocol'] ?? 'ANY'))
        );
        $confidence = (float)($_POST['confidence'] ?? 50);
        $description = trim(
            (string)($_POST['description'] ?? '')
        );

        if ($categoryId <= 0) {
            throw new RuntimeException('Kategori wajib dipilih.');
        }

        $stmt = $db->prepare("
            SELECT id
            FROM traffic_categories
            WHERE id = ?
              AND enabled = 1
            LIMIT 1
        ");
        $stmt->execute([$categoryId]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException(
                'Kategori tidak ditemukan atau tidak aktif.'
            );
        }

        $allowedTypes = [
            'DOMAIN',
            'HOST',
            'IP',
            'CIDR',
            'PORT',
            'PROTOCOL',
        ];

        if (!in_array($entryType, $allowedTypes, true)) {
            throw new RuntimeException('Entry type tidak valid.');
        }

        $allowedProtocols = [
            'ANY',
            'TCP',
            'UDP',
            'ICMP',
        ];

        if (!in_array($protocol, $allowedProtocols, true)) {
            throw new RuntimeException('Protocol tidak valid.');
        }

        $normalized = submit_normalize_value(
            $entryType,
            $value
        );

        if ($normalized === '') {
            throw new RuntimeException(
                'Value traffic tidak boleh kosong.'
            );
        }

        if ($port < 0 || $port > 65535) {
            throw new RuntimeException(
                'Port harus berada antara 0-65535.'
            );
        }

        if ($confidence < 0) {
            $confidence = 0;
        }

        if ($confidence > 100) {
            $confidence = 100;
        }

        if ($name === '') {
            $name = $normalized;
        }

        if (strlen($name) > 150) {
            throw new RuntimeException(
                'Nama maksimal 150 karakter.'
            );
        }

        if (strlen($description) > 5000) {
            throw new RuntimeException(
                'Deskripsi maksimal 5000 karakter.'
            );
        }

        $dedupeKey = hash(
            'sha256',
            implode('|', [
                $categoryId,
                $entryType,
                $normalized,
                $port,
                $protocol,
            ])
        );

        /*
         * Cegah submission PENDING yang sama
         * dibuat berkali-kali oleh tenant yang sama.
         */
        $check = $db->prepare("
            SELECT id
            FROM traffic_catalog_submissions
            WHERE organization_id = ?
              AND dedupe_key = ?
              AND status = 'PENDING'
            LIMIT 1
        ");

        $check->execute([
            $organizationId,
            $dedupeKey,
        ]);

        $existingPending = $check->fetchColumn();

        if ($existingPending) {
            throw new RuntimeException(
                'Submission yang sama masih menunggu review.'
            );
        }

        $metadata = json_encode([
            'submitted_from' => 'BAJAMA',
            'submission_version' => 1,
        ], JSON_UNESCAPED_SLASHES);

        $stmt = $db->prepare("
            INSERT INTO traffic_catalog_submissions (
                category_id,
                name,
                entry_type,
                value,
                normalized_value,
                port,
                protocol,
                dedupe_key,
                organization_id,
                user_id,
                status,
                confidence,
                description,
                metadata,
                created_at,
                updated_at
            ) VALUES (
                :category_id,
                :name,
                :entry_type,
                :value,
                :normalized_value,
                :port,
                :protocol,
                :dedupe_key,
                :organization_id,
                :user_id,
                'PENDING',
                :confidence,
                :description,
                :metadata,
                NOW(),
                NOW()
            )
        ");

        $stmt->execute([
            ':category_id' => $categoryId,
            ':name' => $name,
            ':entry_type' => $entryType,
            ':value' => $value,
            ':normalized_value' => $normalized,
            ':port' => $port,
            ':protocol' => $protocol,
            ':dedupe_key' => $dedupeKey,
            ':organization_id' => $organizationId,
            ':user_id' => $userId,
            ':confidence' => $confidence,
            ':description' => $description !== ''
                ? $description
                : null,
            ':metadata' => $metadata,
        ]);

        $success = 'Traffic intelligence berhasil dikirim dan menunggu review.';
    } catch (Throwable $e) {
        error_log('BAJAMA traffic submit error: ' . $e->getMessage());
        $error = 'Traffic intelligence tidak dapat dikirim.';
    }
}

$stmt = $db->prepare("
    SELECT
        s.id,
        s.name,
        s.entry_type,
        s.value,
        s.port,
        s.protocol,
        s.status,
        s.confidence,
        s.description,
        s.created_at,
        c.name AS category_name
    FROM traffic_catalog_submissions s
    INNER JOIN traffic_categories c
        ON c.id = s.category_id
    WHERE s.organization_id = ?
    ORDER BY s.created_at DESC
    LIMIT 100
");

$stmt->execute([$organizationId]);

$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Submit Traffic - BAJAMA';

ob_start();

?>
<style>
body {
    background: #f5f7fb;
}
.hero {
    background: linear-gradient(135deg,#172033,#263957);
    color: #fff;
    border-radius: 22px;
}
.card {
    border: 0;
    border-radius: 18px;
}
.form-control,
.form-select {
    border-radius: 12px;
    min-height: 46px;
}
.btn {
    border-radius: 12px;
}
.table > :not(caption) > * > * {
    padding: .85rem .75rem;
}
.badge-status {
    font-size: .75rem;
}
</style>

<style>
/* BAJAMA CONTENT SAFETY */
.content-area {
    min-width: 0;
    max-width: 100%;
    overflow-x: hidden;
}

.content-area > .container-fluid {
    width: 100%;
    max-width: 100%;
    min-width: 0;
    box-sizing: border-box;
}
</style>

<div class="container-fluid px-3 px-md-4 py-4">

    <div class="hero p-4 p-md-5 mb-4 shadow-sm">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
            <div>
                <div class="text-uppercase small opacity-75 fw-semibold">
                    BAJAMA Traffic Intelligence
                </div>
                <h1 class="h3 fw-bold mt-2 mb-2">
                    Submit Traffic Intelligence
                </h1>
                <p class="mb-0 opacity-75">
                    Kirim domain, IP, CIDR, port, atau protocol
                    untuk ditinjau sebelum masuk Global Traffic Catalog.
                </p>
            </div>

            <div class="align-self-md-center">
                <a href="traffic_catalog.php"
                   class="btn btn-light">
                    <i class="bi bi-database me-1"></i>
                    Traffic Catalog
                </a>
            </div>
        </div>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?= h_submit($success) ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?= h_submit($error) ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <div class="col-12 col-xl-7">

            <div class="card shadow-sm">
                <div class="card-body p-4">

                    <h2 class="h5 fw-bold mb-1">
                        <i class="bi bi-send me-2"></i>
                        Kirim Intelligence
                    </h2>

                    <p class="text-secondary small mb-4">
                        Data akan berstatus <strong>PENDING</strong>
                        sampai disetujui reviewer.
                    </p>

                    <form method="post" autocomplete="off">

                        <input type="hidden"
                               name="csrf"
                               value="<?= h_submit($csrf) ?>">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Kategori
                            </label>

                            <select name="category_id"
                                    class="form-select"
                                    required>

                                <option value="">
                                    Pilih kategori...
                                </option>

                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= (int)$category['id'] ?>">
                                        <?= h_submit($category['name']) ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="row g-3">

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    Entry Type
                                </label>

                                <select name="entry_type"
                                        class="form-select"
                                        required>

                                    <option value="DOMAIN">DOMAIN</option>
                                    <option value="HOST">HOST</option>
                                    <option value="IP">IP</option>
                                    <option value="CIDR">CIDR</option>
                                    <option value="PORT">PORT</option>
                                    <option value="PROTOCOL">PROTOCOL</option>

                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    Protocol
                                </label>

                                <select name="protocol"
                                        class="form-select">

                                    <option value="ANY">ANY</option>
                                    <option value="TCP">TCP</option>
                                    <option value="UDP">UDP</option>
                                    <option value="ICMP">ICMP</option>

                                </select>
                            </div>

                        </div>

                        <div class="mb-3 mt-3">
                            <label class="form-label fw-semibold">
                                Nama Intelligence
                            </label>

                            <input type="text"
                                   name="name"
                                   class="form-control"
                                   maxlength="150"
                                   placeholder="Contoh: YouTube">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Value
                            </label>

                            <input type="text"
                                   name="value"
                                   class="form-control"
                                   maxlength="255"
                                   placeholder="contoh.com / 1.2.3.4 / 10.0.0.0/24"
                                   required>
                        </div>

                        <div class="row g-3">

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    Port
                                </label>

                                <input type="number"
                                       name="port"
                                       class="form-control"
                                       min="0"
                                       max="65535"
                                       value="0">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    Confidence
                                </label>

                                <div class="input-group">
                                    <input type="number"
                                           name="confidence"
                                           class="form-control"
                                           min="0"
                                           max="100"
                                           step="0.01"
                                           value="50">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>

                        </div>

                        <div class="mb-4 mt-3">
                            <label class="form-label fw-semibold">
                                Catatan
                            </label>

                            <textarea name="description"
                                      class="form-control"
                                      rows="4"
                                      maxlength="5000"
                                      placeholder="Jelaskan sumber atau alasan intelligence ini..."></textarea>
                        </div>

                        <button type="submit"
                                class="btn btn-primary btn-lg w-100">
                            <i class="bi bi-send-fill me-2"></i>
                            Kirim untuk Review
                        </button>

                    </form>

                </div>
            </div>

        </div>

        <div class="col-12 col-xl-5">

            <div class="card shadow-sm mb-4">
                <div class="card-body p-4">

                    <h2 class="h5 fw-bold">
                        <i class="bi bi-shield-check me-2"></i>
                        Alur Keamanan
                    </h2>

                    <div class="mt-3">

                        <div class="d-flex gap-3 mb-3">
                            <div class="fs-4">1️⃣</div>
                            <div>
                                <strong>Submit</strong>
                                <div class="small text-secondary">
                                    Customer mengirim intelligence.
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-3 mb-3">
                            <div class="fs-4">2️⃣</div>
                            <div>
                                <strong>Review</strong>
                                <div class="small text-secondary">
                                    Reviewer memeriksa data.
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-3">
                            <div class="fs-4">3️⃣</div>
                            <div>
                                <strong>Approve</strong>
                                <div class="small text-secondary">
                                    Data masuk Global Traffic Catalog.
                                </div>
                            </div>
                        </div>

                    </div>

                    <hr>

                    <div class="alert alert-warning small mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Submission ini <strong>tidak mengubah MikroTik</strong>.
                        Deployment router akan dilakukan pada tahap terpisah.
                    </div>

                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-body p-4">

                    <h2 class="h5 fw-bold mb-3">
                        Submission Saya
                    </h2>

                    <?php if (!$submissions): ?>

                        <div class="text-center text-secondary py-4">
                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                            Belum ada submission.
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">
                            <table class="table align-middle mb-0">

                                <thead>
                                <tr>
                                    <th>Traffic</th>
                                    <th>Status</th>
                                </tr>
                                </thead>

                                <tbody>

                                <?php foreach ($submissions as $row): ?>

                                    <?php
                                    $status = strtoupper(
                                        (string)$row['status']
                                    );

                                    $badge = 'secondary';

                                    if ($status === 'PENDING') {
                                        $badge = 'warning text-dark';
                                    } elseif ($status === 'APPROVED') {
                                        $badge = 'success';
                                    } elseif ($status === 'REJECTED') {
                                        $badge = 'danger';
                                    }
                                    ?>

                                    <tr>

                                        <td>
                                            <div class="fw-semibold">
                                                <?= h_submit($row['name']) ?>
                                            </div>

                                            <div class="small text-secondary">
                                                <?= h_submit($row['category_name']) ?>
                                                ·
                                                <?= h_submit($row['entry_type']) ?>
                                            </div>

                                            <code class="small">
                                                <?= h_submit($row['value']) ?>
                                            </code>
                                        </td>

                                        <td>
                                            <span class="badge bg-<?= $badge ?> badge-status">
                                                <?= h_submit($status) ?>
                                            </span>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>
                            </table>
                        </div>

                    <?php endif; ?>

                </div>
            </div>

        </div>

    </div>

</div>

<?php
$content = ob_get_clean();

require __DIR__ . '/../app/layout/layout.php';
