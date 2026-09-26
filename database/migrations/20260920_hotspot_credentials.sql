-- Hotspot credentials and Mikhmon-style limits for BAJAMA users/vouchers.
ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS password_encrypted TEXT NULL AFTER username;
ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS hotspot_address VARCHAR(80) NULL AFTER password_encrypted;
ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS hotspot_mac_address VARCHAR(32) NULL AFTER hotspot_address;
ALTER TABLE network_hotspot_vouchers ADD COLUMN IF NOT EXISTS address VARCHAR(80) NULL AFTER password;
ALTER TABLE network_hotspot_vouchers ADD COLUMN IF NOT EXISTS mac_address VARCHAR(32) NULL AFTER address;
ALTER TABLE network_hotspot_vouchers ADD COLUMN IF NOT EXISTS limit_uptime VARCHAR(40) NULL AFTER mac_address;
ALTER TABLE network_hotspot_vouchers ADD COLUMN IF NOT EXISTS limit_bytes_total BIGINT UNSIGNED NULL AFTER limit_uptime;
ALTER TABLE network_hotspot_vouchers ADD COLUMN IF NOT EXISTS hotspot_server VARCHAR(100) NULL AFTER limit_bytes_total;
ALTER TABLE network_hotspot_vouchers ADD COLUMN IF NOT EXISTS hotspot_dns_name VARCHAR(255) NULL AFTER hotspot_server;
