<?php

namespace App\Http\Controllers;

use App\Actions\ReportMissingHireAsset;
use App\Actions\ReturnAsset;
use App\Actions\ReturnBulkHire;
use App\Models\HireItem;
use App\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReturnController extends Controller
{
    public function create(Request $request): Response
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['super_admin', 'management', 'storekeeper', 'sales_crm'], true), 403);
        $allowedLocationIds = $user->mayViewAllLocations()
            ? null
            : $user->locations()->where('is_active', true)->pluck('locations.id')->all();

        return Inertia::render('Hiring/Return', [
            'locations' => Location::query()->where('is_active', true)
                ->when($allowedLocationIds !== null, fn ($query) => $query->whereIn('id', $allowedLocationIds))
                ->orderBy('name')->get(['id', 'name', 'type']),
            'defaultLocationId' => $user->locations()->wherePivot('is_primary', true)->value('locations.id'),
            'bulkHireItems' => HireItem::query()->whereNull('physical_asset_id')->whereColumn('returned_quantity', '<', 'quantity')
                ->whereHas('hire', fn ($query) => $query->whereIn('status', ['issued', 'partially_returned'])->when($allowedLocationIds !== null, fn ($hireQuery) => $hireQuery->whereIn('location_id', $allowedLocationIds)))
                ->with(['hire.customer:id,name', 'hire.location:id,name'])->orderBy('hire_id')->get()
                ->map(fn (HireItem $item): array => [
                    'id' => $item->id, 'hireReference' => $item->hire->reference, 'customer' => $item->hire->customer->name,
                    'origin' => $item->hire->location->name, 'product' => $item->product_name_snapshot,
                    'dueAt' => $item->hire->due_at?->timezone(config('app.timezone'))->format('d M Y'),
                    'remaining' => $item->quantity - $item->returned_quantity,
                ])->values(),
        ]);
    }

    public function store(Request $request, ReturnAsset $returnAsset, ReportMissingHireAsset $reportMissing): RedirectResponse
    {
        $validated = $request->validate([
            'asset_code' => ['required', 'string', 'max:64'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'condition' => ['required', 'in:good,damaged,missing'],
            'notes' => [$request->input('condition') === 'missing' ? 'required' : 'nullable', 'string', $request->input('condition') === 'missing' ? 'min:5' : 'min:0', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        abort_unless(in_array($request->user()->role, ['super_admin', 'management', 'storekeeper', 'sales_crm'], true), 403);
        $location = Location::query()->findOrFail($validated['location_id']);
        if ($validated['condition'] === 'missing') {
            $event = $reportMissing->handle(
                $validated['asset_code'], $location, $request->user(), 'hire-missing:'.$validated['idempotency_key'], $validated['notes'],
            );

            return back()->with('success', "{$event->asset()->value('asset_code')} reported missing. A different manager must approve any write-off.");
        }

        $event = $returnAsset->handle(
            $validated['asset_code'],
            $location,
            $request->user(),
            'hire-return:'.$validated['idempotency_key'],
            $validated['condition'],
            $validated['notes'] ?? null,
        );
        $penaltyMinor = (int) HireItem::query()->where('physical_asset_id', $event->physical_asset_id)->latest('id')->value('penalty_assessed_minor');
        $notice = "{$event->asset()->value('asset_code')} recorded as returned for inspection.";
        if ($penaltyMinor > 0) {
            $notice .= ' Final late return penalty assessed: KES '.number_format($penaltyMinor / 100, 2).'.';
        }

        return back()->with('success', $notice);
    }

    public function returnBulk(Request $request, ReturnBulkHire $returnBulk): RedirectResponse
    {
        $data = $request->validate([
            'hire_item_id' => ['required', 'integer', 'exists:hire_items,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'condition' => ['required', 'in:good,damaged'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        abort_unless(in_array($request->user()->role, ['super_admin', 'management', 'storekeeper', 'sales_crm'], true), 403);
        $location = Location::query()->whereKey($data['location_id'])->where('is_active', true)->firstOrFail();
        $result = $returnBulk->handle((int) $data['hire_item_id'], (int) $data['quantity'], $location, $request->user(), 'bulk-hire-return:'.$data['idempotency_key'], $data['condition'], $data['notes'] ?? null);
        $notice = $data['quantity'].' bulk unit(s) recorded at '.$location->name.'.';
        if ($result['penalty_minor'] > 0) {
            $notice .= ' Final late return penalty assessed: KES '.number_format($result['penalty_minor'] / 100, 2).'.';
        }

        return back()->with('success', $notice);
    }
}
