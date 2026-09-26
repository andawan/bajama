-- Router selection is required before applying routes to a MikroTik device.
ALTER TABLE network_routes ADD COLUMN IF NOT EXISTS router_id BIGINT UNSIGNED NULL AFTER organization_id;
CREATE INDEX IF NOT EXISTS idx_network_routes_router ON network_routes (organization_id, router_id);
