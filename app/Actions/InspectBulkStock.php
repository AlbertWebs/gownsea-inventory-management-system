<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BulkHireReturn;
use App\Models\BulkStockBalance;
use App\Models\Hire;
use App\Models\HireItem;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InspectBulkStock
{
    /** @param array{product_id:int,location_id:int,quantity:int,source:string,result:string,reason:string,idempotency_key:string} $data */
    public function handle(array $data, User $actor): StockMovement
    {
        abort_unless(in_array($actor->role, ['super_admin', 'management', 'storekeeper'], true), 403);
        abort_unless($actor->mayViewAllLocations() || $actor->locations()->whereKey($data['location_id'])->exists(), 403);

        return DB::transaction(function () use ($data, $actor): StockMovement {
            $type = $data['result'] === 'available' ? 'bulk_inspection_approved' : 'bulk_inspection_damaged';
            $key = 'bulk-inspection:'.$data['idempotency_key'];
            $existing = StockMovement::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ($existing->product_id !== (int) $data['product_id'] || $existing->to_location_id !== (int) $data['location_id']
                    || $existing->quantity !== (int) $data['quantity'] || $existing->movement_type !== $type
                    || $existing->from_status !== $data['source'] || $existing->to_status !== $data['result']
                    || $existing->notes !== trim($data['reason']) || $existing->actor_id !== $actor->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This inspection key was already used for a different stock decision.']);
                }

                return $existing;
            }

            $product = Product::query()->whereKey($data['product_id'])->where('tracking_type', 'bulk')->lockForUpdate()->firstOrFail();
            $location = Location::query()->whereKey($data['location_id'])->where('is_active', true)->firstOrFail();
            $balance = BulkStockBalance::query()->where('product_id', $product->id)->where('location_id', $location->id)->lockForUpdate()->firstOrFail();
            $quantity = (int) $data['quantity'];
            $source = $data['source'];
            $affectedHireItemIds = [];
            if ($source === 'pending_inspection') {
                if ($balance->pending_inspection < $quantity) {
                    throw ValidationException::withMessages(['quantity' => 'The quantity exceeds bulk stock waiting for inspection.']);
                }
                $returns = BulkHireReturn::query()->where('location_id', $location->id)->where('condition', 'good')
                    ->whereHas('hireItem', fn ($query) => $query->where('product_id', $product->id))
                    ->whereColumn('quantity', '>', 'inspected_quantity')->orderBy('id')->lockForUpdate()->get();
                $uninspected = $returns->sum(fn (BulkHireReturn $return): int => $return->quantity - $return->inspected_quantity);
                if ($uninspected < $quantity) {
                    throw ValidationException::withMessages(['quantity' => 'The inspection quantity does not match returned bulk hire records at this location.']);
                }
                $remainingToInspect = $quantity;
                foreach ($returns as $return) {
                    if ($remainingToInspect === 0) {
                        break;
                    }
                    $inspected = min($remainingToInspect, $return->quantity - $return->inspected_quantity);
                    $return->increment('inspected_quantity', $inspected);
                    $affectedHireItemIds[] = $return->hire_item_id;
                    $remainingToInspect -= $inspected;
                }
                $balance->pending_inspection -= $quantity;
                if ($data['result'] === 'damaged') {
                    $balance->damaged += $quantity;
                }
            } elseif ($source === 'damaged') {
                if ($data['result'] !== 'available') {
                    throw ValidationException::withMessages(['result' => 'Damaged stock can only be released as available after successful repair and inspection.']);
                }
                if ($balance->damaged < $quantity) {
                    throw ValidationException::withMessages(['quantity' => 'The quantity exceeds bulk stock currently marked damaged.']);
                }
                $balance->damaged -= $quantity;
            } else {
                throw ValidationException::withMessages(['source' => 'Choose stock waiting for inspection or damaged stock.']);
            }
            $balance->save();

            if ($affectedHireItemIds !== []) {
                $hireItems = HireItem::query()->whereIn('id', array_unique($affectedHireItemIds))->lockForUpdate()->get();
                $hireIds = [];
                foreach ($hireItems as $hireItem) {
                    $hireIds[] = $hireItem->hire_id;
                    $hasUninspected = $hireItem->bulkReturns()->where('condition', 'good')->whereColumn('quantity', '>', 'inspected_quantity')->exists();
                    if ($hireItem->returned_quantity === $hireItem->quantity && ! $hasUninspected) {
                        $hireItem->update(['status' => 'completed']);
                    }
                }
                foreach (array_unique($hireIds) as $hireId) {
                    $hire = Hire::query()->whereKey($hireId)->lockForUpdate()->firstOrFail();
                    $hasOutstanding = HireItem::query()->where('hire_id', $hire->id)->whereColumn('returned_quantity', '<', 'quantity')->whereNotIn('status', ['written_off'])->exists();
                    $hasInspection = HireItem::query()->where('hire_id', $hire->id)->whereIn('status', ['returned_pending_inspection', 'missing'])->exists();
                    $hire->update([
                        'status' => $hasOutstanding ? 'partially_returned' : ($hasInspection ? 'returned_pending_inspection' : 'completed'),
                        'completed_at' => $hasOutstanding || $hasInspection ? null : now(),
                    ]);
                }
            }

            $movement = StockMovement::query()->create([
                'product_id' => $product->id, 'to_location_id' => $location->id, 'actor_id' => $actor->id,
                'movement_type' => $type, 'from_status' => $source, 'to_status' => $data['result'],
                'quantity' => $quantity, 'idempotency_key' => $key, 'notes' => trim($data['reason']), 'occurred_at' => now(),
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $location->id, 'action' => 'inventory.'.$type,
                'subject_type' => Product::class, 'subject_id' => $product->id,
                'after_state' => ['quantity' => $quantity, 'source' => $source, 'result' => $data['result'], 'movement_id' => $movement->id],
                'reason' => trim($data['reason']), 'occurred_at' => now(),
            ]);

            return $movement;
        }, attempts: 3);
    }
}
