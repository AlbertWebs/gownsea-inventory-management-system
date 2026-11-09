<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\LateReturnPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteLateReturnPolicyOverride
{
    public function handle(LateReturnPolicy $policy, User $actor, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        if ($policy->scope_type === 'default') {
            throw ValidationException::withMessages(['policy' => 'The default late return policy cannot be removed.']);
        }

        DB::transaction(function () use ($policy, $actor, $ipAddress, $userAgent): void {
            $policy = LateReturnPolicy::query()->lockForUpdate()->findOrFail($policy->id);
            $before = $policy->only(['scope_type', 'scope_id', 'scope_reference', 'per_day_minor', 'grace_days', 'cap_minor', 'count_return_day']);
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'finance.late_return_override_deleted',
                'subject_type' => LateReturnPolicy::class,
                'subject_id' => $policy->id,
                'before_state' => $before,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'reason' => 'A scoped late return fee rule was removed.',
                'occurred_at' => now(),
            ]);
            $policy->delete();
        }, attempts: 3);
    }
}
