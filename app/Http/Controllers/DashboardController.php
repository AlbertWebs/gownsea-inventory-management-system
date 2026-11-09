<?php

namespace App\Http\Controllers;

use App\Models\BulkStockBalance;
use App\Models\CustomOrder;
use App\Models\CustomOrderDepositApplication;
use App\Models\GraduationEvent;
use App\Models\Hire;
use App\Models\HireDepositApplication;
use App\Models\HireItem;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PhysicalAsset;
use App\Models\SmsMessage;
use App\Services\HirePenaltyBalance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, HirePenaltyBalance $penaltyBalance): Response
    {
        $user = $request->user();
        $assignedLocationIds = $user->mayViewAllLocations()
            ? null
            : $user->locations()->where('is_active', true)->pluck('locations.id')->all();

        $locations = Location::query()
            ->where('is_active', true)
            ->when($assignedLocationIds !== null, fn ($query) => $query->whereIn('id', $assignedLocationIds))
            ->orderBy('type')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type']);

        $requestedLocation = $request->integer('location');
        $period = in_array($request->string('period')->toString(), ['6', '12'], true)
            ? (int) $request->string('period')->toString()
            : 6;
        $selectedLocationId = $requestedLocation > 0 && $locations->contains('id', $requestedLocation)
            ? $requestedLocation
            : null;

        if ($selectedLocationId === null && $assignedLocationIds !== null) {
            $primaryLocationId = $user->locations()->wherePivot('is_primary', true)->value('locations.id');
            $selectedLocationId = $locations->firstWhere('id', $primaryLocationId)?->id ?? $locations->first()?->id;
        }

        $locationIds = $selectedLocationId !== null ? [$selectedLocationId] : ($assignedLocationIds ?? $locations->modelKeys());
        $hasLocationScope = $selectedLocationId !== null || $assignedLocationIds !== null;
        $assetQuery = PhysicalAsset::query()->when($hasLocationScope, function ($query) use ($locationIds): void {
            $query->where(function ($assets) use ($locationIds): void {
                $assets->whereIn('current_location_id', $locationIds)
                    ->orWhereHas('transfer', fn ($transfer) => $transfer
                        ->whereIn('source_location_id', $locationIds)
                        ->orWhereIn('destination_location_id', $locationIds))
                    ->orWhereHas('hireItems', fn ($items) => $items->where('status', 'issued')
                        ->whereHas('hire', fn ($hire) => $hire->whereIn('location_id', $locationIds)));
            });
        });

        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $available = (clone $assetQuery)->where('status', 'available')->count();
        $currentlyHired = (clone $assetQuery)->where('status', 'hired')->count();
        $bulkBalances = BulkStockBalance::query()->when($hasLocationScope, fn ($query) => $query->whereIn('location_id', $locationIds));
        $bulkOnHand = (int) (clone $bulkBalances)->sum('on_hand');
        $bulkAvailable = (int) (clone $bulkBalances)->selectRaw('COALESCE(SUM(on_hand - reserved - pending_inspection - damaged), 0) as aggregate')->value('aggregate');
        $bulkReserved = (int) (clone $bulkBalances)->sum('reserved');
        $bulkInspection = (int) (clone $bulkBalances)->sum('pending_inspection');
        $bulkDamaged = (int) (clone $bulkBalances)->sum('damaged');
        $bulkHiredQuery = HireItem::query()->join('hires', 'hires.id', '=', 'hire_items.hire_id')->whereNull('hire_items.physical_asset_id')
            ->whereIn('hire_items.status', ['issued', 'partially_returned'])->when($hasLocationScope, fn ($query) => $query->whereIn('hires.location_id', $locationIds));
        $bulkHired = (int) (clone $bulkHiredQuery)->sum(new Expression('hire_items.quantity - hire_items.returned_quantity'));
        $available += $bulkAvailable;
        $currentlyHired += $bulkHired;
        $inventoryTotal = (clone $assetQuery)->whereNotIn('status', ['sold', 'written_off'])->count() + $bulkOnHand + $bulkHired;
        $dueToday = $this->unreturnedQuantity($locationIds, $hasLocationScope, $today, $today->endOfDay());
        $overdue = $this->unreturnedQuantity($locationIds, $hasLocationScope, null, $today);
        $issuedNotDue = max(0, $currentlyHired - $dueToday - $overdue);
        $assetsByStatus = (clone $assetQuery)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $assetsByStatus['reserved'] = (int) ($assetsByStatus['reserved'] ?? 0) + $bulkReserved;
        $assetsByStatus['returned_pending_inspection'] = (int) ($assetsByStatus['returned_pending_inspection'] ?? 0) + $bulkInspection;
        $assetsByStatus['damaged'] = (int) ($assetsByStatus['damaged'] ?? 0) + $bulkDamaged;

        $customOrdersDue = CustomOrder::query()
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereDate('expected_completion_date', '<=', $today->toDateString())
            ->when($hasLocationScope, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->count();
        $activeCustomOrders = CustomOrder::query()
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->when($hasLocationScope, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->count();
        $activeLeads = Lead::query()
            ->whereNotIn('stage', ['won', 'lost'])
            ->when($hasLocationScope, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->count();
        $leadFollowUpsDue = Lead::query()
            ->whereNotIn('stage', ['won', 'lost'])
            ->where(function ($query) use ($today): void {
                $query->where('follow_up_at', '<=', $today->endOfDay())
                    ->orWhereHas('tasks', fn ($tasks) => $tasks->whereNull('completed_at')->where('due_at', '<=', $today->endOfDay()));
            })
            ->when($hasLocationScope, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->count();
        $outstandingReceivables = $this->outstandingReceivables($locationIds, $hasLocationScope, $penaltyBalance);
        $smsRemindersPending = SmsMessage::query()
            ->where('status', 'queued')
            ->whereDate('queued_at', $today->toDateString())
            ->whereHas('template', fn ($query) => $query->whereIn('key', ['hire_due', 'hire_overdue']))
            ->when($hasLocationScope, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->count();

        $months = collect(range($period - 1, 0))->map(function (int $monthsAgo) use ($locationIds, $hasLocationScope, $today): array {
            $month = $today->subMonthsNoOverflow($monthsAgo);
            $amount = Payment::query()
                ->where('status', 'received')
                ->whereIn('purpose', ['service_charge', 'penalty', 'custom_order_charge', 'product_sale'])
                ->whereBetween('received_at', [$month->startOfMonth(), $month->endOfMonth()])
                ->when($hasLocationScope, fn ($query) => $query->whereIn('location_id', $locationIds))
                ->sum('amount_minor');
            $refunded = PaymentRefund::query()->whereHas('payment', fn ($query) => $query->whereIn('purpose', ['service_charge', 'penalty', 'custom_order_charge', 'product_sale'])->when($hasLocationScope, fn ($q) => $q->whereIn('location_id', $locationIds)))
                ->whereBetween('refunded_at', [$month->startOfMonth(), $month->endOfMonth()])->sum('amount_minor');
            $depositApplications = CustomOrderDepositApplication::query()->whereBetween('applied_at', [$month->startOfMonth(), $month->endOfMonth()])
                ->whereHas('order', fn ($query) => $query->when($hasLocationScope, fn ($q) => $q->whereIn('location_id', $locationIds)))->sum('amount_minor');
            $hireDepositApplications = HireDepositApplication::query()->whereBetween('applied_at', [$month->startOfMonth(), $month->endOfMonth()])
                ->whereHas('hire', fn ($query) => $query->when($hasLocationScope, fn ($q) => $q->whereIn('location_id', $locationIds)))->sum('amount_minor');

            return ['label' => $month->format('M'), 'value' => (int) $amount - (int) $refunded + (int) $depositApplications + (int) $hireDepositApplications];
        });

        $recentHires = Hire::query()
            ->with([
                'customer:id,name',
                'location:id,name',
                'institution:id,name',
                'items:id,hire_id,quantity,returned_quantity,product_name_snapshot,unit_rate_minor',
            ])
            ->when($hasLocationScope, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->latest('created_at')
            ->limit(7)
            ->get()
            ->map(fn (Hire $hire): array => [
                'id' => $hire->id,
                'reference' => $hire->reference,
                'date' => $hire->created_at->timezone(config('app.timezone'))->format('d M Y'),
                'customer' => $hire->customer->name,
                'institution' => $hire->institution?->name ?? $hire->location->name,
                'items' => $hire->items->sum('quantity'),
                'itemLabel' => $hire->items->map(fn (HireItem $item): string => $item->product_name_snapshot)->unique()->join(', '),
                'dueDate' => $hire->due_at?->timezone(config('app.timezone'))->format('d M Y') ?? '—',
                'amount' => $hire->items->sum(fn (HireItem $item): int => $item->unit_rate_minor * $item->quantity),
                'status' => $hire->status,
            ]);

        $events = GraduationEvent::query()
            ->with('institution:id,name')
            ->whereDate('event_date', '>=', $today->toDateString())
            ->whereIn('status', ['planned', 'confirmed'])
            ->when($hasLocationScope, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->orderBy('event_date')
            ->limit(3)
            ->get()
            ->map(fn (GraduationEvent $event): array => [
                'id' => $event->id,
                'institution' => $event->institution->name,
                'name' => $event->name,
                'date' => $event->event_date->format('d'),
                'month' => strtoupper($event->event_date->format('M')),
                'expected' => $event->expected_quantity,
                'venue' => $event->venue,
            ]);

        $receivedThisPeriod = Payment::query()
            ->where('status', 'received')
            ->whereIn('purpose', ['service_charge', 'penalty', 'custom_order_charge', 'product_sale'])
            ->whereBetween('received_at', [$today->startOfMonth(), $today->endOfDay()])
            ->when($hasLocationScope, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->sum('amount_minor');
        $refundedThisPeriod = PaymentRefund::query()->whereHas('payment', fn ($query) => $query->whereIn('purpose', ['service_charge', 'penalty', 'custom_order_charge', 'product_sale'])->when($hasLocationScope, fn ($q) => $q->whereIn('location_id', $locationIds)))
            ->whereBetween('refunded_at', [$today->startOfMonth(), $today->endOfDay()])->sum('amount_minor');
        $depositApplicationsThisPeriod = CustomOrderDepositApplication::query()->whereBetween('applied_at', [$today->startOfMonth(), $today->endOfDay()])
            ->whereHas('order', fn ($query) => $query->when($hasLocationScope, fn ($q) => $q->whereIn('location_id', $locationIds)))->sum('amount_minor');
        $hireDepositApplicationsThisPeriod = HireDepositApplication::query()->whereBetween('applied_at', [$today->startOfMonth(), $today->endOfDay()])
            ->whereHas('hire', fn ($query) => $query->when($hasLocationScope, fn ($q) => $q->whereIn('location_id', $locationIds)))->sum('amount_minor');
        $receivedThisPeriod = $receivedThisPeriod - $refundedThisPeriod + $depositApplicationsThisPeriod + $hireDepositApplicationsThisPeriod;

        return Inertia::render('Dashboard', [
            'filters' => ['locationId' => $selectedLocationId, 'locations' => $locations, 'period' => $period],
            'metrics' => [
                'inventoryTotal' => $inventoryTotal,
                'available' => $available,
                'currentlyHired' => $currentlyHired,
                'dueToday' => $dueToday,
                'overdue' => $overdue,
                'damaged' => (int) ($assetsByStatus['damaged'] ?? 0),
                'missing' => (int) ($assetsByStatus['missing'] ?? 0),
                'underRepair' => (int) ($assetsByStatus['under_repair'] ?? 0),
                'reserved' => (int) ($assetsByStatus['reserved'] ?? 0),
                'inTransit' => (int) ($assetsByStatus['in_transit'] ?? 0),
                'inspection' => (int) ($assetsByStatus['returned_pending_inspection'] ?? 0),
                'receivedThisPeriod' => (int) $receivedThisPeriod,
                'customOrdersDue' => $customOrdersDue,
                'activeCustomOrders' => $activeCustomOrders,
                'activeLeads' => $activeLeads,
                'leadFollowUpsDue' => $leadFollowUpsDue,
                'outstandingReceivables' => $outstandingReceivables,
                'smsRemindersPending' => $smsRemindersPending,
            ],
            'returnStatus' => [
                ['label' => 'Available', 'value' => $available, 'color' => '#0c9561'],
                ['label' => 'Due today', 'value' => $dueToday, 'color' => '#d7a750'],
                ['label' => 'Overdue', 'value' => $overdue, 'color' => '#f05b38'],
                ['label' => 'Issued, not yet due', 'value' => $issuedNotDue, 'color' => '#d42127'],
                ['label' => 'Inspection', 'value' => (int) ($assetsByStatus['returned_pending_inspection'] ?? 0), 'color' => '#90949c'],
                ['label' => 'Damaged', 'value' => (int) ($assetsByStatus['damaged'] ?? 0), 'color' => '#ef733e'],
                ['label' => 'Missing', 'value' => (int) ($assetsByStatus['missing'] ?? 0), 'color' => '#3a414d'],
                ['label' => 'Other unavailable', 'value' => (int) ($assetsByStatus['in_transit'] ?? 0)
                    + (int) ($assetsByStatus['reserved'] ?? 0)
                    + (int) ($assetsByStatus['under_repair'] ?? 0)
                    + (int) ((clone $assetQuery)->whereNotIn('status', ['available', 'hired', 'returned_pending_inspection', 'in_transit', 'reserved', 'damaged', 'missing', 'under_repair', 'sold', 'written_off'])->count()), 'color' => '#727986'],
            ],
            'revenue' => $months,
            'recentHires' => $recentHires,
            'upcomingEvents' => $events,
            'today' => $today->format('l, d F Y'),
        ]);
    }

    private function unreturnedQuantity(array $locationIds, bool $hasLocationScope, ?CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (int) HireItem::query()
            ->join('hires', 'hires.id', '=', 'hire_items.hire_id')
            ->whereIn('hire_items.status', ['issued', 'partially_returned'])
            ->when($start, fn ($query) => $query->where('hires.due_at', '>=', $start))
            ->where('hires.due_at', '<=', $end)
            ->when($hasLocationScope, fn ($query) => $query->whereIn('hires.location_id', $locationIds))
            ->sum(new Expression('hire_items.quantity - hire_items.returned_quantity'));
    }

    /** @param list<int> $locationIds */
    private function outstandingReceivables(array $locationIds, bool $hasLocationScope, HirePenaltyBalance $penaltyBalance): int
    {
        $hireBalances = DB::table('hires as h')
            ->whereIn('h.status', ['issued', 'partially_returned', 'returned_pending_inspection', 'completed'])
            ->when($hasLocationScope, fn ($query) => $query->whereIn('h.location_id', $locationIds))
            ->selectRaw("COALESCE((SELECT SUM(i.quantity * i.unit_rate_minor) FROM hire_items i WHERE i.hire_id = h.id), 0) + COALESCE((SELECT SUM(i.penalty_assessed_minor) FROM hire_items i WHERE i.hire_id = h.id), 0) - COALESCE((SELECT SUM(a.amount_minor) FROM penalty_adjustments a WHERE a.hire_id = h.id AND a.kind = 'waiver'), 0) as billed_minor")
            ->selectRaw("COALESCE((SELECT SUM(p.amount_minor - COALESCE((SELECT SUM(r.amount_minor) FROM payment_refunds r WHERE r.payment_id = p.id), 0)) FROM payments p WHERE p.hire_id = h.id AND p.status = 'received' AND p.purpose IN ('service_charge', 'penalty')), 0) + COALESCE((SELECT SUM(a.amount_minor) FROM hire_deposit_applications a WHERE a.hire_id = h.id), 0) as settled_minor");

        $customOrderBalances = DB::table('custom_order_invoices as i')
            ->join('custom_orders as o', 'o.id', '=', 'i.custom_order_id')
            ->where('o.status', '!=', 'cancelled')
            ->when($hasLocationScope, fn ($query) => $query->whereIn('i.location_id', $locationIds))
            ->selectRaw('i.total_minor as billed_minor')
            ->selectRaw("COALESCE((SELECT SUM(CASE WHEN a.kind = 'refund' THEN -a.amount_minor ELSE a.amount_minor END) FROM custom_order_invoice_allocations a WHERE a.custom_order_invoice_id = i.id), 0) as settled_minor");

        $balances = $hireBalances->unionAll($customOrderBalances);

        $activeHireIds = Hire::query()->whereIn('status', ['issued', 'partially_returned'])
            ->when($hasLocationScope, fn ($query) => $query->whereIn('location_id', $locationIds))->pluck('id');
        $accruedByHire = $penaltyBalance->accruedByHireIds($activeHireIds);
        $activeHireBalances = Hire::query()->whereIn('id', $activeHireIds)->select('id')
            ->selectSub(HireItem::query()->selectRaw('COALESCE(SUM(quantity * unit_rate_minor), 0)')->whereColumn('hire_id', 'hires.id'), 'charge_minor')
            ->selectSub(HireItem::query()->selectRaw('COALESCE(SUM(penalty_assessed_minor), 0)')->whereColumn('hire_id', 'hires.id'), 'penalty_minor')
            ->selectSub(DB::table('penalty_adjustments')->selectRaw('COALESCE(SUM(amount_minor), 0)')->whereColumn('hire_id', 'hires.id')->where('kind', 'waiver'), 'waived_minor')
            ->selectSub(Payment::query()->selectRaw('COALESCE(SUM(amount_minor - COALESCE((SELECT SUM(payment_refunds.amount_minor) FROM payment_refunds WHERE payment_refunds.payment_id = payments.id), 0)), 0)')
                ->whereColumn('hire_id', 'hires.id')->where('status', 'received')->whereIn('purpose', ['service_charge', 'penalty']), 'received_minor')
            ->selectSub(HireDepositApplication::query()->selectRaw('COALESCE(SUM(amount_minor), 0)')->whereColumn('hire_id', 'hires.id'), 'applied_minor')
            ->get()->sum(function (Hire $hire) use ($accruedByHire): int {
                $billed = (int) $hire->charge_minor + (int) $hire->penalty_minor - (int) $hire->waived_minor;
                $settled = (int) $hire->received_minor + (int) $hire->applied_minor;
                $baseline = max(0, $billed - $settled);

                return max(0, $billed + (int) $accruedByHire->get($hire->id, 0) - $settled) - $baseline;
            });

        return (int) DB::query()->fromSub($balances, 'receivables')
            ->selectRaw('COALESCE(SUM(CASE WHEN billed_minor > settled_minor THEN billed_minor - settled_minor ELSE 0 END), 0) as outstanding_minor')
            ->value('outstanding_minor') + $activeHireBalances;
    }
}
