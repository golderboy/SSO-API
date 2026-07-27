-- Sobmoei SSO API - deployed database upgrade
-- Adds explicit Admin / SuperAdmin / User roles.
-- Safe rule: the legacy database may contain at most one is_super_admin=1 row.

USE `sso`;

DELIMITER //
CREATE PROCEDURE `sso_assert_single_legacy_admin`()
BEGIN
    IF (SELECT COUNT(*) FROM `users` WHERE `is_super_admin` = 1) > 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'More than one legacy super administrator exists; aborting role migration';
    END IF;
END//
DELIMITER ;

CALL `sso_assert_single_legacy_admin`();
DROP PROCEDURE `sso_assert_single_legacy_admin`;

ALTER TABLE `users`
    ADD COLUMN `system_role` VARCHAR(20) NOT NULL DEFAULT 'user'
        AFTER `is_active`,
    ADD COLUMN `admin_slot` TINYINT UNSIGNED NULL
        AFTER `system_role`,
    ADD KEY `users_system_role_index` (`system_role`),
    ADD UNIQUE KEY `users_admin_slot_unique` (`admin_slot`);

UPDATE `users`
SET
    `system_role` = 'admin',
    `admin_slot` = 1
WHERE `is_super_admin` = 1;

ALTER TABLE `users`
    ADD CONSTRAINT `users_system_role_check`
        CHECK (
            (`system_role` = 'admin' AND `admin_slot` = 1)
            OR
            (
                `system_role` IN ('super_admin', 'user')
                AND `admin_slot` IS NULL
            )
        ),
    DROP KEY `users_is_super_admin_index`,
    DROP COLUMN `is_super_admin`;

INSERT IGNORE INTO `migrations` (`migration`, `batch`)
SELECT
    '2026_07_27_063318_replace_super_admin_flag_with_system_role',
    COALESCE(MAX(`batch`), 0) + 1
FROM `migrations`;
