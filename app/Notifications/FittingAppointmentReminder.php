<?php

namespace App\Notifications;

use App\Models\CustomOrderFitting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class FittingAppointmentReminder extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public CustomOrderFitting $fitting)
    {
        $this->afterCommit = true;
        $this->fitting->loadMissing('order');
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('GOWNSEA fitting appointment reminder')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('A fitting appointment is coming up for order '.$this->fitting->order->reference.'.')
            ->line($this->fitting->order->item_type.' · '.$this->fitting->appointment_at->timezone(config('app.timezone'))->format('D, d M Y H:i'))
            ->action('Open custom orders', route('custom-orders.index'))
            ->line('This is an automated reminder for a scheduled fitting.');
    }
}
