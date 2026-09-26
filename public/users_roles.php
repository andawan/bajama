<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

\BAJAMA\Core\Auth::requireLogin();
\BAJAMA\Core\RBAC::require(db(), 'users.manage');

$db = db();
$orgId = (int) \BAJAMA\Core\Tenant::id();
$currentRoles = \BAJAMA\Core\RBAC::roles($db);
$isSuperAdmin = in_array('SUPER_ADMIN', $currentRoles, true);
$message = '';
$error = '';
$editUserId = (int) ($_GET['edit_id'] ?? 0);
$editUser = null;

if ($editUserId > 0) {
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?' . (!$isSuperAdmin ? ' AND organization_id = ?' : ''));
    $params = [$editUserId];
    if (!$isSuperAdmin) {
        $params[] = $orgId;
    }
    $stmt->execute($params);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf((string) ($_POST['_csrf'] ?? ''));
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_user') {
            $id = (int) ($_POST['id'] ?? 0);
            $username = strtolower(trim((string) ($_POST['username'] ?? '')));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $status = (string) ($_POST['status'] ?? 'ACTIVE');
            $roleId = (int) ($_POST['role_id'] ?? 0);
            $password = (string) ($_POST['password'] ?? '');
            $targetOrgId = $isSuperAdmin ? (int) ($_POST['organization_id'] ?? $orgId) : $orgId;

            if ($username === '' || !in_array($status, ['ACTIVE', 'SUSPENDED', 'DISABLED'], true)) {
                throw new RuntimeException('Username dan status wajib valid.');
            }
            if ($targetOrgId <= 0) {
                throw new RuntimeException('Organisasi user wajib valid.');
            }
            if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,99}$/', $username)) {
              throw new RuntimeException('Username hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung.');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
              throw new RuntimeException('Format email user tidak valid.');
            }
            if ($id === 0 && strlen($password) < 10) {
                throw new RuntimeException('Password user baru minimal 10 karakter.');
            }

            $duplicate = $db->prepare('SELECT id, username, email FROM users WHERE (username = ? OR (email IS NOT NULL AND email = ?)) AND id <> ? LIMIT 1');
            $duplicate->execute([$username, $email !== '' ? $email : null, $id]);
            $duplicateUser = $duplicate->fetch(PDO::FETCH_ASSOC);
            if ($duplicateUser) {
              throw new RuntimeException((string)$duplicateUser['username'] === $username ? 'Username sudah digunakan.' : 'Email sudah digunakan.');
            }

            $db->beginTransaction();

            if ($id > 0) {
                $query = 'UPDATE users SET organization_id=?, username=?, email=?, full_name=?, status=?' . ($password !== '' ? ', password_hash=?' : '') . ' WHERE id=?' . (!$isSuperAdmin ? ' AND organization_id=?' : '');
                $params = [$targetOrgId, $username, $email !== '' ? $email : null, $fullName !== '' ? $fullName : null, $status];
                if ($password !== '') {
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                }
                $params[] = $id;
                if (!$isSuperAdmin) {
                    $params[] = $orgId;
                }
                $db->prepare($query)->execute($params);
                $targetId = $id;
            } else {
                $insert = $db->prepare('INSERT INTO users (organization_id, username, email, password_hash, full_name, status) VALUES (?, ?, ?, ?, ?, ?)');
                $insert->execute([
                    $targetOrgId,
                    $username,
                    $email !== '' ? $email : null,
                    password_hash($password, PASSWORD_DEFAULT),
                    $fullName !== '' ? $fullName : null,
                    $status,
                ]);
                $targetId = (int) $db->lastInsertId();
            }

            if ($roleId > 0) {
                $roleCheck = $db->prepare('SELECT id FROM roles WHERE id=?');
                $roleCheck->execute([$roleId]);
                if ($roleCheck->fetchColumn()) {
                    $deleteSql = 'DELETE ur FROM user_roles ur INNER JOIN users u ON u.id = ur.user_id WHERE ur.user_id = ?';
                    $deleteParams = [$targetId];
                    if (!$isSuperAdmin) {
                        $deleteSql .= ' AND u.organization_id = ?';
                        $deleteParams[] = $orgId;
                    }
                    $db->prepare($deleteSql)->execute($deleteParams);
                    $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$targetId, $roleId]);
                }
            }

            \BAJAMA\Core\Audit::log($db, $id > 0 ? 'USER_UPDATED' : 'USER_CREATED', 'admin', 'user', $targetId, ['organization_id' => $targetOrgId]);
            $db->commit();
            $message = 'User berhasil disimpan.';
        }

        if ($action === 'delete_user') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                throw new RuntimeException('User tidak valid.');
            }

            $db->beginTransaction();
            $deleteSql = 'DELETE FROM users WHERE id=?';
            $deleteParams = [$userId];
            if (!$isSuperAdmin) {
                $deleteSql .= ' AND organization_id=?';
                $deleteParams[] = $orgId;
            }
            $stmt = $db->prepare($deleteSql);
            $stmt->execute($deleteParams);
            if ($stmt->rowCount() <= 0) {
                throw new RuntimeException('User tidak dapat dihapus.');
            }
            $db->prepare('DELETE FROM user_roles WHERE user_id=?')->execute([$userId]);
            \BAJAMA\Core\Audit::log($db, 'USER_DELETED', 'admin', 'user', $userId, ['organization_id' => $orgId]);
            $db->commit();
            $message = 'User berhasil dihapus.';
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('BAJAMA users roles error: ' . $e->getMessage());
        $error = 'Perubahan user gagal disimpan.';
    }

    header('Location: users_roles.php');
    exit;
}

