<?php

namespace App\Console\Commands;

use App\Actions\QueueCustomerSms;
use App\Models\CustomOrderFitting;
use App\Models\CustomOrderInvoice;
use App\Models\Hire;
use App\Models\HireInvoice;
use App\Models\SmsNotificationRule;
use Illuminate\Console\Command;

class QueueHireSmsReminders extends Command
{
    protected $signature = 'gownsea:queue-hire-sms-reminders';

    protected $description = 'Queue configured, idempotent upcoming and overdue hire reminders.';

    public function handle(QueueCustomerSms $queueSms): int
    {
        $today = now(config('app.timezone'))->startOfDay();
        $dueIntervals = SmsNotificationRule::query()->where('key', 'hire_due')->first()?->intervals ?? [3, 1];
        $overdueIntervals = SmsNotificationRule::query()->where('key', 'hire_overdue')->first()?->intervals ?? [1, 3, 7];
        $dueDays = collect($dueIntervals)->map(fn ($day): int => max(0, (int) $day))->unique()->values();
        $overdueDays = collect($overdueIntervals)->map(fn ($day): int => max(1, (int) $day))->unique()->values();
        $maxUpcoming = (int) ($dueDays->max() ?? 0);
        $maxOverdue = (int) ($overdueDays->max() ?? 1);
        $fromDate = $today->copy()->subDays($maxOverdue)->toDateString();
        $toDate = $today->copy()->addDays($maxUpcoming)->toDateString();
        $count = 0;

        Hire::query()->with(['customer', 'location'])->whereIn('status', ['issued', 'partially_returned'])
            ->whereDate('due_at', '>=', $fromDate)->whereDate('due_at', '<=', $toDate)
            ->orderBy('id')->chunkById(200, function ($hires) use ($queueSms, $today, $dueDays, $overdueDays, &$count): void {
                foreach ($hires as $hire) {
                    $dueDate = $hire->due_at?->timezone(config('app.timezone'))->toDateString();
                    $days = (int) $today->diffInDays($dueDate, false);
                    $overdue = $days < 0;
                    $interval = abs($days);
                    if (($overdue && ! $overdueDays->contains($interval)) || (! $overdue && ! $dueDays->contains($interval))) {
                        continue;
                    }
                    $key = 'hire-reminder:'.$hire->id.':'.($overdue ? 'overdue' : 'due').':'.$interval.':'.$dueDate;
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

        foreach ([HireInvoice::class => 'hire', CustomOrderInvoice::class => 'order'] as $invoiceModel => $type) {
            $invoiceDueDays = collect(SmsNotificationRule::query()->where('key', 'invoice_due')->first()?->intervals ?? [3, 1])->map(fn ($day): int => max(0, (int) $day));
            $invoiceOverdueDays = collect(SmsNotificationRule::query()->where('key', 'invoice_overdue')->first()?->intervals ?? [1, 7, 30])->map(fn ($day): int => max(1, (int) $day));
            $invoiceRelations = $type === 'hire' ? ['hire'] : ['order'];
            $invoiceModel::query()->with(['customer', 'location', ...$invoiceRelations])->withSum('allocations', 'amount_minor')
                ->whereNotNull('due_on')->whereDate('due_on', '>=', $today->copy()->subDays((int) ($invoiceOverdueDays->max() ?? 30))->toDateString())
                ->whereDate('due_on', '<=', $today->copy()->addDays((int) ($invoiceDueDays->max() ?? 3))->toDateString())
                ->orderBy('id')->chunkById(200, function ($invoices) use ($queueSms, $today, $type, $invoiceDueDays, $invoiceOverdueDays, &$count): void {
                    foreach ($invoices as $invoice) {
                        $balance = max(0, $invoice->total_minor - (int) ($invoice->allocations_sum_amount_minor ?? 0));
                        if ($balance === 0) {
                            continue;
                        }
                        $days = (int) $today->diffInDays($invoice->due_on, false);
                        $overdue = $days < 0;
                        $intervals = $overdue ? $invoiceOverdueDays : $invoiceDueDays;
                        if (! collect($intervals)->contains(abs($days))) {
                            continue;
                        }
                        $reference = $type === 'hire' ? $invoice->hire?->reference : $invoice->order?->reference;
                        $key = 'invoice-balance:'.$type.':'.$invoice->id.':'.($overdue ? 'overdue' : 'due').':'.abs($days);
                        $queueSms->handle($overdue ? 'invoice_overdue' : 'invoice_due', [
                            'balance' => number_format($balance / 100, 2),
                            'reference' => $reference ?? $invoice->number,
                            'due_date' => $invoice->due_on->format('d M Y'),
                        ], $key, $invoice->customer, $type === 'hire' ? $invoice->hire : null, $type === 'order' ? $invoice->order : null, $invoice->location);
                        $count++;
                    }
                });
        }

        $fittingRule = SmsNotificationRule::query()->where('key', 'fitting_reminder')->first();
        $fittingDays = collect($fittingRule?->intervals ?? [1])->map(fn ($day): int => max(0, (int) $day));
        CustomOrderFitting::query()->with(['order.customer', 'order.location'])->where('status', 'scheduled')
            ->whereDate('appointment_at', '>=', $today->toDateString())->whereDate('appointment_at', '<=', $today->copy()->addDays((int) ($fittingDays->max() ?? 1))->toDateString())
            ->orderBy('id')->chunkById(200, function ($fittings) use ($queueSms, $today, $fittingDays, &$count): void {
                foreach ($fittings as $fitting) {
                    $days = (int) $today->diffInDays($fitting->appointment_at->timezone(config('app.timezone'))->startOfDay(), false);
                    if (! $fittingDays->contains($days)) {
                        continue;
                    }
                    $order = $fitting->order;
                    $queueSms->handle('fitting_reminder', ['reference' => $order->reference, 'appointment' => $fitting->appointment_at->timezone(config('app.timezone'))->format('d M Y H:i')], 'fitting-reminder:'.$fitting->id.':'.$fitting->appointment_at->toDateString(), $order->customer, order: $order, location: $order->location);
                    $count++;
                }
            });

        $this->info("Queued {$count} idempotent customer reminder record(s).");

        return self::SUCCESS;
    }
}
