<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\RBAC;

Auth::requireLogin();
$db = db();
RBAC::require($db, 'users.manage');

$editId = (int) ($_GET['edit_id'] ?? 0);
$editOrganization = null;
$message = '';
$error = '';

if ($editId > 0) {
    $stmt = $db->prepare('SELECT * FROM organizations WHERE id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $editOrganization = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf((string) ($_POST['_csrf'] ?? ''));
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_organization') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $status = strtoupper(trim((string) ($_POST['status'] ?? 'ACTIVE')));

            if ($name === '') {
                throw new RuntimeException('Nama organisasi wajib diisi.');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
              throw new RuntimeException('Format email organisasi tidak valid.');
            }
            if ($slug === '') {
                $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name));
                $slug = trim($slug, '-');
            }
            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                throw new RuntimeException('Slug organisasi hanya boleh berisi huruf kecil, angka, dan tanda hubung.');
            }
            if (!in_array($status, ['ACTIVE', 'SUSPENDED', 'CANCELLED'], true)) {
                throw new RuntimeException('Status organisasi tidak valid.');
            }

            $duplicate = $db->prepare('SELECT id, name, slug, email FROM organizations WHERE (name = ? OR slug = ? OR (email IS NOT NULL AND email = ?)) AND id <> ? LIMIT 1');
            $duplicate->execute([$name, $slug, $email !== '' ? $email : null, $id]);
            $duplicateOrganization = $duplicate->fetch(PDO::FETCH_ASSOC);
            if ($duplicateOrganization) {
              if ((string)$duplicateOrganization['slug'] === $slug) {
                throw new RuntimeException('Slug organisasi sudah digunakan.');
              }
              if (strcasecmp((string)$duplicateOrganization['name'], $name) === 0) {
                throw new RuntimeException('Nama organisasi sudah digunakan.');
              }
              throw new RuntimeException('Email organisasi sudah digunakan.');
            }

            if ($id > 0) {
                $stmt = $db->prepare('UPDATE organizations SET name=?, slug=?, email=?, phone=?, status=? WHERE id=?');
                $stmt->execute([$name, $slug, $email !== '' ? $email : null, $phone !== '' ? $phone : null, $status, $id]);
                $targetId = $id;
                $auditAction = 'ORGANIZATION_UPDATED';
            } else {
                $stmt = $db->prepare('INSERT INTO organizations (name, slug, email, phone, status) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$name, $slug, $email !== '' ? $email : null, $phone !== '' ? $phone : null, $status]);
                $targetId = (int) $db->lastInsertId();
                $auditAction = 'ORGANIZATION_CREATED';
            }

            \BAJAMA\Core\Audit::log($db, $auditAction, 'platform', 'organization', $targetId, ['name' => $name, 'slug' => $slug]);
            $message = 'Organisasi berhasil disimpan.';
        }

        if ($action === 'delete_organization') {
            $organizationId = (int) ($_POST['organization_id'] ?? 0);
            if ($organizationId <= 0) {
                throw new RuntimeException('Organisasi tidak valid.');
            }

          if ($organizationId === (int) \BAJAMA\Core\Tenant::id()) {
            throw new RuntimeException('Perusahaan yang sedang dipakai oleh akun ini tidak dapat dihapus.');
          }

          $db->beginTransaction();
          try {
            /*
             * Semua tabel tenant ditemukan dari metadata database agar
             * modul baru yang memiliki organization_id ikut terhapus.
             * Foreign key dimatikan hanya selama transaksi hard-delete.
             */
            $tableStmt = $db->query(
              "SELECT DISTINCT TABLE_NAME
               FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
                 AND COLUMN_NAME = 'organization_id'
                 AND TABLE_NAME <> 'organizations'"
            );
            $tables = $tableStmt->fetchAll(PDO::FETCH_COLUMN);
            $db->exec('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($tables as $table) {
              if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $table)) {
                continue;
              }
              $delete = $db->prepare('DELETE FROM `' . $table . '` WHERE organization_id = ?');
              $delete->execute([$organizationId]);
            }

            $deleteUsers = $db->prepare('DELETE FROM users WHERE organization_id = ?');
            $deleteUsers->execute([$organizationId]);
            $deleteOrganization = $db->prepare('DELETE FROM organizations WHERE id = ?');
            $deleteOrganization->execute([$organizationId]);
            if ($deleteOrganization->rowCount() < 1) {
              throw new RuntimeException('Perusahaan tidak ditemukan.');
            }

            $db->exec('SET FOREIGN_KEY_CHECKS = 1');
            $db->commit();
          } catch (Throwable $deleteError) {
            $db->exec('SET FOREIGN_KEY_CHECKS = 1');
            if ($db->inTransaction()) {
              $db->rollBack();
            }
            throw $deleteError;
          }
            $message = 'Perusahaan berhasil dihapus.';
        }
    } catch (Throwable $e) {
        error_log('BAJAMA organization error: ' . $e->getMessage());
        $error = $e->getMessage();
    }

}