$orgs = $db->query('SELECT id, name FROM organizations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$filterOrgId = $isSuperAdmin ? (int) ($_GET['organization_id'] ?? 0) : $orgId;

$usersSql = 'SELECT u.id, u.username, u.email, u.full_name, u.status, u.last_login_at, o.name AS organization_name, GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ", ") AS roles FROM users u LEFT JOIN organizations o ON o.id = u.organization_id LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id WHERE 1 = 1';
$params = [];
if (!$isSuperAdmin) {
    $usersSql .= ' AND u.organization_id = ?';
    $params[] = $orgId;
} elseif ($filterOrgId > 0) {
    $usersSql .= ' AND u.organization_id = ?';
    $params[] = $filterOrgId;
}
$usersSql .= ' GROUP BY u.id ORDER BY u.organization_id, u.username';
$usersStmt = $db->prepare($usersSql);
$usersStmt->execute($params);
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

$roles = $db->query('SELECT id, name, description FROM roles ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

function ur_h($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

$pageTitle = 'Users & Roles';
ob_start();
?>
<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
    <div>
      <h1 class="fw-bold mb-1">User & Role</h1>
      <p class="text-muted mb-0">Kelola akses administrator secara terpisah per organisasi.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-primary" href="dashboard.php">Kembali ke Dashboard</a>
      <a class="btn btn-outline-dark" href="dashboard.php">Dashboard</a>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-success"><?= ur_h($message) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= ur_h($error) ?></div>
  <?php endif; ?>

  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white"><?= $editUser ? 'Edit User' : 'Tambah User' ?></div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="_csrf" value="<?= ur_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_user">
        <?php if ($editUser): ?>
          <input type="hidden" name="id" value="<?= (int) $editUser['id'] ?>">
        <?php endif; ?>

        <?php if ($isSuperAdmin): ?>
          <div class="col-md-3">
            <label class="form-label">Organisasi</label>
            <select class="form-select" name="organization_id" required>
              <option value="">Pilih organisasi</option>
              <?php foreach ($orgs as $org): ?>
                <option value="<?= (int) $org['id'] ?>" <?= (($editUser['organization_id'] ?? $orgId) == (int) $org['id']) ? 'selected' : '' ?>><?= ur_h($org['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>

        <div class="col-md-3">
          <label class="form-label">Username</label>
          <input class="form-control" name="username" value="<?= ur_h($editUser['username'] ?? '') ?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Nama lengkap</label>
          <input class="form-control" name="full_name" value="<?= ur_h($editUser['full_name'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Email</label>
          <input class="form-control" type="email" name="email" value="<?= ur_h($editUser['email'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Password</label>
          <input class="form-control" type="password" name="password" placeholder="Kosongkan bila tidak diubah" <?= $editUser ? '' : 'required minlength="10"' ?>>
        </div>
        <div class="col-md-3">
          <label class="form-label">Role</label>
          <select class="form-select" name="role_id">
            <option value="0">Tanpa role</option>
            <?php foreach ($roles as $role): ?>
              <?php
                $selectedRole = '';
                if ($editUser) {
                    $assignedRole = $db->prepare('SELECT role_id FROM user_roles WHERE user_id = ? LIMIT 1');
                    $assignedRole->execute([(int) $editUser['id']]);
                    $assignedRoleId = (int) $assignedRole->fetchColumn();
                    if ($assignedRoleId === (int) $role['id']) {
                        $selectedRole = 'selected';
                    }
                }
              ?>
              <option value="<?= (int) $role['id'] ?>" <?= $selectedRole ?>><?= ur_h($role['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select class="form-select" name="status">
            <?php foreach (['ACTIVE', 'SUSPENDED', 'DISABLED'] as $status): ?>
              <option value="<?= $status ?>" <?= (($editUser['status'] ?? 'ACTIVE') === $status) ? 'selected' : '' ?>><?= $status ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <button class="btn btn-primary w-100" type="submit"><?= $editUser ? 'Update User' : 'Simpan User' ?></button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($isSuperAdmin): ?>
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
          <div class="col-md-4">
            <label class="form-label">Filter organisasi</label>
            <select class="form-select" name="organization_id">
              <option value="0">Semua organisasi</option>
              <?php foreach ($orgs as $org): ?>
                <option value="<?= (int) $org['id'] ?>" <?= $filterOrgId === (int) $org['id'] ? 'selected' : '' ?>><?= ur_h($org['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <button class="btn btn-outline-primary w-100" type="submit">Filter</button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white">Daftar user & role</div>
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead>
          <tr>
            <?php if ($isSuperAdmin): ?>
              <th>Organisasi</th>
            <?php endif; ?>
            <th>Username</th>
            <th>Nama</th>
            <th>Email</th>
            <th>Role</th>
            <th>Status</th>
            <th>Login</th>
            <th class="text-end">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $user): ?>
            <tr>
              <?php if ($isSuperAdmin): ?>
                <td><?= ur_h($user['organization_name'] ?? '-') ?></td>
              <?php endif; ?>
              <td><?= ur_h($user['username']) ?></td>
              <td><?= ur_h($user['full_name'] ?? '-') ?></td>
              <td><?= ur_h($user['email'] ?? '-') ?></td>
              <td><?= ur_h($user['roles'] ?? '-') ?></td>
              <td>
                <span class="badge <?= $user['status'] === 'ACTIVE' ? 'text-bg-success' : 'text-bg-warning' ?>"><?= ur_h($user['status']) ?></span>
              </td>
              <td><?= ur_h($user['last_login_at'] ?? '-') ?></td>
              <td class="text-end">
                <div class="btn-group btn-group-sm">
                  <a href="users_roles.php?edit_id=<?= (int) $user['id'] ?>" class="btn btn-outline-primary">Edit</a>
                  <form method="post" onsubmit="return confirm('Hapus user ini?');" style="display:inline;">
                    <input type="hidden" name="_csrf" value="<?= ur_h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                    <button class="btn btn-outline-danger" type="submit">Hapus</button>
                  </form>
                </div>
              </td>
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
