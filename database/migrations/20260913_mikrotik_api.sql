-- BAJAMA MikroTik API module
-- Run once against the existing BAJAMA database.
CREATE TABLE IF NOT EXISTS mikrotik_routers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    host VARCHAR(255) NOT NULL,
    port INT UNSIGNED NOT NULL DEFAULT 8728,
    username VARCHAR(150) NOT NULL,
    password_encrypted TEXT NOT NULL,
    use_ssl TINYINT(1) NOT NULL DEFAULT 0,
    timeout_seconds DECIMAL(5,2) NOT NULL DEFAULT 8.00,
    status ENUM('UNKNOWN','ONLINE','ERROR','DISABLED') NOT NULL DEFAULT 'UNKNOWN',
    last_tested_at DATETIME NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mikrotik_router_name (organization_id, name),
    INDEX idx_mikrotik_org_status (organization_id, status),
    CONSTRAINT fk_mikrotik_routers_org
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;
