<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BulkStockBalance;
use App\Models\Customer;
use App\Models\Hire;
use App\Models\HireItem;
use App\Models\LateReturnPolicy;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReserveBulkHire
{
    /** @param array{items:list<array{product_id:int,quantity:int,unit_rate_minor?:int}>,customer_id:int,location_id:int,due_at:string,event_date?:string|null,graduation_event_id?:int|null,contract_reference?:string|null,idempotency_key:string,notes?:string|null} $data */
    public function handle(array $data, User $actor): Hire
    {
        abort_unless(in_array($actor->role, ['super_admin', 'management', 'storekeeper', 'sales_crm'], true), 403);
        abort_unless($actor->mayViewAllLocations() || $actor->locations()->whereKey($data['location_id'])->exists(), 403);

        return DB::transaction(function () use ($data, $actor): Hire {
            $requested = collect($data['items'])->sortBy('product_id')->values();
            if ($requested->isEmpty() || $requested->pluck('product_id')->unique()->count() !== $requested->count()) {
                throw ValidationException::withMessages(['bulk_items' => 'Add at least one bulk product, once per product.']);
            }

            $existing = Hire::query()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                $lines = $existing->items()->whereNull('physical_asset_id')->orderBy('product_id')->get(['product_id', 'quantity', 'unit_rate_minor']);
                $sameLines = $lines->map(fn (HireItem $item): array => ['product_id' => $item->product_id, 'quantity' => $item->quantity])->all() === $requested->map(fn (array $item): array => ['product_id' => (int) $item['product_id'], 'quantity' => (int) $item['quantity']])->all();
                $sameQuotedRates = $requested->every(fn (array $item): bool => ! isset($item['unit_rate_minor']) || (int) $lines->firstWhere('product_id', $item['product_id'])?->unit_rate_minor === (int) $item['unit_rate_minor']);
                if ($existing->status !== 'reserved' || $existing->customer_id !== (int) $data['customer_id'] || $existing->location_id !== (int) $data['location_id'] || ! $sameLines || ! $sameQuotedRates
                    || ! $existing->due_at?->equalTo(CarbonImmutable::parse($data['due_at'], config('app.timezone')))
                    || $existing->event_date?->toDateString() !== ($data['event_date'] ?? null) || $existing->graduation_event_id !== (isset($data['graduation_event_id']) ? (int) $data['graduation_event_id'] : null) || $existing->contract_reference !== ($data['contract_reference'] ?? null) || $existing->notes !== ($data['notes'] ?? null)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This submission key was already used for another reservation.']);
                }

                return $existing;
            }

            $location = Location::query()->whereKey($data['location_id'])->where('is_active', true)->firstOrFail();
            $customer = Customer::query()->whereKey($data['customer_id'])->firstOrFail();
            $products = Product::query()->with('classification')->whereIn('id', $requested->pluck('product_id'))->where('tracking_type', 'bulk')->where('is_active', true)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($products->count() !== $requested->count()) {
                throw ValidationException::withMessages(['bulk_items' => 'A selected bulk product is inactive or does not exist.']);
            }
            $penaltyPolicies = LateReturnPolicy::resolveForProducts($products->keys()->map(fn ($id): int => (int) $id)->all(), $customer->institution_id, $data['contract_reference'] ?? null);

            $balances = BulkStockBalance::query()->whereIn('product_id', $requested->pluck('product_id'))->where('location_id', $location->id)->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
            foreach ($requested as $line) {
                $balance = $balances->get((int) $line['product_id']);
                if ($balance === null || $balance->available < (int) $line['quantity']) {
                    throw ValidationException::withMessages(['bulk_items' => 'There is not enough available bulk stock at the selected pickup location.']);
                }
            }

            $hire = Hire::query()->create([
                'reference' => 'HIRE-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'idempotency_key' => $data['idempotency_key'], 'customer_id' => $customer->id,
                'institution_id' => $customer->institution_id, 'graduation_event_id' => $data['graduation_event_id'] ?? null, 'contract_reference' => $data['contract_reference'] ?? null, 'location_id' => $location->id,
                'created_by' => $actor->id, 'status' => 'reserved', 'event_date' => $data['event_date'] ?? null,
                'due_at' => $data['due_at'], 'notes' => $data['notes'] ?? null,
            ]);

            foreach ($requested as $line) {
                $productId = (int) $line['product_id'];
                $quantity = (int) $line['quantity'];
                $product = $products->get($productId);
                $balance = $balances->get($productId);
                if (isset($line['unit_rate_minor']) && (int) $line['unit_rate_minor'] <= 0) {
                    throw ValidationException::withMessages(['items' => 'Quoted hire rates must be greater than zero.']);
                }
                $before = ['on_hand' => $balance->on_hand, 'reserved' => $balance->reserved, 'available' => $balance->available];
                $balance->increment('reserved', $quantity);
                $item = HireItem::query()->create([
                    'hire_id' => $hire->id, 'product_id' => $productId, 'product_name_snapshot' => $product->name,
                    'classification_code_snapshot' => $product->classification?->code, 'qualification_snapshot' => $product->qualification_level,
                    'quantity' => $quantity, 'unit_rate_minor' => $line['unit_rate_minor'] ?? $product->hire_rate_minor,
                    'penalty_policy_snapshot' => $penaltyPolicies[$productId], 'status' => 'reserved',
                ]);
                $key = "bulk-hire-reserve:{$data['idempotency_key']}:{$productId}";
                $movement = StockMovement::query()->create([
                    'product_id' => $productId, 'from_location_id' => $location->id, 'to_location_id' => $location->id,
                    'actor_id' => $actor->id, 'movement_type' => 'bulk_hire_reserved', 'from_status' => 'available',
                    'to_status' => 'reserved', 'quantity' => $quantity, 'idempotency_key' => $key,
                    'notes' => "Reserved on hire {$hire->reference}.", 'occurred_at' => now(),
                ]);
                AuditLog::query()->create([
                    'actor_id' => $actor->id, 'location_id' => $location->id, 'action' => 'hire.bulk_reserved',
                    'subject_type' => Product::class, 'subject_id' => $productId, 'before_state' => $before,
                    'after_state' => ['on_hand' => $balance->fresh()->on_hand, 'reserved' => $balance->fresh()->reserved, 'hire_id' => $hire->id, 'hire_item_id' => $item->id, 'quantity' => $quantity, 'movement_id' => $movement->id],
                    'reason' => "Reserved for customer {$customer->customer_number}.", 'occurred_at' => now(),
                ]);
            }

            return $hire;
        }, attempts: 3);
    }
}
