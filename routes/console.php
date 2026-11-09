<?php

use App\Actions\RunHireAssetDepreciation;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('gownsea:send-lead-task-reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('gownsea:send-fitting-reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('gownsea:send-graduation-event-reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('gownsea:queue-hire-sms-reminders')->dailyAt('09:00')->withoutOverlapping();
Schedule::command('gownsea:backup')->dailyAt('01:00')->withoutOverlapping()->when(fn (): bool => filled(config('services.gownsea_backup.encryption_key')));
Artisan::command('gownsea:depreciate-hire-assets {period?}', function (RunHireAssetDepreciation $depreciation): void {
    $period = $this->argument('period') ?: now()->subMonth()->format('Y-m');
    $count = $depreciation->handle($period);
    $this->info("Posted {$count} serialized hire asset depreciation entries for {$period}.");
})->purpose('Post idempotent straight-line depreciation for serialized hire assets');
Schedule::command('gownsea:depreciate-hire-assets')->monthlyOn(1, '02:00')->withoutOverlapping();
