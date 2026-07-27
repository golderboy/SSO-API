<?php

namespace App\Services;

use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AccessDecisionService
{
    public function __construct(private readonly PersonnelIdentityService $identity) {}

    /**
     * @return array{allowed: bool, reason: string, user?: User, grant?: AccessGrant}
     */
    public function decide(
        Application $application,
        string $cid,
        ?string $organizationHcode,
    ): array {
        $user = User::query()
            ->where('cid_hash', $this->identity->hash($cid))
            ->where('is_active', true)
            ->first();

        if ($user === null) {
            return ['allowed' => false, 'reason' => 'identity_not_authorized'];
        }

        $organization = null;

        if ($organizationHcode !== null) {
            $organization = Organization::query()
                ->where('hcode', $organizationHcode)
                ->where('is_active', true)
                ->first();

            if ($organization === null) {
                return ['allowed' => false, 'reason' => 'organization_not_authorized'];
            }
        }

        if ($application->require_organization_match && $organization === null) {
            return ['allowed' => false, 'reason' => 'organization_required'];
        }

        $query = AccessGrant::query()
            ->effective()
            ->with('organization')
            ->where('user_id', $user->id)
            ->where('application_id', $application->id);

        if ($organization !== null) {
            $query->where(fn (Builder $builder) => $builder
                ->where('organization_id', $organization->id)
                ->when(
                    ! $application->require_organization_match,
                    fn (Builder $optional) => $optional->orWhereNull('organization_id'),
                ));
        } else {
            $query->whereNull('organization_id');
        }

        $grant = $query->first();

        if ($grant === null) {
            return ['allowed' => false, 'reason' => 'access_grant_not_found'];
        }

        return [
            'allowed' => true,
            'reason' => 'authorized',
            'user' => $user,
            'grant' => $grant,
        ];
    }
}
