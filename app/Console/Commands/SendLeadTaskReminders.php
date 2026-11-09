<?php

namespace App\Console\Commands;

use App\Models\LeadTask;
use App\Notifications\LeadTaskReminderNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('gownsea:send-lead-task-reminders')]
#[Description('Queue one email reminder for each due, open CRM task assigned to active staff.')]
class SendLeadTaskReminders extends Command
{
    public function handle(): int
    {
        $count = 0;
        LeadTask::query()
            ->with(['assignee', 'lead'])
            ->whereNull('completed_at')
            ->whereNull('reminder_sent_at')
            ->where('due_at', '<=', Carbon::now())
            ->whereNotNull('assigned_to')
            ->whereHas('assignee', fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->chunkById(100, function ($tasks) use (&$count): void {
                foreach ($tasks as $task) {
                    $claimed = LeadTask::query()
                        ->whereKey($task->id)
                        ->whereNull('completed_at')
                        ->whereNull('reminder_sent_at')
                        ->update(['reminder_sent_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

                    if ($claimed === 1 && $task->assignee?->is_active) {
                        $task->assignee->notify(new LeadTaskReminderNotification($task));
                        $count++;
                    }
                }
            });

        $this->info("Queued {$count} CRM follow-up reminder(s).");

        return self::SUCCESS;
    }
}
