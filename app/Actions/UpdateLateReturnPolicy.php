<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\LateReturnPolicy as LateReturnPolicyModel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateLateReturnPolicy
{
    /** @param array{per_day_amount:string,grace_days:int|string,cap_amount:string,count_return_day:bool|int|string} $data */
    public function handle(array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): LateReturnPolicyModel
    {
        return DB::transaction(function () use ($data, $actor, $ipAddress, $userAgent): LateReturnPolicyModel {
            $policy = LateReturnPolicyModel::query()->where('policy_key', 'default')->lockForUpdate()->first();
            $before = $policy?->only(['per_day_minor', 'grace_days', 'cap_minor', 'count_return_day']) ?? LateReturnPolicyModel::effective();
            $policy ??= new LateReturnPolicyModel(['policy_key' => 'default']);
            $policy->fill([
                'per_day_minor' => self::toMinor($data['per_day_amount']),
                'grace_days' => (int) $data['grace_days'],
                'cap_minor' => self::toMinor($data['cap_amount']),
                'count_return_day' => (bool) $data['count_return_day'],
                'updated_by' => $actor->id,
            ])->save();

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'finance.late_return_policy_updated',
                'subject_type' => LateReturnPolicyModel::class,
                'subject_id' => $policy->id,
                'before_state' => $before,
                'after_state' => $policy->only(['per_day_minor', 'grace_days', 'cap_minor', 'count_return_day']),
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
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
