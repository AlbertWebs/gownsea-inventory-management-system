<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\CustomOrder;
use App\Models\CustomOrderDepositApplication;
use App\Models\CustomOrderEvent;
use App\Models\MpesaStkPush;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordCustomOrderPayment
{
    /** @param array{custom_order_id: int|string, amount: string, method: string, purpose: string, provider_reference?: string|null, idempotency_key: string} $data */
    public function handle(array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): Payment
    {
        return DB::transaction(function () use ($data, $actor, $ipAddress, $userAgent): Payment {
            $amountMinor = $this->toMinorUnits($data['amount']);
            if ($amountMinor <= 0) {
                throw ValidationException::withMessages(['amount' => 'Enter a payment amount greater than zero.']);
            }

            $existing = Payment::query()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->custom_order_id !== (int) $data['custom_order_id'] || $existing->amount_minor !== $amountMinor || $existing->method !== $data['method'] || $existing->purpose !== $data['purpose'] || $existing->provider_reference !== ($data['provider_reference'] ?? null)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This payment key was already used for different payment details.']);
                }

                return $existing;
            }

            $order = CustomOrder::query()->lockForUpdate()->findOrFail($data['custom_order_id']);
            if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($order->location_id)->exists()) {
                abort(403);
            }
            if ($order->status === 'cancelled') {
                throw ValidationException::withMessages(['custom_order_id' => 'Cancelled custom orders cannot receive payments.']);
            }
            if (! in_array($data['purpose'], ['custom_order_charge', 'custom_order_deposit'], true)) {
                throw ValidationException::withMessages(['purpose' => 'Choose a custom order charge or deposit receipt.']);
            }

            $purpose = $data['purpose'];
            $balanceCeiling = $purpose === 'custom_order_deposit' ? $order->deposit_required_minor : $order->agreed_total_minor;
            $paid = (int) Payment::query()->where('custom_order_id', $order->id)->where('status', 'received')->where('purpose', $purpose)->sum('amount_minor');
            $refunded = (int) PaymentRefund::query()->whereHas('payment', fn ($query) => $query->where('custom_order_id', $order->id)->where('purpose', $purpose))->sum('amount_minor');
            $applied = $purpose === 'custom_order_charge' ? (int) CustomOrderDepositApplication::query()->where('custom_order_id', $order->id)->sum('amount_minor') : 0;
            $reserved = (int) MpesaStkPush::query()->where('custom_order_id', $order->id)->where('purpose', $purpose)->whereIn('status', ['pending', 'review'])->where('idempotency_key', '!=', $data['idempotency_key'])->sum('amount_minor');
            $outstanding = max(0, $balanceCeiling - $paid + $refunded - $applied - $reserved);
            if ($outstanding === 0 || $amountMinor > $outstanding) {
                throw ValidationException::withMessages(['amount' => 'This receipt cannot exceed the remaining '.($purpose === 'custom_order_deposit' ? 'required deposit' : 'agreed order charge').' of KES '.number_format($outstanding / 100, 2).'.']);
            }

            $providerReference = $data['provider_reference'] ?? null;
            if ($providerReference !== null && Payment::query()->where('provider_reference', $providerReference)->exists()) {
                throw ValidationException::withMessages(['provider_reference' => 'That provider reference has already been recorded.']);
            }

            $payment = Payment::query()->create([
                'reference' => 'REC-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'idempotency_key' => $data['idempotency_key'],
                'customer_id' => $order->customer_id,
                'custom_order_id' => $order->id,
                'location_id' => $order->location_id,
                'recorded_by' => $actor->id,
                'amount_minor' => $amountMinor,
                'currency' => 'KES',
                'method' => $data['method'],
                'purpose' => $purpose,
                'status' => 'received',
                'provider_reference' => $providerReference,
                'received_at' => now(),
            ]);

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'location_id' => $order->location_id,
                'action' => 'payment.received',
                'subject_type' => Payment::class,
                'subject_id' => $payment->id,
                'after_state' => ['reference' => $payment->reference, 'custom_order_id' => $order->id, 'amount_minor' => $amountMinor, 'currency' => 'KES', 'purpose' => $purpose, 'method' => $payment->method],
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'occurred_at' => now(),
            ]);
            CustomOrderEvent::query()->create([
                'custom_order_id' => $order->id,
                'actor_id' => $actor->id,
                'event_type' => 'payment.received',
                'reason' => ucfirst(str_replace('_', ' ', $purpose)).' receipt recorded',
                'after_state' => ['payment_reference' => $payment->reference, 'amount_minor' => $amountMinor, 'purpose' => $purpose],
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
