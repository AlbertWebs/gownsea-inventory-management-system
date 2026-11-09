<?php

namespace App\Actions;

use App\Models\CreditNote;
use App\Models\CustomOrderInvoice;
use App\Models\HireInvoice;
use App\Models\PaymentRefund;
use Illuminate\Support\Facades\DB;

class IssueRefundCreditNote
{
    public function __construct(private readonly QueueEtimsDocumentSubmission $etimsSubmission) {}

    public function handle(PaymentRefund $refund, HireInvoice|CustomOrderInvoice $invoice): ?CreditNote
    {
        return DB::transaction(function () use ($refund, $invoice): ?CreditNote {
            $invoice = $invoice->newQuery()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
            $invoiceType = $invoice instanceof HireInvoice ? 'hire' : 'custom_order';
            $existing = CreditNote::query()->where('payment_refund_id', $refund->id)->first();
            if ($existing !== null) {
                return $existing;
            }

            $credited = CreditNote::query()
                ->where('invoice_type', $invoiceType)
                ->where('invoice_id', $invoice->id)
                ->sum('total_minor');
            $amount = min($refund->amount_minor, max(0, $invoice->total_minor - $credited));
            if ($amount === 0) {
                return null;
            }

            $lines = $invoice->lines()->orderBy('id')->get();
            if ($lines->isEmpty()) {
                return null;
            }

            $remaining = $amount;
            $invoiceTotal = max(1, $invoice->total_minor);
            $noteSubtotal = 0;
            $noteTax = 0;
            $noteLines = [];
            foreach ($lines as $index => $line) {
                $gross = (int) $line->line_total_minor;
                $lineAmount = $index === $lines->count() - 1
                    ? $remaining
                    : min($remaining, intdiv($gross * $amount, $invoiceTotal));
                $remaining -= $lineAmount;
                $lineTax = $gross > 0 ? min($lineAmount, (int) round($line->tax_minor * $lineAmount / $gross)) : 0;
                $lineNet = $lineAmount - $lineTax;
                $noteLines[] = [
                    'description' => 'Credit: '.$line->description,
                    'quantity' => 1,
                    'total_minor' => $lineAmount,
                    'net_minor' => $lineNet,
                    'tax_minor' => $lineTax,
                    'tax_code' => $line->tax_code,
                    'tax_treatment' => $line->tax_treatment,
                    'tax_rate' => $line->tax_rate,
                    'tax_account_code' => $line->tax_account_code,
                    'revenue_account_code' => $line->revenue_account_code,
                ];
                $noteSubtotal += $lineNet;
                $noteTax += $lineTax;
            }

            $note = CreditNote::query()->create([
                'number' => 'CN-'.$refund->reference,
                'payment_refund_id' => $refund->id,
                'invoice_type' => $invoiceType,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'customer_id' => $invoice->customer_id,
                'location_id' => $refund->location_id ?? $invoice->location_id,
                'currency' => $invoice->currency,
                'total_minor' => $amount,
                'subtotal_minor' => $noteSubtotal,
                'tax_minor' => $noteTax,
                'issued_on' => $refund->refunded_at?->toDateString() ?? now()->toDateString(),
                'reason' => $refund->reason,
                'recorded_by' => $refund->recorded_by,
            ]);
            $note->lines()->createMany($noteLines);

            $this->etimsSubmission->handle($note);

            return $note->load('lines');
        }, attempts: 3);
    }
}
