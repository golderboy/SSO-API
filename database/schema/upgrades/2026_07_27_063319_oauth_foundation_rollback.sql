-- Sobmoei SSO API - rollback downstream OAuth foundation
-- Revoke external traffic and roll back application code before running.

USE `sso`;

DELETE FROM `migrations`
WHERE `migration` IN (
    '2026_07_27_063319_create_sso_subjects_table',
    '2026_07_27_063320_create_oauth_clients_table',
    '2026_07_27_063321_create_oauth_auth_codes_table',
    '2026_07_27_063322_create_oauth_access_tokens_table',
    '2026_07_27_063323_create_oauth_refresh_tokens_table'
);

DROP TABLE IF EXISTS `oauth_refresh_tokens`;
DROP TABLE IF EXISTS `oauth_access_tokens`;
DROP TABLE IF EXISTS `oauth_auth_codes`;
DROP TABLE IF EXISTS `oauth_clients`;
DROP TABLE IF EXISTS `sso_subjects`;
