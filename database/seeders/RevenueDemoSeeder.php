<?php

namespace Database\Seeders;

use App\Actions\PostFinancialJournal;
use App\Models\Hire;
use App\Models\Payment;
use App\Observers\PaymentObserver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class RevenueDemoSeeder extends Seeder
{
    /**
     * Seed sample receipts that appear in revenue reports and the dashboard.
     * These records are only for local and automated testing environments.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Revenue demo data can only be seeded in local or testing environments.');
        }

        $hireReferences = ['DEMO-HIRE-ISSUED', 'DEMO-HIRE-RETURNED', 'DEMO-HIRE-EXCEPTION'];
        $hires = Hire::query()
            ->with(['customer', 'location'])
            ->whereIn('reference', $hireReferences)
            ->get()
            ->keyBy('reference');

        if ($hires->count() !== count($hireReferences)) {
            throw new LogicException('Run GownseaDemoSeeder before RevenueDemoSeeder so the sample hires exist.');
        }

        $amounts = [100000, 125000, 150000, 175000, 200000, 225000];
        $methods = ['mpesa', 'cash', 'bank_transfer', 'mpesa', 'cash', 'bank_transfer'];
        $journal = app(PostFinancialJournal::class);
        $now = now();

        DB::transaction(function () use ($amounts, $methods, $hires, $journal, $now): void {
            foreach (range(5, 0) as $monthsAgo) {
                $receivedAt = $now->copy()->subMonthsNoOverflow($monthsAgo)->startOfMonth()->addDays(4)->setTime(10, 0);
                $monthKey = $receivedAt->format('Ym');
                $hire = $hires->get(['DEMO-HIRE-ISSUED', 'DEMO-HIRE-RETURNED', 'DEMO-HIRE-EXCEPTION'][$monthsAgo % 3]);
                $amount = $amounts[5 - $monthsAgo];
                $method = $methods[5 - $monthsAgo];
                $reference = 'DEMO-REV-'.$monthKey;
                $providerReference = 'DEMO-REVENUE-'.$monthKey;

                DB::table('payments')->updateOrInsert(
                    ['reference' => $reference],
                    [
                        'idempotency_key' => 'demo-revenue-'.$monthKey,
                        'customer_id' => $hire->customer_id,
                        'hire_id' => $hire->id,
                        'custom_order_id' => null,
                        'bulk_sale_id' => null,
                        'location_id' => $hire->location_id,
                        'recorded_by' => $hire->created_by,
                        'amount_minor' => $amount,
                        'currency' => 'KES',
                        'method' => $method,
                        'purpose' => 'service_charge',
                        'status' => 'received',
                        'provider_reference' => $providerReference,
                        'received_at' => $receivedAt,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );

                $payment = Payment::query()->where('reference', $reference)->firstOrFail();
                $journal->handle(
                    Payment::class,
                    $payment->id,
                    'receipt',
                    $payment->reference,
                    'Demo revenue receipt: '.$payment->reference,
                    $payment->location_id,
                    $payment->recorded_by,
                    $payment->received_at->toDateString(),
                    [
                        ['account' => PaymentObserver::cashAccount($method), 'debit_minor' => $amount, 'credit_minor' => 0],
                        ['account' => '4000', 'debit_minor' => 0, 'credit_minor' => $amount],
                    ],
                );
            }
        });
    }
}
