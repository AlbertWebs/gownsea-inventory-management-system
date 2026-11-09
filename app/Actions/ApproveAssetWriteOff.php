<?php

namespace App\Actions;

use App\Models\AssetCustodyEvent;
use App\Models\AuditLog;
use App\Models\Hire;
use App\Models\HireItem;
use App\Models\PhysicalAsset;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveAssetWriteOff
{
    public function handle(PhysicalAsset $candidate, string $idempotencyKey, string $reason, User $actor): PhysicalAsset
    {
        abort_unless(in_array($actor->role, ['super_admin', 'management'], true), 403);

        return DB::transaction(function () use ($candidate, $idempotencyKey, $reason, $actor): PhysicalAsset {
            $prior = AssetCustodyEvent::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($prior !== null) {
                if ($prior->event_type !== 'asset_written_off' || $prior->physical_asset_id !== $candidate->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for a different asset write-off.']);
                }

                return PhysicalAsset::query()->findOrFail($prior->physical_asset_id);
            }

            $asset = PhysicalAsset::query()->lockForUpdate()->findOrFail($candidate->id);
            $item = HireItem::query()->where('physical_asset_id', $asset->id)->where('status', 'missing')->latest('id')->lockForUpdate()->first();
            if ($asset->status !== 'missing') {
                throw ValidationException::withMessages(['asset' => 'Only a reported missing asset can be written off.']);
            }
            $hire = $item === null ? null : Hire::query()->lockForUpdate()->findOrFail($item->hire_id);
            $report = AssetCustodyEvent::query()->where('physical_asset_id', $asset->id)
                ->whereIn('event_type', ['hire_asset_missing', 'cycle_count_missing'])->latest('id')->first();
            if ($report === null) {
                throw ValidationException::withMessages(['asset' => 'A missing report is required before write-off approval.']);
            }
            $locationId = $hire?->location_id ?? $report->from_location_id;
            if (! $actor->mayViewAllLocations() && ($locationId === null || ! $actor->locations()->whereKey($locationId)->exists())) {
                abort(403);
            }
            if ($report->actor_id === $actor->id) {
                throw ValidationException::withMessages(['asset' => 'A different manager must approve the write-off than the person who reported the loss.']);
            }

            $now = now();
            $asset->update(['status' => 'written_off', 'last_verified_at' => $now]);
            if ($item !== null && $hire !== null) {
                $item->update(['status' => 'written_off']);
                $hasUnresolved = HireItem::query()->where('hire_id', $hire->id)->whereIn('status', ['issued', 'returned_pending_inspection', 'missing'])->exists();
                if (! $hasUnresolved) {
                    $hire->update(['status' => 'completed', 'completed_at' => $now]);
                }
            }

            AssetCustodyEvent::query()->create([
                'physical_asset_id' => $asset->id, 'actor_id' => $actor->id, 'from_location_id' => $locationId,
                'customer_id' => $hire?->customer_id, 'event_type' => 'asset_written_off', 'from_status' => 'missing',
                'to_status' => 'written_off', 'from_custody_type' => 'missing', 'to_custody_type' => 'missing',
                'idempotency_key' => $idempotencyKey, 'notes' => $reason, 'occurred_at' => $now,
            ]);
            StockMovement::query()->create([
                'product_id' => $asset->product_id, 'physical_asset_id' => $asset->id,
                'from_location_id' => $locationId, 'actor_id' => $actor->id, 'movement_type' => 'asset_written_off',
                'from_status' => 'missing', 'to_status' => 'written_off', 'quantity' => 1,
                'idempotency_key' => $idempotencyKey, 'notes' => $reason, 'occurred_at' => $now,
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $locationId, 'action' => 'inventory.asset_written_off',
                'subject_type' => PhysicalAsset::class, 'subject_id' => $asset->id,
                'before_state' => ['status' => 'missing'], 'after_state' => ['status' => 'written_off', 'hire_reference' => $hire?->reference],
                'reason' => $reason, 'occurred_at' => $now,
            ]);

            return $asset->refresh();
        }, attempts: 3);
    }
}
