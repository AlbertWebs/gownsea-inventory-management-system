<?php

namespace App\Notifications;

use App\Models\LeadTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeadTaskReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public LeadTask $task)
    {
        $this->afterCommit = true;
        $this->task->loadMissing('lead');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('GOWNSEA CRM follow-up due')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your CRM follow-up task is due: '.$this->task->title)
            ->line('Lead: '.$this->task->lead->contact_name.' · '.$this->task->lead->reference)
            ->action('Open CRM', route('crm.index'))
            ->line('This reminder was sent because the follow-up is due and remains open.');
    }
}
