<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BulkStockBalance;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordBulkStockMovement
{
    /** @param array{product_id:int,quantity?:int,movement:string,direction?:string,location_id?:int,source_location_id?:int,destination_location_id?:int,unit_cost?:string,valuation_total?:string,reason:string,idempotency_key:string} $data */
    public function handle(array $data, User $actor): StockMovement
    {
        abort_unless(in_array($actor->role, ['super_admin', 'management', 'storekeeper'], true), 403);

        return DB::transaction(function () use ($data, $actor): StockMovement {
            $key = 'bulk:'.$data['idempotency_key'];
            $existing = StockMovement::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $requestedType = match ($data['movement']) {
                    'receive' => 'bulk_received',
                    'adjust' => $data['direction'] === 'increase' ? 'bulk_adjustment_in' : 'bulk_adjustment_out',
                    'revalue' => 'bulk_revaluation',
                    default => 'bulk_transfer',
                };
                $requestedFrom = $data['movement'] === 'transfer'
                    ? (int) $data['source_location_id']
                    : ($data['movement'] === 'adjust' && $data['direction'] === 'decrease' ? (int) $data['location_id'] : null);
                $requestedTo = $data['movement'] === 'transfer'
                    ? (int) $data['destination_location_id']
                    : (in_array($data['movement'], ['receive', 'revalue'], true) || ($data['movement'] === 'adjust' && $data['direction'] === 'increase') ? (int) $data['location_id'] : null);
                $requestPricesStock = $data['movement'] === 'receive' || ($data['movement'] === 'adjust' && $data['direction'] === 'increase');
                $quantityMismatch = $data['movement'] !== 'revalue' && $existing->quantity !== (int) $data['quantity'];
                if ($existing->product_id !== (int) $data['product_id'] || $quantityMismatch
                    || $existing->movement_type !== $requestedType || $existing->actor_id !== $actor->id
                    || $existing->from_location_id !== $requestedFrom || $existing->to_location_id !== $requestedTo
                    || $existing->notes !== trim($data['reason'])
                    || ($requestPricesStock && $existing->cost_minor !== self::toMinor((string) $data['unit_cost']) * (int) $data['quantity'])
                    || ($data['movement'] === 'revalue' && $existing->valuation_total_minor !== self::toMinor((string) $data['valuation_total']))) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used for another movement.']);
                }
                if ($requestedFrom !== null) {
                    $this->authorizedLocation($requestedFrom, $actor);
                }
                if ($requestedTo !== null) {
                    $this->authorizedLocation($requestedTo, $actor);
                }

                return $existing;
            }

            $product = Product::query()->whereKey($data['product_id'])->where('tracking_type', 'bulk')->lockForUpdate()->firstOrFail();
            $movement = $data['movement'];
            $quantity = (int) ($data['quantity'] ?? 0);
            if ($movement === 'adjust') {
                abort_unless(in_array($actor->role, ['super_admin', 'management'], true), 403);
            }
            $from = null;
            $to = null;
            $type = '';
            $costMinor = 0;

            if ($movement === 'receive') {
                $to = $this->authorizedLocation((int) $data['location_id'], $actor);
                $balance = $this->lockBalance($product->id, $to->id);
                $costMinor = self::toMinor((string) $data['unit_cost']) * $quantity;
                $balance->update([
                    'on_hand' => $balance->on_hand + $quantity,
                    'inventory_value_minor' => $balance->inventory_value_minor + $costMinor,
                    'valuation_complete' => $balance->on_hand === 0 || $balance->valuation_complete,
                ]);
                $type = 'bulk_received';
            } elseif ($movement === 'adjust') {
                $location = $this->authorizedLocation((int) $data['location_id'], $actor);
                $balance = $this->lockBalance($product->id, $location->id);
                $direction = $data['direction'];
                if ($direction === 'decrease' && $balance->available < $quantity) {
                    throw ValidationException::withMessages(['quantity' => 'The adjustment cannot reduce stock below reserved, returned-pending-inspection or damaged quantity.']);
                }
                if ($direction === 'decrease' && ! $balance->valuation_complete) {
                    throw ValidationException::withMessages(['quantity' => 'Set the opening valuation before reducing unvalued stock.']);
                }
                $costMinor = $direction === 'increase'
                    ? self::toMinor((string) $data['unit_cost']) * $quantity
                    : BulkInventoryAccounting::costForQuantity($balance->inventory_value_minor, $balance->on_hand, $quantity);
                $balance->update([
                    'on_hand' => $balance->on_hand + ($direction === 'increase' ? $quantity : -$quantity),
                    'inventory_value_minor' => $balance->inventory_value_minor + ($direction === 'increase' ? $costMinor : -$costMinor),
                    'valuation_complete' => $balance->on_hand === 0 || $balance->valuation_complete,
                ]);
                $from = $direction === 'decrease' ? $location : null;
                $to = $direction === 'increase' ? $location : null;
                $type = $direction === 'increase' ? 'bulk_adjustment_in' : 'bulk_adjustment_out';
            } elseif ($movement === 'revalue') {
                abort_unless(in_array($actor->role, ['super_admin', 'management'], true), 403);
                $to = $this->authorizedLocation((int) $data['location_id'], $actor);
                $balance = $this->lockBalance($product->id, $to->id);
                if ($balance->on_hand < 1) {
                    throw ValidationException::withMessages(['valuation_total' => 'There is no stock at this location to value.']);
                }
                if ($balance->valuation_complete) {
                    throw ValidationException::withMessages(['valuation_total' => 'This stock already has a confirmed valuation. Use a count adjustment to record a later value change.']);
                }
                $valuationTotalMinor = self::toMinor((string) $data['valuation_total']);
                $valuationDirection = $valuationTotalMinor >= $balance->inventory_value_minor ? 'increase' : 'decrease';
                $costMinor = abs($valuationTotalMinor - $balance->inventory_value_minor);
                $quantity = $balance->on_hand;
                $balance->update(['inventory_value_minor' => $valuationTotalMinor, 'valuation_complete' => true]);
                $type = 'bulk_revaluation';
            } else {
                $from = $this->authorizedLocation((int) $data['source_location_id'], $actor);
                $to = $this->authorizedLocation((int) $data['destination_location_id'], $actor);
                if ($from->id === $to->id) {
                    throw ValidationException::withMessages(['destination_location_id' => 'Choose a different destination location.']);
                }
                $orderedLocationIds = [$from->id, $to->id];
                sort($orderedLocationIds, SORT_NUMERIC);
                $balances = collect($orderedLocationIds)->mapWithKeys(fn (int $id): array => [$id => $this->lockBalance($product->id, $id)]);
                $source = $balances->get($from->id);
                $destination = $balances->get($to->id);
                if ($source->available < $quantity) {
                    throw ValidationException::withMessages(['quantity' => 'The source location does not have enough available stock.']);
                }
                if (! $source->valuation_complete) {
                    throw ValidationException::withMessages(['source_location_id' => 'Set the source stock valuation before transferring it.']);
                }
                $costMinor = BulkInventoryAccounting::costForQuantity($source->inventory_value_minor, $source->on_hand, $quantity);
                $source->update(['on_hand' => $source->on_hand - $quantity, 'inventory_value_minor' => $source->inventory_value_minor - $costMinor]);
                $destination->update([
                    'on_hand' => $destination->on_hand + $quantity,
                    'inventory_value_minor' => $destination->inventory_value_minor + $costMinor,
                    'valuation_complete' => $destination->on_hand === 0 || $destination->valuation_complete,
                ]);
                $type = 'bulk_transfer';
            }

            $entry = StockMovement::query()->create([
                'product_id' => $product->id, 'from_location_id' => $from?->id, 'to_location_id' => $to?->id,
                'actor_id' => $actor->id, 'movement_type' => $type, 'quantity' => $quantity,
                'cost_minor' => $costMinor,
                'valuation_total_minor' => $movement === 'revalue' ? self::toMinor((string) $data['valuation_total']) : null,
                'from_status' => $movement === 'revalue' ? $valuationDirection : null,
                'idempotency_key' => $key, 'notes' => trim($data['reason']), 'occurred_at' => now(),
            ]);
            app(BulkInventoryAccounting::class)->postMovement($entry, $costMinor, $actor);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $to?->id ?? $from?->id,
                'action' => 'inventory.'.$type, 'subject_type' => Product::class, 'subject_id' => $product->id,
                'after_state' => ['quantity' => $quantity, 'from_location_id' => $from?->id, 'to_location_id' => $to?->id, 'movement_id' => $entry->id],
                'reason' => trim($data['reason']), 'occurred_at' => now(),
            ]);

            return $entry;
        }, attempts: 3);
    }

    private function authorizedLocation(int $id, User $actor): Location
    {
        $location = Location::query()->whereKey($id)->where('is_active', true)->firstOrFail();
        abort_unless($actor->mayViewAllLocations() || $actor->locations()->whereKey($id)->exists(), 403);

        return $location;
    }

    private function lockBalance(int $productId, int $locationId): BulkStockBalance
    {
        BulkStockBalance::query()->firstOrCreate(['product_id' => $productId, 'location_id' => $locationId]);

        return BulkStockBalance::query()->where('product_id', $productId)->where('location_id', $locationId)->lockForUpdate()->firstOrFail();
    }

    private static function toMinor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
