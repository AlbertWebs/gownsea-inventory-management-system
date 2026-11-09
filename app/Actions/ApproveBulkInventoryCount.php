<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BulkInventoryCount;
use App\Models\BulkStockBalance;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveBulkInventoryCount
{
    public function handle(BulkInventoryCount $candidate, string $approvalKey, string $reason, User $actor): BulkInventoryCount
    {
        abort_unless(in_array($actor->role, ['super_admin', 'management'], true), 403);

        return DB::transaction(function () use ($candidate, $approvalKey, $reason, $actor): BulkInventoryCount {
            $count = BulkInventoryCount::query()->lockForUpdate()->findOrFail($candidate->id);
            abort_unless($actor->mayViewAllLocations() || $actor->locations()->whereKey($count->location_id)->exists(), 403);
            $prior = BulkInventoryCount::query()->where('approval_key', $approvalKey)->first();
            if ($prior !== null) {
                if ($prior->id === $count->id && $prior->approved_by === $actor->id) {
                    return $prior;
                }
                throw ValidationException::withMessages(['idempotency_key' => 'This approval key was already used for another count.']);
            }
            if ($count->status !== 'awaiting_approval') {
                throw ValidationException::withMessages(['count' => 'This count is no longer awaiting approval.']);
            }
            if ($count->started_by === $actor->id) {
                throw ValidationException::withMessages(['count' => 'A different manager must approve the stock count.']);
            }

            $lines = $count->lines()->with('product')->orderBy('product_id')->get();
            $balances = BulkStockBalance::query()->where('location_id', $count->location_id)->whereIn('product_id', $lines->pluck('product_id'))
                ->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
            foreach ($lines as $line) {
                $balance = $balances->get($line->product_id);
                if ($balance === null || $balance->on_hand !== $line->expected_on_hand || $balance->reserved !== $line->expected_reserved
                    || $balance->pending_inspection !== $line->expected_pending_inspection || $balance->damaged !== $line->expected_damaged) {
                    throw ValidationException::withMessages(['count' => 'Bulk stock changed after this count started. Recount before approval.']);
                }
                if ($line->counted_on_hand < $balance->reserved + $balance->pending_inspection + $balance->damaged) {
                    throw ValidationException::withMessages(['count' => 'The counted quantity is below stock reserved, awaiting inspection or damaged. Resolve those quantities before approval.']);
                }
            }

            $now = now();
            foreach ($lines as $line) {
                $balance = $balances->get($line->product_id);
                $delta = $line->counted_on_hand - $balance->on_hand;
                if ($delta === 0) {
                    continue;
                }
                if (! $balance->valuation_complete) {
                    throw ValidationException::withMessages(['count' => 'Set the opening valuation before approving quantity changes to this stock.']);
                }
                $product = $line->product;
                $costMinor = $delta > 0
                    ? ($balance->on_hand > 0 ? intdiv($balance->inventory_value_minor, $balance->on_hand) : (int) $product->purchase_cost_minor) * $delta
                    : BulkInventoryAccounting::costForQuantity($balance->inventory_value_minor, $balance->on_hand, abs($delta));
                $balance->update([
                    'on_hand' => $line->counted_on_hand,
                    'inventory_value_minor' => $balance->inventory_value_minor + ($delta > 0 ? $costMinor : -$costMinor),
                ]);
                $key = 'bulk-cycle-count:'.$count->id.':product:'.$line->product_id;
                $movement = StockMovement::query()->create([
                    'product_id' => $line->product_id, 'from_location_id' => $delta < 0 ? $count->location_id : null,
                    'to_location_id' => $delta > 0 ? $count->location_id : null, 'actor_id' => $actor->id,
                    'movement_type' => $delta > 0 ? 'bulk_cycle_count_in' : 'bulk_cycle_count_out',
                    'quantity' => abs($delta), 'cost_minor' => $costMinor, 'idempotency_key' => $key, 'notes' => trim($reason), 'occurred_at' => $now,
                ]);
                app(BulkInventoryAccounting::class)->postMovement($movement, $costMinor, $actor);
                AuditLog::query()->create([
                    'actor_id' => $actor->id, 'location_id' => $count->location_id, 'action' => 'inventory.bulk_cycle_count_adjusted',
                    'subject_type' => BulkStockBalance::class, 'subject_id' => $balance->id,
                    'before_state' => ['on_hand' => $line->expected_on_hand, 'reserved' => $line->expected_reserved, 'pending_inspection' => $line->expected_pending_inspection, 'damaged' => $line->expected_damaged],
                    'after_state' => ['on_hand' => $line->counted_on_hand, 'movement_id' => $movement->id, 'count_reference' => $count->reference],
                    'reason' => trim($reason), 'occurred_at' => $now,
                ]);
            }
            $count->update(['status' => 'approved', 'approved_by' => $actor->id, 'approval_key' => $approvalKey, 'approval_reason' => trim($reason), 'approved_at' => $now]);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $count->location_id, 'action' => 'inventory.bulk_cycle_count_approved',
                'subject_type' => BulkInventoryCount::class, 'subject_id' => $count->id,
                'before_state' => ['status' => 'awaiting_approval'],
                'after_state' => ['status' => 'approved', 'reference' => $count->reference, 'expected_count' => $count->expected_count, 'counted_count' => $count->counted_count],
                'reason' => trim($reason), 'occurred_at' => $now,
            ]);

            return $count->refresh();
        }, attempts: 3);
    }
}
