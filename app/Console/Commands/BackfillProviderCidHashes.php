<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PersonnelIdentityService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('sso:backfill-provider-cid-hashes {--dry-run : Validate without writing}')]
#[Description('Backfill privacy-preserving Provider ID CID lookup hashes')]
class BackfillProviderCidHashes extends Command
{
    public function handle(PersonnelIdentityService $identity): int
    {
        $processed = 0;
        $process = function () use ($identity, &$processed): void {
            User::withTrashed()
                ->whereNotNull('cid_encrypted')
                ->whereNull('provider_cid_hash')
                ->orderBy('id')
                ->chunkById(200, function ($users) use (
                    $identity,
                    &$processed,
                ): void {
                    foreach ($users as $user) {
                        $cid = $user->cid_encrypted;

                        if (! is_string($cid) || $cid === '') {
                            throw new \RuntimeException(
                                'An encrypted citizen identifier is invalid.',
                            );
                        }

                        $providerCidHash = $identity->hashProviderCid($cid);

                        if (! $this->option('dry-run')) {
                            $user->forceFill([
                                'provider_cid_hash' => $providerCidHash,
                            ])->saveQuietly();
                        }

                        $processed++;
                    }
                });
        };

        try {
            if ($this->option('dry-run')) {
                $process();
            } else {
                DB::transaction($process, 3);
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->error(
                'Provider CID hash backfill failed. No CID value was displayed.',
            );

            return self::FAILURE;
        }

        $mode = $this->option('dry-run') ? 'validated' : 'updated';
        $this->info(
            "Provider CID hash backfill {$mode} {$processed} user record(s). "
            .'No CID value was displayed.',
        );

        return self::SUCCESS;
    }
}
