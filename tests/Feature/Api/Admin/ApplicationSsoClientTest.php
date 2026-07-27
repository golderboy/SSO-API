<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Client;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationSsoClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_exact_authorization_code_client_without_refresh_grant(): void
    {
        $application = Application::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->create(), ['admin']);

        $response = $this->postJson($this->endpoint($application), [
            'redirect_uris' => [
                'https://sobmoeiservice.moph.go.th/testsso/callback.php',
            ],
            'allowed_providers' => ['thaid', 'provider_id'],
        ])->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.allowed_providers.0', 'thaid')
            ->assertJsonPath('data.allowed_providers.1', 'provider_id');

        $clientId = $response->json('data.client_id');
        $plainSecret = $response->json('data.client_secret');
        $client = Client::query()->findOrFail($clientId);

        $this->assertIsString($plainSecret);
        $this->assertTrue(Hash::check(
            $plainSecret,
            $client->getRawOriginal('secret'),
        ));
        $this->assertSame(['authorization_code'], $client->grant_types);
        $this->assertSame('sso_subjects', $client->provider);
        $this->assertSame(
            ['https://sobmoeiservice.moph.go.th/testsso/callback.php'],
            $client->redirect_uris,
        );

        $this->getJson($this->endpoint($application))
            ->assertOk()
            ->assertJsonMissingPath('data.client_secret');
    }

    public function test_admin_rotates_secret_and_revokes_client(): void
    {
        $application = Application::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->create(), ['admin']);
        $create = $this->postJson($this->endpoint($application), [
            'redirect_uris' => ['https://app.example.test/callback'],
            'allowed_providers' => ['thaid'],
        ])->assertCreated();
        $oldSecret = $create->json('data.client_secret');
        $clientId = $create->json('data.client_id');

        $newSecret = $this->postJson(
            $this->endpoint($application).'/rotate',
        )->assertOk()->json('data.client_secret');

        $this->assertNotSame($oldSecret, $newSecret);
        $this->assertTrue(Hash::check(
            $newSecret,
            Client::query()->findOrFail($clientId)->getRawOriginal('secret'),
        ));

        $this->deleteJson($this->endpoint($application))->assertNoContent();
        $this->assertTrue(Client::query()->findOrFail($clientId)->revoked);
        $this->assertDatabaseCount('application_sso_configs', 0);
    }

    public function test_super_admin_cannot_mutate_sso_client_configuration(): void
    {
        $application = Application::factory()->create();
        Sanctum::actingAs(User::factory()->superAdmin()->create(), ['admin']);

        $this->postJson($this->endpoint($application), [
            'redirect_uris' => ['https://app.example.test/callback'],
            'allowed_providers' => ['thaid'],
        ])->assertForbidden();
        $this->postJson($this->endpoint($application).'/rotate')
            ->assertForbidden();
        $this->deleteJson($this->endpoint($application))->assertForbidden();
    }

    public function test_callback_must_be_https_without_fragment_or_user_info(): void
    {
        $application = Application::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->create(), ['admin']);

        foreach ([
            'http://app.example.test/callback',
            'https://user@app.example.test/callback',
            'https://app.example.test/callback#fragment',
        ] as $redirectUri) {
            $this->postJson($this->endpoint($application), [
                'redirect_uris' => [$redirectUri],
                'allowed_providers' => ['thaid'],
            ])->assertUnprocessable()
                ->assertJsonValidationErrors('redirect_uris.0');
        }
    }

    private function endpoint(Application $application): string
    {
        return "/api/v1/admin/applications/{$application->public_id}/sso-client";
    }
}
