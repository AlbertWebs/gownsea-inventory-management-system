<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Observers\PaymentObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayVendorBill
{
    public function __construct(private readonly PostFinancialJournal $journal) {}

    public function handle(VendorBill $candidate, array $data, User $actor): VendorBillPayment
    {
        abort_unless(in_array($actor->role, ['super_admin', 'management', 'finance'], true), 403);

        return DB::transaction(function () use ($candidate, $data, $actor): VendorBillPayment {
            $bill = VendorBill::query()->lockForUpdate()->findOrFail($candidate->id);
            if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($bill->location_id)->exists()) {
                abort(403);
            }
            $existing = VendorBillPayment::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                if ($existing->vendor_bill_id !== $bill->id || $existing->amount_minor !== (int) $data['amount_minor'] || $existing->method !== $data['method'] || $existing->paid_on->toDateString() !== $data['paid_on']) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This payment key was already used for another payment.']);
                }

                return $existing;
            }
            $paid = (int) $bill->payments()->sum('amount_minor');
            if ((int) $data['amount_minor'] > $bill->amount_minor - $paid) {
                throw ValidationException::withMessages(['amount' => 'Payment cannot exceed the vendor bill balance.']);
            }

            $payment = VendorBillPayment::query()->create([
                'vendor_bill_id' => $bill->id, 'recorded_by' => $actor->id,
                'idempotency_key' => $data['idempotency_key'], 'amount_minor' => (int) $data['amount_minor'],
                'method' => $data['method'], 'payment_reference' => filled($data['payment_reference'] ?? null) ? trim($data['payment_reference']) : null,
                'paid_on' => $data['paid_on'],
            ]);
            $this->journal->handle(VendorBillPayment::class, $payment->id, 'vendor_payment', $payment->payment_reference ?: 'VPAY-'.$payment->id, 'Payment of vendor bill '.$bill->invoice_number, $bill->location_id, $actor->id, $payment->paid_on->toDateString(), [
                ['account' => '2100', 'debit_minor' => $payment->amount_minor, 'credit_minor' => 0],
                ['account' => PaymentObserver::cashAccount($payment->method), 'debit_minor' => 0, 'credit_minor' => $payment->amount_minor],
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $bill->location_id, 'action' => 'finance.vendor_bill_paid',
                'subject_type' => VendorBillPayment::class, 'subject_id' => $payment->id,
                'after_state' => ['bill_id' => $bill->id, 'amount_minor' => $payment->amount_minor, 'method' => $payment->method, 'reference' => $payment->payment_reference],
                'occurred_at' => now(),
            ]);

            return $payment;
        }, attempts: 3);
    }
}
