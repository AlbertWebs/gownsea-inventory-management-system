<?php

namespace App\Jobs;

use App\Mail\QuotationOfferMail;
use App\Models\Quotation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendQuotationEmail implements ShouldQueueAfterCommit
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $quotationId, public readonly int $revision) {}

    public function handle(): void
    {
        $quotation = Quotation::query()->with(['lead.customer:id,name,email', 'location:id,name'])
            ->whereKey($this->quotationId)->where('revision', $this->revision)
            ->whereIn('status', ['sent', 'accepted'])->where('approval_status', 'approved')->first();
        if ($quotation === null) {
            return;
        }

        $recipient = $quotation->lead->email ?: $quotation->lead->customer?->email;
        if (blank($recipient)) {
            Log::warning('Approved quotation email skipped because the lead has no email address.', [
                'quotation_id' => $quotation->id, 'revision' => $quotation->revision,
            ]);

            return;
        }

        $lines = $quotation->lines()->where('revision', $this->revision)->orderBy('id')->get();
        $pdf = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false])
            ->loadView('quotations.pdf', ['quotation' => $quotation, 'lines' => $lines])->setPaper('a4');
        Mail::to($recipient)->send(new QuotationOfferMail(
            customerName: $quotation->lead->contact_name,
            quoteReference: $quotation->reference,
            revision: $this->revision,
            locationName: $quotation->location->name,
            validUntil: $quotation->valid_until?->format('d M Y'),
            totalMinor: $quotation->total_minor,
            notes: $quotation->notes,
            lines: $lines->map(fn ($line): array => [
                'description' => $line->description, 'quantity' => $line->quantity,
                'unit_price_minor' => $line->unit_price_minor, 'line_total_minor' => $line->line_total_minor,
            ])->all(),
            pdfBytes: $pdf->output(),
        ));
    }
}
