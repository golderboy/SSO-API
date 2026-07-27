-- Sobmoei SSO API - rollback privacy-preserving identity mapping
-- Disable both upstream providers before running.

USE `sso`;

DELETE FROM `migrations`
WHERE `migration` IN (
    '2026_07_27_063324_add_provider_cid_hash_to_users_table',
    '2026_07_27_063325_create_external_identities_table'
);

DROP TABLE `external_identities`;

ALTER TABLE `users`
    DROP KEY `users_provider_cid_hash_unique`,
    DROP COLUMN `provider_cid_hash`;
