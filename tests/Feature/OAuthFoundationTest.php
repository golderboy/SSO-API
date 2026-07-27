<?php

namespace Tests\Feature;

use App\Models\SsoSubject;
use App\Models\User;
use App\Providers\SsoPassportServiceProvider;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\Passport;
use Laravel\Sanctum\HasApiTokens;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use ReflectionProperty;
use Tests\TestCase;

class OAuthFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_downstream_oauth_authentication_are_isolated(): void
    {
        $userTraits = class_uses_recursive(User::class);
        $subjectTraits = class_uses_recursive(SsoSubject::class);

        $this->assertContains(
            HasApiTokens::class,
            $userTraits,
        );
        $this->assertNotContains(
            \Laravel\Passport\HasApiTokens::class,
            $userTraits,
        );
        $this->assertContains(
            \Laravel\Passport\HasApiTokens::class,
            $subjectTraits,
        );
        $this->assertTrue(is_a(
            SsoSubject::class,
            OAuthenticatable::class,
            true,
        ));
        $this->assertSame('users', config('auth.guards.web.provider'));
        $this->assertSame(
            'sso_subjects',
            config('auth.guards.sso_web.provider'),
        );
        $this->assertSame('passport', config('auth.guards.sso_api.driver'));
        $this->assertSame(
            'sso_subjects',
            config('auth.guards.sso_api.provider'),
        );
    }

    public function test_each_user_can_have_only_one_oauth_subject(): void
    {
        $user = User::factory()->create();
        $subject = SsoSubject::query()->create(['user_id' => $user->id]);

        $this->assertTrue($subject->user->is($user));
        $this->assertDatabaseCount('sso_subjects', 1);
        $this->assertTrue($user->fresh()->ssoSubject->is($subject));

        $this->expectException(QueryException::class);
        SsoSubject::query()->create(['user_id' => $user->id]);
    }

    public function test_passport_routes_match_public_call_contract(): void
    {
        $this->assertSame(
            '/authorize',
            route('passport.authorizations.authorize', [], false),
        );
        $this->assertSame('/token', route('passport.token', [], false));
        $this->assertFalse(Route::has('passport.device'));
        $this->assertFalse(Route::has('passport.clients.index'));
        $this->assertFalse(Passport::$deviceCodeGrantEnabled);
        $this->assertFalse(Passport::$implicitGrantEnabled);
        $this->assertFalse(Passport::$passwordGrantEnabled);
        $this->assertFalse(Passport::$registersJsonApiRoutes);
    }

    public function test_oauth_lifetimes_match_the_approved_contract(): void
    {
        $this->assertIntervalSeconds(
            Passport::tokensExpireIn(),
            30 * 60,
        );
        $this->assertIntervalSeconds(
            Passport::refreshTokensExpireIn(),
            30 * 60,
        );

        $provider = new class($this->app) extends SsoPassportServiceProvider
        {
            public function authCodeGrantForTesting(): AuthCodeGrant
            {
                return $this->buildAuthCodeGrant();
            }
        };
        $grant = $provider->authCodeGrantForTesting();
        $property = new ReflectionProperty($grant, 'authCodeTTL');

        $this->assertIntervalSeconds(
            $property->getValue($grant),
            5 * 60,
        );
    }

    private function assertIntervalSeconds(
        \DateInterval $interval,
        int $expectedSeconds,
    ): void {
        $origin = new DateTimeImmutable('@0');

        $this->assertSame(
            $expectedSeconds,
            $origin->add($interval)->getTimestamp(),
        );
    }
}
