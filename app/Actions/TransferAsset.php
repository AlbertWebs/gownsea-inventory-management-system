<?php

namespace App\Actions;

use App\Models\AssetCustodyEvent;
use App\Models\AuditLog;
use App\Models\PhysicalAsset;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferAsset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferAsset
{
    /** @param list<int> $assetIds */
    public function dispatch(StockTransfer $transfer, array $assetIds, User $actor): StockTransfer
    {
        $this->authorizeLocation($actor, (int) $transfer->source_location_id);

        return DB::transaction(function () use ($transfer, $assetIds, $actor): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if (! in_array($transfer->status, ['draft', 'approved'], true)) {
                $alreadyDispatched = in_array($transfer->status, ['in_transit', 'partially_received', 'completed'], true)
                    && collect($assetIds)->every(fn (int $assetId): bool => StockTransferAsset::query()
                        ->where('stock_transfer_id', $transfer->id)->where('physical_asset_id', $assetId)->whereNotNull('dispatched_at')->exists());

                if ($alreadyDispatched) {
                    return $transfer;
                }

                throw ValidationException::withMessages(['status' => 'This transfer is not ready for dispatch.']);
            }

            if ($transfer->source_location_id === $transfer->destination_location_id) {
                throw ValidationException::withMessages(['destination_location_id' => 'Choose a different destination location.']);
            }

            $assets = PhysicalAsset::query()->whereIn('id', $assetIds)->orderBy('id')->lockForUpdate()->get();
            if ($assets->count() !== count(array_unique($assetIds)) || $assets->isEmpty()) {
                throw ValidationException::withMessages(['assets' => 'Select one or more valid assets.']);
            }

            foreach ($assets as $asset) {
                if ($asset->current_location_id !== $transfer->source_location_id || $asset->status !== 'available' || $asset->custody_type === 'customer' || $asset->current_transfer_id !== null) {
                    throw ValidationException::withMessages(['assets' => "Asset {$asset->asset_code} is not available at the transfer source."]);
                }
            }

            $now = now();
            foreach ($assets as $asset) {
                StockTransferAsset::query()->updateOrCreate(
                    ['stock_transfer_id' => $transfer->id, 'physical_asset_id' => $asset->id],
                    ['dispatched_by' => $actor->id, 'dispatched_at' => $now],
                );

                $before = ['status' => $asset->status, 'custody_type' => $asset->custody_type, 'current_location_id' => $asset->current_location_id];
                $asset->update([
                    'current_location_id' => null,
                    'custody_type' => 'carrier',
                    'status' => 'in_transit',
                    'custodian_user_id' => $actor->id,
                    'current_transfer_id' => $transfer->id,
                ]);

                AssetCustodyEvent::query()->firstOrCreate(
                    ['idempotency_key' => "transfer-dispatch:{$transfer->id}:{$asset->id}"],
                    [
                        'physical_asset_id' => $asset->id,
                        'actor_id' => $actor->id,
                        'from_location_id' => $transfer->source_location_id,
                        'to_location_id' => $transfer->destination_location_id,
                        'stock_transfer_id' => $transfer->id,
                        'event_type' => 'transfer_dispatched',
                        'from_status' => 'available',
                        'to_status' => 'in_transit',
                        'from_custody_type' => $transfer->source->type === 'warehouse' ? 'warehouse' : 'branch',
                        'to_custody_type' => 'carrier',
                        'notes' => 'Asset handed over for inter-location transfer.',
                        'occurred_at' => $now,
                    ],
                );

                StockMovement::query()->firstOrCreate(
                    ['idempotency_key' => "transfer-dispatch:{$transfer->id}:{$asset->id}"],
                    [
                        'product_id' => $asset->product_id,
                        'physical_asset_id' => $asset->id,
                        'from_location_id' => $transfer->source_location_id,
                        'to_location_id' => null,
                        'actor_id' => $actor->id,
                        'stock_transfer_id' => $transfer->id,
                        'movement_type' => 'transfer_dispatched',
                        'from_status' => 'available',
                        'to_status' => 'in_transit',
                        'quantity' => 1,
                        'occurred_at' => $now,
                    ],
                );
                AuditLog::query()->create([
                    'actor_id' => $actor->id,
                    'location_id' => $transfer->source_location_id,
                    'action' => 'transfer.asset_dispatched',
                    'subject_type' => PhysicalAsset::class,
                    'subject_id' => $asset->id,
                    'before_state' => $before,
                    'after_state' => ['status' => $asset->status, 'custody_type' => $asset->custody_type, 'current_location_id' => $asset->current_location_id, 'transfer_id' => $transfer->id],
                    'reason' => "Dispatched on transfer {$transfer->reference}.",
                    'occurred_at' => $now,
                ]);
            }

            $transfer->update(['status' => 'in_transit', 'dispatched_by' => $actor->id, 'dispatched_at' => $now]);

            return $transfer->refresh();
        }, attempts: 3);
    }

    /** @param list<int> $assetIds */
    public function receive(StockTransfer $transfer, array $assetIds, User $actor): StockTransfer
    {
        $this->authorizeLocation($actor, (int) $transfer->destination_location_id);

        return DB::transaction(function () use ($transfer, $assetIds, $actor): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if (! in_array($transfer->status, ['in_transit', 'partially_received', 'completed'], true)) {
                throw ValidationException::withMessages(['status' => 'This transfer has no items awaiting receipt.']);
            }

            $allLines = StockTransferAsset::query()
                ->where('stock_transfer_id', $transfer->id)
                ->whereIn('physical_asset_id', $assetIds)
                ->orderBy('physical_asset_id')
                ->lockForUpdate()
                ->get();

            if ($allLines->count() !== count(array_unique($assetIds)) || $allLines->isEmpty()) {
                throw ValidationException::withMessages(['assets' => 'One or more scanned assets are not awaiting receipt on this transfer.']);
            }

            $lines = $allLines->whereNull('received_at');
            if ($lines->isEmpty()) {
                return $transfer;
            }

            $now = now();
            foreach ($lines as $line) {
                $asset = PhysicalAsset::query()->lockForUpdate()->findOrFail($line->physical_asset_id);
                if ($asset->current_transfer_id !== $transfer->id || $asset->status !== 'in_transit') {
                    throw ValidationException::withMessages(['assets' => "Asset {$asset->asset_code} is not in transit on this transfer."]);
                }

                $before = ['status' => $asset->status, 'custody_type' => $asset->custody_type, 'current_location_id' => $asset->current_location_id];
                $asset->update([
                    'current_location_id' => $transfer->destination_location_id,
                    'custody_type' => $transfer->destination->type === 'warehouse' ? 'warehouse' : 'branch',
                    'status' => 'available',
                    'custodian_user_id' => null,
                    'current_transfer_id' => null,
                    'last_verified_at' => $now,
                ]);
                $line->update(['received_by' => $actor->id, 'received_at' => $now, 'receipt_condition' => $asset->condition]);

                AssetCustodyEvent::query()->firstOrCreate(
                    ['idempotency_key' => "transfer-receive:{$transfer->id}:{$asset->id}"],
                    [
                        'physical_asset_id' => $asset->id,
                        'actor_id' => $actor->id,
                        'from_location_id' => null,
                        'to_location_id' => $transfer->destination_location_id,
                        'stock_transfer_id' => $transfer->id,
                        'event_type' => 'transfer_received',
                        'from_status' => 'in_transit',
                        'to_status' => 'available',
                        'from_custody_type' => 'carrier',
                        'to_custody_type' => $asset->custody_type,
                        'notes' => 'Asset scanned and received at destination.',
                        'occurred_at' => $now,
                    ],
                );

                StockMovement::query()->firstOrCreate(
                    ['idempotency_key' => "transfer-receive:{$transfer->id}:{$asset->id}"],
                    [
                        'product_id' => $asset->product_id,
                        'physical_asset_id' => $asset->id,
                        'from_location_id' => null,
                        'to_location_id' => $transfer->destination_location_id,
                        'actor_id' => $actor->id,
                        'stock_transfer_id' => $transfer->id,
                        'movement_type' => 'transfer_received',
                        'from_status' => 'in_transit',
                        'to_status' => 'available',
                        'quantity' => 1,
                        'occurred_at' => $now,
                    ],
                );
                AuditLog::query()->create([
                    'actor_id' => $actor->id,
                    'location_id' => $transfer->destination_location_id,
                    'action' => 'transfer.asset_received',
                    'subject_type' => PhysicalAsset::class,
                    'subject_id' => $asset->id,
                    'before_state' => $before,
                    'after_state' => ['status' => $asset->status, 'custody_type' => $asset->custody_type, 'current_location_id' => $asset->current_location_id, 'transfer_id' => $transfer->id],
                    'reason' => "Received on transfer {$transfer->reference}.",
                    'occurred_at' => $now,
                ]);
            }

            $outstanding = StockTransferAsset::query()->where('stock_transfer_id', $transfer->id)->whereNull('received_at')->exists();
            $transfer->update([
                'status' => $outstanding ? 'partially_received' : 'completed',
                'received_by' => $actor->id,
                'received_at' => $outstanding ? null : $now,
            ]);

            return $transfer->refresh();
        }, attempts: 3);
    }

    private function authorizeLocation(User $actor, int $locationId): void
    {
        if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($locationId)->exists()) {
            abort(403);
        }
    }
}
