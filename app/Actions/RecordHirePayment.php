<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\Hire;
use App\Models\HireDepositApplication;
use App\Models\HireInvoice;
use App\Models\MpesaStkPush;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PenaltyAdjustment;
use App\Models\User;
use App\Services\HirePenaltyBalance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordHirePayment
{
    public function __construct(private readonly HirePenaltyBalance $penaltyBalance) {}

    /** @param array{hire_id: int, amount: string, method: string, purpose: string, provider_reference?: string|null, idempotency_key: string} $data */
    public function handle(array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): Payment
    {
        return DB::transaction(function () use ($data, $actor, $ipAddress, $userAgent): Payment {
            $amountMinor = $this->toMinorUnits($data['amount']);
            if ($amountMinor <= 0) {
                throw ValidationException::withMessages(['amount' => 'Enter a payment amount greater than zero.']);
            }
            $existing = Payment::query()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                if (
                    $existing->hire_id !== (int) $data['hire_id']
                    || $existing->amount_minor !== $amountMinor
                    || $existing->method !== $data['method']
                    || $existing->purpose !== $data['purpose']
                    || $existing->provider_reference !== ($data['provider_reference'] ?? null)
                ) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This payment key was already used for different payment details.']);
                }

                return $existing;
            }

            $hire = Hire::query()->lockForUpdate()->findOrFail($data['hire_id']);
            if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($hire->location_id)->exists()) {
                abort(403);
            }
            if (! in_array($data['purpose'], ['service_charge', 'deposit', 'penalty'], true)) {
                throw ValidationException::withMessages(['purpose' => 'Choose a valid hire receipt purpose.']);
            }
            if ($hire->status === 'reserved' && $data['purpose'] !== 'deposit') {
                throw ValidationException::withMessages(['purpose' => 'A reservation may take a refundable deposit before issue, but service charges are recorded at handover.']);
            }
            if ($data['purpose'] === 'service_charge' && ! HireInvoice::query()->where('hire_id', $hire->id)->exists()) {
                throw ValidationException::withMessages(['purpose' => 'Service charges can be collected after the complete hire handover has been invoiced.']);
            }
            if ($data['purpose'] === 'service_charge') {
                $chargeTotal = $hire->items()->get(['quantity', 'unit_rate_minor'])->sum(fn ($item): int => $item->quantity * $item->unit_rate_minor);
                $receivedTotal = (int) Payment::query()->where('hire_id', $hire->id)->where('status', 'received')->where('purpose', 'service_charge')->sum('amount_minor');
                $refundedTotal = (int) PaymentRefund::query()->whereHas('payment', fn ($query) => $query->where('hire_id', $hire->id)->where('status', 'received')->where('purpose', 'service_charge'))->sum('amount_minor');
                $appliedTotal = (int) HireDepositApplication::query()->where('hire_id', $hire->id)->sum('amount_minor');
                $reserved = (int) MpesaStkPush::query()->where('hire_id', $hire->id)->where('purpose', 'service_charge')->whereIn('status', ['pending', 'review'])->where('idempotency_key', '!=', $data['idempotency_key'])->sum('amount_minor');
                $outstanding = max(0, $chargeTotal - $receivedTotal + $refundedTotal - $appliedTotal - $reserved);
                if ($amountMinor > $outstanding) {
                    throw ValidationException::withMessages(['amount' => 'The service charge receipt cannot exceed the remaining hire charge of KES '.number_format($outstanding / 100, 2).'.']);
                }
            }
            if ($data['purpose'] === 'penalty') {
                $assessed = (int) $hire->items()->sum('penalty_assessed_minor') + $this->penaltyBalance->accruedForHire($hire);
                $waived = (int) PenaltyAdjustment::query()->where('hire_id', $hire->id)->where('kind', 'waiver')->sum('amount_minor');
                $received = (int) Payment::query()->where('hire_id', $hire->id)->where('status', 'received')->where('purpose', 'penalty')->sum('amount_minor');
                $refunded = (int) PaymentRefund::query()->whereHas('payment', fn ($query) => $query->where('hire_id', $hire->id)->where('status', 'received')->where('purpose', 'penalty'))->sum('amount_minor');
                $reserved = (int) MpesaStkPush::query()->where('hire_id', $hire->id)->where('purpose', 'penalty')->whereIn('status', ['pending', 'review'])->where('idempotency_key', '!=', $data['idempotency_key'])->sum('amount_minor');
                $outstanding = max(0, $assessed - $waived - $received + $refunded - $reserved);
                if ($outstanding === 0 || $amountMinor > $outstanding) {
                    throw ValidationException::withMessages(['amount' => 'A penalty receipt cannot exceed the currently assessed unpaid penalty of KES '.number_format($outstanding / 100, 2).'.']);
                }
            }

            $providerReference = $data['provider_reference'] ?? null;
            if ($providerReference !== null && Payment::query()->where('provider_reference', $providerReference)->exists()) {
                throw ValidationException::withMessages(['provider_reference' => 'That provider reference has already been recorded.']);
            }

            $payment = Payment::query()->create([
                'reference' => 'REC-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'idempotency_key' => $data['idempotency_key'],
                'customer_id' => $hire->customer_id,
                'hire_id' => $hire->id,
                'location_id' => $hire->location_id,
                'recorded_by' => $actor->id,
                'amount_minor' => $amountMinor,
                'currency' => 'KES',
                'method' => $data['method'],
                'purpose' => $data['purpose'],
                'status' => 'received',
                'provider_reference' => $providerReference,
                'received_at' => now(),
            ]);

            if ($payment->purpose === 'deposit') {
                $hire->update(['deposit_minor' => $hire->deposit_minor + $amountMinor]);
            }

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'location_id' => $hire->location_id,
                'action' => 'payment.received',
                'subject_type' => Payment::class,
                'subject_id' => $payment->id,
                'after_state' => [
                    'reference' => $payment->reference,
                    'hire_id' => $hire->id,
                    'amount_minor' => $amountMinor,
                    'currency' => 'KES',
                    'purpose' => $payment->purpose,
                    'method' => $payment->method,
                ],
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'occurred_at' => now(),
            ]);

            return $payment;
        }, attempts: 3);
    }

    private function toMinorUnits(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
