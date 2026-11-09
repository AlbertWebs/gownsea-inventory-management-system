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

class InspectAsset
{
    public function handle(PhysicalAsset $asset, string $result, string $idempotencyKey, User $actor, ?string $reason = null): PhysicalAsset
    {
        if (! in_array($result, ['approved', 'cleaning', 'repair'], true)) {
            throw ValidationException::withMessages(['result' => 'Choose an approved inspection result.']);
        }

        if ($asset->current_location_id === null || (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($asset->current_location_id)->exists())) {
            abort(403);
        }

        return DB::transaction(function () use ($asset, $result, $idempotencyKey, $actor, $reason): PhysicalAsset {
            $priorEvent = AssetCustodyEvent::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($priorEvent !== null) {
                return $asset->refresh();
            }

            $asset = PhysicalAsset::query()->lockForUpdate()->findOrFail($asset->id);
            if ($asset->status !== 'returned_pending_inspection') {
                throw ValidationException::withMessages(['asset' => 'This asset is not awaiting inspection.']);
            }

            $hireItem = HireItem::query()->where('physical_asset_id', $asset->id)->where('status', 'returned_pending_inspection')->latest('id')->first();
            if ($hireItem === null || $hireItem->status !== 'returned_pending_inspection') {
                throw ValidationException::withMessages(['asset' => 'The linked hire record is not ready for inspection.']);
            }

            if ($result === 'approved' && $asset->condition !== 'good') {
                throw ValidationException::withMessages(['result' => 'An asset marked damaged cannot be released to available stock.']);
            }

            $now = now();
            $location = Location::query()->findOrFail($asset->current_location_id);
            $before = [
                'status' => $asset->status,
                'condition' => $asset->condition,
                'current_location_id' => $asset->current_location_id,
            ];
            $nextStatus = match ($result) {
                'approved' => 'available',
                'cleaning' => 'cleaning',
                'repair' => 'under_repair',
            };
            if ($result === 'repair') {
                $asset->condition = 'damaged';
            }
            $asset->update(['status' => $nextStatus, 'condition' => $asset->condition, 'last_verified_at' => $now]);
            $hireItem->update(['status' => 'completed']);

            $hire = Hire::query()->lockForUpdate()->findOrFail($hireItem->hire_id);
            $hasUnresolved = HireItem::query()->where('hire_id', $hire->id)->whereIn('status', ['issued', 'returned_pending_inspection', 'missing'])->exists();
            if (! $hasUnresolved) {
                $hire->update(['status' => 'completed', 'completed_at' => $now]);
            }

            AssetCustodyEvent::query()->create([
                'physical_asset_id' => $asset->id,
                'actor_id' => $actor->id,
                'from_location_id' => $location->id,
                'to_location_id' => $location->id,
                'event_type' => 'inspection_completed',
                'from_status' => 'returned_pending_inspection',
                'to_status' => $nextStatus,
                'from_custody_type' => $asset->custody_type,
                'to_custody_type' => $asset->custody_type,
                'idempotency_key' => $idempotencyKey,
                'notes' => $reason,
                'occurred_at' => $now,
            ]);
            StockMovement::query()->create([
                'product_id' => $asset->product_id,
                'physical_asset_id' => $asset->id,
                'from_location_id' => $location->id,
                'to_location_id' => $location->id,
                'actor_id' => $actor->id,
                'movement_type' => 'inspection_'.$result,
                'from_status' => 'returned_pending_inspection',
                'to_status' => $nextStatus,
                'quantity' => 1,
                'idempotency_key' => $idempotencyKey,
                'notes' => $reason,
                'occurred_at' => $now,
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'location_id' => $location->id,
                'action' => 'asset.inspection_completed',
                'subject_type' => PhysicalAsset::class,
                'subject_id' => $asset->id,
                'before_state' => $before,
                'after_state' => ['status' => $asset->status, 'condition' => $asset->condition, 'current_location_id' => $asset->current_location_id],
                'reason' => $reason,
                'occurred_at' => $now,
            ]);

            return $asset->refresh();
        }, attempts: 3);
    }
}
