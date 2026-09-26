<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\RBAC;
use BAJAMA\Core\Tenant;
use BAJAMA\Traffic\TrafficCatalog;

Auth::requireLogin();

$db = db();

$organizationId = Tenant::id();
$userId = Auth::userId();

$canReview =
    RBAC::hasPermission($db, 'traffic_catalog.review') ||
    RBAC::hasPermission($db, 'traffic_catalog.manage');

if (!$canReview) {
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
                Anda tidak memiliki permission untuk melakukan review.
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

function h_review($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

$success = '';
$error = '';

/*
 * Data untuk form EDIT submission.
 */
$stmt = $db->query("
    SELECT id, name, slug
    FROM traffic_categories
    WHERE enabled = 1
    ORDER BY sort_order ASC, name ASC
");
$reviewCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$reviewEntryTypes = [
    'DOMAIN',
    'HOST',
    'IP',
    'CIDR',
    'PORT',
    'PROTOCOL'
];

$reviewProtocols = [
    'ANY',
    'TCP',
    'UDP',
    'ICMP'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        verify_csrf((string) ($_POST['csrf'] ?? $_POST['_csrf'] ?? ''));

        $submissionId = (int)($_POST['submission_id'] ?? 0);
        $action = strtoupper(
            trim((string)($_POST['action'] ?? ''))
        );

        if ($submissionId <= 0) {
            throw new RuntimeException(
                'Submission tidak valid.'
            );
        }

        if (!in_array(
            $action,
            ['APPROVE', 'REJECT', 'EDIT'],
            true
        )) {
            throw new RuntimeException(
                'Action review tidak valid.'
            );
        }

        /*
         * Tenant isolation:
         * reviewer hanya boleh memproses submission
         * dari organisasi tempat dia login.
         */
        $stmt = $db->prepare("
            SELECT
                s.*,
                c.name AS category_name,
                c.slug AS category_slug
            FROM traffic_catalog_submissions s
            INNER JOIN traffic_categories c
                ON c.id = s.category_id
            WHERE s.id = ?
              AND s.organization_id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $submissionId,
            $organizationId,
        ]);

        $submission = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$submission) {
            throw new RuntimeException(
                'Submission tidak ditemukan atau bukan milik organisasi Anda.'
            );
        }

        if (
            strtoupper((string)$submission['status'])
            !== 'PENDING'
        ) {
            throw new RuntimeException(
                'Submission ini sudah direview sebelumnya.'
            );
        }

        /*
         * EDIT / KOREKSI
         *
         * Hanya submission PENDING yang boleh dikoreksi.
         * Setelah dikoreksi status tetap PENDING.
         * Tidak ada perubahan ke Global Traffic Catalog.
         */
        if ($action === 'EDIT') {

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
            $confidence = (float)($_POST['confidence'] ?? 0);
            $description = trim(
                (string)($_POST['description'] ?? '')
            );

            $allowedEntryTypes = [
                'DOMAIN',
                'HOST',
                'IP',
                'CIDR',
                'PORT',
                'PROTOCOL',
            ];

            $allowedProtocols = [
                'ANY',
                'TCP',
                'UDP',
                'ICMP',
            ];

            if ($categoryId <= 0) {
                throw new RuntimeException(
                    'Kategori wajib dipilih.'
                );
            }

            if ($name === '') {
                throw new RuntimeException(
                    'Nama traffic wajib diisi.'
                );
            }

            if (!in_array(
                $entryType,
                $allowedEntryTypes,
                true
            )) {
                throw new RuntimeException(
                    'Entry type tidak valid.'
                );
            }

            if ($value === '') {
                throw new RuntimeException(
                    'Value traffic wajib diisi.'
                );
            }

            if (!in_array(
                $protocol,
                $allowedProtocols,
                true
            )) {
                throw new RuntimeException(
                    'Protocol tidak valid.'
                );
            }

            if ($port < 0 || $port > 65535) {
                throw new RuntimeException(
                    'Port harus berada antara 0 sampai 65535.'
                );
            }

            if ($confidence < 0 || $confidence > 100) {
                throw new RuntimeException(
                    'Confidence harus berada antara 0 sampai 100.'
                );
            }

            /*
             * Pastikan kategori aktif.
             */
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
                    'Kategori tidak ditemukan atau sedang disabled.'
                );
            }

            /*
             * Normalisasi value.
             *
             * Untuk DOMAIN/HOST:
             * - lowercase
             * - trim
             * - hapus protocol URL
             * - hapus trailing dot
             * - hapus path
             */
            $normalized = strtolower(trim($value));

            if (in_array(
                $entryType,
                ['DOMAIN', 'HOST'],
                true
            )) {
                $normalized = preg_replace(
                    '#^https?://#i',
                    '',
                    $normalized
                );

                $normalized = preg_replace(
                    '#/.*$#',
                    '',
                    $normalized
                );

                $normalized = rtrim(
                    $normalized,
                    '.'
                );
            }

            if ($normalized === '') {
                throw new RuntimeException(
                    'Value setelah normalisasi tidak valid.'
                );
            }

            /*
             * Dedupe key submission.
             *
             * category + type + normalized value +
             * port + protocol
             */
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
             * Cegah duplikasi PENDING pada organisasi yang sama.
             * Submission yang sudah APPROVED/REJECTED tidak disentuh.
             */
            $stmt = $db->prepare("
                SELECT id
                FROM traffic_catalog_submissions
                WHERE organization_id = ?
                  AND dedupe_key = ?
                  AND status = 'PENDING'
                  AND id <> ?
                LIMIT 1
            ");

            $stmt->execute([
                $organizationId,
                $dedupeKey,
                $submissionId,
            ]);

            if ($stmt->fetchColumn()) {
                throw new RuntimeException(
                    'Sudah ada submission PENDING dengan data traffic yang sama.'
                );
            }

            /*
             * Simpan koreksi.
             *
             * reviewed_by / reviewed_at dikosongkan karena
             * proses review belum selesai.
             */
            $stmt = $db->prepare("
                UPDATE traffic_catalog_submissions
                SET
                    category_id = ?,
                    name = ?,
                    entry_type = ?,
                    value = ?,
                    normalized_value = ?,
                    port = ?,
                    protocol = ?,
                    dedupe_key = ?,
                    confidence = ?,
                    description = ?,
                    reviewed_by = NULL,
                    reviewed_at = NULL,
                    updated_at = NOW()
                WHERE id = ?
                  AND organization_id = ?
                  AND status = 'PENDING'
            ");

            $stmt->execute([
                $categoryId,
                $name,
                $entryType,
                $value,
                $normalized,
                $port,
                $protocol,
                $dedupeKey,
                $confidence,
                $description !== ''
                    ? $description
                    : null,
                $submissionId,
                $organizationId,
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'Koreksi submission gagal disimpan.'
                );
            }

            $success =
                'Submission #' . $submissionId .
                ' berhasil dikoreksi dan tetap berstatus PENDING.';

        } elseif ($action === 'REJECT') {


            $stmt = $db->prepare("
                UPDATE traffic_catalog_submissions
                SET
                    status = 'REJECTED',
                    reviewed_by = ?,
                    reviewed_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
                  AND organization_id = ?
                  AND status = 'PENDING'
            ");

            $stmt->execute([
                $userId,
                $submissionId,
                $organizationId,
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'Submission gagal direject.'
                );
            }

            $success =
                'Submission #' . $submissionId .
                ' berhasil direject. Histori tetap tersimpan.';

        } else {

            /*
             * APPROVE
             *
             * Global Catalog + perubahan status submission
             * harus berada dalam SATU transaction.
             */
            $db->beginTransaction();

            try {

                /*
                 * Lock submission sebelum approve.
                 * Ini mencegah dua request approve bersamaan.
                 */
                $stmt = $db->prepare("
                    SELECT *
                    FROM traffic_catalog_submissions
                    WHERE id = ?
                      AND organization_id = ?
                      AND status = 'PENDING'
                    FOR UPDATE
                ");

                $stmt->execute([
                    $submissionId,
                    $organizationId,
                ]);

                $lockedSubmission =
                    $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$lockedSubmission) {
                    throw new RuntimeException(
                        'Submission sudah berubah atau sudah direview.'
                    );
                }

                $catalog = new TrafficCatalog($db);

                $result = $catalog->upsert([
                'category_id' => (int)$lockedSubmission['category_id'],
                'name' => (string)$lockedSubmission['name'],
                'entry_type' => (string)$lockedSubmission['entry_type'],
                'value' => (string)$lockedSubmission['value'],
                'port' => (int)$lockedSubmission['port'],
                'protocol' => (string)$lockedSubmission['protocol'],
                'source_type' => 'CUSTOMER',
                'source_organization_id' => $organizationId,
                'source_user_id' => (int)$lockedSubmission['user_id'],
                'confidence' => (float)$lockedSubmission['confidence'],
                'description' => (string)$lockedSubmission['description'],
                'metadata' => (string)$lockedSubmission['metadata'],
            ]);

                $stmt = $db->prepare("
                    UPDATE traffic_catalog_submissions
                    SET
                        status = 'APPROVED',
                        reviewed_by = ?,
                        reviewed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                      AND organization_id = ?
                      AND status = 'PENDING'
                ");

                $stmt->execute([
                    $userId,
                    $submissionId,
                    $organizationId,
                ]);

                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Status submission gagal diperbarui.'
                    );
                }

                $db->commit();

            } catch (Throwable $e) {

                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                throw $e;
            }

            $success =
                'Submission #' . $submissionId .
                ' berhasil APPROVE → Global Catalog ' .
                strtoupper((string)$result['action']) .
                ' ID #' . (int)$result['id'] . '.';
        }

    } catch (Throwable $e) {
        error_log('BAJAMA traffic review error: ' . $e->getMessage());
        $error = 'Review traffic intelligence gagal.';
    }
}

