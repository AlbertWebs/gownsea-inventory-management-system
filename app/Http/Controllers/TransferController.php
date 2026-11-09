<?php

namespace App\Http\Controllers;

use App\Actions\TransferAsset;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\PhysicalAsset;
use App\Models\StockTransfer;
use App\Models\StockTransferAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TransferController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $locationIds = $user->mayViewAllLocations() ? null : $user->locations()->pluck('locations.id')->all();

        return Inertia::render('Operations/Transfers', [
            'transfers' => StockTransfer::query()
                ->with(['source:id,name', 'destination:id,name', 'assets:id,stock_transfer_id,physical_asset_id,received_at'])
                ->when($locationIds !== null, fn ($query) => $query->where(fn ($transfers) => $transfers
                    ->whereIn('source_location_id', $locationIds)
                    ->orWhereIn('destination_location_id', $locationIds)))
                ->latest('created_at')->paginate(20)->withQueryString()->through(fn (StockTransfer $transfer): array => [
                    'id' => $transfer->id,
                    'reference' => $transfer->reference,
                    'source' => $transfer->source->name,
                    'destination' => $transfer->destination->name,
                    'sourceLocationId' => $transfer->source_location_id,
                    'destinationLocationId' => $transfer->destination_location_id,
                    'status' => $transfer->status,
                    'createdAt' => $transfer->created_at->timezone(config('app.timezone'))->format('d M Y, H:i'),
                    'assetCount' => $transfer->assets->count(),
                    'assets' => $transfer->assets->map(fn ($line): array => [
                        'id' => $line->physical_asset_id,
                        'received' => $line->received_at !== null,
                        'code' => $line->asset?->asset_code,
                    ])->values(),
                ]),
            'locations' => Location::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'sourceLocationIds' => $locationIds,
            'canApprove' => in_array($user->role, ['super_admin', 'management'], true),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless(in_array($actor->role, ['super_admin', 'management', 'storekeeper'], true), 403);
        $data = $request->validate([
            'source_location_id' => ['required', 'integer', 'exists:locations,id', 'different:destination_location_id'],
            'destination_location_id' => ['required', 'integer', 'exists:locations,id'],
            'asset_codes' => ['required', 'array', 'min:1', 'max:200'],
            'asset_codes.*' => ['required', 'string', 'distinct', 'max:64'],
            'idempotency_key' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($data['source_location_id'])->exists()) {
            abort(403);
        }

        $assetCodes = collect($data['asset_codes'])->unique()->values();
        $transfer = DB::transaction(function () use ($data, $assetCodes, $actor): StockTransfer {
            $existing = StockTransfer::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                return $existing;
            }

            $assets = PhysicalAsset::query()->whereIn('asset_code', $assetCodes)->orderBy('id')->lockForUpdate()->get(['id', 'asset_code', 'current_location_id', 'status']);
            if ($assets->count() !== $assetCodes->count() || $assets->contains(fn (PhysicalAsset $asset): bool => $asset->current_location_id !== (int) $data['source_location_id'] || $asset->status !== 'available')) {
                throw ValidationException::withMessages(['asset_codes' => 'Every scanned gown must be available at the selected source location.']);
            }

            $transfer = StockTransfer::query()->create([
                'reference' => 'TRF-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'idempotency_key' => $data['idempotency_key'],
                'source_location_id' => $data['source_location_id'],
                'destination_location_id' => $data['destination_location_id'],
                'requested_by' => $actor->id,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($assets as $asset) {
                StockTransferAsset::query()->create(['stock_transfer_id' => $transfer->id, 'physical_asset_id' => $asset->id]);
            }
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $data['source_location_id'], 'action' => 'transfer.created',
                'subject_type' => StockTransfer::class, 'subject_id' => $transfer->id,
                'after_state' => ['reference' => $transfer->reference, 'destination_location_id' => (int) $data['destination_location_id'], 'asset_count' => $assets->count()],
                'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(), 'occurred_at' => now(),
            ]);

            return $transfer;
        }, attempts: 3);

        return back()->with('success', "Transfer {$transfer->reference} was created as a draft.");
    }

    public function dispatch(Request $request, StockTransfer $transfer, TransferAsset $action): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['super_admin', 'management'], true), 403);
        if ($transfer->idempotency_key === null) {
            abort(422, 'This transfer does not include an idempotency reference.');
        }

        $assetCodes = $request->validate(['asset_codes' => ['required', 'array', 'min:1', 'max:200'], 'asset_codes.*' => ['required', 'string', 'distinct', 'exists:physical_assets,asset_code']])['asset_codes'];
        $assetIds = PhysicalAsset::query()->whereIn('asset_code', $assetCodes)->pluck('id')->all();
        $transfer->update(['approved_by' => $request->user()->id, 'status' => 'approved']);
        $action->dispatch($transfer, $assetIds, $request->user());

        return back()->with('success', "Transfer {$transfer->reference} is now in transit.");
    }

    public function receive(Request $request, StockTransfer $transfer, TransferAsset $action): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['super_admin', 'management', 'storekeeper'], true), 403);
        $assetCodes = $request->validate(['asset_codes' => ['required', 'array', 'min:1', 'max:200'], 'asset_codes.*' => ['required', 'string', 'distinct', 'exists:physical_assets,asset_code']])['asset_codes'];
        $assetIds = PhysicalAsset::query()->whereIn('asset_code', $assetCodes)->pluck('id')->all();
        $action->receive($transfer, $assetIds, $request->user());

        return back()->with('success', "Scanned items for transfer {$transfer->reference} were received.");
    }
}
