<?php
declare(strict_types=1);

/*
 * BAJAMA - Dedicated MikroTik Sidebar
 *
 * Sidebar ini KHUSUS halaman MikroTik.
 * Tidak menggantikan sidebar utama BAJAMA.
 *
 * Variable:
 * $mikrotikActive
 * $mikrotikRouterId
 * $mikrotikMenuGuard
 */

$mikrotikActive   = $mikrotikActive ?? '';
$mikrotikRouterId = (int)($mikrotikRouterId ?? 0);
$mikrotikMenuGuard = $mikrotikMenuGuard ?? null;

$mkUrl = static function (string $key) use ($mikrotikRouterId): string {
    $params = [
        'router_id' => $mikrotikRouterId,
        'action'    => $key,
    ];

    return 'mikrotik.php?' . http_build_query($params);
};

$canView = static function (string $menuKey) use ($mikrotikMenuGuard): bool {
    if (!$mikrotikMenuGuard) {
        return false;
    }

    return $mikrotikMenuGuard->allows($menuKey, 'view');
};

$menuGroups = [
    [
        'key'   => 'interfaces',
        'label' => 'Interfaces',
        'icon'  => 'bi-ethernet',
        'items' => [
            [
                'key'   => 'interfaces.list',
                'label' => 'Interface List',
                'icon'  => 'bi-list-ul',
            ],
            [
                'key'   => 'interfaces.vlans',
                'label' => 'VLAN',
                'icon'  => 'bi-diagram-3',
            ],
        ],
    ],
    [
        'key'   => 'ip',
        'label' => 'IP',
        'icon'  => 'bi-diagram-3',
        'items' => [
            [
                'key'   => 'ip.addresses',
                'label' => 'Addresses',
                'icon'  => 'bi-pin-map',
            ],
            [
                'key'   => 'ip.routes',
                'label' => 'Routes',
                'icon'  => 'bi-signpost-split',
            ],
            [
                'key'   => 'ip.arp',
                'label' => 'ARP',
                'icon'  => 'bi-link-45deg',
            ],
            [
                'key'   => 'ip.pools',
                'label' => 'IP Pools',
                'icon'  => 'bi-collection',
            ],
        ],
    ],
    [
        'key'   => 'ppp',
        'label' => 'PPP',
        'icon'  => 'bi-plug',
        'items' => [
            [
                'key'   => 'ppp.profiles',
                'label' => 'PPP Profiles',
                'icon'  => 'bi-person-badge',
            ],
            [
                'key'   => 'ppp.secrets',
                'label' => 'PPPoE Secrets',
                'icon'  => 'bi-person-lock',
            ],
            [
                'key'   => 'ppp.active',
                'label' => 'Active',
                'icon'  => 'bi-activity',
            ],
        ],
    ],
    [
        'key'   => 'hotspot',
        'label' => 'Hotspot',
        'icon'  => 'bi-wifi',
        'items' => [
            [
                'key'   => 'hotspot.servers',
                'label' => 'Servers',
                'icon'  => 'bi-hdd-network',
            ],
            [
                'key'   => 'hotspot.users',
                'label' => 'Users',
                'icon'  => 'bi-people',
            ],
            [
                'key'   => 'hotspot.active',
                'label' => 'Active',
                'icon'  => 'bi-activity',
            ],
        ],
    ],
    [
        'key'   => 'queues',
        'label' => 'Queues',
        'icon'  => 'bi-speedometer',
        'items' => [
            [
                'key'   => 'queues.simple',
                'label' => 'Simple Queues',
                'icon'  => 'bi-list-check',
            ],
        ],
    ],
    [
        'key'   => 'firewall',
        'label' => 'Firewall',
        'icon'  => 'bi-shield-check',
        'items' => [
            [
                'key'   => 'firewall.filter',
                'label' => 'Filter Rules',
                'icon'  => 'bi-filter',
            ],
            [
                'key'   => 'firewall.nat',
                'label' => 'NAT',
                'icon'  => 'bi-arrow-left-right',
            ],
            [
                'key'   => 'firewall.mangle',
                'label' => 'Mangle',
                'icon'  => 'bi-scissors',
            ],
            [
                'key'   => 'firewall.raw',
                'label' => 'Raw',
                'icon'  => 'bi-filter-square',
            ],
        ],
    ],
    [
        'key'   => 'routing',
        'label' => 'Routing',
        'icon'  => 'bi-signpost-split',
        'items' => [
            [
                'key'   => 'ip.routes',
                'label' => 'Routes',
                'icon'  => 'bi-signpost',
            ],
        ],
    ],
    [
        'key'   => 'dhcp',
        'label' => 'DHCP',
        'icon'  => 'bi-router',
        'items' => [
            [
                'key'   => 'dhcp.servers',
                'label' => 'DHCP Servers',
                'icon'  => 'bi-server',
            ],
            [
                'key'   => 'dhcp.leases',
                'label' => 'DHCP Leases',
                'icon'  => 'bi-card-list',
            ],
            [
                'key'   => 'dhcp.networks',
                'label' => 'DHCP Networks',
                'icon'  => 'bi-diagram-2',
            ],
        ],
    ],
    [
        'key'   => 'system',
        'label' => 'System',
        'icon'  => 'bi-cpu',
        'items' => [
            [
                'key'   => 'system.identity',
                'label' => 'Identity',
                'icon'  => 'bi-tag',
            ],
            [
                'key'   => 'system.resource',
                'label' => 'Resource',
                'icon'  => 'bi-speedometer2',
            ],
            [
                'key'   => 'system.health',
                'label' => 'Health',
                'icon'  => 'bi-heart-pulse',
            ],
        ],
    ],
    [
        'key'   => 'tools',
        'label' => 'Tools',
        'icon'  => 'bi-tools',
        'items' => [
            [
                'key'   => 'tools.arp',
                'label' => 'ARP',
                'icon'  => 'bi-search',
            ],
        ],
    ],
    [
        'key'   => 'sync',
        'label' => 'Auto Sync',
        'icon'  => 'bi-arrow-repeat',
        'items' => [
            [
                'key'   => 'sync.status',
                'label' => 'Sync Status',
                'icon'  => 'bi-cloud-check',
            ],
        ],
    ],
    [
        'key'   => 'menu_permissions',
        'label' => 'Menu & Permissions',
        'icon'  => 'bi-shield-lock',
        'items' => [
            [
                'key'   => 'menu_permissions.users',
                'label' => 'User Permissions',
                'icon'  => 'bi-person-gear',
                'href'  => 'mikrotik_menu_permissions.php',
            ],
        ],
    ],
];

