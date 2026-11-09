<?php

namespace App\Actions;

use App\Models\AssetDepreciation;
use App\Models\PhysicalAsset;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RunHireAssetDepreciation
{
    public function __construct(private readonly PostFinancialJournal $journal) {}

    public function handle(string $period, ?User $actor = null): int
    {
        $month = Carbon::createFromFormat('!Y-m', $period);
        $periodEnd = $month->copy()->endOfMonth();

        return DB::transaction(function () use ($period, $periodEnd, $actor): int {
            $assets = PhysicalAsset::query()->with('product:id,useful_life_months')->whereNotIn('status', ['written_off'])
                ->whereNotNull('acquired_on')->whereDate('acquired_on', '<=', $periodEnd)
                ->whereColumn('accumulated_depreciation_minor', '<', 'acquisition_cost_minor')
                ->orderBy('id')->lockForUpdate()->get();
            $posted = 0;
            foreach ($assets as $asset) {
                if (AssetDepreciation::query()->where('physical_asset_id', $asset->id)->where('period', $period)->exists()) {
                    continue;
                }
                $monthsElapsed = Carbon::parse($asset->acquired_on)->startOfMonth()->diffInMonths($periodEnd->copy()->startOfMonth()) + 1;
                if ($monthsElapsed > $asset->product->useful_life_months) {
                    continue;
                }
                $remaining = $asset->acquisition_cost_minor - $asset->accumulated_depreciation_minor;
                $monthly = intdiv($asset->acquisition_cost_minor, max(1, $asset->product->useful_life_months));
                $amount = $monthsElapsed >= $asset->product->useful_life_months ? $remaining : min($remaining, max(1, $monthly));
                $row = AssetDepreciation::query()->create([
                    'physical_asset_id' => $asset->id, 'period' => $period, 'amount_minor' => $amount,
                    'accumulated_minor' => $asset->accumulated_depreciation_minor + $amount, 'recorded_by' => $actor?->id,
                ]);
                $asset->update(['accumulated_depreciation_minor' => $row->accumulated_minor]);
                $this->journal->handle(AssetDepreciation::class, $row->id, 'asset_depreciation', 'DEPR-'.$asset->asset_code.'-'.$period,
                    'Straight-line depreciation '.$period.' · '.$asset->asset_code, $asset->current_location_id ?? $asset->home_location_id,
                    $actor?->id, $periodEnd->toDateString(), [
                        ['account' => '6400', 'debit_minor' => $amount, 'credit_minor' => 0],
                        ['account' => '1510', 'debit_minor' => 0, 'credit_minor' => $amount],
                    ]);
                $posted++;
            }

            return $posted;
        }, attempts: 3);
    }
}
