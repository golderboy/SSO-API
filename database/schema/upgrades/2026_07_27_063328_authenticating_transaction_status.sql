-- Sobmoei SSO API - add atomic upstream authentication callback state

USE `sso`;

ALTER TABLE `authentication_transactions`
    DROP CONSTRAINT `authentication_transactions_status_check`,
    ADD CONSTRAINT `authentication_transactions_status_check`
        CHECK (`status` IN (
            'pending', 'provider_selected', 'authenticating',
            'organization_required', 'approved', 'issuing', 'consumed',
            'denied'
        ));

INSERT IGNORE INTO `migrations` (`migration`, `batch`) VALUES
    ('2026_07_27_063328_add_authenticating_transaction_status', 1);
