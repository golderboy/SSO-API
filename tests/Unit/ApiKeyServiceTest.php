<?php

namespace Tests\Unit;

use App\Models\Application;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ApiKeyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_plain_text_key_is_returned_once_and_only_hash_is_stored(): void
    {
        $application = Application::factory()->create();
        $plainTextKey = str_repeat('k', 64);

        $issued = app(ApiKeyService::class)->issue(
            $application,
            'integration',
            $plainTextKey,
        );

        $this->assertSame($plainTextKey, $issued['plain_text_key']);
        $this->assertNotSame($plainTextKey, $issued['model']->key_hash);
        $this->assertSame(
            hash('sha256', $plainTextKey),
            $issued['model']->key_hash,
        );
        $this->assertTrue(
            app(ApiKeyService::class)->findUsable($plainTextKey)
                ->is($issued['model']),
        );
    }

    public function test_short_provided_key_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ApiKeyService::class)->issue(
            Application::factory()->create(),
            'weak',
            'too-short',
        );
    }
}
