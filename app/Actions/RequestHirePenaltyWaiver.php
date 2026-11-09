<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\Hire;
use App\Models\HireItem;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PenaltyAdjustment;
use App\Models\PenaltyWaiverRequest;
use App\Models\User;
use App\Services\HirePenaltyBalance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RequestHirePenaltyWaiver
{
    public function __construct(private readonly HirePenaltyBalance $penaltyBalance) {}

    /** @param array{hire_id:int,amount:string,reason:string,idempotency_key:string} $data */
    public function handle(array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): PenaltyWaiverRequest
    {
        return DB::transaction(function () use ($data, $actor, $ipAddress, $userAgent): PenaltyWaiverRequest {
            $hire = Hire::query()->lockForUpdate()->findOrFail($data['hire_id']);
            abort_unless($actor->mayViewAllLocations() || $actor->locations()->whereKey($hire->location_id)->exists(), 403);
            $amountMinor = self::toMinorUnits($data['amount']);
            if ($amountMinor < 1) {
                throw ValidationException::withMessages(['amount' => 'Enter a waiver amount greater than zero.']);
            }
            $existing = PenaltyWaiverRequest::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                if ($existing->hire_id !== $hire->id || $existing->amount_minor !== $amountMinor || $existing->reason !== $data['reason']) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for different waiver details.']);
                }

                return $existing;
            }
            $assessed = (int) HireItem::query()->where('hire_id', $hire->id)->sum('penalty_assessed_minor') + $this->penaltyBalance->accruedForHire($hire);
            $waived = (int) PenaltyAdjustment::query()->where('hire_id', $hire->id)->where('kind', 'waiver')->sum('amount_minor');
            $received = (int) Payment::query()->where('hire_id', $hire->id)->where('status', 'received')->where('purpose', 'penalty')->sum('amount_minor');
            $refunded = (int) PaymentRefund::query()->whereHas('payment', fn ($query) => $query->where('hire_id', $hire->id)->where('status', 'received')->where('purpose', 'penalty'))->sum('amount_minor');
            $pending = (int) PenaltyWaiverRequest::query()->where('hire_id', $hire->id)->where('status', 'pending')->sum('amount_minor');
            $outstanding = max(0, $assessed - $waived - $received + $refunded - $pending);
            if ($amountMinor > $outstanding) {
                throw ValidationException::withMessages(['amount' => 'The request exceeds the available unpaid penalty balance of KES '.number_format($outstanding / 100, 2).'.']);
            }
            $request = PenaltyWaiverRequest::query()->create([
                'reference' => 'PWR-'.now()->format('ymd').'-'.Str::upper(Str::random(7)),
                'idempotency_key' => $data['idempotency_key'], 'hire_id' => $hire->id, 'location_id' => $hire->location_id,
                'requested_by' => $actor->id, 'amount_minor' => $amountMinor, 'reason' => $data['reason'],
                'status' => 'pending',
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $hire->location_id, 'action' => 'hire.penalty_waiver_requested',
                'subject_type' => PenaltyWaiverRequest::class, 'subject_id' => $request->id,
                'after_state' => ['reference' => $request->reference, 'amount_minor' => $amountMinor, 'status' => 'pending'],
                'reason' => $data['reason'], 'ip_address' => $ipAddress, 'user_agent' => $userAgent, 'occurred_at' => now(),
            ]);

            return $request;
        }, attempts: 3);
    }

    private static function toMinorUnits(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
