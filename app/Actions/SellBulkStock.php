<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BulkSale;
use App\Models\BulkStockBalance;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SellBulkStock
{
    /** @param array{location_id:int,customer_id:int,items:list<array{product_id:int,quantity:int,unit_price_minor?:int}>,method:string,provider_reference?:?string,notes?:?string,idempotency_key:string} $data */
    public function handle(array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): BulkSale
    {
        abort_unless(in_array($actor->role, ['super_admin', 'management', 'storekeeper', 'sales_crm'], true), 403);
        $locationId = (int) $data['location_id'];
        abort_unless($actor->mayViewAllLocations() || $actor->locations()->whereKey($locationId)->exists(), 403);

        return DB::transaction(function () use ($data, $actor, $locationId, $ipAddress, $userAgent): BulkSale {
            $location = Location::query()->whereKey($locationId)->where('is_active', true)->firstOrFail();
            $customer = Customer::query()->findOrFail($data['customer_id']);
            if (! $customer->locations()->whereKey($location->id)->exists()) {
                throw ValidationException::withMessages(['customer_id' => 'Assign this customer to the selected branch before recording a sale.']);
            }

            $items = collect($data['items'])->map(function (array $item): array {
                return array_filter([
                    'product_id' => (int) $item['product_id'], 'quantity' => (int) $item['quantity'],
                    'unit_price_minor' => isset($item['unit_price_minor']) ? (int) $item['unit_price_minor'] : null,
                ], fn ($value, string $key): bool => $key !== 'unit_price_minor' || $value !== null, ARRAY_FILTER_USE_BOTH);
            })->sortBy('product_id')->values();
            if ($items->pluck('product_id')->unique()->count() !== $items->count()) {
                throw ValidationException::withMessages(['items' => 'A product can only appear once in a sale.']);
            }
            $requestFingerprint = hash('sha256', json_encode([
                'actor_id' => $actor->id, 'location_id' => $location->id, 'customer_id' => $customer->id,
                'items' => $items->all(), 'method' => $data['method'], 'provider_reference' => $data['provider_reference'] ?? null,
                'notes' => trim((string) ($data['notes'] ?? '')),
            ], JSON_THROW_ON_ERROR));
            $existing = BulkSale::query()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->request_hash !== $requestFingerprint) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This sale key was already used for different sale details.']);
                }

                return $existing;
            }

            $products = Product::query()->whereIn('id', $items->pluck('product_id'))->where('tracking_type', 'bulk')->where('is_active', true)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($products->count() !== $items->count()) {
                throw ValidationException::withMessages(['items' => 'Select active bulk-tracked products only.']);
            }
            $balances = BulkStockBalance::query()->where('location_id', $location->id)->whereIn('product_id', $products->keys())
                ->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
            $totalMinor = 0;
            $cogsByProduct = [];
            foreach ($items as $item) {
                $product = $products->get($item['product_id']);
                $balance = $balances->get($product->id);
                $unitPriceMinor = $item['unit_price_minor'] ?? $product->sale_price_minor;
                if ($unitPriceMinor <= 0) {
                    throw ValidationException::withMessages(['items' => "Set a sale price for {$product->name} before selling it."]);
                }
                if ($balance === null || $balance->available < $item['quantity']) {
                    throw ValidationException::withMessages(['items' => "{$product->name} does not have enough available stock at this branch."]);
                }
                if (! $balance->valuation_complete) {
                    throw ValidationException::withMessages(['items' => "Set the opening valuation for {$product->name} before selling it."]);
                }
                if ($unitPriceMinor > intdiv(PHP_INT_MAX - $totalMinor, $item['quantity'])) {
                    throw ValidationException::withMessages(['items' => 'The sale total exceeds the supported amount.']);
                }
                $totalMinor += $unitPriceMinor * $item['quantity'];
                if ($balance->inventory_value_minor > 0) {
                    $cogsByProduct[$product->id] = BulkInventoryAccounting::costForQuantity($balance->inventory_value_minor, $balance->on_hand, $item['quantity']);
                } else {
                    throw ValidationException::withMessages(['items' => "{$product->name} has stock without an inventory cost. Record its opening valuation before selling it."]);
                }
            }
            if ($data['method'] === 'mpesa' && Payment::query()->where('provider_reference', $data['provider_reference'] ?? null)->exists()) {
                throw ValidationException::withMessages(['provider_reference' => 'That M-Pesa receipt reference has already been recorded.']);
            }

            $soldAt = now();
            $sale = BulkSale::query()->create([
                'reference' => 'BS-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'idempotency_key' => $data['idempotency_key'], 'request_hash' => $requestFingerprint,
                'location_id' => $location->id, 'customer_id' => $customer->id, 'sold_by' => $actor->id,
                'total_minor' => $totalMinor, 'status' => 'completed', 'sold_at' => $soldAt,
                'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
            ]);
            $sale->lines()->createMany($items->map(function (array $item) use ($products, $cogsByProduct): array {
                $product = $products->get($item['product_id']);
                $unitPriceMinor = $item['unit_price_minor'] ?? $product->sale_price_minor;

                return [
                    'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $product->sku,
                    'quantity' => $item['quantity'], 'unit_price_minor' => $unitPriceMinor,
                    'line_total_minor' => $unitPriceMinor * $item['quantity'],
                    'cogs_minor' => $cogsByProduct[$product->id],
                ];
            })->all());

            foreach ($items as $item) {
                $product = $products->get($item['product_id']);
                $balance = $balances->get($product->id);
                $costMinor = $cogsByProduct[$product->id];
                $balance->update([
                    'on_hand' => $balance->on_hand - $item['quantity'],
                    'inventory_value_minor' => $balance->inventory_value_minor - $costMinor,
                ]);
                $movement = StockMovement::query()->create([
                    'product_id' => $product->id, 'from_location_id' => $location->id, 'actor_id' => $actor->id,
                    'movement_type' => 'bulk_sale', 'quantity' => $item['quantity'],
                    'cost_minor' => $costMinor,
                    'idempotency_key' => 'bulk-sale:'.$data['idempotency_key'].':'.$product->id,
                    'notes' => 'Sale '.$sale->reference, 'occurred_at' => $soldAt,
                ]);
                app(BulkInventoryAccounting::class)->postMovement($movement, $costMinor, $actor);
            }

            $payment = Payment::query()->create([
                'reference' => 'REC-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'idempotency_key' => 'bulk-sale:'.$data['idempotency_key'], 'customer_id' => $customer->id,
                'bulk_sale_id' => $sale->id, 'location_id' => $location->id, 'recorded_by' => $actor->id,
                'amount_minor' => $totalMinor, 'currency' => 'KES', 'method' => $data['method'], 'purpose' => 'product_sale',
                'status' => 'received', 'provider_reference' => $data['provider_reference'] ?? null, 'received_at' => $soldAt,
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $location->id, 'action' => 'inventory.bulk_sale_completed',
                'subject_type' => BulkSale::class, 'subject_id' => $sale->id,
                'after_state' => ['reference' => $sale->reference, 'customer_id' => $customer->id, 'total_minor' => $totalMinor, 'payment_id' => $payment->id],
                'reason' => $sale->notes ?? 'Bulk stock sale completed.', 'ip_address' => $ipAddress, 'user_agent' => $userAgent,
                'occurred_at' => $soldAt,
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $location->id, 'action' => 'payment.received',
                'subject_type' => Payment::class, 'subject_id' => $payment->id,
                'after_state' => ['reference' => $payment->reference, 'bulk_sale_id' => $sale->id, 'amount_minor' => $totalMinor, 'currency' => 'KES', 'purpose' => 'product_sale', 'method' => $payment->method],
                'ip_address' => $ipAddress, 'user_agent' => $userAgent, 'occurred_at' => $soldAt,
            ]);

            return $sale->refresh();
        }, attempts: 3);
    }
}
