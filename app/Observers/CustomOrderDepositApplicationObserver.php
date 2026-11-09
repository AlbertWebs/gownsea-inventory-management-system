<?php

namespace App\Observers;

use App\Actions\PostFinancialJournal;
use App\Models\CustomOrderDepositApplication;
use App\Models\CustomOrderInvoiceAllocation;
use Illuminate\Validation\ValidationException;

class CustomOrderDepositApplicationObserver
{
    public function __construct(private readonly PostFinancialJournal $journal) {}

    public function created(CustomOrderDepositApplication $application): void
    {
        $this->postApplication($application);
    }

    public function postApplication(CustomOrderDepositApplication $application): void
    {
        $order = $application->order;
        if ($order === null) {
            return;
        }
        $invoice = $order->invoice;
        if ($invoice === null) {
            throw ValidationException::withMessages(['custom_order_id' => 'The custom order must be completed and invoiced before applying its deposit to a charge.']);
        }
        $this->journal->handle(
            CustomOrderDepositApplication::class,
            $application->id,
            'deposit_application',
            $order->reference.'-DA-'.$application->id,
            'Custom order deposit applied to charge: '.$order->reference,
            $order->location_id,
            $application->applied_by,
            $application->applied_at?->toDateString() ?? now()->toDateString(),
            [
                ['account' => '2000', 'debit_minor' => $application->amount_minor, 'credit_minor' => 0],
                ['account' => '1100', 'debit_minor' => 0, 'credit_minor' => $application->amount_minor],
            ],
        );
        CustomOrderInvoiceAllocation::query()->firstOrCreate(
            ['source_type' => $application::class, 'source_id' => $application->id],
            ['custom_order_invoice_id' => $invoice->id, 'kind' => 'deposit_application', 'amount_minor' => $application->amount_minor, 'allocated_on' => $application->applied_at?->toDateString() ?? now()->toDateString(), 'recorded_by' => $application->applied_by],
        );
    }
}
