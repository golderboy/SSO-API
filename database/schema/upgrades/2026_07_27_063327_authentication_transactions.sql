-- Sobmoei SSO API - short-lived browser authentication transactions

USE `sso`;

CREATE TABLE `authentication_transactions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) NOT NULL,
    `application_sso_config_id` BIGINT UNSIGNED NOT NULL,
    `browser_session_hash` VARCHAR(64) NOT NULL,
    `downstream_request` TEXT NOT NULL,
    `upstream_state_hash` VARCHAR(64) NULL,
    `selected_provider` VARCHAR(20) NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
    `user_id` BIGINT UNSIGNED NULL,
    `access_grant_id` BIGINT UNSIGNED NULL,
    `organization_id` BIGINT UNSIGNED NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `authenticated_at` TIMESTAMP NULL,
    `consumed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `authentication_transactions_public_id_unique` (`public_id`),
    UNIQUE KEY `authentication_transactions_upstream_state_hash_unique`
        (`upstream_state_hash`),
    KEY `authentication_transactions_status_index` (`status`),
    KEY `authentication_transactions_expires_at_index` (`expires_at`),
    KEY `authentication_transactions_user_id_foreign` (`user_id`),
    KEY `authentication_transactions_access_grant_id_foreign`
        (`access_grant_id`),
    KEY `authentication_transactions_organization_id_foreign`
        (`organization_id`),
    KEY `authentication_transactions_browser_lookup`
        (`application_sso_config_id`, `browser_session_hash`, `status`),
    CONSTRAINT `authentication_transactions_application_sso_config_id_foreign`
        FOREIGN KEY (`application_sso_config_id`)
        REFERENCES `application_sso_configs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `authentication_transactions_user_id_foreign`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `authentication_transactions_access_grant_id_foreign`
        FOREIGN KEY (`access_grant_id`) REFERENCES `access_grants` (`id`)
        ON DELETE SET NULL,
    CONSTRAINT `authentication_transactions_organization_id_foreign`
        FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
        ON DELETE SET NULL,
    CONSTRAINT `authentication_transactions_status_check`
        CHECK (`status` IN (
            'pending', 'provider_selected', 'organization_required',
            'approved', 'issuing', 'consumed', 'denied'
        )),
    CONSTRAINT `authentication_transactions_provider_check`
        CHECK (
            `selected_provider` IS NULL
            OR `selected_provider` IN ('thaid', 'provider_id')
        )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `migrations` (`migration`, `batch`) VALUES
    ('2026_07_27_063327_create_authentication_transactions_table', 1);
