-- Relasi idempotent antara entitas BAJAMA dan objek RouterOS.
-- source_type/source_id bersifat polymorphic karena satu modul dapat
-- menghasilkan beberapa objek RouterOS (contoh: load balance).
CREATE TABLE IF NOT EXISTS mikrotik_resource_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    router_id BIGINT UNSIGNED NOT NULL,
    source_type VARCHAR(80) NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    resource VARCHAR(80) NOT NULL,
    routeros_id VARCHAR(80) NOT NULL,
    marker VARCHAR(180) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_seen_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mikrotik_link_marker (router_id, marker),
    UNIQUE KEY uq_mikrotik_link_object (router_id, resource, routeros_id),
    KEY idx_mikrotik_link_source (organization_id, source_type, source_id),
    CONSTRAINT fk_mikrotik_link_org
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_mikrotik_link_router
        FOREIGN KEY (router_id) REFERENCES mikrotik_routers(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;
