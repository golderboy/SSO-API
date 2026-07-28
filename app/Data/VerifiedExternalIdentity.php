<?php

namespace App\Data;

use App\Enums\IdentityProvider;
use InvalidArgumentException;

final readonly class VerifiedExternalIdentity
{
    private function __construct(
        public IdentityProvider $provider,
        public string $subject,
        public string $identityMatchValue,
        /** @var list<string> */
        public array $organizationHcodes,
    ) {}

    public static function thaId(string $subject, string $pid): self
    {
        $normalizedPid = trim($pid);

        if (! self::isValidThaiCitizenId($normalizedPid)) {
            throw new InvalidArgumentException(
                'ThaID returned an invalid citizen identifier.',
            );
        }

        return new self(
            IdentityProvider::ThaId,
            self::validSubject($subject),
            $normalizedPid,
            [],
        );
    }

    public static function providerId(
        string $accountId,
        string $providerCidSha256,
        array $organizationHcodes = [],
    ): self {
        $normalizedHash = strtolower(trim($providerCidSha256));

        if (
            strlen($normalizedHash) !== 64
            || ! ctype_xdigit($normalizedHash)
        ) {
            throw new InvalidArgumentException(
                'Provider ID returned an invalid hash_cid.',
            );
        }

        return new self(
            IdentityProvider::ProviderId,
            self::validSubject($accountId),
            $normalizedHash,
            self::validOrganizationHcodes($organizationHcodes),
        );
    }

    /**
     * @param  array<mixed>  $organizationHcodes
     * @return list<string>
     */
    private static function validOrganizationHcodes(
        array $organizationHcodes,
    ): array {
        $normalized = [];

        foreach ($organizationHcodes as $hcode) {
            if (! is_string($hcode)) {
                throw new InvalidArgumentException(
                    'Provider ID returned an invalid organization hcode.',
                );
            }

            $hcode = trim($hcode);

            if (
                preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $hcode) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Provider ID returned an invalid organization hcode.',
                );
            }

            $normalized[] = $hcode;
        }

        return array_values(array_unique($normalized));
    }

    private static function validSubject(string $subject): string
    {
        $subject = trim($subject);

        if (
            $subject === ''
            || strlen($subject) > 255
            || preg_match('//u', $subject) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $subject) === 1
        ) {
            throw new InvalidArgumentException(
                'The external subject is invalid.',
            );
        }

        return $subject;
    }

    private static function isValidThaiCitizenId(string $cid): bool
    {
        if (strlen($cid) !== 13 || ! ctype_digit($cid)) {
            return false;
        }

        $sum = 0;

        for ($index = 0; $index < 12; $index++) {
            $sum += ((int) $cid[$index]) * (13 - $index);
        }

        return ((11 - ($sum % 11)) % 10) === (int) $cid[12];
    }
}
