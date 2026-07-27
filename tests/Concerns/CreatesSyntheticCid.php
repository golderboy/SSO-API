<?php

namespace Tests\Concerns;

trait CreatesSyntheticCid
{
    protected function syntheticCid(string $twelveDigitPrefix = '100000000000'): string
    {
        $sum = 0;

        for ($index = 0; $index < 12; $index++) {
            $sum += ((int) $twelveDigitPrefix[$index]) * (13 - $index);
        }

        return $twelveDigitPrefix.((11 - ($sum % 11)) % 10);
    }
}
