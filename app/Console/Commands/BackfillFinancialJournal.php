<?php

namespace App\Console\Commands;

use App\Models\CustomOrderDepositApplication;
use App\Models\HireDepositApplication;
use App\Models\OperatingExpense;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Observers\CustomOrderDepositApplicationObserver;
use App\Observers\HireDepositApplicationObserver;
use App\Observers\OperatingExpenseObserver;
use App\Observers\PaymentObserver;
use App\Observers\PaymentRefundObserver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gownsea:ledger-backfill {--dry-run : Show eligible source counts without writing journal entries}')]
#[Description('Backfill balanced financial journal entries for existing recorded transactions')]
class BackfillFinancialJournal extends Command
{
    public function handle(
        PaymentObserver $payments,
        PaymentRefundObserver $refunds,
        HireDepositApplicationObserver $hireApplications,
        CustomOrderDepositApplicationObserver $orderApplications,
        OperatingExpenseObserver $expenses,
    ): int {
        $eligible = [
            'receipts' => Payment::query()->where('status', 'received')->count(),
            'refunds' => PaymentRefund::query()->count(),
            'hire deposit applications' => HireDepositApplication::query()->count(),
            'custom order deposit applications' => CustomOrderDepositApplication::query()->count(),
            'paid operating expenses' => OperatingExpense::query()->where('payment_status', 'paid')->count(),
        ];
        foreach ($eligible as $source => $count) {
            $this->line("{$source}: {$count}");
        }
        if ($this->option('dry-run')) {
            $this->info('Dry run complete; no journal entries were written.');

            return self::SUCCESS;
        }

        Payment::query()->where('status', 'received')->orderBy('id')->chunkById(200, fn ($items) => $items->each($payments->postReceipt(...)));
        PaymentRefund::query()->with('payment')->orderBy('id')->chunkById(200, fn ($items) => $items->each($refunds->postRefund(...)));
        HireDepositApplication::query()->with('hire')->orderBy('id')->chunkById(200, fn ($items) => $items->each($hireApplications->postApplication(...)));
        CustomOrderDepositApplication::query()->with('order')->orderBy('id')->chunkById(200, fn ($items) => $items->each($orderApplications->postApplication(...)));
        OperatingExpense::query()->where('payment_status', 'paid')->orderBy('id')->chunkById(200, fn ($items) => $items->each($expenses->postPayment(...)));

        $this->info('Backfill finished. Existing source records are safe to retry because journal entries are keyed by source and event.');

        return self::SUCCESS;
    }
}
