<?php

namespace App\Console\Commands;

use App\Actions\QueueCustomerSms;
use App\Models\Hire;
use Illuminate\Console\Command;

class QueueHireSmsReminders extends Command
{
    protected $signature = 'gownsea:queue-hire-sms-reminders';

    protected $description = 'Queue idempotent due-tomorrow and first-day-overdue hire SMS reminders.';

    public function handle(QueueCustomerSms $queueSms): int
    {
        $tomorrow = now(config('app.timezone'))->addDay()->toDateString();
        $yesterday = now(config('app.timezone'))->subDay()->toDateString();
        $count = 0;

        Hire::query()->with(['customer', 'location'])->whereIn('status', ['issued', 'partially_returned'])
            ->where(fn ($query) => $query->whereDate('due_at', $tomorrow)->orWhereDate('due_at', $yesterday))
            ->orderBy('id')->chunkById(200, function ($hires) use ($queueSms, $yesterday, &$count): void {
                foreach ($hires as $hire) {
                    $dueDate = $hire->due_at?->timezone(config('app.timezone'))->toDateString();
                    $overdue = $dueDate === $yesterday;
                    $key = 'hire-reminder:'.$hire->id.':'.($overdue ? 'overdue' : 'due').':'.$dueDate;
                    $queueSms->handle(
                        $overdue ? 'hire_overdue' : 'hire_due',
                        ['reference' => $hire->reference, 'due_date' => $hire->due_at?->timezone(config('app.timezone'))->format('d M Y') ?? '—'],
                        $key,
                        $hire->customer,
                        $hire,
                        location: $hire->location,
                    );
                    $count++;
                }
            });

        $this->info("Queued {$count} idempotent hire reminder record(s).");

        return self::SUCCESS;
    }
}
