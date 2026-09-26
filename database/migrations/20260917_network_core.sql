-- Tabel inti konfigurasi network yang dipakai halaman WAN, LAN, dan routing.
-- Dibuat sebelum migration network_router_links.

CREATE TABLE IF NOT EXISTS network_isps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    interface_name VARCHAR(100) NOT NULL,
    gateway VARCHAR(80) NULL,
    ip_address VARCHAR(80) NULL,
    subnet VARCHAR(80) NULL,
    public_ip VARCHAR(80) NULL,
    dns_primary VARCHAR(80) NULL,
    dns_secondary VARCHAR(80) NULL,
    bandwidth_download INT UNSIGNED NULL,
    bandwidth_upload INT UNSIGNED NULL,
    routing_mode ENUM('STATIC','ECMP','PCC','FAILOVER') NOT NULL DEFAULT 'STATIC',
    distance INT UNSIGNED NOT NULL DEFAULT 1,
    check_gateway ENUM('NONE','PING','ARP') NOT NULL DEFAULT 'PING',
    status ENUM('UNKNOWN','ONLINE','OFFLINE','ERROR','DISABLED') NOT NULL DEFAULT 'UNKNOWN',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_checked_at DATETIME NULL,
    last_latency_ms DECIMAL(10,2) NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_network_isp_org (organization_id),
    CONSTRAINT fk_network_isp_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS network_lans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    interface_name VARCHAR(100) NOT NULL,
    ip_address VARCHAR(80) NOT NULL,
    subnet VARCHAR(80) NOT NULL,
    vlan_id INT UNSIGNED NULL,
    network_type ENUM('LAN','VLAN','BRIDGE','PPPOE','HOTSPOT','OTHER') NOT NULL DEFAULT 'LAN',
    gateway VARCHAR(80) NULL,
    dhcp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    dhcp_start VARCHAR(80) NULL,
    dhcp_end VARCHAR(80) NULL,
    dns_primary VARCHAR(80) NULL,
    dns_secondary VARCHAR(80) NULL,
    status ENUM('UNKNOWN','ONLINE','OFFLINE','ERROR','DISABLED') NOT NULL DEFAULT 'UNKNOWN',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_network_lan_org (organization_id),
    CONSTRAINT fk_network_lan_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS network_routes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    route_type ENUM('DEFAULT','STATIC','RECURSIVE') NOT NULL DEFAULT 'STATIC',
    destination VARCHAR(80) NOT NULL,
    gateway VARCHAR(80) NULL,
    interface_name VARCHAR(100) NULL,
    routing_table VARCHAR(100) NOT NULL DEFAULT 'main',
    distance INT UNSIGNED NOT NULL DEFAULT 1,
    scope INT UNSIGNED NOT NULL DEFAULT 30,
    target_scope INT UNSIGNED NOT NULL DEFAULT 10,
    check_gateway ENUM('NONE','PING','ARP') NOT NULL DEFAULT 'NONE',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    comment VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_network_route_org (organization_id, enabled),
    CONSTRAINT fk_network_route_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
