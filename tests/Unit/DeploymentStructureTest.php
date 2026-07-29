<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DeploymentStructureTest extends TestCase
{
    public function test_laravel_view_directory_is_tracked_for_production_optimization(): void
    {
        $viewsPath = dirname(__DIR__, 2).'/resources/views';

        $this->assertDirectoryExists($viewsPath);
        $this->assertFileExists($viewsPath.'/.gitkeep');
    }

    public function test_almalinux_and_windows_commands_use_the_correct_shells(): void
    {
        $guide = (string) file_get_contents(
            dirname(__DIR__, 2).'/docs/ALMALINUX_9_INSTALLATION.md',
        );

        $this->assertStringNotContainsString('apachectl', $guide);
        $this->assertStringContainsString('sudo httpd -S', $guide);
        $this->assertStringContainsString('sudo httpd -t', $guide);
        $this->assertStringContainsString('Windows PC ด้วย PowerShell', $guide);
        $this->assertStringContainsString('curl.exe', $guide);
    }

    public function test_passport_key_preparation_never_rotates_existing_keys(): void
    {
        $script = (string) file_get_contents(
            dirname(__DIR__, 2)
                .'/scripts/prepare-almalinux-passport-keys.sh',
        );

        $this->assertStringContainsString(
            'Only one Passport signing key exists.',
            $script,
        );
        $this->assertStringContainsString(
            'exists but is empty.',
            $script,
        );
        $this->assertStringContainsString(
            'passport:keys',
            $script,
        );
        $autoloadPosition = strpos($script, '/vendor/autoload.php');
        $bootstrapPosition = strpos($script, '/bootstrap/app.php');

        $this->assertNotFalse($autoloadPosition);
        $this->assertNotFalse($bootstrapPosition);
        $this->assertLessThan($bootstrapPosition, $autoloadPosition);
        $this->assertStringNotContainsString(
            'passport:keys --force',
            $script,
        );
    }

    public function test_testsso_setup_rejects_super_admin_before_configuration_changes(): void
    {
        $script = (string) file_get_contents(
            dirname(__DIR__, 2).'/scripts/setup-testsso-client.sh',
        );
        $roleCheckPosition = strpos(
            $script,
            'Authenticated account has role ${admin_role}',
        );
        $applicationListPosition = strpos(
            $script,
            '${API_BASE_URL}/admin/applications?per_page=100',
        );

        $this->assertNotFalse($roleCheckPosition);
        $this->assertNotFalse($applicationListPosition);
        $this->assertLessThan(
            $applicationListPosition,
            $roleCheckPosition,
        );
        $this->assertStringContainsString(
            'SuperAdmin can manage access grants but cannot change applications or OAuth clients.',
            $script,
        );
    }
}
