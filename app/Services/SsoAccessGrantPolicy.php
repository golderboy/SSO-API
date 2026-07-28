<?php

namespace App\Services;

use App\Exceptions\BrokerRequestException;
use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SsoAccessGrantPolicy
{
    /**
     * @return Collection<int, AccessGrant>
     */
    public function eligible(
        User $user,
        Application $application,
        ?array $providerOrganizationHcodes = null,
    ): Collection {
        if (! $user->is_active || ! $application->is_active) {
            return new Collection;
        }

        $providerOrganizationHcodes = $this->validatedProviderHcodes(
            $providerOrganizationHcodes,
        );
        $grants = AccessGrant::query()
            ->effective()
            ->with('organization')
            ->where('user_id', $user->id)
            ->where('application_id', $application->id)
            ->when(
                $application->require_organization_match,
                fn (Builder $query): Builder => $query->whereNotNull(
                    'organization_id',
                ),
            )
            ->when(
                $providerOrganizationHcodes !== null,
                function (Builder $query) use (
                    $providerOrganizationHcodes,
                ): Builder {
                    if ($providerOrganizationHcodes === []) {
                        return $query->whereRaw('1 = 0');
                    }

                    return $query
                        ->whereNotNull('organization_id')
                        ->whereHas(
                            'organization',
                            fn (Builder $organization): Builder => $organization
                                ->whereIn(
                                    'hcode',
                                    $providerOrganizationHcodes,
                                ),
                        );
                },
            )
            ->where(function (Builder $query): void {
                $query->whereNull('organization_id')
                    ->orWhereHas(
                        'organization',
                        fn (Builder $organization): Builder => $organization
                            ->where('is_active', true),
                    );
            })
            ->orderBy('organization_id')
            ->orderBy('id')
            ->get();

        $duplicates = $grants
            ->groupBy(
                fn (AccessGrant $grant): string => $grant->organization_id
                    === null
                    ? 'global'
                    : (string) $grant->organization_id,
            )
            ->contains(fn (Collection $group): bool => $group->count() > 1);

        if ($duplicates) {
            throw new BrokerRequestException(
                'access_denied',
                'Conflicting effective access grants require administrator review.',
                403,
            );
        }

        return $grants;
    }

    /**
     * @param  array<mixed>|null  $hcodes
     * @return list<string>|null
     */
    private function validatedProviderHcodes(?array $hcodes): ?array
    {
        if ($hcodes === null) {
            return null;
        }

        $normalized = [];

        foreach ($hcodes as $hcode) {
            if (! is_string($hcode)) {
                throw new BrokerRequestException(
                    'access_denied',
                    'The provider organization set is invalid.',
                    403,
                );
            }

            $hcode = trim($hcode);

            if (
                preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $hcode) !== 1
            ) {
                throw new BrokerRequestException(
                    'access_denied',
                    'The provider organization set is invalid.',
                    403,
                );
            }

            $normalized[] = $hcode;
        }

        return array_values(array_unique($normalized));
    }
}
