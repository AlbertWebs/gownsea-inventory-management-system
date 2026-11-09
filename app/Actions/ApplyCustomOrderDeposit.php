<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\CustomOrder;
use App\Models\CustomOrderDepositApplication;
use App\Models\CustomOrderEvent;
use App\Models\CustomOrderInvoice;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyCustomOrderDeposit
{
    /** @param array{custom_order_id:int|string,payment_id:int|string,amount:string,idempotency_key:string} $data */
    public function handle(array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): CustomOrderDepositApplication
    {
        return DB::transaction(function () use ($data, $actor, $ipAddress, $userAgent): CustomOrderDepositApplication {
            $amountMinor = $this->toMinorUnits($data['amount']);
            if ($amountMinor <= 0) {
                throw ValidationException::withMessages(['amount' => 'Enter an application amount greater than zero.']);
            }

            $existing = CustomOrderDepositApplication::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                if ($existing->custom_order_id !== (int) $data['custom_order_id'] || $existing->payment_id !== (int) $data['payment_id'] || $existing->amount_minor !== $amountMinor) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for different deposit application details.']);
                }

                return $existing;
            }

            $order = CustomOrder::query()->lockForUpdate()->findOrFail($data['custom_order_id']);
            if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($order->location_id)->exists()) {
                abort(403);
            }
            if ($order->status === 'cancelled') {
                throw ValidationException::withMessages(['custom_order_id' => 'Cancelled custom orders cannot use deposits.']);
            }
            $invoice = CustomOrderInvoice::query()->where('custom_order_id', $order->id)->first();
            if ($invoice === null) {
                throw ValidationException::withMessages(['custom_order_id' => 'The custom order must be completed and invoiced before applying its deposit to a charge.']);
            }

            $payment = Payment::query()->whereKey($data['payment_id'])->where('custom_order_id', $order->id)->where('purpose', 'custom_order_deposit')->where('status', 'received')->lockForUpdate()->firstOrFail();
            $alreadyRefunded = (int) PaymentRefund::query()->where('payment_id', $payment->id)->sum('amount_minor');
            $alreadyApplied = (int) CustomOrderDepositApplication::query()->where('payment_id', $payment->id)->sum('amount_minor');
            if ($amountMinor > $payment->amount_minor - $alreadyRefunded - $alreadyApplied) {
                throw ValidationException::withMessages(['amount' => 'The application cannot exceed this receipt’s remaining refundable deposit.']);
            }

            $chargePaid = (int) Payment::query()->where('custom_order_id', $order->id)->where('purpose', 'custom_order_charge')->where('status', 'received')->sum('amount_minor');
            $chargeRefunded = (int) PaymentRefund::query()->whereHas('payment', fn ($query) => $query->where('custom_order_id', $order->id)->where('purpose', 'custom_order_charge'))->sum('amount_minor');
            $appliedToCharge = (int) CustomOrderDepositApplication::query()->where('custom_order_id', $order->id)->sum('amount_minor');
            $chargeOutstanding = max(0, $order->agreed_total_minor - $chargePaid + $chargeRefunded - $appliedToCharge);
            if ($amountMinor > $chargeOutstanding) {
                throw ValidationException::withMessages(['amount' => 'The application cannot exceed the remaining order charge of KES '.number_format($chargeOutstanding / 100, 2).'.']);
            }

            $application = CustomOrderDepositApplication::query()->create([
                'custom_order_id' => $order->id,
                'payment_id' => $payment->id,
                'applied_by' => $actor->id,
                'idempotency_key' => $data['idempotency_key'],
                'amount_minor' => $amountMinor,
                'applied_at' => now(),
            ]);

            CustomOrderEvent::query()->create([
                'custom_order_id' => $order->id,
                'actor_id' => $actor->id,
                'event_type' => 'deposit.applied',
                'reason' => 'Held deposit applied to the agreed order charge',
                'before_state' => ['charge_outstanding_minor' => $chargeOutstanding],
                'after_state' => ['application_id' => $application->id, 'source_receipt' => $payment->reference, 'amount_minor' => $amountMinor, 'charge_outstanding_minor' => $chargeOutstanding - $amountMinor],
                'occurred_at' => now(),
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'location_id' => $order->location_id,
                'action' => 'custom_order.deposit_applied',
                'subject_type' => CustomOrderDepositApplication::class,
                'subject_id' => $application->id,
                'before_state' => ['charge_outstanding_minor' => $chargeOutstanding, 'deposit_receipt_available_minor' => $payment->amount_minor - $alreadyRefunded - $alreadyApplied],
                'after_state' => ['custom_order_id' => $order->id, 'payment_reference' => $payment->reference, 'amount_minor' => $amountMinor, 'charge_outstanding_minor' => $chargeOutstanding - $amountMinor],
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'occurred_at' => now(),
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
