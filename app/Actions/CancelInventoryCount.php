<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\InventoryCount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelInventoryCount
{
    public function handle(InventoryCount $candidate, string $key, string $reason, User $actor): InventoryCount
    {
        return DB::transaction(function () use ($candidate, $key, $reason, $actor): InventoryCount {
            $count = InventoryCount::query()->lockForUpdate()->findOrFail($candidate->id);
            if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($count->location_id)->exists()) {
                abort(403);
            }
            if ($count->cancellation_key === $key && $count->cancelled_by === $actor->id) {
                return $count;
            }
            if ($count->status !== 'awaiting_approval') {
                throw ValidationException::withMessages(['count' => 'Only a count awaiting approval can be cancelled.']);
            }
            if ($count->started_by !== $actor->id && ! in_array($actor->role, ['super_admin', 'management'], true)) {
                abort(403);
            }

            $before = ['status' => $count->status, 'expected_count' => $count->expected_count, 'counted_count' => $count->counted_count];
            $count->update([
                'status' => 'cancelled', 'cancellation_key' => $key, 'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason, 'cancelled_at' => now(),
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $count->location_id, 'action' => 'inventory.cycle_count_cancelled',
                'subject_type' => InventoryCount::class, 'subject_id' => $count->id, 'before_state' => $before,
                'after_state' => ['status' => 'cancelled', 'reference' => $count->reference], 'reason' => $reason, 'occurred_at' => now(),
            ]);

            return $count->refresh();
        }, attempts: 3);
    }
}
