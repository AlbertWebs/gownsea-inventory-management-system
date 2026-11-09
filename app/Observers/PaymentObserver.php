<?php

namespace App\Observers;

use App\Actions\PostFinancialJournal;
use App\Actions\RecognizeHirePenalty;
use App\Models\BusinessConfiguration;
use App\Models\CustomOrderInvoiceAllocation;
use App\Models\HireInvoiceAllocation;
use App\Models\HirePenaltyAccrual;
use App\Models\Payment;

class PaymentObserver
{
    public function __construct(private readonly PostFinancialJournal $journal, private readonly RecognizeHirePenalty $recognizePenalty) {}

    public function created(Payment $payment): void
    {
        $this->postReceipt($payment);
    }

    public function postReceipt(Payment $payment): void
    {
        if ($payment->purpose === 'penalty' && $payment->hire !== null) {
            $this->recognizePenalty->handle($payment->hire, $payment->recorded_by, $payment->received_at, 'collection');
        }

        $invoice = $payment->purpose === 'service_charge' ? $payment->hire?->invoice : null;
        $penaltyAccrual = $payment->purpose === 'penalty'
            ? HirePenaltyAccrual::query()->where('hire_id', $payment->hire_id)->whereDate('occurred_on', '<=', $payment->received_at?->toDateString() ?? now()->toDateString())->latest('id')->first()
            : null;
        $penaltySettings = $penaltyAccrual?->business_configuration_id
            ? BusinessConfiguration::query()->find($penaltyAccrual->business_configuration_id)?->configuration
            : null;
        $revenueAccount = match ($payment->purpose) {
            'deposit', 'custom_order_deposit' => '2000',
            'service_charge' => $invoice === null ? '4000' : '1100',
            'penalty' => $penaltySettings['receivable_account_code'] ?? $penaltySettings['penalty_income_account_code'] ?? '4010',
            'custom_order_charge' => $payment->customOrder?->invoice === null ? '2010' : '1100',
            'product_sale' => '4030',
            default => null,
        };
        if ($revenueAccount === null) {
            return;
        }

        $this->journal->handle(
            Payment::class,
            $payment->id,
            'receipt',
            $payment->reference,
            'Receipt recorded: '.$payment->reference,
            $payment->location_id,
            $payment->recorded_by,
            $payment->received_at?->toDateString() ?? now()->toDateString(),
            [
                ['account' => self::cashAccount($payment->method), 'debit_minor' => $payment->amount_minor, 'credit_minor' => 0],
                ['account' => $revenueAccount, 'debit_minor' => 0, 'credit_minor' => $payment->amount_minor],
            ],
        );

        if ($invoice !== null) {
            HireInvoiceAllocation::query()->firstOrCreate(
                ['source_type' => $payment::class, 'source_id' => $payment->id],
                ['hire_invoice_id' => $invoice->id, 'kind' => 'receipt', 'amount_minor' => $payment->amount_minor, 'allocated_on' => $payment->received_at?->toDateString() ?? now()->toDateString(), 'recorded_by' => $payment->recorded_by],
            );
        }

        $customOrderInvoice = $payment->purpose === 'custom_order_charge' ? $payment->customOrder?->invoice : null;
        if ($customOrderInvoice !== null) {
            CustomOrderInvoiceAllocation::query()->firstOrCreate(
                ['source_type' => $payment::class, 'source_id' => $payment->id],
                ['custom_order_invoice_id' => $customOrderInvoice->id, 'kind' => 'receipt', 'amount_minor' => $payment->amount_minor, 'allocated_on' => $payment->received_at?->toDateString() ?? now()->toDateString(), 'recorded_by' => $payment->recorded_by],
            );
        }
    }

    public static function cashAccount(string $method): string
    {
        return match ($method) {
            'cash' => '1000',
            'bank_transfer' => '1010',
            'mpesa' => '1020',
            default => '1030',
        };
    }
}
