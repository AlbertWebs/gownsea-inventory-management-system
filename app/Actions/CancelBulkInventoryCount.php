<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BulkInventoryCount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelBulkInventoryCount
{
    public function handle(BulkInventoryCount $candidate, string $cancellationKey, string $reason, User $actor): BulkInventoryCount
    {
        return DB::transaction(function () use ($candidate, $cancellationKey, $reason, $actor): BulkInventoryCount {
            $count = BulkInventoryCount::query()->lockForUpdate()->findOrFail($candidate->id);
            abort_unless($actor->mayViewAllLocations() || $actor->locations()->whereKey($count->location_id)->exists(), 403);
            $prior = BulkInventoryCount::query()->where('cancellation_key', $cancellationKey)->first();
            if ($prior !== null) {
                if ($prior->id === $count->id && $prior->cancelled_by === $actor->id) {
                    return $prior;
                }
                throw ValidationException::withMessages(['idempotency_key' => 'This cancellation key was already used for another count.']);
            }
            if ($count->status !== 'awaiting_approval') {
                throw ValidationException::withMessages(['count' => 'Only a pending count can be cancelled.']);
            }
            if ($count->started_by !== $actor->id && ! in_array($actor->role, ['super_admin', 'management'], true)) {
                abort(403);
            }
            $now = now();
            $count->update(['status' => 'cancelled', 'cancelled_by' => $actor->id, 'cancellation_key' => $cancellationKey, 'cancellation_reason' => trim($reason), 'cancelled_at' => $now]);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $count->location_id, 'action' => 'inventory.bulk_cycle_count_cancelled',
                'subject_type' => BulkInventoryCount::class, 'subject_id' => $count->id,
                'before_state' => ['status' => 'awaiting_approval'], 'after_state' => ['status' => 'cancelled', 'reference' => $count->reference],
                'reason' => trim($reason), 'occurred_at' => $now,
            ]);

            return $count->refresh();
        }, attempts: 3);
    }
}
