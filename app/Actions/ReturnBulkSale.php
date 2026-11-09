<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BulkSale;
use App\Models\BulkSaleReturn;
use App\Models\BulkStockBalance;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReturnBulkSale
{
    /** @param array{bulk_sale_id:int,items:list<array{product_id:int,quantity:int,condition:string}>,method:string,provider_reference?:?string,reason:string,idempotency_key:string} $data */
    public function handle(array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): BulkSaleReturn
    {
        abort_unless(in_array($actor->role, ['super_admin', 'management', 'finance'], true), 403);

        return DB::transaction(function () use ($data, $actor, $ipAddress, $userAgent): BulkSaleReturn {
            $sale = BulkSale::query()->lockForUpdate()->findOrFail($data['bulk_sale_id']);
            abort_unless($actor->mayViewAllLocations() || $actor->locations()->whereKey($sale->location_id)->exists(), 403);
            $payment = Payment::query()->where('bulk_sale_id', $sale->id)->lockForUpdate()->first();
            if ($payment === null || $payment->status !== 'received') {
                throw ValidationException::withMessages(['bulk_sale_id' => 'This sale does not have a refundable receipt.']);
            }
            $items = collect($data['items'])->map(fn (array $item): array => [
                'product_id' => (int) $item['product_id'], 'quantity' => (int) $item['quantity'], 'condition' => $item['condition'],
            ])->sortBy('product_id')->values();
            if ($items->pluck('product_id')->unique()->count() !== $items->count()) {
                throw ValidationException::withMessages(['items' => 'A returned product can only appear once.']);
            }
            $fingerprint = hash('sha256', json_encode([
                'actor_id' => $actor->id, 'sale_id' => $sale->id, 'items' => $items->all(), 'method' => $data['method'],
                'provider_reference' => $data['provider_reference'] ?? null, 'reason' => trim($data['reason']),
            ], JSON_THROW_ON_ERROR));
            $existing = BulkSaleReturn::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                if ($existing->request_hash !== $fingerprint) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This return key was already used for different return details.']);
                }

                return $existing;
            }

            $saleLines = $sale->lines()->whereIn('product_id', $items->pluck('product_id'))->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
            if ($saleLines->count() !== $items->count()) {
                throw ValidationException::withMessages(['items' => 'Only products on this sale can be returned.']);
            }
            $previouslyReturned = DB::table('bulk_sale_return_lines as rl')->join('bulk_sale_returns as r', 'r.id', '=', 'rl.bulk_sale_return_id')
                ->where('r.bulk_sale_id', $sale->id)->whereIn('rl.product_id', $items->pluck('product_id'))
                ->groupBy('rl.product_id')->selectRaw('rl.product_id, SUM(rl.quantity) as returned_quantity')->pluck('returned_quantity', 'product_id');
            $totalMinor = 0;
            $cogsByProduct = [];
            foreach ($items as $item) {
                $line = $saleLines->get($item['product_id']);
                $returnedBefore = (int) $previouslyReturned->get($line->product_id, 0);
                if ($item['quantity'] > $line->quantity - $returnedBefore) {
                    throw ValidationException::withMessages(['items' => "Return quantity exceeds the unsold balance for {$line->product_name_snapshot}."]);
                }
                $totalMinor += $item['quantity'] * $line->unit_price_minor;
                $cogsByProduct[$line->product_id] = $item['condition'] === 'good'
                    ? BulkInventoryAccounting::costForSlice((int) $line->cogs_minor, $line->quantity, $returnedBefore, $item['quantity'])
                    : 0;
            }
            $alreadyRefunded = (int) PaymentRefund::query()->where('payment_id', $payment->id)->sum('amount_minor');
            if ($totalMinor > $payment->amount_minor - $alreadyRefunded) {
                throw ValidationException::withMessages(['items' => 'The return value exceeds the remaining refundable sale receipt.']);
            }
            if ($data['method'] === 'mpesa' && PaymentRefund::query()->where('provider_reference', $data['provider_reference'] ?? null)->exists()) {
                throw ValidationException::withMessages(['provider_reference' => 'That provider reference has already been recorded.']);
            }

            $returnedAt = now();
            $refund = PaymentRefund::query()->create([
                'reference' => 'RFD-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'idempotency_key' => $data['idempotency_key'], 'payment_id' => $payment->id, 'location_id' => $sale->location_id,
                'recorded_by' => $actor->id, 'amount_minor' => $totalMinor, 'method' => $data['method'],
                'provider_reference' => $data['provider_reference'] ?? null, 'reason' => trim($data['reason']), 'refunded_at' => $returnedAt,
            ]);
            $saleReturn = BulkSaleReturn::query()->create([
                'reference' => 'BSR-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'idempotency_key' => $data['idempotency_key'], 'request_hash' => $fingerprint, 'bulk_sale_id' => $sale->id,
                'payment_refund_id' => $refund->id, 'recorded_by' => $actor->id, 'total_minor' => $totalMinor,
                'reason' => trim($data['reason']), 'returned_at' => $returnedAt,
            ]);
            $saleReturn->lines()->createMany($items->map(function (array $item) use ($saleLines, $cogsByProduct): array {
                $line = $saleLines->get($item['product_id']);

                return [
                    'product_id' => $line->product_id, 'quantity' => $item['quantity'], 'unit_price_minor' => $line->unit_price_minor,
                    'line_total_minor' => $item['quantity'] * $line->unit_price_minor, 'condition' => $item['condition'],
                    'cogs_minor' => $cogsByProduct[$line->product_id],
                ];
            })->all());

            foreach ($items as $item) {
                $balance = BulkStockBalance::query()->firstOrCreate(['product_id' => $item['product_id'], 'location_id' => $sale->location_id]);
                $balance = BulkStockBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();
                $heldColumn = $item['condition'] === 'damaged' ? 'damaged' : 'pending_inspection';
                $costMinor = $cogsByProduct[$item['product_id']];
                $balance->update([
                    'on_hand' => $balance->on_hand + $item['quantity'], $heldColumn => $balance->{$heldColumn} + $item['quantity'],
                    'inventory_value_minor' => $balance->inventory_value_minor + $costMinor,
                    'valuation_complete' => $balance->valuation_complete || $costMinor > 0,
                ]);
                $movement = StockMovement::query()->create([
                    'product_id' => $item['product_id'], 'to_location_id' => $sale->location_id, 'actor_id' => $actor->id,
                    'movement_type' => $item['condition'] === 'damaged' ? 'bulk_sale_return_damaged' : 'bulk_sale_return_pending',
                    'quantity' => $item['quantity'], 'cost_minor' => $costMinor,
                    'idempotency_key' => 'bulk-sale-return:'.$data['idempotency_key'].':'.$item['product_id'],
                    'notes' => 'Return '.$saleReturn->reference, 'occurred_at' => $returnedAt,
                ]);
                if ($item['condition'] === 'good') {
                    app(BulkInventoryAccounting::class)->postMovement($movement, $costMinor, $actor);
                }
            }
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $sale->location_id, 'action' => 'inventory.bulk_sale_returned',
                'subject_type' => BulkSaleReturn::class, 'subject_id' => $saleReturn->id,
                'after_state' => ['reference' => $saleReturn->reference, 'sale_reference' => $sale->reference, 'refund_reference' => $refund->reference, 'amount_minor' => $totalMinor],
                'reason' => trim($data['reason']), 'ip_address' => $ipAddress, 'user_agent' => $userAgent, 'occurred_at' => $returnedAt,
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $sale->location_id, 'action' => 'payment.refunded',
                'subject_type' => PaymentRefund::class, 'subject_id' => $refund->id,
                'after_state' => ['reference' => $refund->reference, 'payment_reference' => $payment->reference, 'amount_minor' => $totalMinor, 'purpose' => $payment->purpose],
                'reason' => trim($data['reason']), 'ip_address' => $ipAddress, 'user_agent' => $userAgent, 'occurred_at' => $returnedAt,
            ]);

            return $saleReturn->refresh();
        }, attempts: 3);
    }
}
