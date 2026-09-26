-- Router/OLT links for existing tenant network configuration tables.
-- Applied idempotently by the deployment migration runner.
ALTER TABLE network_isps ADD COLUMN IF NOT EXISTS router_id BIGINT UNSIGNED NULL AFTER organization_id;
ALTER TABLE network_lans ADD COLUMN IF NOT EXISTS router_id BIGINT UNSIGNED NULL AFTER organization_id;
ALTER TABLE network_static_ips ADD COLUMN IF NOT EXISTS router_id BIGINT UNSIGNED NULL AFTER organization_id;
ALTER TABLE network_ftth_services ADD COLUMN IF NOT EXISTS router_id BIGINT UNSIGNED NULL AFTER organization_id;
ALTER TABLE network_ftth_services ADD COLUMN IF NOT EXISTS olt_id BIGINT UNSIGNED NULL AFTER router_id;

CREATE INDEX IF NOT EXISTS idx_network_isps_router ON network_isps (organization_id, router_id);
CREATE INDEX IF NOT EXISTS idx_network_lans_router ON network_lans (organization_id, router_id);
CREATE INDEX IF NOT EXISTS idx_network_static_router ON network_static_ips (organization_id, router_id);
CREATE INDEX IF NOT EXISTS idx_network_ftth_router_olt ON network_ftth_services (organization_id, router_id, olt_id);