$visibleGroups = [];

foreach ($menuGroups as $group) {
    $visibleItems = [];

    foreach ($group['items'] as $item) {
        if ($canView($item['key'])) {
            $visibleItems[] = $item;
        }
    }

    if (!empty($visibleItems)) {
        $group['items'] = $visibleItems;
        $visibleGroups[] = $group;
    }
}
?>

<div class="mk-group bajama-mikrotik-sidebar">

    <div class="mk-group-title">
        MIKROTIK
    </div>

    <a
        href="mikrotik_routers.php"
        class="mk-menu mk-mikrotik-dashboard">
        <i class="bi bi-speedometer2"></i>
        <span>Dashboard</span>
    </a>

    <?php foreach ($visibleGroups as $group): ?>

        <?php
        /*
         * Parent group otomatis tetap terbuka ketika
         * salah satu submenu sedang aktif.
         *
         * Ini penting karena setiap submenu melakukan
         * request baru ke mikrotik.php.
         */
        $groupOpen = false;

        foreach ($group['items'] as $item) {
            if ($mikrotikActive === $item['key']) {
                $groupOpen = true;
                break;
            }
        }
        ?>

        <div
            class="mk-submenu-group <?= $groupOpen ? 'open' : '' ?>"
            data-menu-group="<?= h($group['key']) ?>">

            <button
                type="button"
                class="mk-menu mk-submenu-toggle"
                aria-expanded="<?= $groupOpen ? 'true' : 'false' ?>">

                <i class="bi <?= h($group['icon']) ?>"></i>

                <span><?= h($group['label']) ?></span>

                <i class="bi bi-chevron-right mk-chevron"></i>

            </button>

            <div class="mk-submenu-items">

                <?php foreach ($group['items'] as $item): ?>

                    <?php
                    $itemKey = (string)$item['key'];
                    $itemHref = isset($item['href'])
                        ? (string)$item['href']
                        : $mkUrl($itemKey);

                    $itemActive = $mikrotikActive === $itemKey;
                    ?>

                    <a
                        href="<?= h($itemHref) ?>"
                        class="mk-submenu-item <?= $itemActive ? 'active' : '' ?>">

                        <i class="bi <?= h($item['icon']) ?>"></i>

                        <span><?= h($item['label']) ?></span>

                    </a>

                <?php endforeach; ?>

            </div>

        </div>

    <?php endforeach; ?>

</div>

<style>
.bajama-mikrotik-sidebar {
    margin-top: 2px;
}

.bajama-mikrotik-sidebar .mk-submenu-group {
    margin: 2px 0;
}

.bajama-mikrotik-sidebar .mk-submenu-toggle {
    width: 100%;
    border: 0;
    background: transparent;
    text-align: left;
    cursor: pointer;
    display: flex;
    align-items: center;
}

.bajama-mikrotik-sidebar .mk-submenu-toggle .mk-chevron {
    margin-left: auto;
    font-size: 11px;
    transition: transform .18s ease;
}

.bajama-mikrotik-sidebar .mk-submenu-group.open
.mk-submenu-toggle .mk-chevron {
    transform: rotate(90deg);
}

