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
        $key = (string) config('sso.cid_lookup_key');

        if (strlen($key) < 32) {
            throw new LogicException('CID_LOOKUP_KEY must contain at least 32 characters.');
        }

        return hash_hmac('sha256', $this->normalize($cid), $key);
    }

    public function setCid(User $user, ?string $cid): void
    {
        if ($cid === null || $cid === '') {
            $user->cid_hash = null;
            $user->cid_encrypted = null;

            return;
        }

        $normalized = $this->normalize($cid);
        $user->cid_hash = $this->hash($normalized);
        $user->cid_encrypted = $normalized;
    }
}
