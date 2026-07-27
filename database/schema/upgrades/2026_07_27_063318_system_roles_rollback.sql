-- Sobmoei SSO API - rollback for 2026_07_27_063318_system_roles.sql
-- Use only together with the previous application release.
-- SuperAdmin accounts intentionally lose administrative access after rollback.

USE `sso`;

ALTER TABLE `users`
    ADD COLUMN `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0
        AFTER `is_active`,
    ADD KEY `users_is_super_admin_index` (`is_super_admin`);

UPDATE `users`
SET `is_super_admin` = CASE
    WHEN `system_role` = 'admin' THEN 1
    ELSE 0
END;

ALTER TABLE `users`
    DROP CONSTRAINT `users_system_role_check`,
    DROP KEY `users_admin_slot_unique`,
    DROP KEY `users_system_role_index`,
    DROP COLUMN `admin_slot`,
    DROP COLUMN `system_role`;

DELETE FROM `migrations`
WHERE `migration` =
    '2026_07_27_063318_replace_super_admin_flag_with_system_role';