$statusFilter = strtoupper(
    trim((string)($_GET['status'] ?? 'PENDING'))
);

$allowedStatuses = [
    'PENDING',
    'APPROVED',
    'REJECTED',
    'ALL',
];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'PENDING';
}

$params = [$organizationId];

$sql = "
    SELECT
        s.*,
        c.name AS category_name,
        c.slug AS category_slug
    FROM traffic_catalog_submissions s
    INNER JOIN traffic_categories c
        ON c.id = s.category_id
    WHERE s.organization_id = ?
";

if ($statusFilter !== 'ALL') {
    $sql .= " AND s.status = ? ";
    $params[] = $statusFilter;
}

$sql .= "
    ORDER BY
        CASE
            WHEN s.status = 'PENDING' THEN 0
            ELSE 1
        END,
        s.created_at DESC
    LIMIT 300
";

$stmt = $db->prepare($sql);
$stmt->execute($params);

$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$counts = [];

$stmt = $db->prepare("
    SELECT status, COUNT(*) AS total
    FROM traffic_catalog_submissions
    WHERE organization_id = ?
    GROUP BY status
");

$stmt->execute([$organizationId]);

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $counts[strtoupper((string)$row['status'])] =
        (int)$row['total'];
}

$pendingCount = $counts['PENDING'] ?? 0;
$approvedCount = $counts['APPROVED'] ?? 0;
$rejectedCount = $counts['REJECTED'] ?? 0;

