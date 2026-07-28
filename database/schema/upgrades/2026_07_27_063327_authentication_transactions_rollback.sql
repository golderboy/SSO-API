-- Sobmoei SSO API - rollback browser authentication transactions

USE `sso`;

DELETE FROM `migrations`
WHERE `migration` =
    '2026_07_27_063327_create_authentication_transactions_table';

DROP TABLE `authentication_transactions`;
