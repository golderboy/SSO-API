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
}