$pageTitle = 'Traffic Review - BAJAMA';

ob_start();

?>
<style>
body {
    background: #f5f7fb;
}
.hero {
    background: linear-gradient(135deg,#111827,#263957);
    color: white;
    border-radius: 22px;
}
.card {
    border: 0;
    border-radius: 18px;
}
.stat {
    border-radius: 16px;
}
.table > :not(caption) > * > * {
    padding: .85rem .75rem;
}
.btn {
    border-radius: 11px;
}

    .edit-modal .modal-content {
        border: 0;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 20px 60px rgba(0,0,0,.18);
    }

    .edit-modal .modal-header {
        background: linear-gradient(135deg,#0d6efd,#6610f2);
        color: #fff;
        border: 0;
    }

    .edit-modal .form-label {
        font-weight: 600;
        font-size: .9rem;
    }

    .edit-modal .form-control,
    .edit-modal .form-select {
        border-radius: 10px;
    }


    /* ==================================================
       MOBILE RESPONSIVE - TRAFFIC REVIEW MODAL
       ================================================== */

    @media (max-width: 767.98px) {

        #editTrafficModal .modal-dialog {
            margin: 0;
            width: 100%;
            max-width: 100%;
            height: 100%;
        }

        #editTrafficModal .modal-content {
            height: 100dvh;
            max-height: 100dvh;
            border-radius: 0;
            display: flex;
            flex-direction: column;
        }

        #editTrafficModal .modal-header {
            flex: 0 0 auto;
            padding: 1rem;
        }

        #editTrafficModal .modal-body {
            flex: 1 1 auto;
            overflow-y: auto;
            overflow-x: hidden;
            min-height: 0;
            padding: 1rem;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior: contain;
        }

        #editTrafficModal .modal-footer {
            flex: 0 0 auto;
            padding: .75rem 1rem;
            background: #fff;
            border-top: 1px solid rgba(0,0,0,.08);
            position: sticky;
            bottom: 0;
            z-index: 5;
        }

        #editTrafficModal .modal-footer .btn {
            min-height: 44px;
        }

        #editTrafficModal .form-control,
        #editTrafficModal .form-select {
            min-height: 44px;
        }

        #editTrafficModal textarea.form-control {
            min-height: 100px;
        }

        #editTrafficModal .modal-title {
            font-size: 1rem;
        }

        #editTrafficModal .row {
            --bs-gutter-y: .9rem;
        }

    }

    /* HP sangat kecil */
    @media (max-width: 575.98px) {

        #editTrafficModal .modal-header {
            padding: .85rem 1rem;
        }

        #editTrafficModal .modal-body {
            padding: .9rem;
        }

        #editTrafficModal .modal-footer {
            padding: .65rem .9rem;
        }

        #editTrafficModal .modal-footer {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .5rem;
        }

        #editTrafficModal .modal-footer .btn {
            width: 100%;
            margin: 0 !important;
        }

    }

