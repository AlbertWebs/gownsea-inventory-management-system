<?php

namespace App\Actions;

use App\Models\FinancialJournalEntry;
use App\Models\Hire;
use App\Models\HireDepositApplication;
use App\Models\HireInvoice;
use App\Models\HireInvoiceAllocation;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Services\InvoiceTaxCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class IssueHireInvoice
{
    public function __construct(
        private readonly PostFinancialJournal $journal,
        private readonly InvoiceTaxCalculator $taxCalculator,
        private readonly QueueEtimsDocumentSubmission $etimsSubmission,
    ) {}

    public function handle(Hire $candidate): ?HireInvoice
    {
        return DB::transaction(function () use ($candidate): ?HireInvoice {
            $hire = Hire::query()->lockForUpdate()->findOrFail($candidate->id);
            if ($hire->status === 'reserved' || $hire->issued_at === null) {
                return null;
            }

            $existing = HireInvoice::query()->where('hire_id', $hire->id)->first();
            if ($existing !== null) {
                return $existing;
            }

            $items = $hire->items()->orderBy('id')->lockForUpdate()->get();
            $notHandedOver = $items->contains(fn ($item): bool => ! in_array($item->status, ['issued', 'partially_returned', 'returned_pending_inspection', 'completed', 'missing'], true));
            if ($items->isEmpty() || $notHandedOver) {
                return null;
            }

            $date = $hire->issued_at->toDateString();
            $taxCalculation = $this->taxCalculator->calculate($items->map(fn ($item): array => [
                'category_code' => 'gown_hire',
                'base_minor' => $item->quantity * $item->unit_rate_minor,
                'default_revenue_account' => '4000',
            ])->values()->all(), $date);
            $total = $taxCalculation['total_minor'];
            if ($total <= 0) {
                return null;
            }

            $invoice = HireInvoice::query()->create([
                'number' => 'INV-'.now()->format('ymd').'-'.Str::upper(Str::random(7)),
                'hire_id' => $hire->id,
                'customer_id' => $hire->customer_id,
                'location_id' => $hire->location_id,
                'currency' => 'KES',
                'total_minor' => $total,
                'subtotal_minor' => $taxCalculation['subtotal_minor'],
                'tax_minor' => $taxCalculation['tax_minor'],
                'tax_configuration_id' => $taxCalculation['configuration_id'],
                'issued_on' => $date,
                'due_on' => $hire->due_at?->toDateString() ?? $date,
                'posted_at' => now(),
            ]);

            foreach ($items as $index => $item) {
                $taxLine = $taxCalculation['lines'][$index];
                $invoice->lines()->create([
                    'hire_item_id' => $item->id,
                    'description' => trim(implode(' · ', array_filter([$item->product_name_snapshot, $item->classification_code_snapshot, $item->qualification_snapshot]))),
                    'quantity' => $item->quantity,
                    'unit_rate_minor' => $item->unit_rate_minor,
                    'line_total_minor' => $taxLine['line_total_minor'],
                    ...$taxLine,
                ]);
            }

            $journalLines = [['account' => '1100', 'debit_minor' => $total, 'credit_minor' => 0]];
            foreach (collect($taxCalculation['lines'])->groupBy('revenue_account_code') as $account => $taxLines) {
                $revenueMinor = $taxLines->sum('net_minor');
                if ($revenueMinor > 0) {
                    $journalLines[] = ['account' => $account, 'debit_minor' => 0, 'credit_minor' => $revenueMinor];
                }
            }
            foreach (collect($taxCalculation['lines'])->where('tax_minor', '>', 0)->groupBy('tax_account_code') as $account => $taxLines) {
                $taxMinor = $taxLines->sum('tax_minor');
                $journalLines[] = ['account' => $account, 'debit_minor' => 0, 'credit_minor' => $taxMinor];
            }

            $this->journal->handle(
                HireInvoice::class, $invoice->id, 'invoice', $invoice->number,
                'Hire service earned and invoiced: '.$hire->reference, $hire->location_id, $hire->created_by, $date,
                $journalLines,
            );

            $payments = $hire->payments()->where('status', 'received')->where('purpose', 'service_charge')->with('refunds')->orderBy('id')->get();
            $applications = HireDepositApplication::query()->where('hire_id', $hire->id)->orderBy('id')->get();
            $settled = $payments->sum(fn (Payment $payment): int => $payment->amount_minor - $payment->refunds->sum('amount_minor'))
                + $applications->sum('amount_minor');
            if ($settled > $total) {
                throw new LogicException('Historical hire receipts and deposit applications exceed the invoice total; reconcile the opening balance before backfill.');
            }
            foreach ($payments as $payment) {
                HireInvoiceAllocation::query()->firstOrCreate(
                    ['source_type' => $payment::class, 'source_id' => $payment->id],
                    ['hire_invoice_id' => $invoice->id, 'kind' => 'receipt', 'amount_minor' => $payment->amount_minor, 'allocated_on' => $payment->received_at?->toDateString() ?? $date, 'recorded_by' => $payment->recorded_by],
                );
                foreach ($payment->refunds as $refund) {
                    HireInvoiceAllocation::query()->firstOrCreate(
                        ['source_type' => $refund::class, 'source_id' => $refund->id],
                        ['hire_invoice_id' => $invoice->id, 'kind' => 'refund', 'amount_minor' => $refund->amount_minor, 'allocated_on' => $refund->refunded_at?->toDateString() ?? $date, 'recorded_by' => $refund->recorded_by],
                    );
                }
            }
            foreach ($applications as $application) {
                HireInvoiceAllocation::query()->firstOrCreate(
                    ['source_type' => $application::class, 'source_id' => $application->id],
                    ['hire_invoice_id' => $invoice->id, 'kind' => 'deposit_application', 'amount_minor' => $application->amount_minor, 'allocated_on' => $application->applied_at?->toDateString() ?? $date, 'recorded_by' => $application->applied_by],
                );
            }
            $this->reclassifyExistingReceipts($hire, $invoice, $payments, $applications, $date);

            $this->etimsSubmission->handle($invoice);

            return $invoice;
        }, attempts: 3);
    }

    /** @param Collection<int, Payment> $payments
     * @param  Collection<int, HireDepositApplication>  $applications
     */
    private function reclassifyExistingReceipts(Hire $hire, HireInvoice $invoice, Collection $payments, Collection $applications, string $date): void
    {
        $paymentIds = $payments->modelKeys();
        $refundIds = $payments->flatMap(fn (Payment $payment) => $payment->refunds->modelKeys())->all();
        $applicationIds = $applications->modelKeys();
        $sourceEntries = FinancialJournalEntry::query()
            ->where(function ($query) use ($paymentIds, $refundIds): void {
                $query->where(fn ($receipts) => $receipts->where('source_type', Payment::class)->whereIn('source_id', $paymentIds))
                    ->orWhere(fn ($refunds) => $refunds->where('source_type', PaymentRefund::class)->whereIn('source_id', $refundIds));
            })
            ->orWhere(fn ($applications) => $applications->where('source_type', HireDepositApplication::class)->whereIn('source_id', $applicationIds))
            ->with('lines.account')->get();
        if ($sourceEntries->isEmpty()) {
            return;
        }

        $reversals = FinancialJournalEntry::query()->whereIn('reversal_of_id', $sourceEntries->modelKeys())->with('lines.account')->get();
        $revenueBalance = 0;
        foreach ($sourceEntries->concat($reversals) as $entry) {
            foreach ($entry->lines as $line) {
                if ($line->account->code === '4000') {
                    $revenueBalance += $line->credit_minor - $line->debit_minor;
                }
            }
        }
        if ($revenueBalance < 0) {
            throw new LogicException('Historical hire receipts have a negative net revenue balance; reconcile them before invoice backfill.');
        }
        if ($revenueBalance > $invoice->total_minor) {
            throw new LogicException('Historical hire revenue receipts exceed the invoice total; reconcile the opening balance before backfill.');
        }
        if ($revenueBalance === 0) {
            return;
        }

        $this->journal->handle(
            HireInvoice::class,
            $invoice->id,
            'advance_reclass',
            $invoice->number.'-ADV',
            'Historical hire receipts and applications reclassified to receivable: '.$hire->reference,
            $hire->location_id,
            $hire->created_by,
            $date,
            [
                ['account' => '4000', 'debit_minor' => $revenueBalance, 'credit_minor' => 0],
                ['account' => '1100', 'debit_minor' => 0, 'credit_minor' => $revenueBalance],
            ],
        );
    }
}
