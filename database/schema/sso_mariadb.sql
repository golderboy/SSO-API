-- Sobmoei SSO API - Phase 1 schema
-- Compatible target: MariaDB 10.5+ / MySQL 8.0+
-- Intended for a new, empty database. No production credentials or seed data.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

CREATE DATABASE IF NOT EXISTS `sso`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `sso`;

CREATE TABLE IF NOT EXISTS `migrations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `migration` VARCHAR(255) NOT NULL,
    `batch` INT NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `migrations_migration_unique` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NULL,
    `email_verified_at` TIMESTAMP NULL,
    `password` VARCHAR(255) NULL,
    `cid_hash` VARCHAR(64) NULL,
    `provider_cid_hash` VARCHAR(64) NULL,
    `cid_encrypted` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `system_role` VARCHAR(20) NOT NULL DEFAULT 'user',
    `admin_slot` TINYINT UNSIGNED NULL,
    `last_login_at` TIMESTAMP NULL,
    `remember_token` VARCHAR(100) NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    `deleted_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `users_public_id_unique` (`public_id`),
    UNIQUE KEY `users_email_unique` (`email`),
    UNIQUE KEY `users_cid_hash_unique` (`cid_hash`),
    UNIQUE KEY `users_provider_cid_hash_unique` (`provider_cid_hash`),
    UNIQUE KEY `users_admin_slot_unique` (`admin_slot`),
    KEY `users_is_active_index` (`is_active`),
    KEY `users_system_role_index` (`system_role`),
    CONSTRAINT `users_system_role_check`
        CHECK (
            (`system_role` = 'admin' AND `admin_slot` = 1)
            OR
            (
                `system_role` IN ('super_admin', 'user')
                AND `admin_slot` IS NULL
            )
        )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    `email` VARCHAR(255) NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP NULL,
    PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sessions` (
    `id` VARCHAR(255) NOT NULL,
    `user_id` BIGINT UNSIGNED NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `payload` LONGTEXT NOT NULL,
    `last_activity` INT NOT NULL,
    PRIMARY KEY (`id`),
    KEY `sessions_user_id_index` (`user_id`),
    KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cache` (
    `key` VARCHAR(255) NOT NULL,
    `value` MEDIUMTEXT NOT NULL,
    `expiration` BIGINT NOT NULL,
    PRIMARY KEY (`key`),
    KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cache_locks` (
    `key` VARCHAR(255) NOT NULL,
    `owner` VARCHAR(255) NOT NULL,
    `expiration` BIGINT NOT NULL,
    PRIMARY KEY (`key`),
    KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `queue` VARCHAR(255) NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `attempts` SMALLINT UNSIGNED NOT NULL,
    `reserved_at` INT UNSIGNED NULL,
    `available_at` INT UNSIGNED NOT NULL,
    `created_at` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `job_batches` (
    `id` VARCHAR(255) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `total_jobs` INT NOT NULL,
    `pending_jobs` INT NOT NULL,
    `failed_jobs` INT NOT NULL,
    `failed_job_ids` LONGTEXT NOT NULL,
    `options` MEDIUMTEXT NULL,
    `cancelled_at` INT NULL,
    `created_at` INT NOT NULL,
    `finished_at` INT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `failed_jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` VARCHAR(255) NOT NULL,
    `connection` VARCHAR(255) NOT NULL,
    `queue` VARCHAR(255) NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `exception` LONGTEXT NOT NULL,
    `failed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
    KEY `failed_jobs_connection_queue_failed_at_index`
        (`connection`, `queue`, `failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `personal_access_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tokenable_type` VARCHAR(255) NOT NULL,
    `tokenable_id` BIGINT UNSIGNED NOT NULL,
    `name` TEXT NOT NULL,
    `token` VARCHAR(64) NOT NULL,
    `abilities` TEXT NULL,
    `last_used_at` TIMESTAMP NULL,
    `expires_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
    KEY `personal_access_tokens_tokenable_type_tokenable_id_index`
        (`tokenable_type`, `tokenable_id`),
    KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `external_identities` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `provider` VARCHAR(20) NOT NULL,
    `subject_hash` VARCHAR(64) NOT NULL,
    `identity_match_hash` VARCHAR(64) NOT NULL,
    `linked_at` TIMESTAMP NOT NULL,
    `last_authenticated_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `external_identities_public_id_unique` (`public_id`),
    UNIQUE KEY `external_identities_provider_subject_unique`
        (`provider`, `subject_hash`),
    UNIQUE KEY `external_identities_provider_match_unique`
        (`provider`, `identity_match_hash`),
    KEY `external_identities_user_id_foreign` (`user_id`),
    CONSTRAINT `external_identities_user_id_foreign`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `external_identities_provider_check`
        CHECK (`provider` IN ('thaid', 'provider_id'))
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

CREATE TABLE IF NOT EXISTS `organizations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) NOT NULL,
    `hcode` VARCHAR(20) NOT NULL,
    `name_th` VARCHAR(255) NOT NULL,
    `name_en` VARCHAR(255) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    `deleted_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `organizations_public_id_unique` (`public_id`),
    UNIQUE KEY `organizations_hcode_unique` (`hcode`),
    KEY `organizations_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `applications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    `base_url` VARCHAR(2048) NULL,
    `require_organization_match` TINYINT(1) NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    `deleted_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `applications_public_id_unique` (`public_id`),
    UNIQUE KEY `applications_slug_unique` (`slug`),
    KEY `applications_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `application_sso_configs` (
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

CREATE TABLE IF NOT EXISTS `application_api_keys` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) NOT NULL,
    `application_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `key_prefix` VARCHAR(16) NOT NULL,
    `key_hash` VARCHAR(64) NOT NULL,
    `last_used_at` TIMESTAMP NULL,
    `expires_at` TIMESTAMP NULL,
    `revoked_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `application_api_keys_public_id_unique` (`public_id`),
    UNIQUE KEY `application_api_keys_key_prefix_unique` (`key_prefix`),
    UNIQUE KEY `application_api_keys_key_hash_unique` (`key_hash`),
    KEY `application_api_keys_expires_at_index` (`expires_at`),
    KEY `application_api_keys_revoked_at_index` (`revoked_at`),
    KEY `application_api_keys_application_id_revoked_at_index`
        (`application_id`, `revoked_at`),
    CONSTRAINT `application_api_keys_application_id_foreign`
        FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `access_grants` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `application_id` BIGINT UNSIGNED NOT NULL,
    `organization_id` BIGINT UNSIGNED NULL,
    `role` VARCHAR(100) NOT NULL DEFAULT 'user',
    `permissions` JSON NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `valid_from` TIMESTAMP NULL,
    `valid_until` TIMESTAMP NULL,
    `revoked_at` TIMESTAMP NULL,
    `created_by` BIGINT UNSIGNED NULL,
    `revoked_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `access_grants_public_id_unique` (`public_id`),
    KEY `access_grants_is_active_index` (`is_active`),
    KEY `access_grants_valid_until_index` (`valid_until`),
    KEY `access_grants_revoked_at_index` (`revoked_at`),
    KEY `access_grants_lookup_index`
        (`user_id`, `application_id`, `organization_id`, `is_active`),
    KEY `access_grants_application_id_foreign` (`application_id`),
    KEY `access_grants_organization_id_foreign` (`organization_id`),
    KEY `access_grants_created_by_foreign` (`created_by`),
    KEY `access_grants_revoked_by_foreign` (`revoked_by`),
    CONSTRAINT `access_grants_user_id_foreign`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `access_grants_application_id_foreign`
        FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `access_grants_organization_id_foreign`
        FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`)
        ON DELETE RESTRICT,
    CONSTRAINT `access_grants_created_by_foreign`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL,
    CONSTRAINT `access_grants_revoked_by_foreign`
        FOREIGN KEY (`revoked_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `authentication_transactions` (
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

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) NOT NULL,
    `actor_user_id` BIGINT UNSIGNED NULL,
    `action` VARCHAR(120) NOT NULL,
    `auditable_type` VARCHAR(255) NULL,
    `auditable_id` BIGINT UNSIGNED NULL,
    `target_public_id` CHAR(36) NULL,
    `ip_hash` VARCHAR(64) NULL,
    `user_agent_hash` VARCHAR(64) NULL,
    `context` JSON NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `audit_logs_public_id_unique` (`public_id`),
    KEY `audit_logs_action_index` (`action`),
    KEY `audit_logs_created_at_index` (`created_at`),
    KEY `audit_logs_auditable_type_auditable_id_index`
        (`auditable_type`, `auditable_id`),
    KEY `audit_logs_actor_user_id_foreign` (`actor_user_id`),
    CONSTRAINT `audit_logs_actor_user_id_foreign`
        FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `migrations` (`migration`, `batch`) VALUES
    ('0001_01_01_000000_create_users_table', 1),
    ('0001_01_01_000001_create_cache_table', 1),
    ('0001_01_01_000002_create_jobs_table', 1),
    ('2026_07_27_063214_create_personal_access_tokens_table', 1),
    ('2026_07_27_063313_create_organizations_table', 1),
    ('2026_07_27_063314_create_applications_table', 1),
    ('2026_07_27_063315_create_application_api_keys_table', 1),
    ('2026_07_27_063316_create_access_grants_table', 1),
    ('2026_07_27_063317_create_audit_logs_table', 1),
    ('2026_07_27_063318_replace_super_admin_flag_with_system_role', 1),
    ('2026_07_27_063319_create_sso_subjects_table', 1),
    ('2026_07_27_063320_create_oauth_clients_table', 1),
    ('2026_07_27_063321_create_oauth_auth_codes_table', 1),
    ('2026_07_27_063322_create_oauth_access_tokens_table', 1),
    ('2026_07_27_063323_create_oauth_refresh_tokens_table', 1),
    ('2026_07_27_063324_add_provider_cid_hash_to_users_table', 1),
    ('2026_07_27_063325_create_external_identities_table', 1),
    ('2026_07_27_063326_create_application_sso_configs_table', 1),
    ('2026_07_27_063327_create_authentication_transactions_table', 1);
