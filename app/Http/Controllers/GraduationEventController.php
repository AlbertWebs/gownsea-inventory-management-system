<?php

namespace App\Http\Controllers;

use App\Actions\SaveGraduationEvent;
use App\Http\Requests\StoreGraduationEventRequest;
use App\Http\Requests\UpdateGraduationEventRequest;
use App\Models\GraduationEvent;
use App\Models\Institution;
use App\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GraduationEventController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('viewAny', GraduationEvent::class), 403);
        $locationIds = $user->mayViewAllLocations() ? null : $user->locations()->where('is_active', true)->pluck('locations.id')->all();
        $locations = Location::query()->where('is_active', true)->when($locationIds !== null, fn ($query) => $query->whereIn('id', $locationIds))->orderBy('name')->get(['id', 'name']);
        $month = $request->query('month');
        $monthStart = is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)
            ? CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();
        $calendarItems = collect();
        $events = GraduationEvent::query()->with(['institution:id,name', 'location:id,name', 'owner:id,name'])
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->where(function ($query) use ($monthStart, $monthEnd): void {
                $query->whereBetween('event_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                    ->orWhereBetween('collection_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                    ->orWhereBetween('delivery_date', [$monthStart->toDateString(), $monthEnd->toDateString()]);
            })->get();
        foreach ($events as $event) {
            foreach ([['event_date', 'Graduation'], ['collection_date', 'Gown collection'], ['delivery_date', 'Gown delivery']] as [$field, $label]) {
                $date = $event->{$field}?->toDateString();
                if ($date !== null && $date >= $monthStart->toDateString() && $date <= $monthEnd->toDateString()) {
                    $calendarItems->push(['date' => $date, 'title' => $event->name, 'type' => $label, 'detail' => $event->institution->name.' · '.($event->location?->name ?? 'Unassigned branch'), 'status' => $event->status]);
                }
            }
        }
        DB::table('hires as h')->join('customers as c', 'c.id', '=', 'h.customer_id')->leftJoin('institutions as i', 'i.id', '=', 'h.institution_id')->leftJoin('graduation_events as ge', 'ge.id', '=', 'h.graduation_event_id')->join('locations as l', 'l.id', '=', 'h.location_id')
            ->whereIn('h.status', ['reserved', 'issued', 'partially_returned', 'returned_pending_inspection'])
            ->when($locationIds !== null, fn ($query) => $query->whereIn('h.location_id', $locationIds))
            ->where(function ($query) use ($monthStart, $monthEnd): void {
                $query->whereBetween('h.event_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                    ->orWhereBetween('h.due_at', [$monthStart->startOfDay(), $monthEnd->endOfDay()]);
            })->select('h.reference', 'h.event_date', 'h.due_at', 'h.status', 'c.name as customer', 'i.name as institution', 'ge.name as graduation_event', 'l.name as location')->get()
            ->each(function ($hire) use ($calendarItems, $monthStart, $monthEnd): void {
                foreach ([['event_date', 'Hire event'], ['due_at', 'Hire return due']] as [$field, $type]) {
                    if ($hire->{$field} === null) {
                        continue;
                    }
                    $date = CarbonImmutable::parse($hire->{$field})->toDateString();
                    if ($date >= $monthStart->toDateString() && $date <= $monthEnd->toDateString()) {
                        $calendarItems->push(['date' => $date, 'title' => $hire->reference, 'type' => $type, 'detail' => trim(($hire->graduation_event ? $hire->graduation_event.' · ' : '').($hire->institution ? $hire->institution.' · ' : '').$hire->customer.' · '.$hire->location), 'status' => $hire->status]);
                    }
                }
            });
        DB::table('custom_orders as co')->join('customers as c', 'c.id', '=', 'co.customer_id')->join('locations as l', 'l.id', '=', 'co.location_id')
            ->when($locationIds !== null, fn ($query) => $query->whereIn('co.location_id', $locationIds))
            ->whereBetween('co.expected_completion_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->select('co.reference', 'co.expected_completion_date', 'co.status', 'c.name as customer', 'l.name as location')->get()
            ->each(fn ($order) => $calendarItems->push(['date' => $order->expected_completion_date, 'title' => $order->reference, 'type' => 'Custom order due', 'detail' => $order->customer.' · '.$order->location, 'status' => $order->status]));
        DB::table('custom_order_fittings as f')->join('custom_orders as co', 'co.id', '=', 'f.custom_order_id')->join('customers as c', 'c.id', '=', 'co.customer_id')->join('locations as l', 'l.id', '=', 'co.location_id')
            ->when($locationIds !== null, fn ($query) => $query->whereIn('co.location_id', $locationIds))
            ->whereBetween('f.appointment_at', [$monthStart->startOfDay(), $monthEnd->endOfDay()])->whereIn('f.status', ['scheduled', 'in_progress'])
            ->select('co.reference', 'f.appointment_at', 'f.status', 'c.name as customer', 'l.name as location')->get()
            ->each(fn ($fitting) => $calendarItems->push(['date' => CarbonImmutable::parse($fitting->appointment_at)->toDateString(), 'time' => CarbonImmutable::parse($fitting->appointment_at)->format('H:i'), 'title' => $fitting->reference, 'type' => 'Fitting appointment', 'detail' => $fitting->customer.' · '.$fitting->location, 'status' => $fitting->status]));

        return Inertia::render('Events/Index', [
            'events' => GraduationEvent::query()->with(['institution:id,name', 'location:id,name', 'owner:id,name'])
                ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
                ->addSelect('graduation_events.*')
                ->selectSub(DB::table('hires as h')->join('hire_items as hi', 'hi.hire_id', '=', 'h.id')
                    ->whereColumn('h.graduation_event_id', 'graduation_events.id')
                    ->whereNotIn('h.status', ['cancelled'])
                    ->selectRaw('COALESCE(SUM(hi.quantity), 0)'), 'booked_quantity')
                ->orderByDesc('event_date')->paginate(25)->withQueryString(),
            'calendar' => ['month' => $monthStart->format('Y-m'), 'items' => $calendarItems->sortBy([['date', 'asc'], ['time', 'asc']])->values()],
            'locations' => $locations,
            'institutions' => Institution::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canManage' => $user->can('create', GraduationEvent::class),
        ]);
    }

    public function store(StoreGraduationEventRequest $request, SaveGraduationEvent $save): RedirectResponse
    {
        $event = $save->create($request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Graduation event {$event->name} created.");
    }

    public function update(UpdateGraduationEventRequest $request, GraduationEvent $event, SaveGraduationEvent $save): RedirectResponse
    {
        $event = $save->update($event, $request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Graduation event {$event->name} updated.");
    }
}
