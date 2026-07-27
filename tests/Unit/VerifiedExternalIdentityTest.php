<?php

namespace Tests\Unit;

use App\Data\VerifiedExternalIdentity;
use App\Enums\IdentityProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class VerifiedExternalIdentityTest extends TestCase
{
    public function test_thaid_requires_a_valid_thai_citizen_id(): void
    {
        $identity = VerifiedExternalIdentity::thaId(
            'subject',
            '1000000000009',
        );

        $this->assertSame(IdentityProvider::ThaId, $identity->provider);
        $this->assertSame('1000000000009', $identity->identityMatchValue);
    }

    public function test_thaid_rejects_invalid_check_digit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VerifiedExternalIdentity::thaId('subject', '1000000000000');
    }

    public function test_thaid_rejects_formatted_or_non_digit_provider_claim(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VerifiedExternalIdentity::thaId('subject', '1-0000-00000-00-9');
    }

    public function test_provider_id_normalizes_verified_hash_cid(): void
    {
        $hash = strtoupper(hash('sha256', '1000000000009'));
        $identity = VerifiedExternalIdentity::providerId('account-id', $hash);

        $this->assertSame(IdentityProvider::ProviderId, $identity->provider);
        $this->assertSame(strtolower($hash), $identity->identityMatchValue);
    }

    public function test_provider_id_rejects_non_sha256_hash_cid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VerifiedExternalIdentity::providerId('account-id', 'not-a-hash');
    }
}
