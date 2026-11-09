<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\Hire;
use App\Models\HireDepositApplication;
use App\Models\HireInvoice;
use App\Models\HireItem;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApplyHireDeposit
{
    /** @param array{hire_id:int|string,payment_id:int|string,amount:string,idempotency_key:string} $data */
    public function handle(array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): HireDepositApplication
    {
        return DB::transaction(function () use ($data, $actor, $ipAddress, $userAgent): HireDepositApplication {
            $amountMinor = $this->toMinorUnits($data['amount']);
            if ($amountMinor <= 0) {
                throw ValidationException::withMessages(['amount' => 'Enter an application amount greater than zero.']);
            }

            $existing = HireDepositApplication::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                if ($existing->hire_id !== (int) $data['hire_id'] || $existing->payment_id !== (int) $data['payment_id'] || $existing->amount_minor !== $amountMinor) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for different hire deposit application details.']);
                }

                return $existing;
            }

            $payment = Payment::query()->whereKey($data['payment_id'])->lockForUpdate()->firstOrFail();
            if ($payment->purpose !== 'deposit' || $payment->status !== 'received' || $payment->hire_id !== (int) $data['hire_id']) {
                throw ValidationException::withMessages(['payment_id' => 'Choose a received deposit receipt for the selected hire.']);
            }
            $hire = Hire::query()->lockForUpdate()->findOrFail($data['hire_id']);
            if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($hire->location_id)->exists()) {
                abort(403);
            }
            if ($hire->status === 'reserved' || $hire->status === 'cancelled') {
                throw ValidationException::withMessages(['hire_id' => 'A reservation deposit can only be applied after gown issue, and cancelled hires cannot use deposits.']);
            }
            if (! HireInvoice::query()->where('hire_id', $hire->id)->exists()) {
                throw ValidationException::withMessages(['hire_id' => 'The full hire handover must be invoiced before its deposit can be applied.']);
            }
            if ($amountMinor > $hire->deposit_minor) {
                throw ValidationException::withMessages(['amount' => 'The application cannot exceed the remaining refundable hire deposit.']);
            }

            $refunded = (int) PaymentRefund::query()->where('payment_id', $payment->id)->sum('amount_minor');
            $alreadyAppliedToReceipt = (int) HireDepositApplication::query()->where('payment_id', $payment->id)->sum('amount_minor');
            if ($amountMinor > $payment->amount_minor - $refunded - $alreadyAppliedToReceipt) {
                throw ValidationException::withMessages(['amount' => 'The application cannot exceed this deposit receipt’s available balance.']);
            }

            $chargeTotal = (int) HireItem::query()->where('hire_id', $hire->id)->sum(DB::raw('quantity * unit_rate_minor'));
            $chargeReceived = (int) Payment::query()->where('hire_id', $hire->id)->where('purpose', 'service_charge')->where('status', 'received')->sum('amount_minor');
            $chargeRefunded = (int) PaymentRefund::query()->whereHas('payment', fn ($query) => $query->where('hire_id', $hire->id)->where('purpose', 'service_charge'))->sum('amount_minor');
            $chargeApplied = (int) HireDepositApplication::query()->where('hire_id', $hire->id)->sum('amount_minor');
            $outstanding = max(0, $chargeTotal - $chargeReceived + $chargeRefunded - $chargeApplied);
            if ($amountMinor > $outstanding) {
                throw ValidationException::withMessages(['amount' => 'The application cannot exceed the remaining hire charge of KES '.number_format($outstanding / 100, 2).'.']);
            }

            $application = HireDepositApplication::query()->create([
                'hire_id' => $hire->id, 'payment_id' => $payment->id, 'applied_by' => $actor->id,
                'reference' => 'HDA-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'idempotency_key' => $data['idempotency_key'], 'amount_minor' => $amountMinor, 'applied_at' => now(),
            ]);
            $hire->update(['deposit_minor' => $hire->deposit_minor - $amountMinor]);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $hire->location_id, 'action' => 'hire.deposit_applied',
                'subject_type' => HireDepositApplication::class, 'subject_id' => $application->id,
                'before_state' => ['charge_outstanding_minor' => $outstanding, 'deposit_remaining_minor' => $hire->deposit_minor + $amountMinor],
                'after_state' => ['application_reference' => $application->reference, 'hire_reference' => $hire->reference,
                    'source_receipt' => $payment->reference, 'amount_minor' => $amountMinor,
                    'charge_outstanding_minor' => $outstanding - $amountMinor, 'deposit_remaining_minor' => $hire->deposit_minor],
                'ip_address' => $ipAddress, 'user_agent' => $userAgent, 'occurred_at' => now(),
            ]);

            return $application;
        }, attempts: 3);
    }

    private function toMinorUnits(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
