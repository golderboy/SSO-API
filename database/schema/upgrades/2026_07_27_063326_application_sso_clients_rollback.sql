-- Sobmoei SSO API - rollback application OAuth client bindings
-- Revoke affected OAuth clients before running.

USE `sso`;

DELETE FROM `migrations`
WHERE `migration` = '2026_07_27_063326_create_application_sso_configs_table';

DROP TABLE `application_sso_configs`;
