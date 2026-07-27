<?php

namespace App\Services;

use App\Data\VerifiedExternalIdentity;
use App\Enums\IdentityProvider;
use App\Exceptions\IdentityResolutionException;
use App\Models\ExternalIdentity;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class IdentityLinkService
{
    public function __construct(
        private readonly PersonnelIdentityService $personnelIdentity,
    ) {}

    public function resolve(VerifiedExternalIdentity $identity): User
    {
        $subjectHash = $this->subjectHash(
            $identity->provider,
            $identity->subject,
        );
        $identityMatchHash = $this->identityMatchHash($identity);

        try {
            return DB::transaction(function () use (
                $identity,
                $identityMatchHash,
                $subjectHash,
            ): User {
                $externalIdentity = ExternalIdentity::query()
                    ->where('provider', $identity->provider->value)
                    ->where('subject_hash', $subjectHash)
                    ->lockForUpdate()
                    ->first();

                if ($externalIdentity !== null) {
                    return $this->validateExistingLink(
                        $externalIdentity,
                        $identity,
                        $identityMatchHash,
                    );
                }

                $matchColumn = $identity->provider === IdentityProvider::ThaId
                    ? 'cid_hash'
                    : 'provider_cid_hash';
                $user = User::query()
                    ->where($matchColumn, $identityMatchHash)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if ($user === null) {
                    throw new IdentityResolutionException(
                        'identity_not_authorized',
                    );
                }

                ExternalIdentity::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'provider' => $identity->provider,
                    'subject_hash' => $subjectHash,
                    'identity_match_hash' => $identityMatchHash,
                    'linked_at' => now(),
                    'last_authenticated_at' => now(),
                ]);

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $externalIdentity = ExternalIdentity::query()
                ->where('provider', $identity->provider->value)
                ->where('subject_hash', $subjectHash)
                ->first();

            if ($externalIdentity === null) {
                throw new IdentityResolutionException(
                    'identity_link_conflict',
                );
            }

            return DB::transaction(
                fn (): User => $this->validateExistingLink(
                    $externalIdentity,
                    $identity,
                    $identityMatchHash,
                ),
                3,
            );
        }
    }

    private function subjectHash(
        IdentityProvider $provider,
        string $subject,
    ): string {
        $key = $this->lookupKey('external_subject_lookup_key');

        return hash_hmac(
            'sha256',
            $provider->value."\0".$subject,
            $key,
        );
    }

    private function identityMatchHash(
        VerifiedExternalIdentity $identity,
    ): string {
        return match ($identity->provider) {
            IdentityProvider::ThaId => $this->personnelIdentity->hash(
                $identity->identityMatchValue,
            ),
            IdentityProvider::ProviderId => $this->personnelIdentity
                ->hashProviderCidSha256($identity->identityMatchValue),
        };
    }

    private function validateExistingLink(
        ExternalIdentity $externalIdentity,
        VerifiedExternalIdentity $identity,
        string $identityMatchHash,
    ): User {
        $user = $externalIdentity->user()->withTrashed()->first();
        $matchColumn = $identity->provider === IdentityProvider::ThaId
            ? 'cid_hash'
            : 'provider_cid_hash';
        $storedUserMatch = $user?->{$matchColumn};

        if (
            $user === null
            || $user->trashed()
            || ! $user->is_active
            || ! is_string($storedUserMatch)
            || ! hash_equals($storedUserMatch, $identityMatchHash)
            || ! hash_equals(
                $externalIdentity->identity_match_hash,
                $identityMatchHash,
            )
        ) {
            throw new IdentityResolutionException('identity_link_mismatch');
        }

        $externalIdentity->forceFill([
            'last_authenticated_at' => now(),
        ])->save();

        return $user;
    }

    private function lookupKey(string $name): string
    {
        $key = config("sso.{$name}");

        if (! is_string($key) || strlen($key) < 32) {
            throw new LogicException(
                strtoupper($name).' must contain at least 32 characters.',
            );
        }

        return $key;
    }
}
