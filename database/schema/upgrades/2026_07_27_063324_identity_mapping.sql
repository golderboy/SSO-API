-- Sobmoei SSO API - privacy-preserving external identity mapping
-- Run after 2026_07_27_063319_oauth_foundation.sql.
-- After import, run sso:backfill-provider-cid-hashes before enabling providers.

USE `sso`;

ALTER TABLE `users`
    ADD COLUMN `provider_cid_hash` VARCHAR(64) NULL AFTER `cid_hash`,
    ADD UNIQUE KEY `users_provider_cid_hash_unique` (`provider_cid_hash`);

CREATE TABLE `external_identities` (
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

INSERT IGNORE INTO `migrations` (`migration`, `batch`) VALUES
    ('2026_07_27_063324_add_provider_cid_hash_to_users_table', 1),
    ('2026_07_27_063325_create_external_identities_table', 1);
