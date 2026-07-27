<?php

namespace App\Services;

use App\Models\User;
use LogicException;

class PersonnelIdentityService
{
    public function normalize(string $cid): string
    {
        return preg_replace('/\D+/', '', $cid) ?? '';
    }

    public function hash(string $cid): string
    {
        return hash_hmac(
            'sha256',
            $this->normalize($cid),
            $this->lookupKey('cid_lookup_key'),
        );
    }

    public function hashProviderCid(string $cid): string
    {
        return $this->hashProviderCidSha256(
            hash('sha256', $this->normalize($cid)),
        );
    }

    public function hashProviderCidSha256(string $providerCidSha256): string
    {
        $normalizedHash = strtolower(trim($providerCidSha256));

        if (
            strlen($normalizedHash) !== 64
            || ! ctype_xdigit($normalizedHash)
        ) {
            throw new LogicException(
                'Provider hash_cid must be a 64-character SHA-256 hex value.',
            );
        }

        return hash_hmac(
            'sha256',
            $normalizedHash,
            $this->lookupKey('provider_cid_lookup_key'),
        );
    }

    public function setCid(User $user, ?string $cid): void
    {
        if ($cid === null || $cid === '') {
            $user->cid_hash = null;
            $user->cid_encrypted = null;
            $user->provider_cid_hash = null;

            return;
        }

        $normalized = $this->normalize($cid);
        $user->cid_hash = $this->hash($normalized);
        $user->provider_cid_hash = $this->hashProviderCid($normalized);
        $user->cid_encrypted = $normalized;
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
