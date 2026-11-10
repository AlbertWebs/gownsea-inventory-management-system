<?php

namespace App\Http\Controllers;

use App\Actions\ManageCustomOrderFitting;
use App\Actions\RecordCustomOrderCost;
use App\Actions\RecordCustomOrderShipmentEvent;
use App\Actions\SaveCustomOrder;
use App\Actions\SaveCustomOrderTask;
use App\Http\Requests\StoreCustomOrderCostRequest;
use App\Http\Requests\StoreCustomOrderFittingRequest;
use App\Http\Requests\StoreCustomOrderRequest;
use App\Http\Requests\StoreCustomOrderShipmentEventRequest;
use App\Http\Requests\StoreCustomOrderTaskRequest;
use App\Http\Requests\UpdateCustomOrderFittingRequest;
use App\Http\Requests\UpdateCustomOrderRequest;
use App\Http\Requests\UpdateCustomOrderTaskRequest;
use App\Models\Customer;
use App\Models\CustomOrder;
use App\Models\CustomOrderCost;
use App\Models\CustomOrderDepositApplication;
use App\Models\CustomOrderFitting;
use App\Models\CustomOrderShipmentEvent;
use App\Models\CustomOrderTask;
use App\Models\Institution;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('viewAny', CustomOrder::class), 403);
        $view = $request->routeIs('custom-orders.create') ? 'create' : 'orders';
        $locationIds = $user->mayViewAllLocations() ? null : $user->locations()->where('is_active', true)->pluck('locations.id')->all();
        $today = now(config('app.timezone'))->toDateString();

        return Inertia::render('CustomOrders/Index', [
            'view' => $view,
            'orders' => CustomOrder::query()->select('custom_orders.*')->with(['customer:id,name', 'institution:id,name', 'location:id,name', 'owner:id,name', 'events' => fn ($query) => $query->latest('occurred_at')->latest('id')->limit(8), 'tasks.assignee:id,name', 'fittings.assignee:id,name', 'shipmentEvents' => fn ($query) => $query->latest('occurred_at')->limit(20), 'costs' => fn ($query) => $query->latest('incurred_on')->latest('id')->limit(100)])
                ->selectSub(Payment::query()->selectRaw('COALESCE(SUM(amount_minor), 0)')->whereColumn('custom_order_id', 'custom_orders.id')->where('status', 'received')->where('purpose', 'custom_order_charge'), 'charge_received_minor')
                ->selectSub(PaymentRefund::query()->selectRaw('COALESCE(SUM(amount_minor), 0)')->whereIn('payment_id', Payment::query()->select('id')->whereColumn('custom_order_id', 'custom_orders.id')->where('purpose', 'custom_order_charge')), 'charge_refunded_minor')
                ->selectSub(Payment::query()->selectRaw('COALESCE(SUM(amount_minor), 0)')->whereColumn('custom_order_id', 'custom_orders.id')->where('status', 'received')->where('purpose', 'custom_order_deposit'), 'deposit_received_minor')
                ->selectSub(PaymentRefund::query()->selectRaw('COALESCE(SUM(amount_minor), 0)')->whereIn('payment_id', Payment::query()->select('id')->whereColumn('custom_order_id', 'custom_orders.id')->where('purpose', 'custom_order_deposit')), 'deposit_refunded_minor')
                ->selectSub(CustomOrderDepositApplication::query()->selectRaw('COALESCE(SUM(amount_minor), 0)')->whereColumn('custom_order_id', 'custom_orders.id'), 'deposit_applied_minor')
                ->selectSub(CustomOrderCost::query()->selectRaw('COALESCE(SUM(amount_minor), 0)')->whereColumn('custom_order_id', 'custom_orders.id'), 'direct_cost_minor')
                ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
                ->latest('updated_at')->paginate(25)->withQueryString()
                ->through(fn (CustomOrder $order): array => [
                    'id' => $order->id, 'reference' => $order->reference, 'customer' => $order->customer->name,
                    'institution' => $order->institution?->name, 'location' => $order->location->name, 'owner' => $order->owner?->name,
                    'status' => $order->status, 'itemType' => $order->item_type, 'size' => $order->size, 'color' => $order->color,
                    'quantity' => $order->quantity, 'measurements' => $order->measurements, 'designSpecs' => $order->design_specs,
                    'specialRequirements' => $order->special_requirements, 'agreedTotal' => number_format($order->agreed_total_minor / 100, 2, '.', ''),
                    'depositRequired' => number_format($order->deposit_required_minor / 100, 2, '.', ''), 'orderDate' => $order->order_date->toDateString(),
                    'chargePaidMinor' => max(0, (int) $order->charge_received_minor - (int) $order->charge_refunded_minor + (int) $order->deposit_applied_minor),
                    'chargeBalanceMinor' => max(0, $order->agreed_total_minor - (int) $order->charge_received_minor + (int) $order->charge_refunded_minor - (int) $order->deposit_applied_minor),
                    'depositPaidMinor' => max(0, (int) $order->deposit_received_minor - (int) $order->deposit_refunded_minor - (int) $order->deposit_applied_minor),
                    'depositBalanceMinor' => max(0, $order->deposit_required_minor - (int) $order->deposit_received_minor + (int) $order->deposit_refunded_minor),
                    'dueDate' => $order->expected_completion_date->toDateString(), 'overdue' => $order->expected_completion_date->toDateString() < $today && ! in_array($order->status, ['completed', 'cancelled'], true),
                    'priority' => $order->priority, 'deliveryMethod' => $order->delivery_method, 'deliveryAddress' => $order->delivery_address,
                    'courier' => $order->courier, 'trackingNumber' => $order->tracking_number, 'notes' => $order->notes,
                    'timeline' => $order->events->take(8)->map(fn ($event) => ['type' => $event->event_type, 'reason' => $event->reason, 'at' => $event->occurred_at->toIso8601String()]),
                    'tasks' => $order->tasks->map(fn (CustomOrderTask $task): array => ['id' => $task->id, 'type' => $task->task_type, 'title' => $task->title, 'description' => $task->description, 'assignee' => $task->assignee?->name, 'assignedTo' => $task->assigned_to, 'dueDate' => $task->due_date?->toDateString(), 'status' => $task->status]),
                    'fittings' => $order->fittings->sortByDesc('appointment_at')->map(fn (CustomOrderFitting $fitting): array => ['id' => $fitting->id, 'assignee' => $fitting->assignee?->name, 'appointmentAt' => $fitting->appointment_at->toIso8601String(), 'status' => $fitting->status, 'measurements' => $fitting->measurements, 'outcome' => $fitting->outcome]),
                    'shipmentEvents' => $order->shipmentEvents->map(fn (CustomOrderShipmentEvent $event): array => ['id' => $event->id, 'type' => $event->event_type, 'providerReference' => $event->provider_reference, 'details' => $event->details, 'occurredAt' => $event->occurred_at->toIso8601String()]),
                    'costTotalMinor' => (int) $order->direct_cost_minor,
                    'contributionEstimateMinor' => $order->agreed_total_minor - (int) $order->direct_cost_minor,
                    'costs' => $order->costs->map(fn (CustomOrderCost $cost): array => ['id' => $cost->id, 'category' => $cost->category, 'description' => $cost->description, 'amountMinor' => $cost->amount_minor, 'incurredOn' => $cost->incurred_on->toDateString()]),
                ]),
            'locations' => Location::query()->where('is_active', true)->when($locationIds !== null, fn ($query) => $query->whereIn('id', $locationIds))->orderBy('name')->get(['id', 'name']),
            'customers' => Customer::query()->with('locations:id')->when($locationIds !== null, fn ($query) => $query->whereHas('locations', fn ($locations) => $locations->whereIn('locations.id', $locationIds)))->orderBy('name')->limit(500)->get(['id', 'name', 'institution_id'])->map(fn (Customer $customer): array => ['id' => $customer->id, 'name' => $customer->name, 'institution_id' => $customer->institution_id, 'location_ids' => $customer->locations->pluck('id')->all()]),
            'institutions' => Institution::query()->where('is_active', true)->orderBy('name')->limit(500)->get(['id', 'name']),
            'staff' => User::query()->where('is_active', true)->when($locationIds !== null, fn ($query) => $query->whereHas('locations', fn ($locations) => $locations->whereIn('locations.id', $locationIds)))->orderBy('name')->get(['id', 'name']),
            'canCreate' => $user->can('create', CustomOrder::class), 'canEdit' => true, 'isProduction' => $user->role === 'production',
        ]);
    }

    public function store(StoreCustomOrderRequest $request, SaveCustomOrder $save): RedirectResponse
    {
        $order = $save->create($request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return redirect()->route('custom-orders.index')->with('success', "Custom order {$order->reference} created.");
    }

    public function update(UpdateCustomOrderRequest $request, CustomOrder $customOrder, SaveCustomOrder $save): RedirectResponse
    {
        $order = $save->update($customOrder, $request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Custom order {$order->reference} updated to {$order->status}.");
    }

    public function storeTask(StoreCustomOrderTaskRequest $request, CustomOrder $customOrder, SaveCustomOrderTask $save): RedirectResponse
    {
        $task = $save->create($customOrder, $request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Production task '{$task->title}' added to {$customOrder->reference}.");
    }

    public function updateTask(UpdateCustomOrderTaskRequest $request, CustomOrder $customOrder, CustomOrderTask $customOrderTask, SaveCustomOrderTask $save): RedirectResponse
    {
        $task = $save->update($customOrder, $customOrderTask, $request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Production task '{$task->title}' updated.");
    }

    public function storeFitting(StoreCustomOrderFittingRequest $request, CustomOrder $customOrder, ManageCustomOrderFitting $manage): RedirectResponse
    {
        $fitting = $manage->schedule($customOrder, $request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', 'Fitting scheduled for '.$fitting->appointment_at->format('d M Y H:i').'.');
    }

    public function updateFitting(UpdateCustomOrderFittingRequest $request, CustomOrder $customOrder, CustomOrderFitting $fitting, ManageCustomOrderFitting $manage): RedirectResponse
    {
        $entry = $manage->update($customOrder, $fitting, $request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', 'Fitting marked '.$entry->status.'.');
    }

    public function storeShipmentEvent(StoreCustomOrderShipmentEventRequest $request, CustomOrder $customOrder, RecordCustomOrderShipmentEvent $record): RedirectResponse
    {
        $event = $record->handle($customOrder, $request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', 'Shipment update recorded: '.str_replace('_', ' ', $event->event_type).'.');
    }

    public function storeCost(StoreCustomOrderCostRequest $request, CustomOrder $customOrder, RecordCustomOrderCost $record): RedirectResponse
    {
        $cost = $record->handle($customOrder, $request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', 'KES '.number_format($cost->amount_minor / 100, 2).' direct cost recorded.');
    }
}
