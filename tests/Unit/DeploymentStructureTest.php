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
}
