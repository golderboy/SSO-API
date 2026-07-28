-- Sobmoei SSO API - rollback upstream authentication callback state

USE `sso`;

DELIMITER //

CREATE PROCEDURE `sso_assert_no_authenticating_transactions`()
BEGIN
    IF EXISTS (
        SELECT 1
        FROM `authentication_transactions`
        WHERE `status` = 'authenticating'
        LIMIT 1
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Cannot rollback while authentication callbacks are active';
    END IF;
END//

DELIMITER ;

CALL `sso_assert_no_authenticating_transactions`();
DROP PROCEDURE `sso_assert_no_authenticating_transactions`;

ALTER TABLE `authentication_transactions`
    DROP CONSTRAINT `authentication_transactions_status_check`,
    ADD CONSTRAINT `authentication_transactions_status_check`
        CHECK (`status` IN (
            'pending', 'provider_selected', 'organization_required',
            'approved', 'issuing', 'consumed', 'denied'
        ));

DELETE FROM `migrations`
WHERE `migration` =
    '2026_07_27_063328_add_authenticating_transaction_status';
