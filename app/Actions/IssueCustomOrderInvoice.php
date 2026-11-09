<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\CustomOrder;
use App\Models\CustomOrderDepositApplication;
use App\Models\CustomOrderEvent;
use App\Models\CustomOrderInvoice;
use App\Models\CustomOrderInvoiceAllocation;
use App\Models\FinancialJournalEntry;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Services\InvoiceTaxCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class IssueCustomOrderInvoice
{
    public function __construct(
        private readonly PostFinancialJournal $journal,
        private readonly InvoiceTaxCalculator $taxCalculator,
        private readonly QueueEtimsDocumentSubmission $etimsSubmission,
    ) {}

    public function handle(CustomOrder $candidate, ?User $actor = null): ?CustomOrderInvoice
    {
        return DB::transaction(function () use ($candidate, $actor): ?CustomOrderInvoice {
            $order = CustomOrder::query()->lockForUpdate()->findOrFail($candidate->id);
            if ($order->status !== 'completed' || $order->completed_at === null) {
                return null;
            }

            $existing = CustomOrderInvoice::query()->where('custom_order_id', $order->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($order->agreed_total_minor <= 0) {
                throw new LogicException('A completed custom order must have a positive agreed total before invoicing.');
            }

            $payments = Payment::query()->where('custom_order_id', $order->id)->where('status', 'received')->where('purpose', 'custom_order_charge')->with('refunds')->orderBy('id')->get();
            $applications = $order->depositApplications()->orderBy('id')->get();
            $date = $order->completed_at->toDateString();
            $taxCalculation = $this->taxCalculator->calculate([[
                'category_code' => 'custom_orders',
                'base_minor' => $order->agreed_total_minor,
                'default_revenue_account' => '4020',
            ]], $date);
            $settled = $payments->sum(fn (Payment $payment): int => $payment->amount_minor - $payment->refunds->sum('amount_minor'))
                + $applications->sum('amount_minor');
            if ($settled > $taxCalculation['total_minor']) {
                throw new LogicException('Historical custom-order receipts and deposit applications exceed the invoice total; reconcile the opening balance before backfill.');
            }

            $invoice = CustomOrderInvoice::query()->create([
                'number' => 'COI-'.now()->format('ymd').'-'.Str::upper(Str::random(7)),
                'custom_order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'location_id' => $order->location_id,
                'currency' => 'KES',
                'total_minor' => $taxCalculation['total_minor'],
                'subtotal_minor' => $taxCalculation['subtotal_minor'],
                'tax_minor' => $taxCalculation['tax_minor'],
                'tax_configuration_id' => $taxCalculation['configuration_id'],
                'issued_on' => $date,
                'due_on' => $date,
                'posted_at' => now(),
            ]);

            $description = trim(implode(' · ', array_filter([
                $order->item_type,
                $order->size ? 'Size '.$order->size : null,
                $order->color ? 'Color '.$order->color : null,
                'Quantity '.$order->quantity,
            ])));
            $taxLine = $taxCalculation['lines'][0];
            $invoice->lines()->create([
                'description' => $description,
                'quantity' => $order->quantity,
                ...$taxLine,
            ]);

            $journalLines = [['account' => '1100', 'debit_minor' => $invoice->total_minor, 'credit_minor' => 0]];
            if ($taxLine['net_minor'] > 0) {
                $journalLines[] = ['account' => $taxLine['revenue_account_code'], 'debit_minor' => 0, 'credit_minor' => $taxLine['net_minor']];
            }
            if ($taxLine['tax_minor'] > 0) {
                $journalLines[] = ['account' => $taxLine['tax_account_code'], 'debit_minor' => 0, 'credit_minor' => $taxLine['tax_minor']];
            }

            $this->journal->handle(
                CustomOrderInvoice::class, $invoice->id, 'invoice', $invoice->number,
                'Custom order earned and invoiced: '.$order->reference, $order->location_id, $order->owner_id, $date,
                $journalLines,
            );

            foreach ($payments as $payment) {
                CustomOrderInvoiceAllocation::query()->firstOrCreate(
                    ['source_type' => $payment::class, 'source_id' => $payment->id],
                    ['custom_order_invoice_id' => $invoice->id, 'kind' => 'receipt', 'amount_minor' => $payment->amount_minor, 'allocated_on' => $payment->received_at?->toDateString() ?? $date, 'recorded_by' => $payment->recorded_by],
                );
                foreach ($payment->refunds as $refund) {
                    CustomOrderInvoiceAllocation::query()->firstOrCreate(
                        ['source_type' => $refund::class, 'source_id' => $refund->id],
                        ['custom_order_invoice_id' => $invoice->id, 'kind' => 'refund', 'amount_minor' => $refund->amount_minor, 'allocated_on' => $refund->refunded_at?->toDateString() ?? $date, 'recorded_by' => $refund->recorded_by],
                    );
                }
            }

            foreach ($applications as $application) {
                CustomOrderInvoiceAllocation::query()->firstOrCreate(
                    ['source_type' => $application::class, 'source_id' => $application->id],
                    ['custom_order_invoice_id' => $invoice->id, 'kind' => 'deposit_application', 'amount_minor' => $application->amount_minor, 'allocated_on' => $application->applied_at?->toDateString() ?? $date, 'recorded_by' => $application->applied_by],
                );
            }

            $this->reclassifyExistingReceipts($order, $invoice, $payments, $date);

            $actorId = $actor?->id ?? $order->owner_id ?? $order->created_by;
            CustomOrderEvent::query()->create([
                'custom_order_id' => $order->id,
                'actor_id' => $actorId,
                'event_type' => 'invoice.issued',
                'reason' => 'Custom order completed and invoiced',
                'after_state' => ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->number, 'total_minor' => $invoice->total_minor],
                'occurred_at' => now(),
            ]);
            AuditLog::query()->create([
                'actor_id' => $actorId,
                'location_id' => $order->location_id,
                'action' => 'custom_order.invoice_issued',
                'subject_type' => CustomOrderInvoice::class,
                'subject_id' => $invoice->id,
                'after_state' => ['custom_order_id' => $order->id, 'invoice_number' => $invoice->number, 'total_minor' => $invoice->total_minor],
                'occurred_at' => now(),
            ]);

            $this->etimsSubmission->handle($invoice, $actor);

            return $invoice;
        }, attempts: 3);
    }

    /** @param Collection<int, Payment> $payments */
    private function reclassifyExistingReceipts(CustomOrder $order, CustomOrderInvoice $invoice, Collection $payments, string $date): void
    {
        $paymentIds = $payments->modelKeys();
        $refundIds = $payments->flatMap(fn (Payment $payment) => $payment->refunds->modelKeys())->all();
        $applicationIds = $order->depositApplications()->pluck('id')->all();
        $sourceEntries = FinancialJournalEntry::query()
            ->where(function ($query) use ($paymentIds, $refundIds): void {
                $query->where(fn ($payments) => $payments->where('source_type', Payment::class)->whereIn('source_id', $paymentIds))
                    ->orWhere(fn ($refunds) => $refunds->where('source_type', PaymentRefund::class)->whereIn('source_id', $refundIds));
            })
            ->orWhere(fn ($applications) => $applications->where('source_type', CustomOrderDepositApplication::class)->whereIn('source_id', $applicationIds))
            ->with('lines.account')->get();
        $reversalEntries = FinancialJournalEntry::query()->whereIn('reversal_of_id', $sourceEntries->modelKeys())->with('lines.account')->get();
        $balances = ['2010' => 0, '4020' => 0];
        foreach ($sourceEntries->concat($reversalEntries) as $entry) {
            foreach ($entry->lines as $line) {
                $code = $line->account->code;
                if (array_key_exists($code, $balances)) {
                    $balances[$code] += $line->credit_minor - $line->debit_minor;
                }
            }
        }

        $lines = [];
        foreach ($balances as $account => $balance) {
            if ($balance < 0) {
                throw new LogicException('A custom order has a negative advance balance; reconcile its receipts before completion.');
            }
            if ($balance > 0) {
                $lines[] = ['account' => $account, 'debit_minor' => $balance, 'credit_minor' => 0];
                $lines[] = ['account' => '1100', 'debit_minor' => 0, 'credit_minor' => $balance];
            }
        }
        if ($lines === []) {
            return;
        }

        $this->journal->handle(
            CustomOrderInvoice::class, $invoice->id, 'advance_reclass', $invoice->number.'-ADV',
            'Custom order advance applied to receivable: '.$order->reference, $order->location_id, $order->owner_id, $date, $lines,
        );
    }
}
