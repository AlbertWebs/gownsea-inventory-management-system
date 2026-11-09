<?php

namespace App\Providers;

use App\Models\CustomOrderDepositApplication;
use App\Models\HireDepositApplication;
use App\Models\OperatingExpense;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Observers\CustomOrderDepositApplicationObserver;
use App\Observers\HireDepositApplicationObserver;
use App\Observers\OperatingExpenseObserver;
use App\Observers\PaymentObserver;
use App\Observers\PaymentRefundObserver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Payment::observe(PaymentObserver::class);
        PaymentRefund::observe(PaymentRefundObserver::class);
        HireDepositApplication::observe(HireDepositApplicationObserver::class);
        CustomOrderDepositApplication::observe(CustomOrderDepositApplicationObserver::class);
        OperatingExpense::observe(OperatingExpenseObserver::class);

        DB::listen(function (QueryExecuted $query): void {
            if ($query->time < max(1, (int) config('gownsea.slow_query_threshold_ms', 1000))) {
                return;
            }

            Log::warning('Slow database query observed.', [
                'query_hash' => hash('sha256', $query->sql),
                'duration_ms' => $query->time,
                'connection' => $query->connectionName,
                'route' => app()->bound('request') ? request()->route()?->getName() : null,
            ]);
        });
    }
}
