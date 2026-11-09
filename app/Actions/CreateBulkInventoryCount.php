<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BulkInventoryCount;
use App\Models\BulkStockBalance;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateBulkInventoryCount
{
    /** @param array{location_id:int,counts:list<array{product_id:int,counted_on_hand:int}>,idempotency_key:string,notes?:?string} $data */
    public function handle(array $data, User $actor): BulkInventoryCount
    {
        abort_unless(in_array($actor->role, ['super_admin', 'management', 'storekeeper'], true), 403);
        $locationId = (int) $data['location_id'];
        abort_unless($actor->mayViewAllLocations() || $actor->locations()->whereKey($locationId)->exists(), 403);

        return DB::transaction(function () use ($data, $actor, $locationId): BulkInventoryCount {
            $submitted = collect($data['counts'])->map(fn (array $line): array => [
                'product_id' => (int) $line['product_id'], 'counted_on_hand' => (int) $line['counted_on_hand'],
            ])->sortBy('product_id')->values();
            if ($submitted->pluck('product_id')->unique()->count() !== $submitted->count()) {
                throw ValidationException::withMessages(['counts' => 'Each bulk product can only be counted once.']);
            }

            $existing = BulkInventoryCount::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                $recorded = $existing->lines()->orderBy('product_id')->get(['product_id', 'counted_on_hand'])
                    ->map(fn ($line): array => ['product_id' => $line->product_id, 'counted_on_hand' => $line->counted_on_hand])->values();
                if ($existing->location_id !== $locationId || $existing->started_by !== $actor->id || $recorded->all() !== $submitted->all()) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key is already in use for a different count.']);
                }

                return $existing;
            }

            $location = Location::query()->whereKey($locationId)->where('is_active', true)->firstOrFail();
            $products = Product::query()->where('tracking_type', 'bulk')->where('is_active', true)->orderBy('id')->get(['id']);
            if ($products->pluck('id')->all() !== $submitted->pluck('product_id')->sort()->values()->all()) {
                throw ValidationException::withMessages(['counts' => 'Count every active bulk product exactly once before submitting.']);
            }

            $timestamp = now();
            BulkStockBalance::query()->insertOrIgnore($products->map(fn (Product $product): array => [
                'product_id' => $product->id, 'location_id' => $location->id,
                'created_at' => $timestamp, 'updated_at' => $timestamp,
            ])->all());
            $balances = BulkStockBalance::query()->where('location_id', $location->id)->whereIn('product_id', $products->pluck('id'))->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
            $linesByProduct = $submitted->keyBy('product_id');
            $count = BulkInventoryCount::query()->create([
                'reference' => 'BCN-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'idempotency_key' => $data['idempotency_key'], 'location_id' => $location->id, 'started_by' => $actor->id,
                'status' => 'awaiting_approval', 'expected_count' => $balances->sum('on_hand'),
                'counted_count' => $submitted->sum('counted_on_hand'), 'notes' => $data['notes'] ?? null,
            ]);
            $count->lines()->createMany($balances->map(function (BulkStockBalance $balance) use ($linesByProduct): array {
                $submittedLine = $linesByProduct->get($balance->product_id);

                return [
                    'product_id' => $balance->product_id, 'expected_on_hand' => $balance->on_hand,
                    'expected_reserved' => $balance->reserved, 'expected_pending_inspection' => $balance->pending_inspection,
                    'expected_damaged' => $balance->damaged, 'counted_on_hand' => $submittedLine['counted_on_hand'],
                ];
            })->values()->all());
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $location->id, 'action' => 'inventory.bulk_cycle_count_submitted',
                'subject_type' => BulkInventoryCount::class, 'subject_id' => $count->id,
                'after_state' => ['reference' => $count->reference, 'expected_count' => $count->expected_count, 'counted_count' => $count->counted_count],
                'reason' => $data['notes'] ?? null, 'occurred_at' => now(),
            ]);

            return $count;
        }, attempts: 3);
    }
}