$organizations = $db->query('SELECT o.*, (SELECT COUNT(*) FROM users u WHERE u.organization_id = o.id) AS user_count, (SELECT COUNT(*) FROM licenses l WHERE l.organization_id = o.id) AS license_count FROM organizations o ORDER BY o.name')->fetchAll(PDO::FETCH_ASSOC);

function org_h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$pageTitle = 'Organizations';
ob_start();
?>
<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h1>Organizations</h1>
      <p class="text-muted mb-0">Kelola kepemilikan perusahaan di BAJAMA. Data perusahaan hanya dapat dikelola oleh SUPER_ADMIN.</p>
    </div>
  </div>

  <?php if ($message): ?><div class="alert alert-success"><?= org_h($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= org_h($error) ?></div><?php endif; ?>

  <div class="card mb-4">
    <div class="card-body">
      <h2 class="h5 mb-3"><?= $editOrganization ? 'Setting Perusahaan' : 'Tambah Perusahaan' ?></h2>
      <form method="post" class="row g-3">
        <input type="hidden" name="_csrf" value="<?= org_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_organization">
        <?php if ($editOrganization): ?><input type="hidden" name="id" value="<?= (int) $editOrganization['id'] ?>"><?php endif; ?>
        <div class="col-md-3"><label class="form-label">Nama perusahaan</label><input class="form-control" name="name" value="<?= org_h($editOrganization['name'] ?? '') ?>" required></div>
        <div class="col-md-3"><label class="form-label">Slug</label><input class="form-control" name="slug" value="<?= org_h($editOrganization['slug'] ?? '') ?>" placeholder="otomatis dari nama"></div>
        <div class="col-md-2"><label class="form-label">Email</label><input class="form-control" type="email" name="email" value="<?= org_h($editOrganization['email'] ?? '') ?>"></div>
        <div class="col-md-2"><label class="form-label">Telepon</label><input class="form-control" name="phone" value="<?= org_h($editOrganization['phone'] ?? '') ?>"></div>
        <div class="col-md-2"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach (['ACTIVE','SUSPENDED','CANCELLED'] as $status): ?><option value="<?= $status ?>" <?= (($editOrganization['status'] ?? 'ACTIVE') === $status) ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><button class="btn btn-primary" type="submit"><?= $editOrganization ? 'Perbarui Perusahaan' : 'Simpan Perusahaan' ?></button></div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>Perusahaan</th><th>Slug</th><th>Email</th><th>Status</th><th>User</th><th>License</th><th>Dibuat</th><th class="text-end">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($organizations as $organization): ?>
          <tr>
            <td><?= org_h($organization['name']) ?></td>
            <td><code><?= org_h($organization['slug']) ?></code></td>
            <td><?= org_h($organization['email'] ?? '-') ?></td>
            <td><span class="badge <?= $organization['status'] === 'ACTIVE' ? 'text-bg-success' : 'text-bg-warning' ?>"><?= org_h($organization['status']) ?></span></td>
            <td><?= (int) $organization['user_count'] ?></td>
            <td><?= (int) $organization['license_count'] ?></td>
            <td><?= org_h($organization['created_at']) ?></td>
            <td class="text-end"><div class="btn-group btn-group-sm"><a class="btn btn-outline-primary" href="organizations.php?edit_id=<?= (int) $organization['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Hapus perusahaan ini?');" style="display:inline"><input type="hidden" name="_csrf" value="<?= org_h(csrf_token()) ?>"><input type="hidden" name="action" value="delete_organization"><input type="hidden" name="organization_id" value="<?= (int) $organization['id'] ?>"><button class="btn btn-outline-danger" type="submit">Hapus</button></form></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../app/layout/layout.php';
