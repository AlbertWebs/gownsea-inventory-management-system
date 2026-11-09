<?php

namespace App\Console\Commands;

use App\Models\CustomOrderFitting;
use App\Notifications\FittingAppointmentReminder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('gownsea:send-fitting-reminders')]
#[Description('Queue one email reminder for each upcoming fitting assigned to active staff.')]
class SendFittingReminders extends Command
{
    public function handle(): int
    {
        $now = Carbon::now(config('app.timezone'));
        $remindThrough = $now->copy()->addMinutes(max(5, (int) config('gownsea.fitting_reminder_minutes', 180)));
        $count = 0;

        CustomOrderFitting::query()
            ->with(['assignee', 'scheduler', 'order'])
            ->where('status', 'scheduled')
            ->whereNull('reminder_sent_at')
            ->whereBetween('appointment_at', [$now, $remindThrough])
            ->where(function ($query): void {
                $query->whereHas('assignee', fn ($users) => $users->where('is_active', true))
                    ->orWhereHas('scheduler', fn ($users) => $users->where('is_active', true));
            })
            ->orderBy('id')
            ->chunkById(100, function ($fittings) use ($now, &$count): void {
                foreach ($fittings as $fitting) {
                    $recipient = $fitting->assignee?->is_active ? $fitting->assignee : $fitting->scheduler;
                    if (! $recipient?->is_active || blank($recipient->email)) {
                        continue;
                    }

                    $claimed = CustomOrderFitting::query()
                        ->whereKey($fitting->id)
                        ->where('status', 'scheduled')
                        ->whereNull('reminder_sent_at')
                        ->update(['reminder_sent_at' => $now, 'updated_at' => $now]);
                    if ($claimed !== 1) {
                        continue;
                    }

                    try {
                        $recipient->notify(new FittingAppointmentReminder($fitting));
                        $count++;
                    } catch (Throwable $exception) {
                        CustomOrderFitting::query()->whereKey($fitting->id)->where('reminder_sent_at', $now)->update(['reminder_sent_at' => null]);
                        Log::warning('Fitting reminder could not be queued.', ['fitting_id' => $fitting->id, 'exception' => $exception::class]);
                    }
                }
            });

        $this->info("Queued {$count} fitting appointment reminder(s).");

        return self::SUCCESS;
    }
}
