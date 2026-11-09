<?php

namespace App\Actions;

use App\Models\AssetCustodyEvent;
use App\Models\AuditLog;
use App\Models\BulkStockBalance;
use App\Models\Hire;
use App\Models\HireItem;
use App\Models\PhysicalAsset;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelHireReservation
{
    public function handle(Hire $hire, User $actor, ?string $reason = null): Hire
    {
        if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($hire->location_id)->exists()) {
            abort(403);
        }

        return DB::transaction(function () use ($hire, $actor, $reason): Hire {
            $hire = Hire::query()->lockForUpdate()->findOrFail($hire->id);
            if ($hire->status === 'cancelled') {
                return $hire;
            }
            if ($hire->status !== 'reserved') {
                throw ValidationException::withMessages(['hire' => 'Only an unissued reservation can be cancelled.']);
            }
            if ($hire->deposit_minor > 0) {
                throw ValidationException::withMessages(['hire' => 'Refund the remaining deposit before cancelling this reservation.']);
            }

            $items = HireItem::query()->where('hire_id', $hire->id)->orderBy('id')->lockForUpdate()->get();
            if ($items->isEmpty() || $items->contains(fn (HireItem $item): bool => $item->status !== 'reserved')) {
                throw ValidationException::withMessages(['hire' => 'This reservation no longer has a complete set of reserved items.']);
            }
            $bulkItems = $items->whereNull('physical_asset_id');
            $assetItems = $items->whereNotNull('physical_asset_id');
            if ($bulkItems->isNotEmpty() && $assetItems->isNotEmpty()) {
                throw ValidationException::withMessages(['hire' => 'This reservation contains mixed tracking types. Contact management.']);
            }
            $now = now();
            if ($bulkItems->isNotEmpty()) {
                $balances = BulkStockBalance::query()->whereIn('product_id', $bulkItems->pluck('product_id'))->where('location_id', $hire->location_id)->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
                if ($balances->count() !== $bulkItems->count()) {
                    throw ValidationException::withMessages(['hire' => 'A bulk reservation balance is missing. Contact management.']);
                }
                foreach ($bulkItems as $item) {
                    $balance = $balances->get($item->product_id);
                    if ($balance->reserved < $item->quantity) {
                        throw ValidationException::withMessages(['hire' => 'Reserved quantity no longer reconciles to the stock balance. Contact management.']);
                    }
                }
                foreach ($bulkItems as $item) {
                    $balance = $balances->get($item->product_id);
                    $before = ['on_hand' => $balance->on_hand, 'reserved' => $balance->reserved];
                    $balance->decrement('reserved', $item->quantity);
                    $item->update(['status' => 'cancelled']);
                    $key = "bulk-hire-cancel:{$hire->id}:{$item->product_id}";
                    $movement = StockMovement::query()->create([
                        'product_id' => $item->product_id, 'from_location_id' => $hire->location_id,
                        'to_location_id' => $hire->location_id, 'actor_id' => $actor->id,
                        'movement_type' => 'bulk_hire_reservation_cancelled', 'from_status' => 'reserved', 'to_status' => 'available',
                        'quantity' => $item->quantity, 'idempotency_key' => $key, 'notes' => $reason, 'occurred_at' => now(),
                    ]);
                    AuditLog::query()->create([
                        'actor_id' => $actor->id, 'location_id' => $hire->location_id, 'action' => 'hire.bulk_reservation_cancelled',
                        'subject_type' => BulkStockBalance::class, 'subject_id' => $balance->id, 'before_state' => $before,
                        'after_state' => ['on_hand' => $balance->fresh()->on_hand, 'reserved' => $balance->fresh()->reserved, 'hire_id' => $hire->id, 'quantity' => $item->quantity, 'movement_id' => $movement->id],
                        'reason' => $reason, 'occurred_at' => now(),
                    ]);
                }
            } else {
                $assets = PhysicalAsset::query()->whereIn('id', $items->pluck('physical_asset_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if ($assets->count() !== $items->count()) {
                    throw ValidationException::withMessages(['hire' => 'A reserved asset record is missing. Contact management.']);
                }
                foreach ($items as $item) {
                    $asset = $assets->get($item->physical_asset_id);
                    if ($asset->status !== 'reserved' || $asset->current_location_id !== $hire->location_id) {
                        throw ValidationException::withMessages(['hire' => "Reserved gown {$asset->asset_code} is no longer at the pickup location."]);
                    }
                    $asset->update(['status' => 'available']);
                    $item->update(['status' => 'cancelled']);
                    $key = "hire-cancel:{$hire->id}:{$asset->id}";
                    AssetCustodyEvent::query()->create([
                        'physical_asset_id' => $asset->id,
                        'actor_id' => $actor->id,
                        'from_location_id' => $hire->location_id,
                        'to_location_id' => $hire->location_id,
                        'customer_id' => $hire->customer_id,
                        'event_type' => 'hire_reservation_cancelled',
                        'from_status' => 'reserved',
                        'to_status' => 'available',
                        'from_custody_type' => $asset->custody_type,
                        'to_custody_type' => $asset->custody_type,
                        'idempotency_key' => $key,
                        'notes' => $reason,
                        'occurred_at' => $now,
                    ]);
                    StockMovement::query()->create([
                        'product_id' => $asset->product_id,
                        'physical_asset_id' => $asset->id,
                        'from_location_id' => $hire->location_id,
                        'to_location_id' => $hire->location_id,
                        'actor_id' => $actor->id,
                        'movement_type' => 'hire_reservation_cancelled',
                        'from_status' => 'reserved',
                        'to_status' => 'available',
                        'quantity' => 1,
                        'idempotency_key' => $key,
                        'notes' => $reason,
                        'occurred_at' => $now,
                    ]);
                }
            }
            $before = ['status' => $hire->status];
            $hire->update(['status' => 'cancelled']);
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'location_id' => $hire->location_id,
                'action' => 'hire.reservation_cancelled',
                'subject_type' => Hire::class,
                'subject_id' => $hire->id,
                'before_state' => $before,
                'after_state' => ['status' => 'cancelled', 'unit_count' => (int) $items->sum('quantity'), 'tracking_type' => $bulkItems->isNotEmpty() ? 'bulk' : 'serialized'],
                'reason' => $reason,
                'occurred_at' => $now,
            ]);

            return $hire->refresh();
        }, attempts: 3);
    }
}
