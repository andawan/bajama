CREATE TABLE IF NOT EXISTS traffic_policies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    router_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    isp_id BIGINT UNSIGNED NOT NULL,

    enabled TINYINT(1) NOT NULL DEFAULT 1,
    priority INT UNSIGNED NOT NULL DEFAULT 100,

    notes TEXT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_traffic_policy_category_router (
        organization_id,
        router_id,
        category_id
    ),

    KEY idx_traffic_policy_org (
        organization_id
    ),

    KEY idx_traffic_policy_router (
        router_id
    ),

    KEY idx_traffic_policy_category (
        category_id
    ),

    KEY idx_traffic_policy_isp (
        isp_id
    ),

    KEY idx_traffic_policy_enabled (
        enabled
    )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
