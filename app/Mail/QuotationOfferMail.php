<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class QuotationOfferMail extends Mailable
{
    use Queueable;

    /** @param list<array{description:string,quantity:int,unit_price_minor:int,line_total_minor:int}> $lines */
    public function __construct(
        public readonly string $customerName,
        public readonly string $quoteReference,
        public readonly int $revision,
        public readonly string $locationName,
        public readonly ?string $validUntil,
        public readonly int $totalMinor,
        public readonly ?string $notes,
        public readonly array $lines,
        private readonly string $pdfBytes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "GOWNSEA quotation {$this->quoteReference}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.quotation-offer');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->pdfBytes, $this->quoteReference.'-revision-'.$this->revision.'.pdf')->withMime('application/pdf')];
    }
}
