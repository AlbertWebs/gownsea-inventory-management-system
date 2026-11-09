<?php

namespace App\Mail;

use App\Models\GraduationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UpcomingGraduationEventReminder extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly GraduationEvent $event, public readonly int $daysBefore) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "GOWNSEA gown preparation: {$this->event->name} in {$this->daysBefore} days");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.upcoming-graduation-event-reminder');
    }
}
