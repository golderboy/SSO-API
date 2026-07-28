<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Throwable;

#[Signature('sso:check-installation {--providers : Require both ThaID and MOPH ID credentials}')]
#[Description('Validate runtime, database, secrets, and optional provider configuration')]
class CheckInstallation extends Command
{
    /**
     * @var array<int, array{string, bool, string}>
     */
    private array $checks = [];

    public function handle(): int
    {
        $this->checkRuntime();
        $this->checkApplication();
        $this->checkOAuth();
        $this->checkDatabase();
        $this->checkFilesystem();
        $this->checkProviders();

        $this->table(
            ['Status', 'Check', 'Detail'],
            array_map(
                fn (array $check): array => [
                    $check[1] ? 'OK' : 'FAIL',
                    $check[0],
                    $check[2],
                ],
                $this->checks,
            ),
        );

        if (collect($this->checks)->contains(fn (array $check): bool => ! $check[1])) {
            $this->error('Installation check failed. No secret value was displayed.');

            return self::FAILURE;
        }

        $this->info('Installation check passed. No secret value was displayed.');

        return self::SUCCESS;
    }

    private function checkRuntime(): void
    {
        $this->record(
            'PHP version',
            version_compare(PHP_VERSION, '8.3.0', '>='),
            PHP_VERSION.' (required >= 8.3.0)',
        );

        $requiredExtensions = [
            'ctype',
            'curl',
            'dom',
            'fileinfo',
            'filter',
            'hash',
            'intl',
            'json',
            'mbstring',
            'openssl',
            'pdo',
            'pdo_mysql',
            'session',
            'sodium',
            'tokenizer',
            'xml',
            'xmlwriter',
            'zip',
        ];
        $missing = array_values(array_filter(
            $requiredExtensions,
            fn (string $extension): bool => ! extension_loaded($extension),
        ));

        $this->record(
            'PHP extensions',
            $missing === [],
            $missing === [] ? 'all required extensions loaded' : 'missing: '.implode(', ', $missing),
        );
    }

    private function checkApplication(): void
    {
        $appKey = (string) config('app.key');
        $cidKey = (string) config('sso.cid_lookup_key');
        $providerCidKey = (string) config('sso.provider_cid_lookup_key');
        $externalSubjectKey = (string) config(
            'sso.external_subject_lookup_key',
        );
        $transactionKey = (string) config('sso.transaction_hash_key');
        $auditKey = (string) config('sso.audit_hash_key');
        $appUrl = (string) config('app.url');
        $urlHost = parse_url($appUrl, PHP_URL_HOST);

        $this->record(
            'APP_ENV',
            app()->environment(['production', 'testing']),
            app()->environment().' (must be production on the server)',
        );
        $decodedAppKey = str_starts_with($appKey, 'base64:')
            ? base64_decode(substr($appKey, 7), true)
            : $appKey;
        $appKeyIsValid = is_string($decodedAppKey)
            && Encrypter::supported($decodedAppKey, (string) config('app.cipher'));

        $this->record(
            'APP_KEY',
            $appKeyIsValid,
            $appKeyIsValid ? 'configured' : 'missing or invalid for the configured cipher',
        );
        $this->record(
            'APP_DEBUG',
            ! config('app.debug'),
            config('app.debug') ? 'must be false' : 'false',
        );
        $this->record(
            'APP_URL',
            parse_url($appUrl, PHP_URL_SCHEME) === 'https'
                && is_string($urlHost)
                && ! str_ends_with($urlHost, '.invalid'),
            'must be the final HTTPS URL',
        );
        $this->record(
            'CID_LOOKUP_KEY',
            strlen($cidKey) >= 32,
            strlen($cidKey) >= 32 ? 'configured' : 'must contain at least 32 characters',
        );
        $this->record(
            'AUDIT_HASH_KEY',
            strlen($auditKey) >= 32,
            strlen($auditKey) >= 32 ? 'configured' : 'must contain at least 32 characters',
        );
        $this->record(
            'PROVIDER_CID_LOOKUP_KEY',
            strlen($providerCidKey) >= 32,
            strlen($providerCidKey) >= 32
                ? 'configured'
                : 'must contain at least 32 characters',
        );
        $this->record(
            'TRANSACTION_HASH_KEY',
            strlen($transactionKey) >= 32,
            strlen($transactionKey) >= 32
                ? 'configured'
                : 'must contain at least 32 characters',
        );
        $this->record(
            'EXTERNAL_SUBJECT_LOOKUP_KEY',
            strlen($externalSubjectKey) >= 32,
            strlen($externalSubjectKey) >= 32
                ? 'configured'
                : 'must contain at least 32 characters',
        );
        $lookupKeys = [
            $cidKey,
            $providerCidKey,
            $externalSubjectKey,
            $transactionKey,
            $auditKey,
        ];
        $configuredLookupKeys = array_filter(
            $lookupKeys,
            fn (string $key): bool => $key !== '',
        );
        $this->record(
            'Independent lookup keys',
            count($configuredLookupKeys) === count($lookupKeys)
                && count(array_unique($configuredLookupKeys))
                    === count($lookupKeys),
            'all lookup and audit keys must be configured and different',
        );
        $this->record(
            'Session lifetime',
            (int) config('session.lifetime') === 30,
            'must be exactly 30 minutes',
        );
        $this->record(
            'Admin token lifetime',
            (int) config('sanctum.expiration') === 30,
            'must be exactly 30 minutes',
        );
    }

