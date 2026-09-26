-- BAJAMA router-scoped billing foundation.
-- Additive migration: existing records remain available.

ALTER TABLE subscriptions
    ADD COLUMN router_id BIGINT UNSIGNED NULL AFTER customer_id,
    ADD KEY idx_subscriptions_router (organization_id, router_id);

ALTER TABLE invoices
    ADD COLUMN router_id BIGINT UNSIGNED NULL AFTER customer_id,
    ADD COLUMN payment_currency CHAR(3) NULL AFTER total,
    ADD KEY idx_invoices_router (organization_id, router_id);

ALTER TABLE payments
    ADD COLUMN payment_channel VARCHAR(30) NULL AFTER payment_method,
    ADD COLUMN provider_name VARCHAR(100) NULL AFTER payment_channel,
    ADD COLUMN provider_transaction_id VARCHAR(190) NULL AFTER reference_number,
    ADD COLUMN bank_name VARCHAR(120) NULL AFTER provider_transaction_id,
    ADD COLUMN ewallet_name VARCHAR(120) NULL AFTER bank_name,
    ADD COLUMN gateway_payload JSON NULL AFTER ewallet_name,
    ADD COLUMN confirmed_at DATETIME NULL AFTER paid_at,
    ADD COLUMN confirmed_by_user_id BIGINT UNSIGNED NULL AFTER confirmed_at,
    ADD KEY idx_payments_provider_transaction (provider_name, provider_transaction_id),
    ADD KEY idx_payments_confirmed_by (confirmed_by_user_id);

UPDATE subscriptions s
INNER JOIN provisioned_services ps
    ON ps.subscription_id = s.id
   AND ps.organization_id = s.organization_id
SET s.router_id = ps.router_id
WHERE s.router_id IS NULL;

UPDATE invoices i
INNER JOIN subscriptions s
    ON s.id = i.subscription_id
   AND s.organization_id = i.organization_id
SET i.router_id = s.router_id
WHERE i.router_id IS NULL
  AND s.router_id IS NOT NULL;

ALTER TABLE subscriptions
    ADD CONSTRAINT fk_subscriptions_router
    FOREIGN KEY (router_id) REFERENCES mikrotik_routers(id)
    ON DELETE RESTRICT;

ALTER TABLE invoices
    ADD CONSTRAINT fk_invoices_router
    FOREIGN KEY (router_id) REFERENCES mikrotik_routers(id)
    ON DELETE RESTRICT;

ALTER TABLE payments
    ADD CONSTRAINT fk_payments_confirmed_by
    FOREIGN KEY (confirmed_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL;
