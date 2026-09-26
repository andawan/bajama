<?php
if (!defined('BAJAMA_LAYOUT')) {
    define('BAJAMA_LAYOUT', true);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pageTitle = $pageTitle ?? 'Dashboard';
$userName  = $_SESSION['username'] ?? 'User';
$appLocale = \BAJAMA\Core\I18n::locale();

/*
 * Role header diambil langsung dari RBAC/database.
 * Tidak mengubah session login.
 *
 * SUPER_ADMIN selalu diprioritaskan jika user memiliki
 * lebih dari satu role, misalnya SUPER_ADMIN + OWNER.
 */
$displayRole = 'OWNER';

try {
    $currentRoles = \BAJAMA\Core\RBAC::roles(\db());

    if (in_array('SUPER_ADMIN', $currentRoles, true)) {
        $displayRole = 'SUPERADMIN';
    } elseif (!empty($currentRoles)) {
        $displayRole = strtoupper(trim((string) $currentRoles[0]));
    }
} catch (\Throwable $e) {
    /*
     * Fallback hanya untuk tampilan.
     * Tidak mempengaruhi proses autentikasi.
     */
    $displayRole = strtoupper(
        trim((string)($_SESSION['role'] ?? 'OWNER'))
    );

    if ($displayRole === 'SUPER_ADMIN') {
        $displayRole = 'SUPERADMIN';
    }
}
?>

<!doctype html>
<html lang="<?= htmlspecialchars($appLocale, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?= htmlspecialchars($pageTitle) ?> - BAJAMA</title>

    <meta name="description"
          content="BAJAMA ISP Business & Network Operations Platform">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet">

    <link
        href="assets/css/bajama.css"
        rel="stylesheet">
</head>

<body>

<div class="bajama-wrapper">

    <!-- MOBILE OVERLAY -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- SIDEBAR -->
    <?php
    /*
     * Network pages menggunakan sidebar Network BAJAMA standar.
     * Halaman Business/Platform tetap menggunakan sidebar lama.
     */
    if (!empty($networkLayout)) {
        $networkEmbedded = false;
        $networkActive = $networkActive ?? '';
        require __DIR__ . '/../Views/network_sidebar.php';
    } else {
        require __DIR__ . '/sidebar.php';
    }
    ?>

    <main class="main-content<?= !empty($networkLayout) ? ' network-layout-main' : '' ?>">

        <!-- TOPBAR -->
        <?php require __DIR__ . '/header.php'; ?>

        <div class="content-area">
            <?= $content ?? '' ?>
        </div>

        <!-- FOOTER -->
        <?php require __DIR__ . '/footer.php'; ?>

    </main>

</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>

<script src="assets/js/bajama.js"></script>

</body>
</html>
