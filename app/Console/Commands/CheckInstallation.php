<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            'Independent lookup keys',
            $cidKey !== '' && $auditKey !== '' && ! hash_equals($cidKey, $auditKey),
            'CID_LOOKUP_KEY and AUDIT_HASH_KEY must be different',
        );
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
            'migrations',
        ];
        $missingTables = array_values(array_filter(
            $requiredTables,
            fn (string $table): bool => ! Schema::hasTable($table),
        ));

        $this->record(
            'Database schema',
            $missingTables === [],
            $missingTables === [] ? 'required tables found' : 'missing: '.implode(', ', $missingTables),
        );

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

        $this->checkProviderGroup(
            'ThaID',
            $requireProviders || (bool) config('services.thaid.enabled'),
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

        $this->checkProviderGroup(
            'MOPH ID',
            $requireProviders || (bool) config('services.provider_id.enabled'),
            [
                'health_client_id' => config('services.health_id.client_id'),
                'health_client_secret' => config('services.health_id.client_secret'),
                'health_redirect_uri' => config('services.health_id.redirect_uri'),
                'health_base_url' => config('services.health_id.base_url'),
                'provider_client_id' => config('services.provider_id.client_id'),
                'provider_secret_key' => config('services.provider_id.secret_key'),
                'provider_base_url' => config('services.provider_id.base_url'),
            ],
        );
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
}
