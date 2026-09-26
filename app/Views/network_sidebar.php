<?php
declare(strict_types=1);

/*
 * BAJAMA - Standard Network Sidebar
 *
 * Variable optional:
 * $networkActive
 *
 * Values:
 * dashboard
 * mikrotik
 * routers
 * isp
 * lan
 * routing
 * loadbalance
 * firewall
 * monitoring
 */

$networkActive = $networkActive ?? '';
$networkEmbedded = $networkEmbedded ?? false;

$networkMenu = [
    [
        'key'   => 'dashboard',
        'href'  => 'dashboard.php',
        'icon'  => 'bi-speedometer2',
        'label' => 'Dashboard',
    ],
    [
        'key'   => 'mikrotik',
        'href'  => 'mikrotik.php',
        'icon'  => 'bi-router',
        'label' => 'MikroTik',
    ],
    [
        'key'   => 'routers',
        'href'  => 'mikrotik_routers.php',
        'icon'  => 'bi-hdd-network',
        'label' => 'Router Management',
    ],
];
?>


<?php if ($networkEmbedded): ?>

<div class="mk-group bajama-network-embedded">

    <div class="mk-group-title">
        NETWORK
    </div>

    <?php foreach ($networkMenu as $item): ?>

        <?php
        $isActive = $networkActive === ($item['key'] ?? '');
        $isSoon   = !empty($item['soon']);
        ?>

        <a
            href="<?= h($item['href']) ?>"
            class="mk-menu mk-network-link <?= $isActive ? 'active' : '' ?> <?= $isSoon ? 'disabled' : '' ?>"
            <?= $isSoon ? 'aria-disabled="true"' : '' ?>>

            <i class="bi <?= h($item['icon']) ?>"></i>

            <span>
                <?= h($item['label']) ?>
            </span>

            <?php if ($isSoon): ?>
                <span
                    style="
                        margin-left:auto;
                        font-size:9px;
                        padding:3px 6px;
                        border-radius:20px;
                        background:rgba(255,255,255,.08);
                        color:#9ca3af;
                    ">
                    SOON
                </span>
            <?php endif; ?>

        </a>

    <?php endforeach; ?>

</div>

<?php return; ?>

<?php endif; ?>

<style>
.bajama-network-sidebar {
    width: 260px;
    background: #111827;
    border-right: 1px solid #273244;
    min-height: 100vh;
    position: fixed;
    inset: 0 auto 0 0;
    z-index: 1040;
    overflow-y: auto;
    transition: transform .25s ease;
}

.bajama-network-sidebar-brand {
    height: 68px;
    display: flex;
    align-items: center;
    padding: 0 20px;
    border-bottom: 1px solid #e5e7eb;
}

.bajama-network-sidebar-brand .brand-icon {
    width: 38px;
    height: 38px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    background: #111827;
    color: #fff;
    margin-right: 11px;
}

.bajama-network-sidebar-brand strong {
    font-size: 17px;
    color: #ffffff;
}

.bajama-network-sidebar-brand small {
    display: block;
    color: #6b7280;
    font-size: 11px;
    margin-top: 1px;
}

.bajama-network-nav {
    padding: 14px 12px 25px;
}

.bajama-network-section {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #9ca3af;
    padding: 12px 10px 7px;
}

.bajama-network-menu {
    display: flex;
    align-items: center;
    min-height: 44px;
    padding: 9px 11px;
    margin: 3px 0;
    border-radius: 9px;
    color: #d1d5db;
    text-decoration: none;
    font-size: 14px;
    transition: all .15s ease;
}

.bajama-network-menu:hover {
    background: #f3f4f6;
    color: #ffffff;
}

.bajama-network-menu.active {
    background: #2563eb;
    color: #ffffff;
}

.bajama-network-menu i {
    width: 22px;
    margin-right: 10px;
    font-size: 17px;
}

.bajama-network-menu .menu-soon {
    margin-left: auto;
    font-size: 9px;
    padding: 3px 6px;
    border-radius: 20px;
    background: #f3f4f6;
    color: #9ca3af;
}

.bajama-network-menu.disabled {
    cursor: default;
}

.bajama-network-menu.disabled:hover {
    background: transparent;
    color: #d1d5db;
}

.bajama-network-divider {
    height: 1px;
    background: #e5e7eb;
    margin: 12px 8px;
}

.bajama-network-mobile-button {
    display: none;
}

@media (max-width: 991.98px) {

    .bajama-network-sidebar {
        transform: translateX(-100%);
    }

    .bajama-network-sidebar.show {
        transform: translateX(0);
    }

    .bajama-network-mobile-button {
        display: inline-flex;
        position: fixed;
        top: 12px;
        left: 12px;
        z-index: 1050;
        width: 42px;
        height: 42px;
        align-items: center;
        justify-content: center;
        border: 1px solid #e5e7eb;
        border-radius: 9px;
        background: #111827;
        box-shadow: 0 4px 14px rgba(0,0,0,.08);
    }

    .bajama-network-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.35);
        z-index: 1035;
    }

    .bajama-network-overlay.show {
        display: block;
    }
}

@media (min-width: 992px) {
    .bajama-network-main-offset {
        margin-left: 260px;
    }
}
</style>

<button
    type="button"
    class="bajama-network-mobile-button"
    onclick="bajamaNetworkToggleSidebar()"
    aria-label="Buka menu Network">
    <i class="bi bi-list fs-5"></i>
</button>

<div
    class="bajama-network-overlay"
    id="bajamaNetworkOverlay"
    onclick="bajamaNetworkToggleSidebar()">
</div>

<aside class="bajama-network-sidebar" id="bajamaNetworkSidebar">

    <div class="bajama-network-sidebar-brand">
        <div class="brand-icon">
            <i class="bi bi-diagram-3-fill"></i>
        </div>

        <div>
            <strong>BAJAMA</strong>
            <small>Network Operations</small>
        </div>
    </div>

    <nav class="bajama-network-nav">

        <div class="bajama-network-section">
            Network
        </div>

        <?php foreach ($networkMenu as $item): ?>

            <?php
            $isSoon = !empty($item['soon']);
            $isActive = $networkActive === $item['key'];
            ?>

            <?php if ($isSoon): ?>

                <a
                    href="#"
                    class="bajama-network-menu disabled"
                    onclick="return false;"
                    aria-disabled="true">

                    <i class="bi <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>

                    <span>
                        <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
                    </span>

                    <span class="menu-soon">
                        Soon
                    </span>
                </a>

            <?php else: ?>

                <a
                    href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"
                    class="bajama-network-menu<?= $isActive ? ' active' : '' ?>">

                    <i class="bi <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>

                    <span>
                        <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </a>

            <?php endif; ?>

        <?php endforeach; ?>


    </nav>
</aside>

<script>
function bajamaNetworkToggleSidebar() {
    const sidebar = document.getElementById('bajamaNetworkSidebar');
    const overlay = document.getElementById('bajamaNetworkOverlay');

    if (!sidebar) {
        return;
    }

    sidebar.classList.toggle('show');

    if (overlay) {
        overlay.classList.toggle('show');
    }
}
</script>
