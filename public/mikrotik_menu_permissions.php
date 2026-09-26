<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\License;
use BAJAMA\Core\RBAC;
use BAJAMA\Network\MikroTik;
use BAJAMA\Network\MikroTikMenuManager;
use BAJAMA\Network\MikroTikMenuPermissionService;

Auth::requireLogin();

$db = db();

$organizationId = (int) Auth::organizationId();
$userId         = (int) Auth::userId();

if ($organizationId <= 0 || $userId <= 0) {
    http_response_code(403);
    exit('Organization atau user tidak valid.');
}

if (!RBAC::hasPermission($db, 'mikrotik.view')) {
    http_response_code(403);
    exit('Akses MikroTik ditolak.');
}

if (!License::hasFeature($db, 'mikrotik')) {
    http_response_code(403);
    exit('Fitur MikroTik tidak aktif.');
}

$permissionService = new MikroTikMenuPermissionService($db);

$routerId = isset($_GET['router_id'])
    ? (int) $_GET['router_id']
    : 0;

$targetUserId = isset($_GET['user_id'])
    ? (int) $_GET['user_id']
    : $userId;

/*
 * Router list hanya milik organization aktif.
 */
$routers = MikroTik::all($db, $organizationId);

/*
 * User list hanya dari organization yang sama.
 */
$stmt = $db->prepare(
    "SELECT
        u.id,
        u.username,
        u.full_name AS name,
        u.status,
        COALESCE(
            GROUP_CONCAT(
                DISTINCT r.name
                ORDER BY r.name
                SEPARATOR ', '
            ),
            ''
        ) AS roles
     FROM users u
     LEFT JOIN user_roles ur
        ON ur.user_id = u.id
     LEFT JOIN roles r
        ON r.id = ur.role_id
     WHERE u.organization_id = ?
     GROUP BY
        u.id,
        u.username,
        u.full_name,
        u.status
     ORDER BY
        CASE WHEN u.id = ? THEN 0 ELSE 1 END,
        u.full_name,
        u.username"
);
$stmt->execute([
    $organizationId,
    $userId
]);

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
 * Jika router belum dipilih, gunakan router pertama.
 */
if ($routerId <= 0 && !empty($routers)) {
    $routerId = (int) $routers[0]['id'];
}

/*
 * Validasi router.
 */
$selectedRouter = null;

foreach ($routers as $router) {
    if ((int) $router['id'] === $routerId) {
        $selectedRouter = $router;
        break;
    }
}

if ($routerId > 0 && !$selectedRouter) {
    http_response_code(404);
    exit('Router tidak ditemukan pada organization ini.');
}

/*
 * Validasi target user.
 */
$selectedUser = null;

foreach ($users as $user) {
    if ((int) $user['id'] === $targetUserId) {
        $selectedUser = $user;
        break;
    }
}

if (!$selectedUser) {
    http_response_code(404);
    exit('User tidak ditemukan pada organization ini.');
}

$menuManager = null;
$menuTree = [];
$permissionRows = [];

if ($selectedRouter) {
    $menuManager = new MikroTikMenuManager(
        $db,
        $organizationId,
        $targetUserId,
        $routerId
    );

    $menuManager->validateContext();

    $menuTree = $menuManager->menuTree();
    $permissionRows = $menuManager->permissions();
}

/*
 * Helper untuk mendapatkan permission sebuah menu.
 */
$getPermission = static function (
    array $permissionRows,
    int $menuId
): array {
    return $permissionRows[$menuId] ?? [
        'enabled'      => 0,
        'can_view'     => 0,
        'can_create'   => 0,
        'can_edit'     => 0,
        'can_enable'   => 0,
        'can_disable'  => 0,
        'can_delete'   => 0
    ];
};

$pageTitle = 'MikroTik Menu & Permissions';

/*
 * Variabel yang dibutuhkan oleh app/layout/header.php.
 * Ikuti standar layout BAJAMA.
 */
$userName = $_SESSION['username'] ?? 'User';
$userRole = $_SESSION['role'] ?? 'OWNER';

/*
 * Gunakan layout utama BAJAMA.
 * Seluruh HTML halaman ditampung sebagai $content,
 * kemudian dirender oleh app/layout/layout.php.
 */
$networkLayout = true;
$networkActive = 'mikrotik';

ob_start();
?>

