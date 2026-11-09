<?php

namespace App\Actions;

use App\Models\AssetCustodyEvent;
use App\Models\AuditLog;
use App\Models\Hire;
use App\Models\HireItem;
use App\Models\Location;
use App\Models\PhysicalAsset;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportMissingHireAsset
{
    public function handle(string $assetCode, Location $reportingLocation, User $actor, string $idempotencyKey, string $reason): AssetCustodyEvent
    {
        if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($reportingLocation->id)->exists()) {
            abort(403);
        }

        return DB::transaction(function () use ($assetCode, $reportingLocation, $actor, $idempotencyKey, $reason): AssetCustodyEvent {
            $existing = AssetCustodyEvent::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if ($existing->event_type !== 'hire_asset_missing' || $existing->physical_asset_id !== PhysicalAsset::query()->where('asset_code', $assetCode)->value('id')) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for a different asset event.']);
                }

                return $existing;
            }

            $asset = PhysicalAsset::query()->where('asset_code', $assetCode)->lockForUpdate()->firstOrFail();
            $item = HireItem::query()->where('physical_asset_id', $asset->id)->where('status', 'issued')->latest('id')->lockForUpdate()->first();
            if ($asset->status !== 'hired' || $asset->custody_type !== 'customer' || $item === null) {
                throw ValidationException::withMessages(['asset_code' => 'Only an asset currently issued to a customer can be reported missing.']);
            }

            $hire = Hire::query()->lockForUpdate()->findOrFail($item->hire_id);
            $before = ['status' => $asset->status, 'custody_type' => $asset->custody_type, 'customer_id' => $asset->custodian_customer_id, 'hire_item_status' => $item->status];
            $now = now();
            $asset->update([
                'status' => 'missing', 'custody_type' => 'missing', 'current_location_id' => null,
                'custodian_user_id' => null, 'custodian_customer_id' => null, 'last_verified_at' => $now,
            ]);
            $item->update(['status' => 'missing']);

            $hasIssuedItems = HireItem::query()->where('hire_id', $hire->id)->where('status', 'issued')->exists();
            $hasReturnedItems = HireItem::query()->where('hire_id', $hire->id)->whereIn('status', ['returned_pending_inspection', 'completed'])->exists();
            $hire->update(['status' => $hasIssuedItems ? ($hasReturnedItems ? 'partially_returned' : 'issued') : ($hasReturnedItems ? 'returned_pending_inspection' : 'partially_returned')]);

            $event = AssetCustodyEvent::query()->create([
                'physical_asset_id' => $asset->id, 'actor_id' => $actor->id,
                'from_location_id' => $hire->location_id, 'to_location_id' => null,
                'customer_id' => $hire->customer_id, 'event_type' => 'hire_asset_missing',
                'from_status' => 'hired', 'to_status' => 'missing', 'from_custody_type' => 'customer',
                'to_custody_type' => 'missing', 'idempotency_key' => $idempotencyKey,
                'notes' => $reason, 'occurred_at' => $now,
            ]);
            StockMovement::query()->create([
                'product_id' => $asset->product_id, 'physical_asset_id' => $asset->id,
                'from_location_id' => $hire->location_id, 'actor_id' => $actor->id,
                'movement_type' => 'hire_asset_missing', 'from_status' => 'hired', 'to_status' => 'missing',
                'quantity' => 1, 'idempotency_key' => $idempotencyKey, 'notes' => $reason, 'occurred_at' => $now,
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $reportingLocation->id, 'action' => 'hire.asset_reported_missing',
                'subject_type' => PhysicalAsset::class, 'subject_id' => $asset->id,
                'before_state' => $before,
                'after_state' => ['status' => 'missing', 'hire_reference' => $hire->reference, 'customer_id' => $hire->customer_id],
                'reason' => $reason, 'occurred_at' => $now,
            ]);

            return $event;
        }, attempts: 3);
    }
}
