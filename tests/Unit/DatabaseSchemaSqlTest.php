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
        ] as $migration) {
            $this->assertStringContainsString($migration, $sql);
        }
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
}
