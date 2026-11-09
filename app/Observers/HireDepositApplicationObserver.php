<?php

namespace App\Observers;

use App\Actions\PostFinancialJournal;
use App\Models\HireDepositApplication;
use App\Models\HireInvoiceAllocation;

class HireDepositApplicationObserver
{
    public function __construct(private readonly PostFinancialJournal $journal) {}

    public function created(HireDepositApplication $application): void
    {
        $this->postApplication($application);
    }

    public function postApplication(HireDepositApplication $application): void
    {
        $hire = $application->hire;
        if ($hire === null) {
            return;
        }
        $invoice = $hire->invoice;
        $this->journal->handle(
            HireDepositApplication::class,
            $application->id,
            'deposit_application',
            $application->reference,
            'Hire deposit applied to earned charge: '.$application->reference,
            $hire->location_id,
            $application->applied_by,
            $application->applied_at?->toDateString() ?? now()->toDateString(),
            [
                ['account' => '2000', 'debit_minor' => $application->amount_minor, 'credit_minor' => 0],
                ['account' => $invoice === null ? '4000' : '1100', 'debit_minor' => 0, 'credit_minor' => $application->amount_minor],
            ],
        );
        if ($invoice !== null) {
            HireInvoiceAllocation::query()->firstOrCreate(
                ['source_type' => $application::class, 'source_id' => $application->id],
                ['hire_invoice_id' => $invoice->id, 'kind' => 'deposit_application', 'amount_minor' => $application->amount_minor, 'allocated_on' => $application->applied_at?->toDateString() ?? now()->toDateString(), 'recorded_by' => $application->applied_by],
            );
        }
    }
}
