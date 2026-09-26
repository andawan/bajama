<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\RBAC;

Auth::requireLogin();
$db = db();
$currentRoles = RBAC::roles($db);
if (!in_array('SUPER_ADMIN', $currentRoles, true)) {
    http_response_code(403);
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>403</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><div class="container py-5"><div class="card shadow-sm"><div class="card-body text-center p-5"><h1 class="display-5 fw-bold">403</h1><p class="text-muted">Halaman kontak hanya bisa diakses oleh superadmin.</p><a href="dashboard.php" class="btn btn-primary">Kembali</a></div></div></div></body></html>';
    exit;
}

function ensureSiteContactsTable(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS site_contacts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        label VARCHAR(80) NOT NULL DEFAULT 'ADMIN',
        display_name VARCHAR(150) NOT NULL DEFAULT 'BAJAMA Support',
        email VARCHAR(190) NULL,
        phone VARCHAR(50) NULL,
        whatsapp VARCHAR(50) NULL,
        address TEXT NULL,
        is_primary TINYINT(1) NOT NULL DEFAULT 1,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
}

ensureSiteContactsTable($db);
$db->exec("CREATE TABLE IF NOT EXISTS app_settings (setting_key VARCHAR(120) PRIMARY KEY, setting_value TEXT NULL, is_secret TINYINT(1) NOT NULL DEFAULT 0, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");

