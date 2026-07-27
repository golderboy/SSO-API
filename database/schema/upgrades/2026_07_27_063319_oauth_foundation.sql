-- Sobmoei SSO API - downstream OAuth foundation upgrade
-- Target: MariaDB 10.5+ / MySQL 8.0+
-- Run after 2026_07_27_063318_system_roles.sql.

USE `sso`;

CREATE TABLE IF NOT EXISTS `sso_subjects` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `sso_subjects_user_id_unique` (`user_id`),
    CONSTRAINT `sso_subjects_user_id_foreign`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `oauth_clients` (
    `id` CHAR(36) NOT NULL,
    `owner_type` VARCHAR(255) NULL,
    `owner_id` BIGINT UNSIGNED NULL,
    `name` VARCHAR(255) NOT NULL,
    `secret` VARCHAR(255) NULL,
    `provider` VARCHAR(255) NULL,
    `redirect_uris` TEXT NOT NULL,
    `grant_types` TEXT NOT NULL,
    `revoked` TINYINT(1) NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `oauth_clients_owner_type_owner_id_index`
        (`owner_type`, `owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `oauth_auth_codes` (
    `id` CHAR(80) NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `client_id` CHAR(36) NOT NULL,
    `scopes` TEXT NULL,
    `revoked` TINYINT(1) NOT NULL,
    `expires_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `oauth_auth_codes_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `oauth_access_tokens` (
    `id` CHAR(80) NOT NULL,
    `user_id` BIGINT UNSIGNED NULL,
    `client_id` CHAR(36) NOT NULL,
    `name` VARCHAR(255) NULL,
    `scopes` TEXT NULL,
    `revoked` TINYINT(1) NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    `expires_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `oauth_access_tokens_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `oauth_refresh_tokens` (
    `id` CHAR(80) NOT NULL,
    `access_token_id` CHAR(80) NOT NULL,
    `revoked` TINYINT(1) NOT NULL,
    `expires_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `oauth_refresh_tokens_access_token_id_index` (`access_token_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `migrations` (`migration`, `batch`) VALUES
    ('2026_07_27_063319_create_sso_subjects_table', 1),
    ('2026_07_27_063320_create_oauth_clients_table', 1),
    ('2026_07_27_063321_create_oauth_auth_codes_table', 1),
    ('2026_07_27_063322_create_oauth_access_tokens_table', 1),
    ('2026_07_27_063323_create_oauth_refresh_tokens_table', 1);