<div class="container-fluid py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">
                <i class="bi bi-shield-lock me-2"></i>
                MikroTik Menu & Permissions
            </h1>

            <div class="text-muted">
                Atur akses menu, submenu, dan action per user pada setiap MikroTik.
            </div>
        </div>

        <a href="mikrotik.php<?= $routerId > 0 ? '?router_id=' . $routerId : '' ?>"
           class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>
            Kembali ke MikroTik
        </a>
    </div>

    <?php if (!$routers): ?>

        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            Belum ada MikroTik yang terdaftar pada organization ini.
        </div>

    <?php else: ?>

        <div class="card shadow-sm mb-4">
            <div class="card-body">

                <form method="get" class="row g-3">

                    <div class="col-lg-6">
                        <label class="form-label fw-semibold">
                            MikroTik
                        </label>

                        <select
                            name="router_id"
                            class="form-select"
                            onchange="this.form.submit()"
                        >
                            <?php foreach ($routers as $router): ?>

                                <?php
                                $routerStatus = strtoupper(
                                    (string) ($router['status'] ?? 'UNKNOWN')
                                );

                                $routerHost = (string) (
                                    $router['host'] ?? ''
                                );

                                $routerLabel = (string) (
                                    $router['name'] ?? 'Router'
                                );

                                if ($routerHost !== '') {
                                    $routerLabel .= ' — ' . $routerHost;
                                }

                                $routerLabel .= ' — ' . $routerStatus;
                                ?>

                                <option
                                    value="<?= (int) $router['id'] ?>"
                                    <?= (int) $router['id'] === $routerId ? 'selected' : '' ?>
                                >
                                    <?= htmlspecialchars(
                                        $routerLabel,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </option>

                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-6">
                        <label class="form-label fw-semibold">
                            User
                        </label>

                        <select
                            name="user_id"
                            class="form-select"
                            onchange="this.form.submit()"
                        >
                            <?php foreach ($users as $user): ?>

                                <?php
                                $displayName = trim(
                                    (string) ($user['name'] ?? '')
                                );

                                $username = trim(
                                    (string) ($user['username'] ?? '')
                                );

                                $roles = trim(
                                    (string) ($user['roles'] ?? '')
                                );

                                if ($displayName === '') {
                                    $displayName = $username ?: 'User';
                                }

                                if ($username === '') {
                                    $username = 'unknown';
                                }

                                if ($roles === '') {
                                    $roles = 'TANPA ROLE';
                                }

                                $userLabel =
                                    $displayName
                                    . ' — @' . $username
                                    . ' — ' . $roles;
                                ?>

                                <option
                                    value="<?= (int) $user['id'] ?>"
                                    <?= (int) $user['id'] === $targetUserId ? 'selected' : '' ?>
                                >
                                    <?= htmlspecialchars(
                                        $userLabel,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </option>

                            <?php endforeach; ?>
                        </select>
                    </div>

                </form>

            </div>
        </div>

        <?php if ($selectedRouter): ?>

            <div class="alert alert-info d-flex align-items-start gap-2">
                <i class="bi bi-info-circle mt-1"></i>

                <div>
                    <strong>
                        Permission terpusat.
                    </strong>

                    <div class="small mt-1">
                        Pengaturan di bawah berlaku khusus untuk
                        <strong>
                            <?= htmlspecialchars(
                                (string) $selectedUser['name'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </strong>
                        pada MikroTik
                        <strong>
                            <?= htmlspecialchars(
                                (string) $selectedRouter['name'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </strong>.
                    </div>
                </div>
            </div>

            <form method="post" action="mikrotik_menu_permissions_save.php">

                <input
                    type="hidden"
                    name="router_id"
                    value="<?= $routerId ?>"
                >

                <input
                    type="hidden"
                    name="user_id"
                    value="<?= $targetUserId ?>"
                >

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= htmlspecialchars(
                        (string) csrf_token(),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <div class="card shadow-sm">

                    <div class="card-header">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">

                            <div>
                                <strong>
                                    Permission Matrix
                                </strong>

                                <div class="small text-muted">
                                    OFF pada menu akan otomatis mematikan seluruh action.
                                </div>
                            </div>

                            <div class="small text-muted">
                                <?= count($menuTree) ?> menu utama
                            </div>

                        </div>
                    </div>

                    <div class="table-responsive">

                        <table class="table table-hover align-middle mb-0">

                            <thead class="table-light">

                                <tr>
                                    <th style="min-width:280px;">
                                        Menu / Submenu
                                    </th>

                                    <th class="text-center">
                                        ON/OFF
                                    </th>

                                    <th class="text-center">
                                        View
                                    </th>

                                    <th class="text-center">
                                        Create
                                    </th>

                                    <th class="text-center">
                                        Edit
                                    </th>

                                    <th class="text-center">
                                        Enable
                                    </th>

                                    <th class="text-center">
                                        Disable
                                    </th>

                                    <th class="text-center">
                                        Delete
                                    </th>
                                </tr>

                            </thead>

                            <tbody>

                            <?php foreach ($menuTree as $menu): ?>

                                <?php
                                $menuId = (int) $menu['id'];
                                $permission = $getPermission(
                                    $permissionRows,
                                    $menuId
                                );
                                ?>

                                <tr class="table-primary">

                                    <td>
                                        <div class="fw-semibold">
                                            <?php if (!empty($menu['icon'])): ?>
                                                <i class="<?= htmlspecialchars(
                                                    (string) $menu['icon'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?> me-2"></i>
                                            <?php endif; ?>

                                            <?= htmlspecialchars(
                                                (string) $menu['label'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </div>

                                        <div class="small text-muted">
                                            <?= htmlspecialchars(
                                                (string) $menu['menu_key'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </div>
                                    </td>

                                    <?php
                                    $fields = [
                                        'enabled',
                                        'can_view',
                                        'can_create',
                                        'can_edit',
                                        'can_enable',
                                        'can_disable',
                                        'can_delete'
                                    ];
                                    ?>

                                    <?php foreach ($fields as $field): ?>

                                        <td class="text-center">

                                            <div class="form-check d-flex justify-content-center">

                                                <input
                                                    class="form-check-input permission-checkbox menu-toggle-<?= $menuId ?>"
                                                    type="checkbox"
                                                    name="permissions[<?= $menuId ?>][<?= $field ?>]"
                                                    value="1"
                                                    <?= !empty($permission[$field]) ? 'checked' : '' ?>
                                                    data-menu-id="<?= $menuId ?>"
                                                    data-field="<?= htmlspecialchars(
                                                        $field,
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>"
                                                >

                                            </div>

                                        </td>

                                    <?php endforeach; ?>

                                </tr>

                                <?php foreach (($menu['children'] ?? []) as $child): ?>

                                    <?php
                                    $childId = (int) $child['id'];

                                    $childPermission = $getPermission(
                                        $permissionRows,
                                        $childId
                                    );
                                    ?>

                                    <tr>

                                        <td>
                                            <div class="ps-4">

                                                <i class="bi bi-arrow-return-right me-2 text-muted"></i>

                                                <span class="fw-semibold">
                                                    <?= htmlspecialchars(
                                                        (string) $child['label'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>
                                                </span>

                                                <div class="small text-muted ms-4">
                                                    <?= htmlspecialchars(
                                                        (string) $child['menu_key'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>
                                                </div>

                                            </div>
                                        </td>

                                        <?php foreach ($fields as $field): ?>

                                            <td class="text-center">

                                                <div class="form-check d-flex justify-content-center">

                                                    <input
                                                        class="form-check-input permission-checkbox menu-toggle-<?= $childId ?>"
                                                        type="checkbox"
                                                        name="permissions[<?= $childId ?>][<?= $field ?>]"
                                                        value="1"
                                                        <?= !empty($childPermission[$field]) ? 'checked' : '' ?>
                                                        data-menu-id="<?= $childId ?>"
                                                        data-field="<?= htmlspecialchars(
                                                            $field,
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        ) ?>"
                                                    >

                                                </div>

                                            </td>

                                        <?php endforeach; ?>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                    <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">

                        <div class="small text-muted">
                            Perubahan belum disimpan sampai tombol Save ditekan.
                        </div>

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            <i class="bi bi-save me-1"></i>
                            Simpan Permission
                        </button>

                    </div>

                </div>

            </form>

        <?php endif; ?>

    <?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    function applyEnabledState(menuId) {

        const boxes = document.querySelectorAll(
            '.menu-toggle-' + menuId
        );

        let enabled = false;

        boxes.forEach(function (box) {
            if (box.dataset.field === 'enabled') {
                enabled = box.checked;
            }
        });

        boxes.forEach(function (box) {

            if (box.dataset.field === 'enabled') {
                return;
            }

            box.disabled = !enabled;

            if (!enabled) {
                box.checked = false;
            }

        });
    }

    document
        .querySelectorAll('.permission-checkbox[data-field="enabled"]')
        .forEach(function (box) {

            box.addEventListener('change', function () {
                applyEnabledState(
                    this.dataset.menuId
                );
            });

            applyEnabledState(
                box.dataset.menuId
            );
        });

});
</script>

<?php
$content = ob_get_clean();

require __DIR__ . '/../app/layout/layout.php';
