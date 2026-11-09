<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\LateReturnPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaveLateReturnPolicyOverride
{
    /** @param array{scope_type:string,scope_id?:int|null,scope_reference?:string|null,per_day_amount:string,grace_days:int|string,cap_amount:string,count_return_day:bool|int|string} $data */
    public function handle(array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): LateReturnPolicy
    {
        $scopeType = $data['scope_type'];
        $scopeId = in_array($scopeType, ['product', 'institution'], true) ? (int) $data['scope_id'] : null;
        $scopeReference = $scopeType === 'contract' ? Str::upper(trim((string) $data['scope_reference'])) : null;
        $policyKey = match ($scopeType) {
            'product' => 'p:'.$scopeId,
            'institution' => 'i:'.$scopeId,
            'contract' => 'c:'.substr(hash('sha256', $scopeReference), 0, 30),
        };

        return DB::transaction(function () use ($data, $actor, $ipAddress, $userAgent, $scopeType, $scopeId, $scopeReference, $policyKey): LateReturnPolicy {
            $policy = LateReturnPolicy::query()->where('policy_key', $policyKey)->lockForUpdate()->first();
            $before = $policy?->only(['scope_type', 'scope_id', 'scope_reference', 'per_day_minor', 'grace_days', 'cap_minor', 'count_return_day']);
            $policy ??= new LateReturnPolicy(['policy_key' => $policyKey]);
            $policy->fill([
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'scope_reference' => $scopeReference,
                'per_day_minor' => self::toMinor($data['per_day_amount']),
                'grace_days' => (int) $data['grace_days'],
                'cap_minor' => self::toMinor($data['cap_amount']),
                'count_return_day' => (bool) $data['count_return_day'],
                'updated_by' => $actor->id,
            ])->save();
            $after = $policy->only(['scope_type', 'scope_id', 'scope_reference', 'per_day_minor', 'grace_days', 'cap_minor', 'count_return_day']);

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => $before === null ? 'finance.late_return_override_created' : 'finance.late_return_override_updated',
                'subject_type' => LateReturnPolicy::class,
                'subject_id' => $policy->id,
                'before_state' => $before,
                'after_state' => $after,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'reason' => 'A scoped late return fee rule was configured.',
                'occurred_at' => now(),
            ]);

            return $policy;
        }, attempts: 3);
    }

    private static function toMinor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
