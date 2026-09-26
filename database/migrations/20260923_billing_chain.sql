-- Menyelaraskan paket layanan -> subscription -> invoice dengan endpoint billing.
-- Jalankan sekali pada database yang sudah memakai schema lama.

ALTER TABLE organizations
    ADD COLUMN IF NOT EXISTS logo VARCHAR(255) NULL AFTER phone,
    ADD COLUMN IF NOT EXISTS currency CHAR(3) NOT NULL DEFAULT 'IDR' AFTER logo,
    ADD COLUMN IF NOT EXISTS timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Jakarta' AFTER currency;

ALTER TABLE payments
    MODIFY COLUMN payment_method VARCHAR(120) NULL;

ALTER TABLE payment_methods
    ADD COLUMN IF NOT EXISTS organization_id BIGINT UNSIGNED NULL AFTER id;

ALTER TABLE payment_methods
    DROP INDEX uq_payment_method_name,
    ADD UNIQUE KEY uq_payment_method_org_name (organization_id, name),
    ADD CONSTRAINT fk_payment_methods_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE;

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

ALTER TABLE subscriptions
    ADD COLUMN IF NOT EXISTS service_plan_id BIGINT UNSIGNED NULL AFTER customer_id,
    ADD COLUMN IF NOT EXISTS speed_download INT UNSIGNED NOT NULL DEFAULT 0 AFTER username,
    ADD COLUMN IF NOT EXISTS speed_upload INT UNSIGNED NOT NULL DEFAULT 0 AFTER speed_download,
    ADD COLUMN IF NOT EXISTS password_encrypted TEXT NULL AFTER username,
    ADD COLUMN IF NOT EXISTS hotspot_address VARCHAR(80) NULL AFTER password_encrypted,
    ADD COLUMN IF NOT EXISTS hotspot_mac_address VARCHAR(32) NULL AFTER hotspot_address,
    ADD KEY idx_subscriptions_service_plan (organization_id, service_plan_id),
    ADD CONSTRAINT fk_subscriptions_service_plan
        FOREIGN KEY (service_plan_id) REFERENCES service_plans(id) ON DELETE SET NULL;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS subscription_id BIGINT UNSIGNED NULL AFTER router_id,
    ADD COLUMN IF NOT EXISTS period_start DATE NULL AFTER due_date,
    ADD COLUMN IF NOT EXISTS period_end DATE NULL AFTER period_start,
    ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER total,
    ADD KEY idx_invoices_subscription_period (organization_id, subscription_id, period_start, period_end),
    ADD CONSTRAINT fk_invoices_subscription
        FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL;