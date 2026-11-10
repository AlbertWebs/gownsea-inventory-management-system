<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BulkHireReturn;
use App\Models\BulkStockBalance;
use App\Models\Hire;
use App\Models\HireItem;
use App\Models\LateReturnPolicy;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnBulkHire
{
    public function __construct(private readonly RecognizeHirePenalty $recognizePenalty, private readonly QueueCustomerSms $queueSms) {}

    /** @return array{return:BulkHireReturn,penalty_minor:int} */
    public function handle(int $hireItemId, int $quantity, Location $location, User $actor, string $idempotencyKey, string $condition, ?string $notes = null): array
    {
        abort_unless(in_array($actor->role, ['super_admin', 'management', 'storekeeper', 'sales_crm'], true), 403);
        abort_unless($actor->mayViewAllLocations() || $actor->locations()->whereKey($location->id)->exists(), 403);
        if (! in_array($condition, ['good', 'damaged'], true)) {
            throw ValidationException::withMessages(['condition' => 'Bulk returns must be marked good or damaged.']);
        }

        return DB::transaction(function () use ($hireItemId, $quantity, $location, $actor, $idempotencyKey, $condition, $notes): array {
            $existing = BulkHireReturn::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if ($existing->hire_item_id !== $hireItemId || $existing->quantity !== $quantity || $existing->location_id !== $location->id || $existing->condition !== $condition || $existing->actor_id !== $actor->id || $existing->notes !== $notes) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This return key was already used for different details.']);
                }

                return ['return' => $existing, 'penalty_minor' => $existing->penalty_assessed_minor];
            }

            $item = HireItem::query()->whereKey($hireItemId)->whereNull('physical_asset_id')->lockForUpdate()->firstOrFail();
            $hire = Hire::query()->whereKey($item->hire_id)->lockForUpdate()->firstOrFail();
            if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($hire->location_id)->exists()) {
                abort(404);
            }
            $remaining = $item->quantity - $item->returned_quantity;
            if (! in_array($item->status, ['issued', 'partially_returned'], true) || $quantity < 1 || $quantity > $remaining) {
                throw ValidationException::withMessages(['quantity' => 'Return quantity must be between one and the remaining quantity on this hire line.']);
            }
            $beforeReturnedQuantity = $item->returned_quantity;
            $beforeStatus = $item->status;

            $balance = BulkStockBalance::query()->where('product_id', $item->product_id)->where('location_id', $location->id)->lockForUpdate()->first();
            if ($balance === null) {
                $balance = BulkStockBalance::query()->create(['product_id' => $item->product_id, 'location_id' => $location->id]);
                $balance = BulkStockBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();
            }
            $now = now();
            $assessment = LateReturnPolicy::assess($hire->due_at, $now, $item->penalty_policy_snapshot ?? config('gownsea.penalties'));
            $penalty = $assessment['amount_minor'] * $quantity;
            $return = BulkHireReturn::query()->create([
                'hire_item_id' => $item->id, 'location_id' => $location->id, 'actor_id' => $actor->id,
                'quantity' => $quantity, 'inspected_quantity' => $condition === 'damaged' ? $quantity : 0,
                'penalty_assessed_minor' => $penalty, 'condition' => $condition,
                'idempotency_key' => $idempotencyKey, 'notes' => $notes, 'returned_at' => $now,
            ]);
            $item->returned_quantity += $quantity;
            $item->penalty_assessed_minor += $penalty;
            $item->returned_at = $now;
            $item->return_condition = $condition;
            $hasUninspectedReturns = $item->bulkReturns()->where('condition', 'good')->whereColumn('quantity', '>', 'inspected_quantity')->exists();
            $item->status = $item->returned_quantity < $item->quantity
                ? 'partially_returned'
                : ($hasUninspectedReturns ? 'returned_pending_inspection' : 'completed');
            $item->save();
            $this->recognizePenalty->handle($hire, $actor->id, $now, 'return');
            $balance->on_hand += $quantity;
            if ($condition === 'damaged') {
                $balance->damaged += $quantity;
            } else {
                $balance->pending_inspection += $quantity;
            }
            $balance->save();
            $hasOutstanding = HireItem::query()->where('hire_id', $hire->id)->whereColumn('returned_quantity', '<', 'quantity')->exists();
            $hasInspection = HireItem::query()->where('hire_id', $hire->id)->where('status', 'returned_pending_inspection')->exists();
            $hire->update([
                'status' => $hasOutstanding ? 'partially_returned' : ($hasInspection ? 'returned_pending_inspection' : 'completed'),
                'completed_at' => $hasOutstanding || $hasInspection ? null : $now,
            ]);
            if (! $hasOutstanding) {
                $this->queueSms->handle(
                    'hire_returned',
                    ['reference' => $hire->reference],
                    'hire-returned:'.$hire->id,
                    $hire->customer,
                    $hire,
                    location: $location,
                    actor: $actor,
                );
            }

            $movement = StockMovement::query()->create([
                'product_id' => $item->product_id, 'to_location_id' => $location->id, 'actor_id' => $actor->id,
                'movement_type' => $condition === 'damaged' ? 'bulk_hire_returned_damaged' : 'bulk_hire_returned_pending_inspection',
                'from_status' => 'hired', 'to_status' => $condition === 'damaged' ? 'damaged' : 'returned_pending_inspection',
                'quantity' => $quantity, 'idempotency_key' => 'bulk-hire-return:'.$idempotencyKey, 'notes' => $notes, 'occurred_at' => $now,
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $location->id, 'action' => 'hire.bulk_returned',
                'subject_type' => HireItem::class, 'subject_id' => $item->id,
                'before_state' => ['returned_quantity' => $beforeReturnedQuantity, 'status' => $beforeStatus],
                'after_state' => ['returned_quantity' => $item->returned_quantity, 'status' => $item->status, 'quantity' => $quantity, 'condition' => $condition, 'penalty_minor' => $penalty, 'stock_movement_id' => $movement->id],
                'reason' => $notes, 'occurred_at' => $now,
            ]);

            return ['return' => $return, 'penalty_minor' => $penalty];
        }, attempts: 3);
    }
}