    private function checkOAuth(): void
    {
        $expectedTtls = [
            'authorization_code_ttl_minutes' => 5,
            'access_token_ttl_minutes' => 30,
            'refresh_token_ttl_minutes' => 30,
            'transaction_ttl_minutes' => 5,
        ];

        foreach ($expectedTtls as $key => $expected) {
            $actual = config("sso.oauth.{$key}");

            $this->record(
                "OAuth {$key}",
                $actual === $expected,
                "must be exactly {$expected} minutes",
            );
        }

        if (! app()->environment('production')) {
            return;
        }

        foreach (['private', 'public'] as $type) {
            $inlineKey = config("passport.{$type}_key");
            $keyAvailable = is_string($inlineKey) && trim($inlineKey) !== '';

            if (! $keyAvailable) {
                $keyAvailable = is_readable(
                    Passport::keyPath("oauth-{$type}.key"),
                );
            }

            $this->record(
                "OAuth {$type} key",
                $keyAvailable,
                $keyAvailable ? 'configured and readable' : 'missing or unreadable',
            );
        }
    }

    private function checkDatabase(): void
    {
        try {
            DB::select('SELECT 1');
            $connected = true;
            $detail = DB::connection()->getDriverName();
        } catch (Throwable $exception) {
            $connected = false;
            $detail = 'connection failed: '.$exception->getCode();
        }

        $this->record('Database connection', $connected, $detail);

        if (! $connected) {
            return;
        }

        $requiredTables = [
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
            'external_identities',
            'application_sso_configs',
            'authentication_transactions',
            'migrations',
        ];
        $missingTables = array_values(array_filter(
            $requiredTables,
            fn (string $table): bool => ! Schema::hasTable($table),
        ));
        $hasProviderCidHash = Schema::hasTable('users')
            && Schema::hasColumn('users', 'provider_cid_hash');

        $this->record(
            'Database schema',
            $missingTables === [] && $hasProviderCidHash,
            match (true) {
                $missingTables !== [] => 'missing: '.implode(', ', $missingTables),
                ! $hasProviderCidHash => 'missing: users.provider_cid_hash',
                default => 'required tables and columns found',
            },
        );

        if ($missingTables === [] && $hasProviderCidHash) {
            $missingProviderHashes = DB::table('users')
                ->whereNotNull('cid_hash')
                ->whereNull('provider_cid_hash')
                ->count();
            $this->record(
                'Provider CID hash backfill',
                $missingProviderHashes === 0,
                $missingProviderHashes === 0
                    ? 'complete'
                    : "{$missingProviderHashes} user record(s) require backfill",
            );
        }

        if (app()->environment('production')) {
            $connectionName = DB::getDefaultConnection();
            $databasePassword = (string) config(
                "database.connections.{$connectionName}.password",
            );

            $this->record(
                'Production database driver',
                in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true),
                'must be mariadb or mysql',
            );
            $this->record(
                'Production database password',
                $databasePassword !== '',
                $databasePassword === '' ? 'not configured' : 'configured',
            );
        }
    }

    private function checkFilesystem(): void
    {
        foreach (['storage', 'bootstrap/cache'] as $path) {
            $this->record(
                "Writable {$path}",
                is_writable(base_path($path)),
                is_writable(base_path($path)) ? 'writable' : 'not writable',
            );
        }
    }

    private function checkProviders(): void
    {
        $requireProviders = (bool) $this->option('providers');
        $requireThaId = $requireProviders
            || (bool) config('services.thaid.enabled');

        $this->checkProviderGroup(
            'ThaID',
            $requireThaId,
            [
                'client_id' => config('services.thaid.client_id'),
                'client_secret' => config('services.thaid.client_secret'),
                'redirect_uri' => config('services.thaid.redirect_uri'),
                'issuer' => config('services.thaid.issuer'),
                'authorization_url' => config('services.thaid.authorization_url'),
                'token_url' => config('services.thaid.token_url'),
                'introspection_url' => config('services.thaid.introspection_url'),
                'revocation_url' => config('services.thaid.revocation_url'),
                'discovery_url' => config('services.thaid.discovery_url'),
            ],
        );

        if ($requireThaId) {
            $expectedCallback = rtrim(
                (string) config('app.url'),
                '/',
            ).'/sso/callback/thaid';
            $actualCallback = config('services.thaid.redirect_uri');

            $this->record(
                'ThaID callback URI',
                is_string($actualCallback)
                    && hash_equals($expectedCallback, $actualCallback),
                'must exactly match APP_URL/sso/callback/thaid',
            );
            $this->record(
                'ThaID validation settings',
                $this->integerInRange(
                    config('services.thaid.clock_skew_seconds'),
                    0,
                    300,
                )
                    && $this->integerInRange(
                        config('services.thaid.discovery_cache_seconds'),
                        60,
                        3600,
                    )
                    && $this->integerInRange(
                        config('services.thaid.jwks_cache_seconds'),
                        60,
                        3600,
                    ),
                'clock skew must be 0-300 seconds; cache TTLs must be 60-3600 seconds',
            );
        }

        $requireMophId = $requireProviders
            || (bool) config('services.moph_id.enabled');

        $this->checkProviderGroup(
            'MOPH ID',
            $requireMophId,
            [
                'health_client_id' => config('services.moph_id.health_id.client_id'),
                'health_client_secret' => config('services.moph_id.health_id.client_secret'),
                'health_redirect_uri' => config('services.moph_id.health_id.redirect_uri'),
                'health_base_url' => config('services.moph_id.health_id.base_url'),
                'provider_client_id' => config('services.moph_id.provider_id.client_id'),
                'provider_secret_key' => config('services.moph_id.provider_id.secret_key'),
                'provider_base_url' => config('services.moph_id.provider_id.base_url'),
            ],
        );

        if ($requireMophId) {
            $expectedCallback = rtrim(
                (string) config('app.url'),
                '/',
            ).'/sso/callback/provider-id';
            $actualCallback = config(
                'services.moph_id.health_id.redirect_uri',
            );

            $this->record(
                'MOPH ID callback URI',
                is_string($actualCallback)
                    && hash_equals($expectedCallback, $actualCallback),
                'must exactly match APP_URL/sso/callback/provider-id',
            );
            $this->record(
                'MOPH ID validation settings',
                $this->integerInRange(
                    config('services.moph_id.clock_skew_seconds'),
                    0,
                    300,
                )
                    && $this->integerInRange(
                        config(
                            'services.moph_id.public_key_cache_seconds',
                        ),
                        60,
                        3600,
                    ),
                'clock skew must be 0-300 seconds; public key cache TTL must be 60-3600 seconds',
            );
            $paths = [
                config(
                    'services.moph_id.health_id.authorization_path',
                ),
                config('services.moph_id.health_id.token_path'),
                config('services.moph_id.provider_id.token_path'),
                config('services.moph_id.provider_id.profile_path'),
                config(
                    'services.moph_id.provider_id.public_key_path',
                ),
            ];
            $this->record(
                'MOPH ID endpoint paths',
                collect($paths)->every(
                    fn (mixed $path): bool => is_string($path)
                        && preg_match(
                            '#^/[A-Za-z0-9/_-]+$#D',
                            $path,
                        ) === 1
                        && ! str_contains($path, '//')
                        && ! str_contains($path, '/./')
                        && ! str_contains($path, '/../'),
                ),
                'must be absolute provider paths without query, fragment, or dot segments',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function checkProviderGroup(string $name, bool $required, array $values): void
    {
        if (! $required) {
            $this->record("{$name} credentials", true, 'disabled; validation skipped');

            return;
        }

        $missing = array_keys(array_filter(
            $values,
            fn (mixed $value): bool => ! is_string($value) || trim($value) === '',
        ));
        $urlValues = array_filter(
            $values,
            fn (mixed $value, string $key): bool => str_contains($key, 'url')
                || str_contains($key, 'uri')
                || str_contains($key, 'issuer'),
            ARRAY_FILTER_USE_BOTH,
        );
        $invalidUrls = array_keys(array_filter(
            $urlValues,
            fn (mixed $value): bool => ! is_string($value)
                || parse_url($value, PHP_URL_SCHEME) !== 'https',
        ));

        $valid = $missing === [] && $invalidUrls === [];
        $detail = $valid
            ? 'configured'
            : implode('; ', array_filter([
                $missing === [] ? null : 'missing: '.implode(', ', $missing),
                $invalidUrls === [] ? null : 'non-HTTPS URL: '.implode(', ', $invalidUrls),
            ]));

        $this->record("{$name} credentials", $valid, $detail);
    }

    private function record(string $name, bool $passed, string $detail): void
    {
        $this->checks[] = [$name, $passed, $detail];
    }

    private function integerInRange(
        mixed $value,
        int $minimum,
        int $maximum,
    ): bool {
        return is_int($value)
            && $value >= $minimum
            && $value <= $maximum;
    }
}
