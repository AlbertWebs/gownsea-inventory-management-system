<?php

namespace App\Http\Controllers;

use App\Actions\CancelHireReservation;
use App\Actions\IssueReservedBulkHire;
use App\Actions\IssueReservedHire;
use App\Actions\ReserveBulkHire;
use App\Actions\ReserveHireBatch;
use App\Models\BulkStockBalance;
use App\Models\Customer;
use App\Models\GraduationEvent;
use App\Models\Hire;
use App\Models\LateReturnPolicy;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class HireReservationController extends Controller
{
    public function create(Request $request): Response
    {
        $user = $request->user();
        $this->authorizeHireOperations($user->role);
        $locationIds = $user->mayViewAllLocations() ? null : $user->locations()->where('is_active', true)->pluck('locations.id')->all();

        return Inertia::render('Hiring/Issue', [
            'mode' => 'reserve',
            'locations' => Location::query()->where('is_active', true)->when($locationIds !== null, fn ($query) => $query->whereIn('id', $locationIds))->orderBy('name')->get(['id', 'name', 'type']),
            'customers' => Customer::query()->select(['id', 'name', 'customer_number', 'phone', 'institution_id'])
                ->when($locationIds !== null, fn ($query) => $query->where(fn ($customers) => $customers
                    ->whereHas('locations', fn ($locations) => $locations->whereIn('locations.id', $locationIds))
                    ->orWhereHas('hires', fn ($hires) => $hires->whereIn('location_id', $locationIds))))
                ->orderBy('name')->limit(100)->get(),
            'graduationEvents' => GraduationEvent::query()->whereIn('status', ['planned', 'confirmed'])
                ->whereDate('event_date', '>=', now(config('app.timezone'))->toDateString())
                ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
                ->orderBy('event_date')->limit(500)->get(['id', 'name', 'institution_id', 'location_id', 'event_date']),
            'contractReferences' => LateReturnPolicy::query()->where('scope_type', 'contract')->orderBy('scope_reference')->pluck('scope_reference'),
            'defaultLocationId' => $user->locations()->wherePivot('is_primary', true)->value('locations.id'),
            'bulkProducts' => Product::query()->where('tracking_type', 'bulk')->where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name', 'hire_rate_minor']),
            'bulkAvailability' => BulkStockBalance::query()->whereIn('product_id', Product::query()->select('id')->where('tracking_type', 'bulk')->where('is_active', true))
                ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
                ->get(['product_id', 'location_id', 'on_hand', 'reserved', 'pending_inspection', 'damaged'])
                ->map(fn (BulkStockBalance $balance): array => ['productId' => $balance->product_id, 'locationId' => $balance->location_id, 'available' => $balance->available]),
        ]);
    }

    public function store(Request $request, ReserveHireBatch $reserve, ReserveBulkHire $reserveBulk): RedirectResponse
    {
        $request->merge(['contract_reference' => filled($request->input('contract_reference')) ? mb_strtoupper(trim((string) $request->input('contract_reference'))) : null]);
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'asset_codes' => ['sometimes', 'array', 'min:1', 'max:200'],
            'asset_codes.*' => ['required', 'string', 'max:64', 'distinct'],
            'bulk_items' => ['sometimes', 'array', 'min:1', 'max:50'],
            'bulk_items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'bulk_items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'due_at' => ['required', 'date', 'after:now'],
            'event_date' => ['nullable', 'date'],
            'graduation_event_id' => ['nullable', 'integer', 'exists:graduation_events,id'],
            'contract_reference' => ['nullable', 'string', 'max:100', Rule::exists('late_return_policies', 'scope_reference')->where('scope_type', 'contract')],
            'idempotency_key' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $assetCodes = $data['asset_codes'] ?? [];
        $bulkItems = $data['bulk_items'] ?? [];
        if (($assetCodes === []) === ($bulkItems === [])) {
            throw ValidationException::withMessages(['asset_codes' => 'Reserve serialized assets or bulk quantities.']);
        }
        $user = $request->user();
        $this->authorizeHireOperations($user->role);
        $this->authorizeCustomerAndLocation($user, $data['customer_id'], $data['location_id']);
        if (isset($data['graduation_event_id'])) {
            $customer = Customer::query()->findOrFail($data['customer_id']);
            $event = GraduationEvent::query()->whereKey($data['graduation_event_id'])->whereIn('status', ['planned', 'confirmed'])
                ->whereDate('event_date', '>=', now(config('app.timezone'))->toDateString())->firstOrFail();
            if ($event->location_id !== (int) $data['location_id'] || $event->institution_id !== $customer->institution_id || ($data['event_date'] ?? null) !== $event->event_date?->toDateString()) {
                throw ValidationException::withMessages(['graduation_event_id' => 'Choose an event that matches the customer institution, pickup location and event date.']);
            }
        }

        $reservation = [
            'customer_id' => (int) $data['customer_id'], 'location_id' => (int) $data['location_id'],
            'due_at' => $data['due_at'], 'event_date' => $data['event_date'] ?? null,
            'graduation_event_id' => isset($data['graduation_event_id']) ? (int) $data['graduation_event_id'] : null,
            'contract_reference' => $data['contract_reference'] ?? null,
            'idempotency_key' => $data['idempotency_key'], 'notes' => $data['notes'] ?? null,
        ];
        $hire = $bulkItems !== []
            ? $reserveBulk->handle($reservation + ['items' => $bulkItems], $user)
            : $reserve->handle($reservation + ['asset_codes' => $assetCodes], $user);

        $message = $hire->status === 'reserved'
            ? ($bulkItems !== [] ? array_sum(array_column($bulkItems, 'quantity')) : count($assetCodes))." gown(s) reserved. Booking reference {$hire->reference}."
            : "Booking request already exists as {$hire->status}. Reference {$hire->reference}.";

        return redirect()->route('hiring.index')->with('success', $message);
    }

    public function issuePage(Request $request, Hire $hire): Response
    {
        $this->authorizeHireOperations($request->user()->role);
        $this->authorizeHireLocation($request, $hire);
        abort_unless($hire->status === 'reserved', 404);

        $bulkLines = $hire->items()->whereNull('physical_asset_id')->orderBy('product_id')->get();
        $isBulk = $bulkLines->isNotEmpty();

        return Inertia::render('Hiring/IssueReservation', [
            'hire' => [
                'id' => $hire->id,
                'reference' => $hire->reference,
                'customer' => $hire->customer()->value('name'),
                'location' => $hire->location()->value('name'),
                'dueAt' => $hire->due_at?->timezone(config('app.timezone'))->format('d M Y, H:i'),
                'isBulk' => $isBulk,
                'bulkItems' => $bulkLines->map(fn ($item): array => ['product' => $item->product_name_snapshot, 'quantity' => $item->quantity])->values(),
                'assets' => $hire->items()->where('status', 'reserved')->whereNotNull('physical_asset_id')->with('asset:id,asset_code')->orderBy('id')->get()->map(fn ($item): array => [
                    'code' => $item->asset?->asset_code,
                    'product' => $item->product_name_snapshot,
                ])->values(),
            ],
        ]);
    }

    public function issue(Request $request, Hire $hire, IssueReservedHire $issue, IssueReservedBulkHire $issueBulk): RedirectResponse
    {
        $data = $request->validate([
            'asset_codes' => ['sometimes', 'array', 'min:1', 'max:200'],
            'asset_codes.*' => ['required', 'string', 'max:64', 'distinct'],
            'bulk_handover_confirmed' => ['sometimes', 'boolean'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $this->authorizeHireOperations($request->user()->role);
        $this->authorizeHireLocation($request, $hire);
        $isBulk = $hire->items()->whereNull('physical_asset_id')->exists();
        if ($isBulk && ! ($data['bulk_handover_confirmed'] ?? false)) {
            throw ValidationException::withMessages(['bulk_handover_confirmed' => 'Confirm the counted bulk quantities before completing handover.']);
        }
        if (! $isBulk && empty($data['asset_codes'])) {
            throw ValidationException::withMessages(['asset_codes' => 'Scan every serialized asset before completing handover.']);
        }
        $issued = $isBulk
            ? $issueBulk->handle($hire, $data['idempotency_key'], $request->user())
            : $issue->handle($hire, $data['asset_codes'], $data['idempotency_key'], $request->user());

        return redirect()->route('hiring.index')->with('success', "Reserved gowns were issued. Hire reference {$issued->reference}.");
    }

    public function cancel(Request $request, Hire $hire, CancelHireReservation $cancel): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        abort_unless(in_array($request->user()->role, ['super_admin', 'management', 'sales_crm'], true), 403);
        $this->authorizeHireLocation($request, $hire);
        $cancelled = $cancel->handle($hire, $request->user(), $data['reason'] ?? null);

        return redirect()->route('hiring.index')->with('success', "Reservation {$cancelled->reference} was cancelled.");
    }

    private function authorizeHireLocation(Request $request, Hire $hire): void
    {
        if (! $request->user()->mayViewAllLocations() && ! $request->user()->locations()->whereKey($hire->location_id)->exists()) {
            abort(404);
        }
    }

    private function authorizeHireOperations(string $role): void
    {
        abort_unless(in_array($role, ['super_admin', 'management', 'storekeeper', 'sales_crm'], true), 403);
    }

    private function authorizeCustomerAndLocation(User $user, int $customerId, int $locationId): void
    {
        $location = Location::query()->whereKey($locationId)->where('is_active', true)->firstOrFail();
        if (! $user->mayViewAllLocations() && ! $user->locations()->whereKey($location->id)->exists()) {
            abort(403);
        }
        if (! $user->mayViewAllLocations() && ! Customer::query()->whereKey($customerId)
            ->where(fn ($customers) => $customers
                ->whereHas('locations', fn ($locations) => $locations->where('locations.id', $location->id))
                ->orWhereHas('hires', fn ($hires) => $hires->where('location_id', $location->id)))
            ->exists()) {
            abort(403);
        }
    }
}
