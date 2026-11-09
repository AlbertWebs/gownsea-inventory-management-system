<?php

namespace App\Actions;

use App\Models\AssetCustodyEvent;
use App\Models\AuditLog;
use App\Models\InventoryCount;
use App\Models\PhysicalAsset;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveInventoryCount
{
    public function handle(InventoryCount $candidate, string $approvalKey, string $reason, User $actor): InventoryCount
    {
        abort_unless(in_array($actor->role, ['super_admin', 'management'], true), 403);

        return DB::transaction(function () use ($candidate, $approvalKey, $reason, $actor): InventoryCount {
            $count = InventoryCount::query()->lockForUpdate()->findOrFail($candidate->id);
            if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($count->location_id)->exists()) {
                abort(403);
            }
            $priorApproval = InventoryCount::query()->where('approval_key', $approvalKey)->first();
            if ($priorApproval !== null) {
                if ($priorApproval->id === $count->id && $priorApproval->approved_by === $actor->id) {
                    return $priorApproval;
                }

                throw ValidationException::withMessages(['idempotency_key' => 'This approval key was already used for a different count.']);
            }
            if ($count->status !== 'awaiting_approval') {
                throw ValidationException::withMessages(['count' => 'This count is no longer awaiting approval.']);
            }
            if ($count->started_by === $actor->id) {
                throw ValidationException::withMessages(['count' => 'A different manager must approve a stock count.']);
            }

            $unseen = $count->lines()->where('was_counted', false)->orderBy('id')->get();
            $assets = PhysicalAsset::query()->whereIn('id', $unseen->pluck('physical_asset_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($unseen as $line) {
                $asset = $assets->get($line->physical_asset_id);
                if ($asset === null || $asset->current_location_id !== $count->location_id || $asset->status !== $line->expected_status || $asset->current_transfer_id !== null) {
                    throw ValidationException::withMessages(['count' => 'Inventory changed after this count started. Recount the affected stock before approval.']);
                }
            }

            $now = now();
            foreach ($unseen as $line) {
                $asset = $assets->get($line->physical_asset_id);
                $before = ['status' => $asset->status, 'custody_type' => $asset->custody_type, 'current_location_id' => $asset->current_location_id];
                $asset->update(['status' => 'missing', 'custody_type' => 'missing', 'current_location_id' => null, 'last_verified_at' => $now]);
                $key = 'cycle-count:'.$count->id.':asset:'.$asset->id;
                AssetCustodyEvent::query()->create([
                    'physical_asset_id' => $asset->id, 'actor_id' => $actor->id, 'from_location_id' => $count->location_id,
                    'event_type' => 'cycle_count_missing', 'from_status' => $before['status'], 'to_status' => 'missing',
                    'from_custody_type' => $before['custody_type'], 'to_custody_type' => 'missing', 'idempotency_key' => $key,
                    'notes' => $reason, 'occurred_at' => $now,
                ]);
                StockMovement::query()->create([
                    'product_id' => $asset->product_id, 'physical_asset_id' => $asset->id, 'from_location_id' => $count->location_id,
                    'actor_id' => $actor->id, 'movement_type' => 'cycle_count_missing', 'from_status' => $before['status'],
                    'to_status' => 'missing', 'quantity' => 1, 'idempotency_key' => $key, 'notes' => $reason, 'occurred_at' => $now,
                ]);
                AuditLog::query()->create([
                    'actor_id' => $actor->id, 'location_id' => $count->location_id, 'action' => 'inventory.cycle_count_missing',
                    'subject_type' => PhysicalAsset::class, 'subject_id' => $asset->id, 'before_state' => $before,
                    'after_state' => ['status' => 'missing', 'count_reference' => $count->reference], 'reason' => $reason, 'occurred_at' => $now,
                ]);
            }

            $count->update(['status' => 'approved', 'approved_by' => $actor->id, 'approval_key' => $approvalKey, 'approval_reason' => $reason, 'approved_at' => $now]);

            return $count->refresh();
        }, attempts: 3);
    }
}
