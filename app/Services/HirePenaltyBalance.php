<?php

namespace App\Services;

use App\Models\Hire;
use App\Models\LateReturnPolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HirePenaltyBalance
{
    public function accruedForHire(Hire $hire, ?CarbonInterface $asOf = null): int
    {
        return $this->accruedByHireIds(collect([$hire->id]), $asOf)->get($hire->id, 0);
    }

    /** @param Collection<int, int> $hireIds @return Collection<int, int> */
    public function accruedByHireIds(Collection $hireIds, ?CarbonInterface $asOf = null): Collection
    {
        if ($hireIds->isEmpty()) {
            return collect();
        }

        $accrued = [];
        $assessmentAt = $asOf === null ? now() : Carbon::parse($asOf);
        DB::table('hire_items as hi')->join('hires as h', 'h.id', '=', 'hi.hire_id')
            ->whereIn('hi.hire_id', $hireIds->all())
            ->whereIn('hi.status', ['issued', 'partially_returned'])
            ->select('hi.id as id', 'hi.hire_id', 'hi.quantity', 'hi.returned_quantity', 'hi.physical_asset_id', 'hi.penalty_policy_snapshot', 'h.due_at')
            ->orderBy('hi.id')->chunkById(500, function (Collection $items) use (&$accrued, $assessmentAt): void {
                foreach ($items as $item) {
                    $remaining = $item->physical_asset_id === null ? max(0, (int) $item->quantity - (int) $item->returned_quantity) : 1;
                    $policy = json_decode($item->penalty_policy_snapshot ?? '', true) ?: config('gownsea.penalties');
                    $amount = LateReturnPolicy::assess(Carbon::parse($item->due_at), $assessmentAt, $policy)['amount_minor'] * $remaining;
                    $accrued[$item->hire_id] = ($accrued[$item->hire_id] ?? 0) + $amount;
                }
            }, 'hi.id', 'id');

        return collect($accrued);
    }
}