.bajama-mikrotik-sidebar .mk-submenu-items {
    display: none;
    padding-left: 18px;
}

.bajama-mikrotik-sidebar .mk-submenu-group.open
.mk-submenu-items {
    display: block;
}

.bajama-mikrotik-sidebar .mk-submenu-item {
    display: flex;
    align-items: center;
    min-height: 38px;
    padding: 7px 10px 7px 17px;
    margin: 2px 0;
    border-radius: 8px;
    color: #9ca3af;
    text-decoration: none;
    font-size: 13px;
    transition: all .15s ease;
}

.bajama-mikrotik-sidebar .mk-submenu-item:hover {
    background: rgba(255,255,255,.06);
    color: #ffffff;
}

.bajama-mikrotik-sidebar .mk-submenu-item.active {
    background: rgba(37,99,235,.22);
    color: #ffffff;
}

.bajama-mikrotik-sidebar .mk-submenu-item i {
    width: 20px;
    margin-right: 7px;
    font-size: 14px;
}

.bajama-mikrotik-sidebar .mk-submenu-toggle {
    color: #d1d5db;
}

.bajama-mikrotik-sidebar .mk-submenu-toggle:hover {
    background: rgba(255,255,255,.06);
    color: #ffffff;
}

.bajama-mikrotik-sidebar .mk-mikrotik-dashboard {
    margin-bottom: 6px;
}
</style>

<script>
(function () {

    const sidebar = document.querySelector(
        '.bajama-mikrotik-sidebar'
    );

    if (!sidebar) {
        return;
    }

    const groups = Array.from(
        sidebar.querySelectorAll('.mk-submenu-group')
    );

    const STORAGE_KEY =
        'bajama_mikrotik_open_group';

    function setGroup(group, open) {

        if (!group) {
            return;
        }

        group.classList.toggle('open', open);

        const toggle = group.querySelector(
            '.mk-submenu-toggle'
        );

        if (toggle) {
            toggle.setAttribute(
                'aria-expanded',
                open ? 'true' : 'false'
            );
        }
    }

    function closeAll(except) {

        groups.forEach(function (group) {

            if (group !== except) {
                setGroup(group, false);
            }

        });

    }

    function openGroup(group) {

        if (!group) {
            return;
        }

        closeAll(group);
        setGroup(group, true);

        try {
            const key =
                group.getAttribute('data-menu-group');

            if (key) {
                sessionStorage.setItem(
                    STORAGE_KEY,
                    key
                );
            }

        } catch (e) {
            /* sessionStorage optional */
        }
    }

    function closeGroup(group) {

        setGroup(group, false);

        try {
            const key =
                group.getAttribute('data-menu-group');

            const saved =
                sessionStorage.getItem(STORAGE_KEY);

            if (saved === key) {
                sessionStorage.removeItem(
                    STORAGE_KEY
                );
            }

        } catch (e) {
            /* ignore */
        }

    }

    /*
     * Parent menu.
     *
     * Klik parent:
     * - jika tertutup -> buka
     * - jika terbuka -> tutup
     *
     * Parent lain selalu ditutup.
     */
    groups.forEach(function (group) {

        const toggle = group.querySelector(
            '.mk-submenu-toggle'
        );

        if (!toggle) {
            return;
        }

        toggle.addEventListener(
            'click',
            function (event) {

                event.preventDefault();
                event.stopPropagation();

                if (
                    group.classList.contains('open')
                ) {
                    closeGroup(group);
                } else {
                    openGroup(group);
                }

            }
        );

    });

    /*
     * Klik submenu.
     *
     * Jangan biarkan parent tertutup.
     * Parent tetap menjadi dropdown aktif
     * ketika browser berpindah halaman.
     */
    groups.forEach(function (group) {

        group.querySelectorAll(
            '.mk-submenu-item'
        ).forEach(function (link) {

            link.addEventListener(
                'click',
                function () {

                    openGroup(group);

                }
            );

        });

    });

    /*
     * Saat halaman pertama kali dimuat:
     *
     * 1. Prioritas submenu aktif dari PHP.
     * 2. Kalau tidak ada, gunakan state terakhir.
     */
    let activeGroup = null;

    groups.forEach(function (group) {

        const active =
            group.querySelector(
                '.mk-submenu-item.active'
            );

        if (active) {
            activeGroup = group;
        }

    });

    if (activeGroup) {

        openGroup(activeGroup);

    } else {

        try {

            const saved =
                sessionStorage.getItem(
                    STORAGE_KEY
                );

            if (saved) {

                const savedGroup =
                    groups.find(function (group) {

                        return (
                            group.getAttribute(
                                'data-menu-group'
                            ) === saved
                        );

                    });

                if (savedGroup) {
                    openGroup(savedGroup);
                }

            }

        } catch (e) {
            /* ignore */
        }

    }

})();
</script>
