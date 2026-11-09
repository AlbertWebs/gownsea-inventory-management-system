<?php

namespace App\Http\Controllers;

use App\Actions\IssueBulkHire;
use App\Actions\IssueHireBatch;
use App\Models\BulkStockBalance;
use App\Models\Customer;
use App\Models\GraduationEvent;
use App\Models\Hire;
use App\Models\LateReturnPolicy;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class HireController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $locationIds = $user->mayViewAllLocations() ? null : $user->locations()->pluck('locations.id')->all();
        $search = trim($request->string('q')->toString());
        $status = $request->string('status')->toString();

        return Inertia::render('Hiring/Index', [
            'hires' => Hire::query()->with(['customer:id,name,phone', 'location:id,name', 'items:id,hire_id,product_name_snapshot,quantity,returned_quantity,status'])
                ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
                ->when($search !== '', fn ($query) => $query->where(fn ($hires) => $hires
                    ->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"))))
                ->when($status === 'due_today', fn ($query) => $query->whereDate('due_at', now(config('app.timezone'))->toDateString())->whereIn('status', ['issued', 'partially_returned']))
                ->when($status === 'overdue', fn ($query) => $query->where('due_at', '<', now(config('app.timezone'))->startOfDay())->whereIn('status', ['issued', 'partially_returned']))
                ->when(in_array($status, ['issued', 'partially_returned', 'returned_pending_inspection', 'completed', 'reserved', 'cancelled'], true), fn ($query) => $query->where('status', $status))
                ->latest('created_at')->paginate(20)->withQueryString(),
            'filters' => ['q' => $search, 'status' => $status],
            'canOperate' => in_array($user->role, ['super_admin', 'management', 'storekeeper', 'sales_crm'], true),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['super_admin', 'management', 'storekeeper', 'sales_crm'], true), 403);
        $allowedLocationIds = $user->mayViewAllLocations()
            ? null
            : $user->locations()->where('is_active', true)->pluck('locations.id')->all();

        return Inertia::render('Hiring/Issue', [
            'mode' => 'issue',
            'locations' => Location::query()->where('is_active', true)
                ->when($allowedLocationIds !== null, fn ($query) => $query->whereIn('id', $allowedLocationIds))
                ->orderBy('name')->get(['id', 'name', 'type']),
            'customers' => Customer::query()->select(['id', 'name', 'customer_number', 'phone', 'institution_id'])
                ->when(! $user->mayViewAllLocations(), fn ($query) => $query->where(fn ($customers) => $customers
                    ->whereHas('locations', fn ($locations) => $locations->whereIn('locations.id', $allowedLocationIds))
                    ->orWhereHas('hires', fn ($hires) => $hires->whereIn('location_id', $allowedLocationIds))))
                ->orderBy('name')->limit(100)->get(),
            'graduationEvents' => GraduationEvent::query()->whereIn('status', ['planned', 'confirmed'])
                ->whereDate('event_date', '>=', now(config('app.timezone'))->toDateString())
                ->when($allowedLocationIds !== null, fn ($query) => $query->whereIn('location_id', $allowedLocationIds))
                ->orderBy('event_date')->limit(500)->get(['id', 'name', 'institution_id', 'location_id', 'event_date']),
            'contractReferences' => LateReturnPolicy::query()->where('scope_type', 'contract')->orderBy('scope_reference')->pluck('scope_reference'),
            'defaultLocationId' => $user->locations()->wherePivot('is_primary', true)->value('locations.id'),
            'bulkProducts' => Product::query()->where('tracking_type', 'bulk')->where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name', 'hire_rate_minor']),
            'bulkAvailability' => BulkStockBalance::query()->whereIn('product_id', Product::query()->select('id')->where('tracking_type', 'bulk')->where('is_active', true))
                ->when($allowedLocationIds !== null, fn ($query) => $query->whereIn('location_id', $allowedLocationIds))
                ->get(['product_id', 'location_id', 'on_hand', 'reserved', 'pending_inspection', 'damaged'])
                ->map(fn (BulkStockBalance $balance): array => ['productId' => $balance->product_id, 'locationId' => $balance->location_id, 'available' => $balance->available]),
        ]);
    }

    public function store(Request $request, IssueHireBatch $issue, IssueBulkHire $issueBulk): RedirectResponse
    {
        $request->merge(['contract_reference' => filled($request->input('contract_reference')) ? mb_strtoupper(trim((string) $request->input('contract_reference'))) : null]);
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'asset_codes' => ['sometimes', 'array', 'min:1', 'max:200'],
            'asset_codes.*' => ['required', 'string', 'max:64', 'distinct'],
            'asset_code' => ['sometimes', 'string', 'max:64'],
            'bulk_items' => ['sometimes', 'array', 'max:50'],
            'bulk_items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'bulk_items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'due_at' => ['required', 'date', 'after:now'],
            'event_date' => ['nullable', 'date'],
            'graduation_event_id' => ['nullable', 'integer', 'exists:graduation_events,id'],
            'contract_reference' => ['nullable', 'string', 'max:100', Rule::exists('late_return_policies', 'scope_reference')->where('scope_type', 'contract')],
            'idempotency_key' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $assetCodes = $validated['asset_codes'] ?? (isset($validated['asset_code']) ? [$validated['asset_code']] : []);
        $bulkItems = $validated['bulk_items'] ?? [];
        if ($assetCodes !== [] && $bulkItems !== []) {
            throw ValidationException::withMessages(['asset_codes' => 'Submit serialized assets or bulk quantities in one hire; mixed tracking types are not supported in a single request.']);
        }
        if ($assetCodes === [] && $bulkItems === []) {
            throw ValidationException::withMessages(['asset_codes' => 'Scan at least one gown before confirming the hire.']);
        }

        $actor = $request->user();
        abort_unless(in_array($actor->role, ['super_admin', 'management', 'storekeeper', 'sales_crm'], true), 403);
        $location = Location::query()->whereKey($validated['location_id'])->where('is_active', true)->firstOrFail();
        if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($location->id)->exists()) {
            abort(403);
        }

        if (! $actor->mayViewAllLocations() && ! Customer::query()->whereKey($validated['customer_id'])
            ->where(fn ($customers) => $customers
                ->whereHas('locations', fn ($locations) => $locations->where('locations.id', $location->id))
                ->orWhereHas('hires', fn ($hires) => $hires->where('location_id', $location->id)))
            ->exists()) {
            abort(403);
        }

        $customer = Customer::query()->findOrFail($validated['customer_id']);
        if (isset($validated['graduation_event_id'])) {
            $event = GraduationEvent::query()->whereKey($validated['graduation_event_id'])->whereIn('status', ['planned', 'confirmed'])
                ->whereDate('event_date', '>=', now(config('app.timezone'))->toDateString())->firstOrFail();
            if ($event->location_id !== $location->id || $event->institution_id !== $customer->institution_id || ($validated['event_date'] ?? null) !== $event->event_date?->toDateString()) {
                throw ValidationException::withMessages(['graduation_event_id' => 'Choose an event that matches the customer institution, pickup location and event date.']);
            }
        }

        $data = [
            'asset_codes' => $assetCodes,
            'items' => $bulkItems,
            'customer_id' => (int) $validated['customer_id'],
            'location_id' => (int) $location->id,
            'due_at' => $validated['due_at'],
            'event_date' => $validated['event_date'] ?? null,
            'graduation_event_id' => isset($validated['graduation_event_id']) ? (int) $validated['graduation_event_id'] : null,
            'contract_reference' => $validated['contract_reference'] ?? null,
            'idempotency_key' => $validated['idempotency_key'],
            'notes' => $validated['notes'] ?? null,
        ];
        $hire = $bulkItems !== [] ? $issueBulk->handle($data, $actor) : $issue->handle($data, $actor);

        $quantity = $bulkItems !== [] ? array_sum(array_column($bulkItems, 'quantity')) : count($assetCodes);

        return redirect()->route('dashboard')->with('success', $quantity.' gown(s) issued successfully. Hire reference '.$hire->reference.'.');
    }
}