</style>
</head>

<body>

<div class="container-fluid px-3 px-md-4 py-4">

    <div class="hero p-4 p-md-5 mb-4 shadow-sm">

        <div class="d-flex flex-column flex-md-row
                    justify-content-between gap-3">

            <div>
                <div class="small text-uppercase opacity-75 fw-semibold">
                    BAJAMA Traffic Intelligence
                </div>

                <h1 class="h3 fw-bold mt-2 mb-2">
                    Traffic Review Center
                </h1>

                <p class="mb-0 opacity-75">
                    Review submission sebelum menjadi bagian
                    dari Global Traffic Catalog.
                </p>
            </div>

            <div class="align-self-md-center">

                <a href="traffic_catalog.php"
                   class="btn btn-light me-1">
                    <i class="bi bi-database me-1"></i>
                    Catalog
                </a>

                <a href="traffic_submit.php"
                   class="btn btn-outline-light">
                    <i class="bi bi-send me-1"></i>
                    Submit
                </a>

            </div>

        </div>

    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?= h_review($success) ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?= h_review($error) ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">

        <div class="col-6 col-lg-3">
            <div class="card stat shadow-sm">
                <div class="card-body">
                    <div class="text-secondary small">
                        Pending
                    </div>
                    <div class="fs-3 fw-bold text-warning">
                        <?= $pendingCount ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="card stat shadow-sm">
                <div class="card-body">
                    <div class="text-secondary small">
                        Approved
                    </div>
                    <div class="fs-3 fw-bold text-success">
                        <?= $approvedCount ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="card stat shadow-sm">
                <div class="card-body">
                    <div class="text-secondary small">
                        Rejected
                    </div>
                    <div class="fs-3 fw-bold text-danger">
                        <?= $rejectedCount ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="card stat shadow-sm">
                <div class="card-body">
                    <div class="text-secondary small">
                        Total
                    </div>
                    <div class="fs-3 fw-bold">
                        <?= $pendingCount + $approvedCount + $rejectedCount ?>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <div class="card shadow-sm">

        <div class="card-body p-3 p-md-4">

            <div class="d-flex flex-column flex-md-row
                        justify-content-between gap-3 mb-4">

                <div>
                    <h2 class="h5 fw-bold mb-1">
                        Submission
                    </h2>

                    <div class="text-secondary small">
                        Organisasi aktif:
                        #<?= (int)$organizationId ?>
                    </div>
                </div>

                <div class="btn-group">

                    <?php foreach (
                        ['PENDING', 'APPROVED', 'REJECTED', 'ALL']
                        as $filter
                    ): ?>

                        <a href="?status=<?= $filter ?>"
                           class="btn btn-sm
                           <?= $statusFilter === $filter
                               ? 'btn-primary'
                               : 'btn-outline-secondary' ?>">
                            <?= $filter ?>
                        </a>

                    <?php endforeach; ?>

                </div>

            </div>

            <?php if (!$submissions): ?>

                <div class="text-center text-secondary py-5">
                    <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                    Tidak ada submission pada filter ini.
                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table align-middle">

                        <thead>
                        <tr>
                            <th>Traffic</th>
                            <th>Type</th>
                            <th>Confidence</th>
                            <th>Status</th>
                            <th>Tanggal</th>
                            <th class="text-end">Action</th>
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
                                        <?= h_review($row['name']) ?>
                                    </div>

                                    <div class="small text-secondary">
                                        <?= h_review($row['category_name']) ?>
                                    </div>

                                    <code>
                                        <?= h_review($row['value']) ?>
                                    </code>

                                    <?php if ((int)$row['port'] > 0): ?>
                                        <span class="small text-secondary">
                                            :<?= (int)$row['port'] ?>
                                        </span>
                                    <?php endif; ?>

                                </td>

                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <?= h_review($row['entry_type']) ?>
                                    </span>

                                    <div class="small text-secondary mt-1">
                                        <?= h_review($row['protocol']) ?>
                                    </div>
                                </td>

                                <td>
                                    <?= number_format(
                                        (float)$row['confidence'],
                                        2
                                    ) ?>%
                                </td>

                                <td>
                                    <span class="badge bg-<?= $badge ?>">
                                        <?= h_review($status) ?>
                                    </span>
                                </td>

                                <td class="small text-secondary">
                                    <?= h_review($row['created_at']) ?>
                                </td>

                                <td class="text-end">

                                    <?php if ($status === 'PENDING'): ?>

                                        <div class="d-flex
                                                    justify-content-end
                                                    gap-1 flex-wrap">

                                            <button type="button"
                                                    class="btn btn-sm btn-outline-primary btn-edit-submission"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editTrafficModal"
                                                    data-submission-id="<?= (int)$row['id'] ?>"
                                                    data-category-id="<?= (int)$row['category_id'] ?>"
                                                    data-name="<?= h_review($row['name']) ?>"
                                                    data-entry-type="<?= h_review($row['entry_type']) ?>"
                                                    data-value="<?= h_review($row['value']) ?>"
                                                    data-port="<?= (int)$row['port'] ?>"
                                                    data-protocol="<?= h_review($row['protocol']) ?>"
                                                    data-confidence="<?= h_review($row['confidence']) ?>"
                                                    data-description="<?= h_review($row['description'] ?? '') ?>">
                                                <i class="bi bi-pencil-square"></i>
                                                Edit
                                            </button>

                                            <form method="post"
                                                  onsubmit="return confirm('Approve submission ini ke Global Traffic Catalog?');">

                                                <input type="hidden"
                                                       name="csrf"
                                                       value="<?= h_review($csrf) ?>">

                                                <input type="hidden"
                                                       name="submission_id"
                                                       value="<?= (int)$row['id'] ?>">

                                                <input type="hidden"
                                                       name="action"
                                                       value="APPROVE">

                                                <button class="btn btn-sm btn-success">
                                                    <i class="bi bi-check-lg"></i>
                                                    Approve
                                                </button>

                                            </form>

                                            <form method="post"
                                                  onsubmit="return confirm('Reject submission ini?');">

                                                <input type="hidden"
                                                       name="csrf"
                                                       value="<?= h_review($csrf) ?>">

                                                <input type="hidden"
                                                       name="submission_id"
                                                       value="<?= (int)$row['id'] ?>">

                                                <input type="hidden"
                                                       name="action"
                                                       value="REJECT">

                                                <button class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-x-lg"></i>
                                                    Reject
                                                </button>

                                            </form>

                                        </div>

                                    <?php else: ?>

                                        <span class="text-secondary small">
                                            Sudah direview
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>

    </div>

    <div class="alert alert-warning mt-4 small">
        <i class="bi bi-shield-check me-1"></i>
        Approve hanya memasukkan intelligence ke Global Traffic Catalog.
        <strong>Tidak ada konfigurasi MikroTik yang diubah pada tahap ini.</strong>
    </div>

