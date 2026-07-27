-- Sobmoei SSO API - bind applications to managed OAuth clients

USE `sso`;

CREATE TABLE `application_sso_configs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `application_id` BIGINT UNSIGNED NOT NULL,
    `oauth_client_id` CHAR(36) NOT NULL,
    `allowed_providers` JSON NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `application_sso_configs_application_id_unique`
        (`application_id`),
    UNIQUE KEY `application_sso_configs_oauth_client_id_unique`
        (`oauth_client_id`),
    CONSTRAINT `application_sso_configs_application_id_foreign`
        FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `application_sso_configs_oauth_client_id_foreign`
        FOREIGN KEY (`oauth_client_id`) REFERENCES `oauth_clients` (`id`)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `migrations` (`migration`, `batch`) VALUES
    ('2026_07_27_063326_create_application_sso_configs_table', 1);
