SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS organizations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(150) NOT NULL UNIQUE,
    email VARCHAR(190) NULL,
    phone VARCHAR(50) NULL,
    logo VARCHAR(255) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'IDR',
    timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Jakarta',
    status ENUM('ACTIVE','SUSPENDED','CANCELLED') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_organizations_name (name),
    UNIQUE KEY uq_organizations_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(190) NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NULL,
    status ENUM('ACTIVE','SUSPENDED','DISABLED') NOT NULL DEFAULT 'ACTIVE',
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username_global (username),
    UNIQUE KEY uq_users_email_global (email),
    CONSTRAINT fk_users_org
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    description VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS license_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    features JSON NULL,
    max_customers INT UNSIGNED NOT NULL DEFAULT 100,
    max_routers INT UNSIGNED NOT NULL DEFAULT 5,
    max_olts INT UNSIGNED NOT NULL DEFAULT 1,
    max_onus INT UNSIGNED NOT NULL DEFAULT 128,
    price_monthly DECIMAL(15,2) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS licenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    license_key VARCHAR(255) NOT NULL UNIQUE,
    status ENUM('TRIAL','ACTIVE','SUSPENDED','EXPIRED','CANCELLED') NOT NULL DEFAULT 'TRIAL',
    issued_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    last_validated_at DATETIME NULL,
    features JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES license_plans(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    module VARCHAR(100) NULL,
    target_type VARCHAR(100) NULL,
    target_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    details JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_org_created (organization_id, created_at),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS blog_posts (
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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS site_contacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(80) NOT NULL DEFAULT 'ADMIN',
    display_name VARCHAR(150) NOT NULL DEFAULT 'BAJAMA Support',
    email VARCHAR(190) NULL,
    phone VARCHAR(50) NULL,
    whatsapp VARCHAR(50) NULL,
    address TEXT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ,UNIQUE KEY uq_organizations_name (name)
    ,UNIQUE KEY uq_organizations_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payment_methods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    type ENUM('BANK','EWALLET','GATEWAY') NOT NULL DEFAULT 'BANK',
    provider_name VARCHAR(120) NULL,
    account_name VARCHAR(150) NULL,
    account_number VARCHAR(150) NULL,
    instructions TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_method_org_name (organization_id, name),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS license_registrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(180) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(50) NULL,
    package_name VARCHAR(120) NOT NULL,
    plan_id BIGINT UNSIGNED NULL,
    payment_method_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    status ENUM('PENDING','PAID','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
    payment_reference VARCHAR(150) NULL,
    payment_proof VARCHAR(255) NULL,
    payment_review_status ENUM('WAITING_REVIEW','VERIFIED','REJECTED') NOT NULL DEFAULT 'WAITING_REVIEW',
    processed_by_user_id BIGINT UNSIGNED NULL,
    processed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (plan_id) REFERENCES license_plans(id) ON DELETE SET NULL,
    FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id) ON DELETE SET NULL,
    FOREIGN KEY (processed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_registration_status (status),
    INDEX idx_registration_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(120) PRIMARY KEY,
    setting_value TEXT NULL,
    is_secret TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS otp_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    purpose ENUM('REGISTER','PASSWORD_RESET') NOT NULL,
    code_hash CHAR(64) NOT NULL,
    payload JSON NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    consumed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_otp_lookup (email, purpose, expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    customer_code VARCHAR(50) NOT NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(50) NULL,
    address TEXT NULL,
    status ENUM('ACTIVE','INACTIVE','BLOCKED') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customer_code (organization_id, customer_code),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(80) NOT NULL,
    service_type ENUM('PPPOE','HOTSPOT','STATIC','FTTH') NOT NULL DEFAULT 'PPPOE',
    speed_download INT UNSIGNED NOT NULL DEFAULT 0,
    speed_upload INT UNSIGNED NOT NULL DEFAULT 0,
    price DECIMAL(15,2) NOT NULL DEFAULT 0,
    billing_cycle ENUM('MONTHLY','QUARTERLY','YEARLY') NOT NULL DEFAULT 'MONTHLY',
    description TEXT NULL,
    status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_service_plan_org_code (organization_id, code),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    service_plan_id BIGINT UNSIGNED NULL,
    router_id BIGINT UNSIGNED NULL,
    service_type ENUM('PPPOE','HOTSPOT','STATIC','FTTH') NOT NULL,
    service_name VARCHAR(150) NOT NULL,
    username VARCHAR(150) NULL,
    speed_download INT UNSIGNED NOT NULL DEFAULT 0,
    speed_upload INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('ACTIVE','GRACE_PERIOD','SUSPENDED','TERMINATED') NOT NULL DEFAULT 'ACTIVE',
    billing_cycle ENUM('MONTHLY','QUARTERLY','YEARLY') NOT NULL DEFAULT 'MONTHLY',
    price DECIMAL(15,2) NOT NULL DEFAULT 0,
    next_due_date DATE NULL,
    password_encrypted TEXT NULL,
    hotspot_address VARCHAR(80) NULL,
    hotspot_mac_address VARCHAR(32) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (service_plan_id) REFERENCES service_plans(id) ON DELETE SET NULL,
    FOREIGN KEY (router_id) REFERENCES mikrotik_routers(id) ON DELETE RESTRICT,
    INDEX idx_subscription_org_status (organization_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    router_id BIGINT UNSIGNED NULL,
    subscription_id BIGINT UNSIGNED NULL,
    invoice_number VARCHAR(80) NOT NULL,
    issue_date DATE NOT NULL,
    due_date DATE NOT NULL,
    period_start DATE NULL,
    period_end DATE NULL,
    subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
    discount DECIMAL(15,2) NOT NULL DEFAULT 0,
    total DECIMAL(15,2) NOT NULL DEFAULT 0,
    description TEXT NULL,
    payment_currency CHAR(3) NULL,
    status ENUM('DRAFT','UNPAID','PARTIAL','PAID','OVERDUE','CANCELLED') NOT NULL DEFAULT 'UNPAID',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invoice_number (organization_id, invoice_number),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
    ,FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL
    ,FOREIGN KEY (router_id) REFERENCES mikrotik_routers(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    payment_method VARCHAR(120) NULL,
    reference_number VARCHAR(150) NULL,
    paid_at DATETIME NOT NULL,
    payment_channel VARCHAR(30) NULL,
    provider_name VARCHAR(100) NULL,
    provider_transaction_id VARCHAR(190) NULL,
    bank_name VARCHAR(120) NULL,
    ewallet_name VARCHAR(120) NULL,
    gateway_payload JSON NULL,
    confirmed_at DATETIME NULL,
    confirmed_by_user_id BIGINT UNSIGNED NULL,
    status ENUM('PENDING','SUCCESS','FAILED','REFUNDED') NOT NULL DEFAULT 'SUCCESS',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
    ,FOREIGN KEY (confirmed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

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

SET FOREIGN_KEY_CHECKS=1;
