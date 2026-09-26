-- BAJAMA network operations: tenant-scoped load balance, firewall, static IP, hotspot vouchers and FTTH.
CREATE TABLE IF NOT EXISTS network_olts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    router_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    vendor VARCHAR(100) NULL,
    management_ip VARCHAR(80) NULL,
    status ENUM('ACTIVE','OFFLINE','DISABLED') NOT NULL DEFAULT 'ACTIVE',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_olt_name_org (organization_id, name),
    INDEX idx_olt_org_status (organization_id, status),
    CONSTRAINT fk_olt_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_olt_router FOREIGN KEY (router_id) REFERENCES mikrotik_routers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS network_load_balances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    router_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    mode ENUM('PCC','ECMP','NTH','FAILOVER') NOT NULL DEFAULT 'PCC',
    algorithm VARCHAR(80) NOT NULL DEFAULT 'both-addresses-and-ports',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    health_check TINYINT(1) NOT NULL DEFAULT 1,
    comment VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_load_balance_org (organization_id, enabled),
    CONSTRAINT fk_load_balance_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_load_balance_router FOREIGN KEY (router_id) REFERENCES mikrotik_routers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS network_firewall_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    router_id BIGINT UNSIGNED NOT NULL,
    chain ENUM('INPUT','FORWARD','OUTPUT') NOT NULL DEFAULT 'FORWARD',
    action ENUM('ACCEPT','DROP','REJECT','FASTTRACK','LOG') NOT NULL DEFAULT 'ACCEPT',
    protocol ENUM('ANY','TCP','UDP','ICMP') NOT NULL DEFAULT 'ANY',
    src_address VARCHAR(100) NULL,
    dst_address VARCHAR(100) NULL,
    src_port VARCHAR(80) NULL,
    dst_port VARCHAR(80) NULL,
    position INT UNSIGNED NOT NULL DEFAULT 100,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    comment VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_firewall_org (organization_id, enabled, position),
    CONSTRAINT fk_firewall_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_firewall_router FOREIGN KEY (router_id) REFERENCES mikrotik_routers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS network_static_ips (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    subscription_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(80) NOT NULL,
    gateway VARCHAR(80) NULL,
    interface_name VARCHAR(100) NULL,
    status ENUM('ACTIVE','SUSPENDED','RESERVED','RELEASED') NOT NULL DEFAULT 'ACTIVE',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    comment VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_static_ip_org (organization_id, ip_address),
    INDEX idx_static_ip_customer (organization_id, customer_id),
    CONSTRAINT fk_static_ip_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_static_ip_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_static_ip_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS network_hotspot_vouchers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    router_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    profile VARCHAR(100) NOT NULL DEFAULT 'default',
    username VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    status ENUM('READY','USED','EXPIRED','DISABLED') NOT NULL DEFAULT 'READY',
    expires_at DATETIME NULL,
    used_at DATETIME NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hotspot_username_org (organization_id, username),
    INDEX idx_hotspot_org_status (organization_id, status),
    CONSTRAINT fk_hotspot_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_hotspot_router FOREIGN KEY (router_id) REFERENCES mikrotik_routers(id) ON DELETE CASCADE,
    CONSTRAINT fk_hotspot_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS network_ftth_services (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    subscription_id BIGINT UNSIGNED NULL,
    olt_name VARCHAR(150) NOT NULL,
    pon_port VARCHAR(80) NOT NULL,
    onu_serial VARCHAR(120) NOT NULL,
    onu_type VARCHAR(80) NULL,
    status ENUM('PLANNED','PROVISIONING','ONLINE','OFFLINE','SUSPENDED','DECOMMISSIONED') NOT NULL DEFAULT 'PLANNED',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ftth_serial_org (organization_id, onu_serial),
    INDEX idx_ftth_org_status (organization_id, status),
    CONSTRAINT fk_ftth_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_ftth_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_ftth_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL
) ENGINE=InnoDB;
