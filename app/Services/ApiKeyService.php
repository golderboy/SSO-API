<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationApiKey;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ApiKeyService
{
    /**
     * @return array{model: ApplicationApiKey, plain_text_key: string}
     */
    public function issue(
        Application $application,
        string $name,
        ?string $providedKey = null,
        ?string $expiresAt = null,
    ): array {
        $plainTextKey = $providedKey ?? 'sso_'.Str::random(config('sso.api_key_length'));

        if (
            strlen($plainTextKey) < 32
            || strlen($plainTextKey) > 255
            || preg_match('/^[A-Za-z0-9._~-]+$/', $plainTextKey) !== 1
        ) {
            throw new InvalidArgumentException(
                'API key must be 32-255 URL-safe characters.',
            );
        }

        $hash = hash('sha256', $plainTextKey);

        if (ApplicationApiKey::query()->where('key_hash', $hash)->exists()) {
            throw new InvalidArgumentException('API key already exists.');
        }

        $key = $application->apiKeys()->create([
            'public_id' => (string) Str::uuid(),
            'name' => $name,
            'key_prefix' => substr($hash, 0, 16),
            'key_hash' => $hash,
            'expires_at' => $expiresAt,
        ]);

        return ['model' => $key, 'plain_text_key' => $plainTextKey];
    }

    public function findUsable(string $plainTextKey): ?ApplicationApiKey
    {
        $hash = hash('sha256', $plainTextKey);
        $key = ApplicationApiKey::query()
            ->with('application')
            ->where('key_prefix', substr($hash, 0, 16))
            ->first();

        if ($key === null || ! hash_equals($key->key_hash, $hash) || ! $key->isUsable()) {
            return null;
        }

        return $key;
    }
}
