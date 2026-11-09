<?php

namespace App\Console\Commands;

use App\Actions\IssueCustomOrderInvoice;
use App\Actions\IssueHireInvoice;
use App\Models\BusinessConfiguration;
use App\Models\CustomOrder;
use App\Models\Hire;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('gownsea:invoice-backfill {--dry-run : Preview eligible historical invoices without writing} {--confirm-reviewed : Create the reviewed historical invoices and post their journals} {--chunk=100 : Records to process per database batch}')]
#[Description('Preview and, after finance review, backfill eligible historical hire and custom-order invoices')]
class BackfillOperationalInvoices extends Command
{
    public function handle(IssueHireInvoice $hireInvoice, IssueCustomOrderInvoice $orderInvoice): int
    {
        $hireQuery = Hire::query()->whereNotNull('issued_at')->whereIn('status', ['issued', 'partially_returned', 'returned_pending_inspection', 'completed'])
            ->whereDoesntHave('invoice')
            ->whereHas('items')
            ->whereDoesntHave('items', fn ($query) => $query->whereNotIn('status', ['issued', 'partially_returned', 'returned_pending_inspection', 'completed', 'missing']));
        $orderQuery = CustomOrder::query()->where('status', 'completed')->whereNotNull('completed_at')->where('agreed_total_minor', '>', 0)
            ->whereDoesntHave('invoice');
        $hireCount = (clone $hireQuery)->count();
        $orderCount = (clone $orderQuery)->count();

        $this->info("Eligible hire invoices: {$hireCount}");
        $this->info("Eligible completed custom-order invoices: {$orderCount}");
        $chunkSize = max(1, min(500, (int) $this->option('chunk')));
        $this->showReviewList(clone $hireQuery, clone $orderQuery, $chunkSize);
        if ($this->option('dry-run') || ! $this->option('confirm-reviewed')) {
            $this->comment('No invoices were created. Finance must review the source records and run again with --confirm-reviewed to post them.');

            return self::SUCCESS;
        }
        if ($hireCount === 0 && $orderCount === 0) {
            $this->comment('No unposted historical invoices remain.');

            return self::SUCCESS;
        }

        $cutover = BusinessConfiguration::query()->where('configuration_key', 'historical_cutover')->where('review_status', 'approved')
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()->toDateString()))
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
        if ($cutover === null) {
            $this->error('Posting is blocked until an independently approved historical cutover configuration exists in Settings.');

            return self::FAILURE;
        }
        $approved = $cutover->configuration;
        if ((int) ($approved['candidate_hire_count'] ?? -1) !== $hireCount || (int) ($approved['candidate_order_count'] ?? -1) !== $orderCount) {
            $this->error('The current candidate counts differ from the approved cutover snapshot. Review a fresh dry run and approve a new cutover record.');

            return self::FAILURE;
        }

        $createdHires = 0;
        $createdOrders = 0;
        $hireQuery->chunkById($chunkSize, function ($hires) use ($hireInvoice, &$createdHires): void {
            foreach ($hires as $hire) {
                if ($hireInvoice->handle($hire) !== null) {
                    $createdHires++;
                }
            }
        });
        $orderQuery->chunkById($chunkSize, function ($orders) use ($orderInvoice, &$createdOrders): void {
            foreach ($orders as $order) {
                if ($orderInvoice->handle($order) !== null) {
                    $createdOrders++;
                }
            }
        });

        $this->info("Backfilled hire invoices: {$createdHires}");
        $this->info("Backfilled custom-order invoices: {$createdOrders}");

        return self::SUCCESS;
    }

    private function showReviewList(Builder $hireQuery, Builder $orderQuery, int $chunkSize): void
    {
        $hireQuery->with(['customer:id,name', 'location:id,name', 'items:id,hire_id,quantity,unit_rate_minor'])
            ->chunkById($chunkSize, function (Collection $hires): void {
                $hireIds = $hires->modelKeys();
                $received = $this->netReceiptsByHire($hireIds);
                $deposits = DB::table('hire_deposit_applications')->whereIn('hire_id', $hireIds)
                    ->selectRaw('hire_id, SUM(amount_minor) as applied_minor')->groupBy('hire_id')->pluck('applied_minor', 'hire_id');

                foreach ($hires as $hire) {
                    $amount = $hire->items->sum(fn ($item): int => $item->quantity * $item->unit_rate_minor);
                    $netReceived = (int) ($received[$hire->id] ?? 0);
                    $depositApplied = (int) ($deposits[$hire->id] ?? 0);
                    $balance = $amount - $netReceived - $depositApplied;
                    $this->line(implode(' | ', [
                        'Hire', $hire->reference, $hire->customer->name, $hire->location->name, $hire->issued_at->toDateString(),
                        'invoice '.self::kes($amount), 'charge receipts net of refunds '.self::kes($netReceived),
                        'deposit applications '.self::kes($depositApplied), 'remaining balance '.self::kes($balance),
                    ]));
                }
            });
        $orderQuery->with(['customer:id,name', 'location:id,name'])
            ->chunkById($chunkSize, function (Collection $orders): void {
                $orderIds = $orders->modelKeys();
                $received = $this->netReceiptsByCustomOrder($orderIds);
                $deposits = DB::table('custom_order_deposit_applications')->whereIn('custom_order_id', $orderIds)
                    ->selectRaw('custom_order_id, SUM(amount_minor) as applied_minor')->groupBy('custom_order_id')->pluck('applied_minor', 'custom_order_id');

                foreach ($orders as $order) {
                    $amount = (int) $order->agreed_total_minor;
                    $netReceived = (int) ($received[$order->id] ?? 0);
                    $depositApplied = (int) ($deposits[$order->id] ?? 0);
                    $balance = $amount - $netReceived - $depositApplied;
                    $this->line(implode(' | ', [
                        'Custom order', $order->reference, $order->customer->name, $order->location->name, $order->completed_at->toDateString(),
                        'invoice '.self::kes($amount), 'charge receipts net of refunds '.self::kes($netReceived),
                        'deposit applications '.self::kes($depositApplied), 'remaining balance '.self::kes($balance),
                    ]));
                }
            });
    }

    /** @param list<int> $hireIds @return array<int, int> */
    private function netReceiptsByHire(array $hireIds): array
    {
        $refunds = DB::table('payment_refunds')->selectRaw('payment_id, SUM(amount_minor) as refunded_minor')->groupBy('payment_id');

        return DB::table('payments as p')->leftJoinSub($refunds, 'r', 'r.payment_id', '=', 'p.id')
            ->whereIn('p.hire_id', $hireIds)->where('p.status', 'received')->where('p.purpose', 'service_charge')
            ->selectRaw('p.hire_id as record_id, SUM(p.amount_minor - COALESCE(r.refunded_minor, 0)) as net_received_minor')
            ->groupBy('p.hire_id')->pluck('net_received_minor', 'record_id')->map(fn ($amount): int => (int) $amount)->all();
    }

    /** @param list<int> $orderIds @return array<int, int> */
    private function netReceiptsByCustomOrder(array $orderIds): array
    {
        $refunds = DB::table('payment_refunds')->selectRaw('payment_id, SUM(amount_minor) as refunded_minor')->groupBy('payment_id');

        return DB::table('payments as p')->leftJoinSub($refunds, 'r', 'r.payment_id', '=', 'p.id')
            ->whereIn('p.custom_order_id', $orderIds)->where('p.status', 'received')->where('p.purpose', 'custom_order_charge')
            ->selectRaw('p.custom_order_id as record_id, SUM(p.amount_minor - COALESCE(r.refunded_minor, 0)) as net_received_minor')
            ->groupBy('p.custom_order_id')->pluck('net_received_minor', 'record_id')->map(fn ($amount): int => (int) $amount)->all();
    }

    private static function kes(int $minor): string
    {
        $absolute = abs($minor);

        return 'KES '.($minor < 0 ? '-' : '').intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
