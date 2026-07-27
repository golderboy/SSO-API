<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LogicException;

class AuditLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(
        string $action,
        ?User $actor = null,
        ?Model $target = null,
        array $context = [],
    ): AuditLog {
        $key = (string) (config('sso.audit_hash_key') ?: config('sso.cid_lookup_key'));

        if (strlen($key) < 32) {
            throw new LogicException('AUDIT_HASH_KEY must contain at least 32 characters.');
        }

        return AuditLog::query()->create([
            'public_id' => (string) Str::uuid(),
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => $target?->getMorphClass(),
            'auditable_id' => $target?->getKey(),
            'target_public_id' => $target?->getAttribute('public_id'),
            'ip_hash' => $this->hashNullable($this->request->ip(), $key),
            'user_agent_hash' => $this->hashNullable($this->request->userAgent(), $key),
            'context' => $context === [] ? null : $context,
        ]);
    }

    private function hashNullable(?string $value, string $key): ?string
    {
        return $value === null ? null : hash_hmac('sha256', $value, $key);
    }
}
