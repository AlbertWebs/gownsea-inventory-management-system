<?php

namespace App\Http\Controllers;

use App\Actions\ApproveAssetWriteOff;
use App\Actions\ApproveBulkInventoryCount;
use App\Actions\ApproveInventoryCount;
use App\Actions\CancelBulkInventoryCount;
use App\Actions\CancelInventoryCount;
use App\Actions\CreateBulkInventoryCount;
use App\Actions\CreateInventoryCount;
use App\Actions\InspectAsset;
use App\Actions\InspectBulkStock;
use App\Actions\RecordBulkStockMovement;
use App\Http\Requests\StoreBulkStockInspectionRequest;
use App\Http\Requests\StoreBulkStockMovementRequest;
use App\Http\Requests\StoreInventoryCountRequest;
use App\Models\AssetCustodyEvent;
use App\Models\BulkInventoryCount;
use App\Models\BulkStockBalance;
use App\Models\InventoryCount;
use App\Models\Location;
use App\Models\PhysicalAsset;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function labels(Request $request): Response
    {
        $data = $request->validate([
            'assets' => ['required', 'array', 'min:1', 'max:500'],
            'assets.*' => ['required', 'integer', 'distinct'],
        ]);
        $user = $request->user();
        $locationIds = $user->mayViewAllLocations() ? null : $user->locations()->where('is_active', true)->pluck('locations.id')->all();
        $assets = PhysicalAsset::query()->whereIn('id', $data['assets'])
            ->when($locationIds !== null, fn ($query) => $query->where(function ($items) use ($locationIds): void {
                $items->whereIn('current_location_id', $locationIds)
                    ->orWhereHas('hireItems', fn ($lines) => $lines->whereIn('status', ['issued', 'missing', 'written_off'])->whereHas('hire', fn ($hire) => $hire->whereIn('location_id', $locationIds)))
                    ->orWhereHas('transfer', fn ($transfer) => $transfer->whereIn('source_location_id', $locationIds)->orWhereIn('destination_location_id', $locationIds))
                    ->orWhereHas('custodyEvents', fn ($events) => $events->whereIn('from_location_id', $locationIds)->orWhereIn('to_location_id', $locationIds));
            }))
            ->with('product:id,sku,name,category,size,color')
            ->orderBy('asset_code')->get();

        abort_unless($assets->count() === count($data['assets']), 404);

        return Inertia::render('Inventory/Labels', [
            'assets' => $assets->map(fn (PhysicalAsset $asset): array => [
                'id' => $asset->id,
                'code' => $asset->asset_code,
                'product' => $asset->product->name,
                'sku' => $asset->product->sku,
                'size' => $asset->product->size,
            ]),
        ]);
    }

    public function show(Request $request, PhysicalAsset $asset): Response
    {
        $user = $request->user();
        $locationIds = $user->mayViewAllLocations() ? null : $user->locations()->where('is_active', true)->pluck('locations.id')->all();
        $scopedAsset = PhysicalAsset::query()->whereKey($asset->id)
            ->when($locationIds !== null, fn ($query) => $query->where(function ($assets) use ($locationIds): void {
                $assets->whereIn('current_location_id', $locationIds)
                    ->orWhereHas('hireItems', fn ($items) => $items->whereIn('status', ['issued', 'missing', 'written_off'])->whereHas('hire', fn ($hire) => $hire->whereIn('location_id', $locationIds)))
                    ->orWhereHas('transfer', fn ($transfer) => $transfer->whereIn('source_location_id', $locationIds)->orWhereIn('destination_location_id', $locationIds))
                    ->orWhereHas('custodyEvents', fn ($events) => $events->whereIn('from_location_id', $locationIds)->orWhereIn('to_location_id', $locationIds));
            }))
            ->with(['product:id,sku,name,category,size,color', 'currentLocation:id,name', 'homeLocation:id,name'])
            ->firstOrFail();

        $timeline = $scopedAsset->custodyEvents()
            ->when($locationIds !== null, fn ($query) => $query->where(fn ($events) => $events->whereIn('from_location_id', $locationIds)->orWhereIn('to_location_id', $locationIds)))
            ->with(['actor:id,name', 'fromLocation:id,name', 'toLocation:id,name'])
            ->get()->map(fn (AssetCustodyEvent $event): array => [
                'id' => 'custody-'.$event->id, 'occurredAt' => $event->occurred_at?->timezone(config('app.timezone'))->format('d M Y, H:i'),
                '_sortAt' => $event->occurred_at?->timestamp ?? 0,
                'type' => $event->event_type, 'actor' => $event->actor?->name ?? 'System', 'fromLocation' => $event->fromLocation?->name,
                'toLocation' => $event->toLocation?->name, 'fromStatus' => $event->from_status, 'toStatus' => $event->to_status,
                'notes' => $event->notes, 'record' => 'Custody event',
            ])->concat($scopedAsset->stockMovements()
            ->when($locationIds !== null, fn ($query) => $query->where(fn ($movements) => $movements->whereIn('from_location_id', $locationIds)->orWhereIn('to_location_id', $locationIds)))
            ->with(['actor:id,name', 'fromLocation:id,name', 'toLocation:id,name'])->get()
            ->map(fn ($movement): array => [
                'id' => 'movement-'.$movement->id, 'occurredAt' => $movement->occurred_at?->timezone(config('app.timezone'))->format('d M Y, H:i'),
                '_sortAt' => $movement->occurred_at?->timestamp ?? 0,
                'type' => $movement->movement_type, 'actor' => $movement->actor?->name ?? 'System', 'fromLocation' => $movement->fromLocation?->name,
                'toLocation' => $movement->toLocation?->name, 'fromStatus' => $movement->from_status, 'toStatus' => $movement->to_status,
                'notes' => $movement->notes, 'record' => 'Stock movement',
            ]))->sortByDesc(fn (array $entry): array => [$entry['_sortAt'], $entry['id']])->values()
            ->map(function (array $entry): array {
                unset($entry['_sortAt']);

                return $entry;
            });

        return Inertia::render('Inventory/Show', [
            'asset' => [
                'id' => $scopedAsset->id, 'code' => $scopedAsset->asset_code, 'product' => $scopedAsset->product->name,
                'imageUrl' => $scopedAsset->product->image_path === null ? null : route('products.image', $scopedAsset->product_id),
                'sku' => $scopedAsset->product->sku, 'category' => $scopedAsset->product->category,
                'size' => $scopedAsset->product->size, 'color' => $scopedAsset->product->color,
                'status' => $scopedAsset->status, 'condition' => $scopedAsset->condition,
                'custody' => $scopedAsset->custody_type, 'homeLocation' => $scopedAsset->homeLocation?->name,
                'currentLocation' => $scopedAsset->currentLocation?->name,
                'verifiedAt' => $scopedAsset->last_verified_at?->timezone(config('app.timezone'))->format('d M Y, H:i'),
            ],
            'timeline' => $timeline,
        ]);
    }

    public function storeCount(StoreInventoryCountRequest $request, CreateInventoryCount $create): RedirectResponse
    {
        $count = $create->handle($request->validated(), $request->user());

        return back()->with('success', "Cycle count {$count->reference} submitted for independent approval.");
    }

    public function storeBulkCount(Request $request, CreateBulkInventoryCount $create): RedirectResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'counts' => ['required', 'array', 'min:1', 'max:500'],
            'counts.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'counts.*.counted_on_hand' => ['required', 'integer', 'min:0', 'max:1000000'],
            'idempotency_key' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $count = $create->handle($data, $request->user());

        return back()->with('success', "Bulk cycle count {$count->reference} submitted for independent approval.");
    }

    public function approveBulkCount(Request $request, BulkInventoryCount $count, ApproveBulkInventoryCount $approve): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000'], 'idempotency_key' => ['required', 'uuid']]);
        $approve->handle($count, $validated['idempotency_key'], $validated['reason'], $request->user());

        return back()->with('success', "Bulk cycle count {$count->reference} approved and adjustments recorded.");
    }

    public function cancelBulkCount(Request $request, BulkInventoryCount $count, CancelBulkInventoryCount $cancel): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000'], 'idempotency_key' => ['required', 'uuid']]);
        $cancel->handle($count, $validated['idempotency_key'], $validated['reason'], $request->user());

        return back()->with('success', "Bulk cycle count {$count->reference} cancelled.");
    }

    public function recordBulkMovement(StoreBulkStockMovementRequest $request, RecordBulkStockMovement $record): RedirectResponse
    {
        $movement = $record->handle($request->validated(), $request->user());

        return back()->with('success', "Bulk stock movement {$movement->movement_type} recorded.");
    }

    public function inspectBulkStock(StoreBulkStockInspectionRequest $request, InspectBulkStock $inspect): RedirectResponse
    {
        $data = $request->validated();
        if (! $request->user()->mayViewAllLocations() && ! $request->user()->locations()->whereKey($data['location_id'])->exists()) {
            abort(403);
        }
        $movement = $inspect->handle($data, $request->user());

        return back()->with('success', "Bulk stock inspection {$movement->movement_type} recorded.");
    }

    public function approveCount(Request $request, InventoryCount $count, ApproveInventoryCount $approve): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['super_admin', 'management'], true), 403);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $approve->handle($count, $validated['idempotency_key'], $validated['reason'], $request->user());

        return back()->with('success', "Cycle count {$count->reference} approved and its discrepancies recorded.");
    }

    public function cancelCount(Request $request, InventoryCount $count, CancelInventoryCount $cancel): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $cancel->handle($count, $validated['idempotency_key'], $validated['reason'], $request->user());

        return back()->with('success', "Cycle count {$count->reference} cancelled.");
    }

    public function writeOff(Request $request, PhysicalAsset $asset, ApproveAssetWriteOff $approve): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['super_admin', 'management'], true), 403);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $approve->handle($asset, 'asset-write-off:'.$validated['idempotency_key'], $validated['reason'], $request->user());

        return back()->with('success', "Asset {$asset->asset_code} was written off after manager approval.");
    }

    public function inspect(Request $request, PhysicalAsset $asset, InspectAsset $inspect): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['super_admin', 'management', 'storekeeper'], true), 403);
        $validated = $request->validate([
            'result' => ['required', 'in:approved,cleaning,repair'],
            'idempotency_key' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $inspect->handle($asset, $validated['result'], 'asset-inspection:'.$validated['idempotency_key'], $request->user(), $validated['reason'] ?? null);

        return back()->with('success', "Inspection recorded for {$asset->asset_code}.");
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $view = $request->routeIs('inventory.bulk-stock') ? 'bulk-stock' : ($request->routeIs('inventory.cycle-counts') ? 'cycle-counts' : 'inventory');
        $allowedLocationIds = $user->mayViewAllLocations()
            ? null
            : $user->locations()->where('is_active', true)->pluck('locations.id')->all();
        $search = trim($request->string('q')->toString());
        $status = $request->string('status')->toString();
        $locationId = $request->integer('location') ?: null;

        if ($locationId !== null && $allowedLocationIds !== null && ! in_array($locationId, $allowedLocationIds, true)) {
            abort(403);
        }

        $assets = PhysicalAsset::query()
            ->select('physical_assets.*')
            ->with(['product:id,sku,name,category,academic_classification_id,qualification_level,size,color,image_path', 'product.classification:id,code,label', 'currentLocation:id,name'])
            ->when($allowedLocationIds !== null, fn ($query) => $query->where(function ($assets) use ($allowedLocationIds): void {
                $assets->whereIn('current_location_id', $allowedLocationIds)
                    ->orWhereHas('hireItems', fn ($items) => $items->whereIn('status', ['issued', 'missing', 'written_off'])->whereHas('hire', fn ($hire) => $hire->whereIn('location_id', $allowedLocationIds)))
                    ->orWhereHas('transfer', fn ($transfer) => $transfer->whereIn('source_location_id', $allowedLocationIds)->orWhereIn('destination_location_id', $allowedLocationIds))
                    ->orWhereHas('custodyEvents', fn ($events) => $events->whereIn('from_location_id', $allowedLocationIds)->orWhereIn('to_location_id', $allowedLocationIds));
            }))
            ->when($locationId !== null, fn ($query) => $query->where(function ($assets) use ($locationId): void {
                $assets->where('current_location_id', $locationId)
                    ->orWhereHas('hireItems', fn ($items) => $items->whereIn('status', ['issued', 'missing', 'written_off'])->whereHas('hire', fn ($hire) => $hire->where('location_id', $locationId)))
                    ->orWhereHas('transfer', fn ($transfer) => $transfer->where('source_location_id', $locationId)->orWhere('destination_location_id', $locationId))
                    ->orWhereHas('custodyEvents', fn ($events) => $events->where('from_location_id', $locationId)->orWhere('to_location_id', $locationId));
            }))
            ->when(in_array($status, ['available', 'reserved', 'hired', 'in_transit', 'returned_pending_inspection', 'cleaning', 'under_repair', 'damaged', 'missing', 'sold', 'written_off'], true), fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(function ($assets) use ($search): void {
                $assets->where('asset_code', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($product) => $product->where('sku', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%"));
            }))
            ->selectSub(AssetCustodyEvent::query()->select('actor_id')->whereColumn('physical_asset_id', 'physical_assets.id')->whereIn('event_type', ['hire_asset_missing', 'cycle_count_missing'])->latest('id')->limit(1), 'missing_reporter_id')
            ->orderBy('asset_code')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (PhysicalAsset $asset): array => [
                'id' => $asset->id,
                'code' => $asset->asset_code,
                'product' => $asset->product->name,
                'sku' => $asset->product->sku,
                'category' => $asset->product->category,
                'classification' => $asset->product->classification?->label,
                'qualification' => $asset->product->qualification_level,
                'size' => $asset->product->size,
                'color' => $asset->product->color,
                'imageUrl' => $asset->product->image_path === null ? null : route('products.image', $asset->product_id),
                'status' => $asset->status,
                'missingReporterId' => $asset->missing_reporter_id === null ? null : (int) $asset->missing_reporter_id,
                'condition' => $asset->condition,
                'location' => $asset->currentLocation?->name ?? ($asset->custody_type === 'customer' ? 'With customer' : 'In transit / unverified'),
                'verifiedAt' => $asset->last_verified_at?->timezone(config('app.timezone'))->format('d M Y, H:i'),
            ]);

        $bulkStock = BulkStockBalance::query()->with(['product:id,sku,name,category,reorder_threshold,image_path', 'location:id,name'])
            ->whereHas('product', fn ($query) => $query->where('tracking_type', 'bulk')->where('is_active', true))
            ->when($allowedLocationIds !== null, fn ($query) => $query->whereIn('location_id', $allowedLocationIds))
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->orderBy('product_id')->get()
            ->map(fn (BulkStockBalance $balance): array => [
                'id' => $balance->id, 'productId' => $balance->product_id, 'sku' => $balance->product->sku,
                'product' => $balance->product->name, 'category' => $balance->product->category,
                'imageUrl' => $balance->product->image_path === null ? null : route('products.image', $balance->product_id),
                'locationId' => $balance->location_id, 'location' => $balance->location->name,
                'onHand' => $balance->on_hand, 'inventoryValueMinor' => $balance->inventory_value_minor,
                'valuationComplete' => $balance->valuation_complete,
                'averageCostMinor' => $balance->on_hand === 0 ? 0 : intdiv($balance->inventory_value_minor, $balance->on_hand),
                'reserved' => $balance->reserved, 'available' => $balance->available,
                'pendingInspection' => $balance->pending_inspection, 'damaged' => $balance->damaged,
                'reorderThreshold' => $balance->product->reorder_threshold,
            ]);
        $bulkProducts = Product::query()->where('tracking_type', 'bulk')->where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name']);
        $bulkMovements = StockMovement::query()->whereNull('physical_asset_id')->where('movement_type', 'like', 'bulk_%')
            ->with(['product:id,sku,name', 'fromLocation:id,name', 'toLocation:id,name', 'actor:id,name'])
            ->when($allowedLocationIds !== null, fn ($query) => $query->where(fn ($inner) => $inner->whereIn('from_location_id', $allowedLocationIds)->orWhereIn('to_location_id', $allowedLocationIds)))
            ->when($locationId !== null, fn ($query) => $query->where(fn ($inner) => $inner->where('from_location_id', $locationId)->orWhere('to_location_id', $locationId)))
            ->latest('occurred_at')->limit(10)->get()->map(fn (StockMovement $movement): array => [
                'id' => $movement->id, 'product' => $movement->product->name, 'sku' => $movement->product->sku,
                'type' => $movement->movement_type, 'quantity' => $movement->quantity,
                'source' => $movement->fromLocation?->name, 'destination' => $movement->toLocation?->name,
                'staff' => $movement->actor?->name, 'reason' => $movement->notes,
                'occurredAt' => $movement->occurred_at?->timezone(config('app.timezone'))->format('d M Y, H:i'),
            ]);

        return Inertia::render('Inventory/Index', [
            'view' => $view,
            'assets' => $assets,
            'bulkStock' => $bulkStock,
            'bulkProducts' => $bulkProducts,
            'bulkMovements' => $bulkMovements,
            'filters' => ['q' => $search, 'status' => $status, 'location' => $locationId],
            'locations' => Location::query()
                ->where('is_active', true)
                ->when($allowedLocationIds !== null, fn ($query) => $query->whereIn('id', $allowedLocationIds))
                ->orderBy('name')->get(['id', 'name']),
            'canApproveWriteOff' => in_array($user->role, ['super_admin', 'management'], true),
            'canApproveCycleCount' => in_array($user->role, ['super_admin', 'management'], true),
            'canAdjustBulk' => in_array($user->role, ['super_admin', 'management'], true),
            'canInspectBulk' => in_array($user->role, ['super_admin', 'management', 'storekeeper'], true),
            'canSellBulk' => in_array($user->role, ['super_admin', 'management', 'storekeeper', 'sales_crm'], true),
            'actorId' => $user->id,
            'pendingCounts' => InventoryCount::query()->with(['location:id,name', 'starter:id,name', 'lines:id,inventory_count_id,was_counted'])
                ->where('status', 'awaiting_approval')
                ->when($allowedLocationIds !== null, fn ($query) => $query->whereIn('location_id', $allowedLocationIds))
                ->orderBy('created_at')->limit(20)->get()
                ->map(fn (InventoryCount $count): array => [
                    'id' => $count->id, 'reference' => $count->reference, 'location' => $count->location->name,
                    'startedBy' => $count->starter->name, 'startedById' => $count->started_by,
                    'expected' => $count->expected_count, 'counted' => $count->counted_count,
                    'missing' => $count->expected_count - $count->counted_count,
                    'unexpectedCodes' => $count->unexpected_codes ?? [],
                    'createdAt' => $count->created_at?->timezone(config('app.timezone'))->format('d M Y, H:i'),
                ]),
            'pendingBulkCounts' => BulkInventoryCount::query()->with(['location:id,name', 'starter:id,name', 'lines:id,bulk_inventory_count_id,expected_on_hand,counted_on_hand,product_id', 'lines.product:id,name,sku'])
                ->where('status', 'awaiting_approval')
                ->when($allowedLocationIds !== null, fn ($query) => $query->whereIn('location_id', $allowedLocationIds))
                ->orderBy('created_at')->limit(20)->get()
                ->map(fn (BulkInventoryCount $count): array => [
                    'id' => $count->id, 'reference' => $count->reference, 'location' => $count->location->name,
                    'startedBy' => $count->starter->name, 'startedById' => $count->started_by,
                    'expected' => $count->expected_count, 'counted' => $count->counted_count,
                    'lines' => $count->lines->map(fn ($line): array => [
                        'product' => $line->product->name, 'sku' => $line->product->sku,
                        'expected' => $line->expected_on_hand, 'counted' => $line->counted_on_hand,
                    ])->all(),
                    'createdAt' => $count->created_at?->timezone(config('app.timezone'))->format('d M Y, H:i'),
                ]),
        ]);
    }
}
