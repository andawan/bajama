<?php

declare(strict_types=1);

if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

require_once __DIR__ . '/../app/bootstrap.php';

\BAJAMA\Core\Auth::requireLogin();
\BAJAMA\Core\RBAC::require(db(), 'license.manage');

$db = db();
$currentRoles = \BAJAMA\Core\RBAC::roles($db);
if (!in_array('SUPER_ADMIN', $currentRoles, true)) {
    http_response_code(403);
    exit('Akses ditolak. Hanya superadmin yang dapat mengelola blog.');
}

function ensureBlogPostsSchema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS blog_posts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(180) NOT NULL,
            slug VARCHAR(180) NOT NULL UNIQUE,
            excerpt VARCHAR(255) NOT NULL DEFAULT '',
            content LONGTEXT NOT NULL,
            author_name VARCHAR(120) NOT NULL DEFAULT 'BAJAMA',
            status ENUM('DRAFT','PUBLISHED') NOT NULL DEFAULT 'DRAFT',
            published_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_blog_status_published (status, published_at)
        ) ENGINE=InnoDB"
    );

    $columns = [
        'excerpt' => "ALTER TABLE blog_posts ADD COLUMN excerpt VARCHAR(255) NOT NULL DEFAULT '' AFTER slug",
        'content' => "ALTER TABLE blog_posts ADD COLUMN content LONGTEXT NOT NULL AFTER excerpt",
        'author_name' => "ALTER TABLE blog_posts ADD COLUMN author_name VARCHAR(120) NOT NULL DEFAULT 'BAJAMA' AFTER content",
        'status' => "ALTER TABLE blog_posts ADD COLUMN status ENUM('DRAFT','PUBLISHED') NOT NULL DEFAULT 'DRAFT' AFTER author_name",
        'published_at' => "ALTER TABLE blog_posts ADD COLUMN published_at DATETIME NULL AFTER status",
        'created_at' => "ALTER TABLE blog_posts ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER published_at",
        'updated_at' => "ALTER TABLE blog_posts ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];

    foreach ($columns as $columnName => $alterSql) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `blog_posts` LIKE '{$columnName}'");
            if ($stmt && $stmt->fetch()) {
                continue;
            }
        } catch (Throwable $e) {
            // table may not exist yet; the CREATE TABLE above should handle that.
        }

        try {
            $db->exec($alterSql);
        } catch (Throwable $e) {
            error_log('BAJAMA blog schema sync warning: ' . $e->getMessage());
        }
    }
}

ensureBlogPostsSchema($db);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf((string)($_POST['_csrf'] ?? ''));
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_post') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $slug = trim((string)($_POST['slug'] ?? ''));
        $excerpt = trim((string)($_POST['excerpt'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $authorName = trim((string)($_POST['author_name'] ?? 'BAJAMA'));
        $status = in_array($_POST['status'] ?? 'DRAFT', ['DRAFT', 'PUBLISHED'], true) ? $_POST['status'] : 'DRAFT';

        if ($title === '' || $content === '') {
            $error = 'Judul dan konten artikel wajib diisi.';
        } else {
            $slug = $slug !== '' ? $slug : strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? $title);
            $slug = trim((string)$slug, '-');
            if ($slug === '') {
                $slug = 'artikel-' . time();
            }

            if ($id > 0) {
                $stmt = $db->prepare('UPDATE blog_posts SET title = ?, slug = ?, excerpt = ?, content = ?, author_name = ?, status = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$title, $slug, $excerpt, $content, $authorName, $status, $id]);
                $message = 'Artikel berhasil diperbarui.';
            } else {
                $stmt = $db->prepare('INSERT INTO blog_posts (title, slug, excerpt, content, author_name, status, published_at) VALUES (?, ?, ?, ?, ?, ?, ? )');
                $stmt->execute([$title, $slug, $excerpt, $content, $authorName, $status, $status === 'PUBLISHED' ? date('Y-m-d H:i:s') : null]);
                $message = 'Artikel baru berhasil dibuat.';
            }
        }
    }

    if ($action === 'delete_post') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare('DELETE FROM blog_posts WHERE id = ?')->execute([$id]);
            $message = 'Artikel berhasil dihapus.';
        }
    }
}

$editPost = null;
$editId = (int)($_GET['edit_id'] ?? 0);
if ($editId > 0) {
    $stmt = $db->prepare('SELECT * FROM blog_posts WHERE id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $editPost = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

try {
    $posts = $db->query('SELECT * FROM blog_posts ORDER BY published_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $posts = [];
}

$pageTitle = 'Kelola Blog';
ob_start();
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Kelola Blog Publik</h1>
            <p class="text-muted mb-0">Mengelola artikel untuk pelanggan lisensi, varian publik, dan halaman informasi utama.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-outline-dark" href="dashboard.php">Dashboard</a>
            <a class="btn btn-primary" href="blog.php" target="_blank">Lihat Blog Publik</a>
        </div>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white"><?= $editPost ? 'Edit Artikel' : 'Buat Artikel Baru' ?></div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="save_post">
                <?php if ($editPost): ?><input type="hidden" name="id" value="<?= (int)$editPost['id'] ?>"><?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Judul</label>
                        <input type="text" name="title" class="form-control" value="<?= htmlspecialchars((string)($editPost['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Slug</label>
                        <input type="text" name="slug" class="form-control" value="<?= htmlspecialchars((string)($editPost['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="DRAFT" <?= (($editPost['status'] ?? 'DRAFT') === 'DRAFT') ? 'selected' : '' ?>>DRAFT</option>
                            <option value="PUBLISHED" <?= (($editPost['status'] ?? 'DRAFT') === 'PUBLISHED') ? 'selected' : '' ?>>PUBLISHED</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Ringkasan</label>
                        <input type="text" name="excerpt" class="form-control" value="<?= htmlspecialchars((string)($editPost['excerpt'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Konten</label>
                        <textarea name="content" class="form-control" rows="8" required><?= htmlspecialchars((string)($editPost['content'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Penulis</label>
                        <input type="text" name="author_name" class="form-control" value="<?= htmlspecialchars((string)($editPost['author_name'] ?? 'BAJAMA'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary"><?= $editPost ? 'Update Artikel' : 'Simpan Artikel' ?></button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">Daftar Artikel</div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Judul</th>
                        <th>Status</th>
                        <th>Publikasi</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($posts as $post): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars((string)$post['title'], ENT_QUOTES, 'UTF-8') ?></div>
                                <small class="text-muted"><?= htmlspecialchars((string)$post['slug'], ENT_QUOTES, 'UTF-8') ?></small>
                            </td>
                            <td><?= htmlspecialchars((string)$post['status'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($post['published_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a class="btn btn-outline-primary" href="blog_admin.php?edit_id=<?= (int)$post['id'] ?>">Edit</a>
                                    <form method="post" onsubmit="return confirm('Hapus artikel ini?');" style="display:inline;">
                                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="delete_post">
                                        <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger">Hapus</button>
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
