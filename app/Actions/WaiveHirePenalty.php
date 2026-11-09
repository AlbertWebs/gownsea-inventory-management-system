<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BusinessConfiguration;
use App\Models\Hire;
use App\Models\HireItem;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PenaltyAdjustment;
use App\Models\User;
use App\Services\HirePenaltyBalance;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WaiveHirePenalty
{
    public function __construct(private readonly HirePenaltyBalance $penaltyBalance, private readonly PostFinancialJournal $journal, private readonly RecognizeHirePenalty $recognizePenalty) {}

    /** @param array{hire_id:int,amount:string,reason:string,idempotency_key:string} $data */
    public function handle(array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): PenaltyAdjustment
    {
        return DB::transaction(function () use ($data, $actor, $ipAddress, $userAgent): PenaltyAdjustment {
            $amountMinor = $this->toMinorUnits($data['amount']);
            if ($amountMinor <= 0) {
                throw ValidationException::withMessages(['amount' => 'Enter a waiver amount greater than zero.']);
            }
            $hire = Hire::query()->lockForUpdate()->findOrFail($data['hire_id']);
            if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($hire->location_id)->exists()) {
                abort(403);
            }
            $existing = PenaltyAdjustment::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if ($existing->hire_id !== $hire->id || $existing->amount_minor !== $amountMinor || $existing->reason !== $data['reason']) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for different waiver details.']);
                }

                return $existing;
            }

            $this->recognizePenalty->handle($hire, $actor->id, now(), 'formal');

            $assessed = (int) HireItem::query()->where('hire_id', $hire->id)->sum('penalty_assessed_minor') + $this->penaltyBalance->accruedForHire($hire);
            $waived = (int) PenaltyAdjustment::query()->where('hire_id', $hire->id)->where('kind', 'waiver')->sum('amount_minor');
            $received = (int) Payment::query()->where('hire_id', $hire->id)->where('status', 'received')->where('purpose', 'penalty')->sum('amount_minor');
            $refunded = (int) PaymentRefund::query()->whereHas('payment', fn ($query) => $query->where('hire_id', $hire->id)->where('status', 'received')->where('purpose', 'penalty'))->sum('amount_minor');
            $outstanding = max(0, $assessed - $waived - $received + $refunded);
            if ($amountMinor > $outstanding) {
                throw ValidationException::withMessages(['amount' => 'The waiver cannot exceed the assessed unpaid penalty balance of KES '.number_format($outstanding / 100, 2).'.']);
            }

            $configuration = BusinessConfiguration::query()->where('configuration_key', 'penalty_accounting')->where('review_status', 'approved')
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()->toDateString()))
                ->orderByDesc('effective_from')->orderByDesc('id')->first();
            $settings = $configuration?->configuration;
            if ($settings !== null) {
                $branchLimit = (int) round(((float) ($settings['waiver_branch_limit'] ?? 0)) * 100);
                $financeLimit = (int) round(((float) ($settings['waiver_finance_limit'] ?? 0)) * 100);
                $canApprove = match (true) {
                    $amountMinor <= $branchLimit => in_array($actor->role, ['super_admin', 'management'], true),
                    $amountMinor <= $financeLimit => in_array($actor->role, ['super_admin', 'management', 'finance'], true),
                    default => $actor->role === 'super_admin',
                };
                if (! $canApprove) {
                    throw ValidationException::withMessages(['amount' => 'Your role cannot approve a waiver of this size under the effective finance policy.']);
                }
            }

            $adjustment = PenaltyAdjustment::query()->create([
                'idempotency_key' => $data['idempotency_key'], 'hire_id' => $hire->id, 'location_id' => $hire->location_id,
                'created_by' => $actor->id, 'amount_minor' => $amountMinor, 'kind' => 'waiver', 'reason' => $data['reason'], 'adjusted_at' => now(),
            ]);
            if ($settings !== null) {
                $this->journal->handle(
                    PenaltyAdjustment::class,
                    $adjustment->id,
                    'waiver',
                    $adjustment->idempotency_key,
                    'Approved late-return penalty waiver for hire '.$hire->reference,
                    $hire->location_id,
                    $actor->id,
                    now()->toDateString(),
                    [
                        ['account' => $settings['waiver_account_code'], 'debit_minor' => $amountMinor, 'credit_minor' => 0],
                        ['account' => $settings['receivable_account_code'], 'debit_minor' => 0, 'credit_minor' => $amountMinor],
                    ],
                );
            }
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $hire->location_id, 'action' => 'hire.penalty_waived',
                'subject_type' => PenaltyAdjustment::class, 'subject_id' => $adjustment->id,
                'before_state' => ['assessed_minor' => $assessed, 'waived_minor' => $waived, 'net_received_minor' => $received - $refunded, 'outstanding_minor' => $outstanding],
                'after_state' => ['waiver_minor' => $amountMinor, 'waived_minor' => $waived + $amountMinor, 'outstanding_minor' => $outstanding - $amountMinor],
                'reason' => $data['reason'], 'ip_address' => $ipAddress, 'user_agent' => $userAgent, 'occurred_at' => now(),
            ]);

            return $adjustment;
        }, attempts: 3);
    }

    private function toMinorUnits(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
