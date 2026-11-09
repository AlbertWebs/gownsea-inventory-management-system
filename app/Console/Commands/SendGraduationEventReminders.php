<?php

namespace App\Console\Commands;

use App\Mail\UpcomingGraduationEventReminder;
use App\Models\GraduationEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

#[Signature('gownsea:send-graduation-event-reminders')]
#[Description('Send email reminders before upcoming graduation gown dates.')]
class SendGraduationEventReminders extends Command
{
    public function handle(): int
    {
        $today = Carbon::now(config('app.timezone'))->startOfDay();
        $recipient = config('mail.event_reminders.to', 'info@gownsea.com');
        $sent = 0;
        $failed = 0;

        foreach ([15, 10, 2] as $daysBefore) {
            $eventDate = $today->copy()->addDays($daysBefore)->toDateString();

            GraduationEvent::query()
                ->with(['institution:id,name', 'location:id,name'])
                ->where('event_date', $eventDate)
                ->whereIn('status', ['planned', 'confirmed'])
                ->orderBy('id')
                ->chunkById(100, function ($events) use ($eventDate, $daysBefore, $recipient, &$sent, &$failed): void {
                    foreach ($events as $event) {
                        $alreadySent = DB::table('graduation_event_reminders')
                            ->where('graduation_event_id', $event->id)
                            ->where('event_date', $eventDate)
                            ->where('days_before', $daysBefore)
                            ->exists();

                        if ($alreadySent) {
                            continue;
                        }

                        try {
                            Mail::to($recipient)->send(new UpcomingGraduationEventReminder($event, $daysBefore));

                            $now = Carbon::now(config('app.timezone'));
                            DB::table('graduation_event_reminders')->insertOrIgnore([
                                'graduation_event_id' => $event->id,
                                'event_date' => $eventDate,
                                'days_before' => $daysBefore,
                                'sent_at' => $now,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                            $sent++;
                        } catch (Throwable $exception) {
                            $failed++;
                            Log::warning('Graduation event reminder could not be sent.', [
                                'graduation_event_id' => $event->id,
                                'event_date' => $eventDate,
                                'days_before' => $daysBefore,
                                'exception' => $exception::class,
                            ]);
                        }
                    }
                });
        }

        $this->info("Sent {$sent} graduation event reminder(s); {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
