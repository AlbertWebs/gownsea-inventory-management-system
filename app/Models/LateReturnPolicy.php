<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class LateReturnPolicy extends Model
{
    protected $fillable = ['policy_key', 'scope_type', 'scope_id', 'scope_reference', 'per_day_minor', 'grace_days', 'cap_minor', 'count_return_day', 'updated_by'];

    protected function casts(): array
    {
        return ['per_day_minor' => 'integer', 'grace_days' => 'integer', 'cap_minor' => 'integer', 'count_return_day' => 'boolean'];
    }

    /** @return array{per_day_minor:int,grace_days:int,cap_minor:int,count_return_day:bool} */
    public static function effective(): array
    {
        $policy = self::query()->where('policy_key', 'default')->first();

        return self::values($policy);
    }

    /** @param list<int> $productIds @return array<int,array{per_day_minor:int,grace_days:int,cap_minor:int,count_return_day:bool}> */
    public static function resolveForProducts(array $productIds, ?int $institutionId, ?string $contractReference): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $policies = self::query()->where(function ($query) use ($productIds, $institutionId, $contractReference): void {
            $query->where('scope_type', 'default');
            if ($productIds !== []) {
                $query->orWhere(fn ($scope) => $scope->where('scope_type', 'product')->whereIn('scope_id', $productIds));
            }
            if ($institutionId !== null) {
                $query->orWhere(fn ($scope) => $scope->where('scope_type', 'institution')->where('scope_id', $institutionId));
            }
            if ($contractReference !== null) {
                $query->orWhere(fn ($scope) => $scope->where('scope_type', 'contract')->where('scope_reference', $contractReference));
            }
        })->get()->keyBy(fn (self $policy): string => $policy->scope_type.':'.($policy->scope_type === 'contract' ? $policy->scope_reference : $policy->scope_id));

        $default = self::values($policies->get('default:'));
        $resolved = [];
        foreach ($productIds as $productId) {
            $productPolicy = $policies->get('product:'.$productId);
            $institutionPolicy = $institutionId === null ? null : $policies->get('institution:'.$institutionId);
            $contractPolicy = $contractReference === null ? null : $policies->get('contract:'.$contractReference);
            $resolved[$productId] = self::values($contractPolicy ?? $productPolicy ?? $institutionPolicy, $default);
        }

        return $resolved;
    }

    /** @param array{per_day_minor:int,grace_days:int,cap_minor:int,count_return_day:bool}|null $fallback
     * @return array{per_day_minor:int,grace_days:int,cap_minor:int,count_return_day:bool}
     */
    private static function values(?self $policy, ?array $fallback = null): array
    {
        return $policy === null ? ($fallback ?? [
            'per_day_minor' => max(0, (int) config('gownsea.penalties.per_day_minor')),
            'grace_days' => max(0, (int) config('gownsea.penalties.grace_days')),
            'cap_minor' => max(0, (int) config('gownsea.penalties.cap_minor')),
            'count_return_day' => (bool) config('gownsea.penalties.count_return_day'),
        ]) : [
            'per_day_minor' => $policy->per_day_minor,
            'grace_days' => $policy->grace_days,
            'cap_minor' => $policy->cap_minor,
            'count_return_day' => $policy->count_return_day,
        ];
    }

    /** @return array{per_day_amount:string,grace_days:int,cap_amount:string,count_return_day:bool} */
    public static function formValues(): array
    {
        $policy = self::effective();

        return [
            'per_day_amount' => number_format($policy['per_day_minor'] / 100, 2, '.', ''),
            'grace_days' => $policy['grace_days'],
            'cap_amount' => number_format($policy['cap_minor'] / 100, 2, '.', ''),
            'count_return_day' => $policy['count_return_day'],
        ];
    }

    /** @param array{per_day_minor:int,grace_days:int,cap_minor:int,count_return_day:bool} $policy
     * @return array{amount_minor:int,rate_per_day_minor:int,overdue_days:int,billable_days:int,grace_days:int,cap_minor:int,count_return_day:bool,due_at:?string}
     */
    public static function assess(?Carbon $dueAt, Carbon $asOf, array $policy): array
    {
        $rate = max(0, (int) $policy['per_day_minor']);
        $grace = max(0, (int) $policy['grace_days']);
        $cap = max(0, (int) $policy['cap_minor']);
        $countsReturnDay = (bool) $policy['count_return_day'];
        $dueDate = $dueAt?->copy()->timezone(config('app.timezone'))->startOfDay();
        $assessmentDate = $asOf->copy()->timezone(config('app.timezone'))->startOfDay();
        $calendarDays = $dueDate ? max(0, (int) $dueDate->diffInDays($assessmentDate, false)) : 0;
        if (! $countsReturnDay && $calendarDays > 0) {
            $calendarDays--;
        }
        $billableDays = max(0, $calendarDays - $grace);
        $amount = $rate * $billableDays;
        if ($cap > 0) {
            $amount = min($amount, $cap);
        }

        return ['amount_minor' => $amount, 'rate_per_day_minor' => $rate, 'overdue_days' => $calendarDays, 'billable_days' => $billableDays, 'grace_days' => $grace, 'cap_minor' => $cap, 'count_return_day' => $countsReturnDay, 'due_at' => $dueAt?->toIso8601String()];
    }
}
