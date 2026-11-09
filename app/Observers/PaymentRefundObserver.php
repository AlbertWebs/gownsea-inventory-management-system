<?php

namespace App\Observers;

use App\Actions\IssueRefundCreditNote;
use App\Actions\PostFinancialJournal;
use App\Models\BusinessConfiguration;
use App\Models\CustomOrderInvoiceAllocation;
use App\Models\HireInvoiceAllocation;
use App\Models\HirePenaltyAccrual;
use App\Models\PaymentRefund;

class PaymentRefundObserver
{
    public function __construct(
        private readonly PostFinancialJournal $journal,
        private readonly IssueRefundCreditNote $creditNotes,
    ) {}

    public function created(PaymentRefund $refund): void
    {
        $this->postRefund($refund);
    }

    public function postRefund(PaymentRefund $refund): void
    {
        $payment = $refund->payment;
        if ($payment === null) {
            return;
        }
        $invoice = $payment->purpose === 'service_charge' ? $payment->hire?->invoice : null;
        $customOrderInvoice = $payment->purpose === 'custom_order_charge' ? $payment->customOrder?->invoice : null;
        $penaltyAccrual = $payment->purpose === 'penalty'
            ? HirePenaltyAccrual::query()->where('hire_id', $payment->hire_id)->whereDate('occurred_on', '<=', $refund->refunded_at?->toDateString() ?? now()->toDateString())->latest('id')->first()
            : null;
        $penaltySettings = $penaltyAccrual?->business_configuration_id
            ? BusinessConfiguration::query()->find($penaltyAccrual->business_configuration_id)?->configuration
            : null;
        $revenueAccount = match ($payment->purpose) {
            'deposit', 'custom_order_deposit' => '2000',
            'service_charge' => $invoice === null ? '4000' : '1100',
            'penalty' => $penaltySettings === null ? '4010' : $penaltySettings['refund_account_code'],
            'custom_order_charge' => $customOrderInvoice === null ? '2010' : '1100',
            'product_sale' => '4030',
            default => null,
        };
        if ($revenueAccount === null) {
            return;
        }

        $this->journal->handle(
            PaymentRefund::class,
            $refund->id,
            'refund',
            $refund->reference,
            'Refund recorded: '.$refund->reference,
            $refund->location_id,
            $refund->recorded_by,
            $refund->refunded_at?->toDateString() ?? now()->toDateString(),
            [
                ['account' => $revenueAccount, 'debit_minor' => $refund->amount_minor, 'credit_minor' => 0],
                ['account' => PaymentObserver::cashAccount($refund->method), 'debit_minor' => 0, 'credit_minor' => $refund->amount_minor],
            ],
        );
        if ($payment->purpose === 'penalty' && $penaltySettings !== null) {
            $this->journal->handle(
                PaymentRefund::class,
                $refund->id,
                'refund_reclass',
                $refund->reference.'-PENALTY',
                'Reclass penalty refund from clearing to approved waiver account: '.$refund->reference,
                $refund->location_id,
                $refund->recorded_by,
                $refund->refunded_at?->toDateString() ?? now()->toDateString(),
                [
                    ['account' => $penaltySettings['waiver_account_code'], 'debit_minor' => $refund->amount_minor, 'credit_minor' => 0],
                    ['account' => $penaltySettings['refund_account_code'], 'debit_minor' => 0, 'credit_minor' => $refund->amount_minor],
                ],
            );
        }

        if ($invoice !== null) {
            HireInvoiceAllocation::query()->firstOrCreate(
                ['source_type' => $refund::class, 'source_id' => $refund->id],
                ['hire_invoice_id' => $invoice->id, 'kind' => 'refund', 'amount_minor' => $refund->amount_minor, 'allocated_on' => $refund->refunded_at?->toDateString() ?? now()->toDateString(), 'recorded_by' => $refund->recorded_by],
            );
        }

        if ($customOrderInvoice !== null) {
            CustomOrderInvoiceAllocation::query()->firstOrCreate(
                ['source_type' => $refund::class, 'source_id' => $refund->id],
                ['custom_order_invoice_id' => $customOrderInvoice->id, 'kind' => 'refund', 'amount_minor' => $refund->amount_minor, 'allocated_on' => $refund->refunded_at?->toDateString() ?? now()->toDateString(), 'recorded_by' => $refund->recorded_by],
            );
        }

        if ($invoice !== null) {
            $this->creditNotes->handle($refund, $invoice);
        } elseif ($customOrderInvoice !== null) {
            $this->creditNotes->handle($refund, $customOrderInvoice);
        }
    }
}
