<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\CustomOrder;
use App\Models\CustomOrderDepositApplication;
use App\Models\CustomOrderEvent;
use App\Models\Hire;
use App\Models\HireDepositApplication;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RefundHirePayment
{
    /** @param array{payment_id:int,amount:string,method:string,provider_reference?:string|null,reason:string,idempotency_key:string} $data */
    public function handle(array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): PaymentRefund
    {
        return DB::transaction(function () use ($data, $actor, $ipAddress, $userAgent): PaymentRefund {
            $amountMinor = $this->toMinorUnits($data['amount']);
            if ($amountMinor <= 0) {
                throw ValidationException::withMessages(['amount' => 'Enter a refund amount greater than zero.']);
            }
            $payment = Payment::query()->lockForUpdate()->findOrFail($data['payment_id']);
            if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($payment->location_id)->exists()) {
                abort(403);
            }
            if ($payment->status !== 'received') {
                throw ValidationException::withMessages(['payment_id' => 'Only received payments can be refunded.']);
            }
            if ($payment->purpose === 'product_sale') {
                throw ValidationException::withMessages(['payment_id' => 'Return bulk sale items through the sale return workflow so refunded units are restored to stock safely.']);
            }
            $existing = PaymentRefund::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $this->sameRequest($existing, $data, $amountMinor) ? $existing : throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for different refund details.']);
            }
            $refunded = (int) PaymentRefund::query()->where('payment_id', $payment->id)->sum('amount_minor');
            $applied = match ($payment->purpose) {
                'custom_order_deposit' => (int) CustomOrderDepositApplication::query()->where('payment_id', $payment->id)->sum('amount_minor'),
                'deposit' => (int) HireDepositApplication::query()->where('payment_id', $payment->id)->sum('amount_minor'),
                default => 0,
            };
            if ($amountMinor > $payment->amount_minor - $refunded - $applied) {
                throw ValidationException::withMessages(['amount' => 'The refund cannot exceed the unrefunded receipt balance.']);
            }
            if ($data['method'] === 'mpesa' && PaymentRefund::query()->where('provider_reference', $data['provider_reference'] ?? null)->exists()) {
                throw ValidationException::withMessages(['provider_reference' => 'That provider reference has already been recorded.']);
            }

            $customOrder = $payment->purpose === 'custom_order_deposit' ? CustomOrder::query()->lockForUpdate()->findOrFail($payment->custom_order_id) : null;
            if ($customOrder) {
                $depositPaid = (int) Payment::query()->where('custom_order_id', $customOrder->id)->where('purpose', 'custom_order_deposit')->where('status', 'received')->sum('amount_minor');
                $depositRefunded = (int) PaymentRefund::query()->whereHas('payment', fn ($query) => $query->where('custom_order_id', $customOrder->id)->where('purpose', 'custom_order_deposit'))->sum('amount_minor');
                $depositApplied = (int) CustomOrderDepositApplication::query()->where('custom_order_id', $customOrder->id)->sum('amount_minor');
                if ($amountMinor > $depositPaid - $depositRefunded - $depositApplied) {
                    throw ValidationException::withMessages(['amount' => 'This refund exceeds the remaining custom order deposit liability.']);
                }
            }
            $hire = $payment->purpose === 'deposit' ? Hire::query()->lockForUpdate()->findOrFail($payment->hire_id) : null;
            if ($hire && $amountMinor > $hire->deposit_minor) {
                throw ValidationException::withMessages(['amount' => 'This refund exceeds the remaining deposit liability.']);
            }
            $refund = PaymentRefund::query()->create([
                'reference' => 'RFD-'.now()->format('ymd').'-'.Str::upper(Str::random(6)), 'idempotency_key' => $data['idempotency_key'],
                'payment_id' => $payment->id, 'location_id' => $payment->location_id, 'recorded_by' => $actor->id,
                'amount_minor' => $amountMinor, 'method' => $data['method'], 'provider_reference' => $data['provider_reference'] ?? null,
                'reason' => $data['reason'], 'refunded_at' => now(),
            ]);
            if ($hire) {
                $hire->update(['deposit_minor' => $hire->deposit_minor - $amountMinor]);
            }
            if ($customOrder) {
                CustomOrderEvent::query()->create([
                    'custom_order_id' => $customOrder->id,
                    'actor_id' => $actor->id,
                    'event_type' => 'payment.refunded',
                    'reason' => $data['reason'],
                    'before_state' => ['deposit_remaining_minor' => $depositPaid - $depositRefunded],
                    'after_state' => ['refund_reference' => $refund->reference, 'amount_minor' => $amountMinor, 'deposit_remaining_minor' => $depositPaid - $depositRefunded - $depositApplied - $amountMinor],
                    'occurred_at' => now(),
                ]);
            }
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $payment->location_id, 'action' => 'payment.refunded',
                'subject_type' => PaymentRefund::class, 'subject_id' => $refund->id,
                'before_state' => ['payment_reference' => $payment->reference, 'refunded_minor' => $refunded, 'deposit_minor' => $hire?->deposit_minor + $amountMinor, 'custom_order_deposit_remaining_minor' => $customOrder ? $depositPaid - $depositRefunded - $depositApplied : null],
                'after_state' => ['refund_reference' => $refund->reference, 'amount_minor' => $amountMinor, 'refunded_minor' => $refunded + $amountMinor, 'deposit_minor' => $hire?->deposit_minor, 'custom_order_deposit_remaining_minor' => $customOrder ? $depositPaid - $depositRefunded - $depositApplied - $amountMinor : null],
                'reason' => $data['reason'], 'ip_address' => $ipAddress, 'user_agent' => $userAgent, 'occurred_at' => now(),
            ]);

            return $refund;
        }, attempts: 3);
    }

    private function sameRequest(PaymentRefund $refund, array $data, int $minor): bool
    {
        return $refund->payment_id === (int) $data['payment_id'] && $refund->amount_minor === $minor && $refund->method === $data['method'] && $refund->provider_reference === ($data['provider_reference'] ?? null) && $refund->reason === $data['reason'];
    }

    private function toMinorUnits(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
