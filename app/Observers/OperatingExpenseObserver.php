<?php

namespace App\Observers;

use App\Actions\PostFinancialJournal;
use App\Models\OperatingExpense;

class OperatingExpenseObserver
{
    public function __construct(private readonly PostFinancialJournal $journal) {}

    public function updated(OperatingExpense $expense): void
    {
        if (! $expense->wasChanged('payment_status') || $expense->payment_status !== 'paid') {
            return;
        }

        $this->postPayment($expense);
    }

    public function postPayment(OperatingExpense $expense): void
    {
        if ($expense->payment_status !== 'paid') {
            return;
        }

        $expenseAccount = match ($expense->category) {
            'transport' => '6100',
            'labor' => '6110',
            'repairs' => '6120',
            'sms' => '6130',
            'delivery' => '6140',
            default => '6190',
        };

        $this->journal->handle(
            OperatingExpense::class,
            $expense->id,
            'expense_payment',
            $expense->reference,
            'Operating expense paid: '.$expense->reference,
            $expense->location_id,
            $expense->paid_by,
            $expense->paid_on?->toDateString() ?? now()->toDateString(),
            [
                ['account' => $expenseAccount, 'debit_minor' => $expense->amount_minor, 'credit_minor' => 0],
                ['account' => PaymentObserver::cashAccount($expense->payment_method ?? 'other'), 'debit_minor' => 0, 'credit_minor' => $expense->amount_minor],
            ],
        );
    }
}
