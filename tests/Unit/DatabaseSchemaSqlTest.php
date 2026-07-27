<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DatabaseSchemaSqlTest extends TestCase
{
    public function test_mariadb_schema_contains_every_application_table_and_migration(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__, 2).'/database/schema/sso_mariadb.sql',
        );

        $this->assertIsString($sql);

        foreach ([
            'users',
            'organizations',
            'applications',
            'application_api_keys',
            'access_grants',
            'audit_logs',
            'personal_access_tokens',
            'sso_subjects',
            'oauth_clients',
            'oauth_auth_codes',
            'oauth_access_tokens',
            'oauth_refresh_tokens',
        ] as $table) {
            $this->assertStringContainsString(
                "CREATE TABLE IF NOT EXISTS `{$table}`",
                $sql,
            );
        }

        foreach ([
            '0001_01_01_000000_create_users_table',
            '0001_01_01_000001_create_cache_table',
            '0001_01_01_000002_create_jobs_table',
            '2026_07_27_063214_create_personal_access_tokens_table',
            '2026_07_27_063313_create_organizations_table',
            '2026_07_27_063314_create_applications_table',
            '2026_07_27_063315_create_application_api_keys_table',
            '2026_07_27_063316_create_access_grants_table',
            '2026_07_27_063317_create_audit_logs_table',
            '2026_07_27_063318_replace_super_admin_flag_with_system_role',
            '2026_07_27_063319_create_sso_subjects_table',
            '2026_07_27_063320_create_oauth_clients_table',
            '2026_07_27_063321_create_oauth_auth_codes_table',
            '2026_07_27_063322_create_oauth_access_tokens_table',
            '2026_07_27_063323_create_oauth_refresh_tokens_table',
        ] as $migration) {
            $this->assertStringContainsString($migration, $sql);
        }
    }

    public function test_mariadb_schema_enforces_explicit_roles_and_single_admin(): void
    {
        $sql = (string) file_get_contents(
            dirname(__DIR__, 2).'/database/schema/sso_mariadb.sql',
        );

        $this->assertStringContainsString('`system_role` VARCHAR(20)', $sql);
        $this->assertStringContainsString(
            'UNIQUE KEY `users_admin_slot_unique` (`admin_slot`)',
            $sql,
        );
        $this->assertStringContainsString(
            'CONSTRAINT `users_system_role_check`',
            $sql,
        );
        $this->assertStringNotContainsString('`is_super_admin`', $sql);
    }

    public function test_deployed_database_has_guarded_role_upgrade_and_rollback_sql(): void
    {
        $base = dirname(__DIR__, 2).'/database/schema/upgrades/';
        $upgrade = (string) file_get_contents(
            $base.'2026_07_27_063318_system_roles.sql',
        );
        $rollback = (string) file_get_contents(
            $base.'2026_07_27_063318_system_roles_rollback.sql',
        );

        $this->assertStringContainsString(
            'sso_assert_single_legacy_admin',
            $upgrade,
        );
        $this->assertStringContainsString("SIGNAL SQLSTATE '45000'", $upgrade);
        $this->assertStringContainsString(
            'DROP CONSTRAINT `users_system_role_check`',
            $rollback,
        );
        $this->assertStringContainsString(
            "WHEN `system_role` = 'admin' THEN 1",
            $rollback,
        );
    }

    public function test_mariadb_schema_contains_no_seed_personnel_or_plaintext_secret(): void
    {
        $sql = (string) file_get_contents(
            dirname(__DIR__, 2).'/database/schema/sso_mariadb.sql',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/INSERT\s+INTO\s+`?(users|application_api_keys)`?/i',
            $sql,
        );
        $this->assertStringNotContainsString('IDENTIFIED BY', $sql);
    }

    public function test_deployed_database_has_oauth_upgrade_and_rollback_sql(): void
    {
        $base = dirname(__DIR__, 2).'/database/schema/upgrades/';
        $upgrade = (string) file_get_contents(
            $base.'2026_07_27_063319_oauth_foundation.sql',
        );
        $rollback = (string) file_get_contents(
            $base.'2026_07_27_063319_oauth_foundation_rollback.sql',
        );

        foreach ([
            'sso_subjects',
            'oauth_clients',
            'oauth_auth_codes',
            'oauth_access_tokens',
            'oauth_refresh_tokens',
        ] as $table) {
            $this->assertStringContainsString(
                "CREATE TABLE IF NOT EXISTS `{$table}`",
                $upgrade,
            );
            $this->assertStringContainsString(
                "DROP TABLE IF EXISTS `{$table}`",
                $rollback,
            );
        }
    }
}