$message = '';
$error = '';
$selectedContact = null;
$contactIdParam = (int) ($_GET['contact_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf((string) ($_POST['_csrf'] ?? ''));
        $action = (string) ($_POST['action'] ?? 'save_contact');

        if ($action === 'save_contact') {
            $contactId = (int) ($_POST['contact_id'] ?? 0);
            $label = trim((string) ($_POST['label'] ?? 'ADMIN'));
            $displayName = trim((string) ($_POST['display_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $whatsapp = trim((string) ($_POST['whatsapp'] ?? ''));
            $address = trim((string) ($_POST['address'] ?? ''));
            $isPrimary = (isset($_POST['is_primary']) && $_POST['is_primary'] === '1') ? 1 : 0;
            $active = (isset($_POST['active']) && $_POST['active'] === '1') ? 1 : 0;

            if ($displayName === '') {
                throw new RuntimeException('Nama kontak wajib diisi.');
            }

            if ($contactId > 0) {
                $stmt = $db->prepare('UPDATE site_contacts SET label = ?, display_name = ?, email = ?, phone = ?, whatsapp = ?, address = ?, is_primary = ?, active = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$label !== '' ? $label : 'ADMIN', $displayName, $email !== '' ? $email : null, $phone !== '' ? $phone : null, $whatsapp !== '' ? $whatsapp : null, $address !== '' ? $address : null, $isPrimary, $active, $contactId]);
                $selectedContactId = $contactId;
            } else {
                $stmt = $db->prepare('INSERT INTO site_contacts (label, display_name, email, phone, whatsapp, address, is_primary, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$label !== '' ? $label : 'ADMIN', $displayName, $email !== '' ? $email : null, $phone !== '' ? $phone : null, $whatsapp !== '' ? $whatsapp : null, $address !== '' ? $address : null, $isPrimary, $active]);
                $selectedContactId = (int) $db->lastInsertId();
            }

            if ($isPrimary) {
                $db->prepare('UPDATE site_contacts SET is_primary = 0 WHERE id != ?')->execute([$selectedContactId]);
            }

            $message = 'Kontak berhasil disimpan.';
            header('Location: settings.php?contact_id=' . $selectedContactId);
            exit;
        }

        if ($action === 'save_mail') {
            $mail = [
                'mail_host' => trim((string)($_POST['mail_host'] ?? 'smtp.gmail.com')),
                'mail_port' => (string)max(1, (int)($_POST['mail_port'] ?? 587)),
                'mail_tls' => isset($_POST['mail_tls']) ? '1' : '0',
                'mail_username' => trim((string)($_POST['mail_username'] ?? '')),
                'mail_from_email' => trim((string)($_POST['mail_from_email'] ?? '')),
                'mail_from_name' => trim((string)($_POST['mail_from_name'] ?? 'BAJAMA')),
            ];
            $password = trim((string)($_POST['mail_password'] ?? ''));
            if ($password !== '') $mail['mail_password'] = encrypt_app_value($password);
            foreach ($mail as $key => $value) {
                $secret = $key === 'mail_password' ? 1 : 0;
                $db->prepare('INSERT INTO app_settings (setting_key, setting_value, is_secret) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = VALUES(is_secret)')->execute([$key, $value, $secret]);
            }
            $message = 'Pengaturan SMTP Gmail berhasil disimpan.';
        }

        if ($action === 'delete_contact') {
            $contactId = (int) ($_POST['contact_id'] ?? 0);
            if ($contactId > 0) {
                $db->prepare('DELETE FROM site_contacts WHERE id = ?')->execute([$contactId]);
                $message = 'Kontak berhasil dihapus.';
                header('Location: settings.php');
                exit;
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$contacts = $db->query('SELECT * FROM site_contacts ORDER BY is_primary DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
$mailSettings = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'mail_%' AND setting_key <> 'mail_password'")->fetchAll(PDO::FETCH_KEY_PAIR);
if ($contactIdParam > 0) {
    foreach ($contacts as $contact) {
        if ((int) $contact['id'] === $contactIdParam) {
            $selectedContact = $contact;
            break;
        }
    }
}
if ($selectedContact === null && !empty($contacts)) {
    $selectedContact = $contacts[0];
}

function settings_h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$pageTitle = 'Kontak & Branding';
ob_start();
?>
<div class="container-fluid py-4 premium-settings-shell">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <span class="section-kicker">BAJAMA ADMIN</span>
            <h1 class="fw-bold mb-1">Kontak & Branding</h1>
            <p class="text-muted mb-0">Kelola kontak admin, WhatsApp, dan informasi publik yang tampil di landing page BAJAMA.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="settings.php" class="btn btn-outline-secondary rounded-pill">Tambah kontak baru</a>
            <a href="dashboard.php" class="btn btn-dark rounded-pill">Kembali ke Dashboard</a>
        </div>
    </div>

    <?php if ($message): ?><div class="alert alert-success rounded-4"><?= settings_h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= settings_h($error) ?></div><?php endif; ?>

    <div class="card premium-card border-0 shadow-sm mb-4">
        <div class="card-header bg-primary text-white border-0 py-3 px-4"><span class="fw-semibold">SMTP Gmail untuk OTP registrasi & lupa sandi</span></div>
        <div class="card-body p-4">
            <form method="post" class="row g-3">
                <input type="hidden" name="_csrf" value="<?= settings_h(csrf_token()) ?>"><input type="hidden" name="action" value="save_mail">
                <div class="col-md-5"><label class="form-label">SMTP host</label><input class="form-control" name="mail_host" value="<?= settings_h($mailSettings['mail_host'] ?? 'smtp.gmail.com') ?>"></div>
                <div class="col-md-2"><label class="form-label">Port</label><input class="form-control" type="number" name="mail_port" value="<?= settings_h($mailSettings['mail_port'] ?? '587') ?>"></div>
                <div class="col-md-5"><label class="form-label">Username Gmail</label><input class="form-control" type="email" name="mail_username" value="<?= settings_h($mailSettings['mail_username'] ?? '') ?>"></div>
                <div class="col-md-5"><label class="form-label">App Password</label><input class="form-control" type="password" name="mail_password" placeholder="Kosongkan jika tidak diubah"><div class="form-text">Gunakan App Password Gmail, bukan password utama.</div></div>
                <div class="col-md-4"><label class="form-label">Email pengirim</label><input class="form-control" type="email" name="mail_from_email" value="<?= settings_h($mailSettings['mail_from_email'] ?? '') ?>"></div>
                <div class="col-md-3"><label class="form-label">Nama pengirim</label><input class="form-control" name="mail_from_name" value="<?= settings_h($mailSettings['mail_from_name'] ?? 'BAJAMA') ?>"></div>
                <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="mail_tls" value="1" id="mail_tls" <?= (($mailSettings['mail_tls'] ?? '1') !== '0') ? 'checked' : '' ?>><label class="form-check-label" for="mail_tls">Gunakan TLS STARTTLS</label></div></div>
                <div class="col-12"><button class="btn btn-primary rounded-pill">Simpan SMTP</button></div>
            </form>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card premium-card border-0 shadow-sm">
                <div class="card-header bg-dark text-white border-0 py-3 px-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-semibold">Form kontak BAJAMA</span>
                        <span class="badge bg-light text-dark rounded-pill">Edit</span>
                    </div>
                </div>
                <div class="card-body p-4">
                    <form method="post" class="row g-3 premium-form">
                        <input type="hidden" name="_csrf" value="<?= settings_h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_contact">
                        <input type="hidden" name="contact_id" value="<?= (int)($selectedContact['id'] ?? 0) ?>">

                        <div class="col-12">
                            <label class="form-label">Label</label>
                            <input class="form-control" name="label" value="<?= settings_h((string)($selectedContact['label'] ?? 'ADMIN')) ?>" placeholder="ADMIN / CS / Support">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Nama kontak</label>
                            <input class="form-control" name="display_name" value="<?= settings_h((string)($selectedContact['display_name'] ?? 'BAJAMA Support')) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input class="form-control" type="email" name="email" value="<?= settings_h((string)($selectedContact['email'] ?? '')) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Telepon</label>
                            <input class="form-control" name="phone" value="<?= settings_h((string)($selectedContact['phone'] ?? '')) ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label">WhatsApp</label>
                            <input class="form-control" name="whatsapp" value="<?= settings_h((string)($selectedContact['whatsapp'] ?? '')) ?>" placeholder="6281234567890">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Alamat</label>
                            <textarea class="form-control" name="address" rows="3" placeholder="Alamat kantor / cabang"><?= settings_h((string)($selectedContact['address'] ?? '')) ?></textarea>
                        </div>

                        <div class="col-6">
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="is_primary" value="1" id="is_primary" <?= !empty($selectedContact['is_primary']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_primary">Jadikan kontak utama</label>
                            </div>
                        </div>

                        <div class="col-6">
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="active" value="1" id="active" <?= !empty($selectedContact['active']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="active">Aktif ditampilkan</label>
                            </div>
                        </div>

                        <div class="col-12 d-flex gap-2 flex-wrap">
                            <button class="btn btn-primary rounded-pill px-4" type="submit">Simpan kontak</button>
                            <?php if (!empty($selectedContact['id'])): ?>
                                <a href="settings.php" class="btn btn-outline-secondary rounded-pill">Buat baru</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card premium-card border-0 shadow-sm">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center py-3 px-4">
                    <span class="fw-semibold">Daftar kontak</span>
                    <span class="badge bg-light text-dark rounded-pill"><?= count($contacts) ?> item</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($contacts)): ?>
                        <div class="p-4 text-muted">Belum ada kontak yang disimpan.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Label</th>
                                        <th>Kontak</th>
                                        <th>WhatsApp</th>
                                        <th>Status</th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($contacts as $item): ?>
                                        <tr class="<?= ((int)($selectedContact['id'] ?? 0) === (int)$item['id']) ? 'table-primary' : '' ?>">
                                            <td><?= settings_h((string)($item['label'] ?? 'ADMIN')) ?></td>
                                            <td>
                                                <div class="fw-semibold"><?= settings_h((string)($item['display_name'] ?? '-')) ?></div>
                                                <small class="text-muted"><?= settings_h((string)($item['email'] ?? '-')) ?></small>
                                            </td>
                                            <td><?= settings_h((string)($item['whatsapp'] ?? '-')) ?></td>
                                            <td>
                                                <?php if (!empty($item['active'])): ?>
                                                    <span class="badge bg-success">Aktif</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Nonaktif</span>
                                                <?php endif; ?>
                                                <?php if (!empty($item['is_primary'])): ?>
                                                    <span class="badge bg-primary-subtle text-primary">Utama</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <div class="btn-group btn-group-sm">
                                                    <a href="settings.php?contact_id=<?= (int)$item['id'] ?>" class="btn btn-outline-primary">Edit</a>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus kontak ini?');">
                                                        <input type="hidden" name="_csrf" value="<?= settings_h(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="delete_contact">
                                                        <input type="hidden" name="contact_id" value="<?= (int)$item['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-danger">Hapus</button>
                                                    </form>
                                                </div>
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

<style>
    .premium-settings-shell {
        background: linear-gradient(180deg, #f8fafc 0%, #eef4ff 100%);
    }
    .section-kicker {
        display: inline-block;
        font-size: 0.72rem;
        letter-spacing: 0.14em;
        font-weight: 700;
        color: #2563eb;
        text-transform: uppercase;
        margin-bottom: 10px;
    }
    .premium-card {
        border-radius: 24px;
        overflow: hidden;
        background: rgba(255,255,255,0.92);
    }
    .premium-form .form-control,
    .premium-form textarea {
        background: #f8fafc;
        border: 1px solid #dfeaf5;
        border-radius: 14px;
        min-height: 48px;
        box-shadow: none;
    }
    .premium-form .form-control:focus,
    .premium-form textarea:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 0.2rem rgba(37,99,235,0.12);
        background: #fff;
    }
    .premium-form .form-check-input:checked {
        background-color: #2563eb;
        border-color: #2563eb;
    }
</style>
<?php
$content = ob_get_clean();
require __DIR__ . '/../app/layout/layout.php';