</div>


    <!-- EDIT TRAFFIC SUBMISSION MODAL -->
    <div class="modal fade edit-modal"
         id="editTrafficModal"
         tabindex="-1"
         aria-labelledby="editTrafficModalLabel"
         aria-hidden="true">

        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">

            <div class="modal-content">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-1" id="editTrafficModalLabel">
                            <i class="bi bi-pencil-square me-2"></i>
                            Koreksi Traffic Submission
                        </h5>
                        <div class="small opacity-75">
                            Perubahan akan tetap berstatus PENDING sampai disetujui.
                        </div>
                    </div>

                    <button type="button"
                            class="btn-close btn-close-white"
                            data-bs-dismiss="modal"
                            aria-label="Close"></button>
                </div>

                <form method="post">

                    <div class="modal-body">

                        <input type="hidden"
                               name="csrf"
                               value="<?= h_review($csrf) ?>">

                        <input type="hidden"
                               name="submission_id"
                               id="edit_submission_id">

                        <input type="hidden"
                               name="action"
                               value="EDIT">

                        <div class="row g-3">

                            <div class="col-md-8">
                                <label class="form-label">
                                    Nama Traffic
                                </label>

                                <input type="text"
                                       class="form-control"
                                       name="name"
                                       id="edit_name"
                                       maxlength="150"
                                       required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">
                                    Confidence
                                </label>

                                <input type="number"
                                       class="form-control"
                                       name="confidence"
                                       id="edit_confidence"
                                       min="0"
                                       max="100"
                                       step="0.01"
                                       required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">
                                    Category
                                </label>

                                <select class="form-select"
                                        name="category_id"
                                        id="edit_category_id"
                                        required>

                                    <?php foreach ($reviewCategories as $category): ?>

                                        <option value="<?= (int)$category['id'] ?>">
                                            <?= h_review($category['name']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">
                                    Entry Type
                                </label>

                                <select class="form-select"
                                        name="entry_type"
                                        id="edit_entry_type"
                                        required>

                                    <?php foreach ($reviewEntryTypes as $type): ?>

                                        <option value="<?= h_review($type) ?>">
                                            <?= h_review($type) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">
                                    Protocol
                                </label>

                                <select class="form-select"
                                        name="protocol"
                                        id="edit_protocol"
                                        required>

                                    <?php foreach ($reviewProtocols as $protocol): ?>

                                        <option value="<?= h_review($protocol) ?>">
                                            <?= h_review($protocol) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>
                            </div>

                            <div class="col-md-9">
                                <label class="form-label">
                                    Value
                                </label>

                                <input type="text"
                                       class="form-control"
                                       name="value"
                                       id="edit_value"
                                       maxlength="255"
                                       required>

                                <div class="form-text">
                                    Contoh: youtube.com, 10.0.0.0/24, 8.8.8.8
                                </div>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">
                                    Port
                                </label>

                                <input type="number"
                                       class="form-control"
                                       name="port"
                                       id="edit_port"
                                       min="0"
                                       max="65535"
                                       value="0"
                                       required>
                            </div>

                            <div class="col-12">
                                <label class="form-label">
                                    Description
                                </label>

                                <textarea class="form-control"
                                          name="description"
                                          id="edit_description"
                                          rows="3"
                                          maxlength="5000"></textarea>
                            </div>

                        </div>

                    </div>

                    <div class="modal-footer">

                        <button type="button"
                                class="btn btn-light"
                                data-bs-dismiss="modal">
                            Batal
                        </button>

                        <button type="submit"
                                class="btn btn-primary">
                            <i class="bi bi-save me-1"></i>
                            Simpan Koreksi
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>



<script>
document.addEventListener('DOMContentLoaded', function () {

    document.querySelectorAll('.btn-edit-submission').forEach(function (button) {

        button.addEventListener('click', function () {

            document.getElementById('edit_submission_id').value =
                this.dataset.submissionId || '';

            document.getElementById('edit_category_id').value =
                this.dataset.categoryId || '';

            document.getElementById('edit_name').value =
                this.dataset.name || '';

            document.getElementById('edit_entry_type').value =
                this.dataset.entryType || 'DOMAIN';

            document.getElementById('edit_value').value =
                this.dataset.value || '';

            document.getElementById('edit_port').value =
                this.dataset.port || '0';

            document.getElementById('edit_protocol').value =
                this.dataset.protocol || 'ANY';

            document.getElementById('edit_confidence').value =
                this.dataset.confidence || '50';

            document.getElementById('edit_description').value =
                this.dataset.description || '';

        });

    });

});
</script>

<?php
$content = ob_get_clean();

require __DIR__ . '/../app/layout/layout.php';
