-- Fondasi tabel yang dipakai modul MikroTik, menu permission, dan adaptive sync.
-- Jalankan setelah schema.sql dan 20260913_mikrotik_api.sql.

CREATE TABLE IF NOT EXISTS mikrotik_menu_definitions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT UNSIGNED NULL,
    menu_key VARCHAR(120) NOT NULL UNIQUE,
    label VARCHAR(150) NOT NULL,
    icon VARCHAR(80) NULL,
    route VARCHAR(255) NULL,
    item_type VARCHAR(30) NOT NULL DEFAULT 'menu',
    sort_order INT NOT NULL DEFAULT 100,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_mikrotik_menu_parent (parent_id),
    CONSTRAINT fk_mikrotik_menu_parent
        FOREIGN KEY (parent_id) REFERENCES mikrotik_menu_definitions(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mikrotik_user_router_menu_permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    router_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    menu_id BIGINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    can_view TINYINT(1) NOT NULL DEFAULT 0,
    can_create TINYINT(1) NOT NULL DEFAULT 0,
    can_edit TINYINT(1) NOT NULL DEFAULT 0,
    can_enable TINYINT(1) NOT NULL DEFAULT 0,
    can_disable TINYINT(1) NOT NULL DEFAULT 0,
    can_delete TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mikrotik_user_router_menu (organization_id, router_id, user_id, menu_id),
    KEY idx_mikrotik_permissions_router (organization_id, router_id),
    CONSTRAINT fk_mikrotik_permission_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_mikrotik_permission_router FOREIGN KEY (router_id) REFERENCES mikrotik_routers(id) ON DELETE CASCADE,
    CONSTRAINT fk_mikrotik_permission_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mikrotik_permission_menu FOREIGN KEY (menu_id) REFERENCES mikrotik_menu_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mikrotik_sync_state (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    router_id BIGINT UNSIGNED NOT NULL,
    status ENUM('IDLE','SYNCING','ONLINE','ERROR') NOT NULL DEFAULT 'IDLE',
    first_sync_at DATETIME NULL,
    last_sync_started_at DATETIME NULL,
    last_sync_at DATETIME NULL,
    last_success_at DATETIME NULL,
    last_error_at DATETIME NULL,
    last_duration_ms INT UNSIGNED NULL,
    total_scans INT UNSIGNED NOT NULL DEFAULT 0,
    successful_scans INT UNSIGNED NOT NULL DEFAULT 0,
    failed_scans INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mikrotik_sync_state (organization_id, router_id),
    CONSTRAINT fk_mikrotik_sync_state_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_mikrotik_sync_state_router FOREIGN KEY (router_id) REFERENCES mikrotik_routers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mikrotik_sync_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    router_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(80) NOT NULL,
    status ENUM('SUCCESS','ERROR') NOT NULL,
    item_count INT UNSIGNED NOT NULL DEFAULT 0,
    data_json LONGTEXT NOT NULL,
    scanned_at DATETIME NOT NULL,
    duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    KEY idx_mikrotik_snapshot_latest (organization_id, router_id, category, id),
    KEY idx_mikrotik_snapshot_scanned (organization_id, router_id, scanned_at),
    CONSTRAINT fk_mikrotik_snapshot_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_mikrotik_snapshot_router FOREIGN KEY (router_id) REFERENCES mikrotik_routers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO mikrotik_menu_definitions (menu_key, label, icon, route, sort_order)
VALUES
    ('interfaces.list', 'Interface List', 'bi-list-ul', 'mikrotik.php?action=interfaces.list', 10),
    ('interfaces.vlans', 'VLAN', 'bi-diagram-3', 'mikrotik.php?action=interfaces.vlans', 20),
    ('interfaces.lists', 'Interface Lists', 'bi-list', 'mikrotik.php?action=interfaces.lists', 30),
    ('ip.addresses', 'IP Addresses', 'bi-pin-map', 'mikrotik.php?action=ip.addresses', 40),
    ('ip.routes', 'Routes', 'bi-signpost-split', 'mikrotik.php?action=ip.routes', 50),
    ('ip.arp', 'ARP', 'bi-link-45deg', 'mikrotik.php?action=ip.arp', 60),
    ('ip.pools', 'IP Pools', 'bi-collection', 'mikrotik.php?action=ip.pools', 70),
    ('ppp.profiles', 'PPP Profiles', 'bi-person-badge', 'mikrotik.php?action=ppp.profiles', 80),
    ('ppp.secrets', 'PPPoE Secrets', 'bi-person-lock', 'mikrotik.php?action=ppp.secrets', 90),
    ('ppp.servers', 'PPPoE Servers', 'bi-server', 'mikrotik.php?action=pppoe_servers', 100),
    ('ppp.active', 'PPP Active', 'bi-activity', 'mikrotik.php?action=ppp.active', 110),
    ('hotspot.servers', 'Hotspot Servers', 'bi-hdd-network', 'mikrotik.php?action=hotspot.servers', 120),
    ('hotspot.users', 'Hotspot Users', 'bi-people', 'mikrotik.php?action=hotspot.users', 130),
    ('hotspot.active', 'Hotspot Active', 'bi-activity', 'mikrotik.php?action=hotspot.active', 140),
    ('queues.simple', 'Simple Queues', 'bi-speedometer', 'mikrotik.php?action=queues.simple', 150),
    ('firewall.filter', 'Firewall Filter', 'bi-filter', 'mikrotik.php?action=firewall.filter', 160),
    ('firewall.nat', 'Firewall NAT', 'bi-arrow-left-right', 'mikrotik.php?action=firewall.nat', 170),
    ('firewall.mangle', 'Firewall Mangle', 'bi-scissors', 'mikrotik.php?action=firewall.mangle', 180),
    ('firewall.raw', 'Firewall Raw', 'bi-filter-square', 'mikrotik.php?action=firewall.raw', 190),
    ('firewall.address_list', 'Address Lists', 'bi-list-check', 'mikrotik.php?action=firewall.address_list', 200),
    ('dhcp.servers', 'DHCP Servers', 'bi-server', 'mikrotik.php?action=dhcp.servers', 210),
    ('dhcp.leases', 'DHCP Leases', 'bi-card-list', 'mikrotik.php?action=dhcp.leases', 220),
    ('dhcp.networks', 'DHCP Networks', 'bi-diagram-2', 'mikrotik.php?action=dhcp.networks', 230),
    ('system.identity', 'Identity', 'bi-tag', 'mikrotik.php?action=system.identity', 240),
    ('system.resource', 'Resource', 'bi-speedometer2', 'mikrotik.php?action=system.resource', 250),
    ('system.health', 'Health', 'bi-heart-pulse', 'mikrotik.php?action=system.health', 260),
    ('system.dns', 'DNS', 'bi-globe2', 'mikrotik.php?action=system.dns', 270),
    ('system.clock', 'Clock', 'bi-clock', 'mikrotik.php?action=system.clock', 280),
    ('system.users', 'Router Users', 'bi-person-gear', 'mikrotik.php?action=system.users', 290),
    ('system.logs', 'Logs', 'bi-journal-text', 'mikrotik.php?action=system.logs', 300)
ON DUPLICATE KEY UPDATE
    label = VALUES(label), icon = VALUES(icon), route = VALUES(route), active = 1;
