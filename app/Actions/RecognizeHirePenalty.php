<?php

namespace App\Actions;

use App\Models\BusinessConfiguration;
use App\Models\Hire;
use App\Models\HireItem;
use App\Models\HirePenaltyAccrual;
use App\Services\HirePenaltyBalance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecognizeHirePenalty
{
    public function __construct(private readonly PostFinancialJournal $journal, private readonly HirePenaltyBalance $penaltyBalance) {}

    public function handle(Hire $candidate, ?int $actorId = null, ?Carbon $asOf = null, string $trigger = 'daily'): ?HirePenaltyAccrual
    {
        $date = ($asOf ?? now())->toDateString();

        return DB::transaction(function () use ($candidate, $actorId, $date, $trigger): ?HirePenaltyAccrual {
            $hire = Hire::query()->lockForUpdate()->findOrFail($candidate->id);
            $configuration = BusinessConfiguration::query()
                ->where('configuration_key', 'penalty_accounting')
                ->where('review_status', 'approved')
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
                ->orderByDesc('effective_from')->orderByDesc('id')->first();
            $settings = $configuration?->configuration ?? [];
            $basis = $settings['recognition_basis'] ?? null;
            if (! $configuration || ! in_array($basis, ['daily', 'assessment'], true) || ($basis === 'assessment' && $trigger === 'daily')) {
                return null;
            }

            $accounting = (int) HireItem::query()->where('hire_id', $hire->id)->sum('penalty_assessed_minor');
            if ($basis === 'daily' || in_array($trigger, ['collection', 'formal'], true)) {
                $accounting += $this->penaltyBalance->accruedForHire($hire, Carbon::parse($date)->endOfDay());
            }
            $recognized = (int) HirePenaltyAccrual::query()->where('hire_id', $hire->id)->sum('amount_minor');
            $difference = max(0, $accounting - $recognized);
            if ($difference === 0) {
                return null;
            }

            $accrual = HirePenaltyAccrual::query()->create([
                'reference' => 'PEN-'.Carbon::parse($date)->format('ymd').'-'.Str::upper(Str::random(8)),
                'hire_id' => $hire->id,
                'location_id' => $hire->location_id,
                'business_configuration_id' => $configuration->id,
                'posted_by' => $actorId,
                'amount_minor' => $difference,
                'occurred_on' => $date,
                'recognition_basis' => $basis,
            ]);

            $this->journal->handle(
                HirePenaltyAccrual::class,
                $accrual->id,
                'penalty_accrual',
                $accrual->reference,
                'Late-return penalty recognized for hire '.$hire->reference,
                $hire->location_id,
                $actorId,
                $date,
                [
                    ['account' => $settings['receivable_account_code'], 'debit_minor' => $difference, 'credit_minor' => 0],
                    ['account' => $settings['penalty_income_account_code'], 'debit_minor' => 0, 'credit_minor' => $difference],
                ],
            );

            return $accrual;
        }, attempts: 3);
    }
}
