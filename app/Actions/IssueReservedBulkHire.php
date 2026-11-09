<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BulkStockBalance;
use App\Models\Customer;
use App\Models\Hire;
use App\Models\HireItem;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssueReservedBulkHire
{
    public function __construct(private readonly QueueCustomerSms $queueSms, private readonly IssueHireInvoice $invoice) {}

    public function handle(Hire $hire, string $idempotencyKey, User $actor): Hire
    {
        abort_unless($actor->mayViewAllLocations() || $actor->locations()->whereKey($hire->location_id)->exists(), 403);

        return DB::transaction(function () use ($hire, $idempotencyKey, $actor): Hire {
            $hire = Hire::query()->lockForUpdate()->findOrFail($hire->id);
            $items = HireItem::query()->where('hire_id', $hire->id)->whereNull('physical_asset_id')->orderBy('product_id')->lockForUpdate()->get();
            if ($hire->status === 'issued' && $items->isNotEmpty() && $items->every(fn (HireItem $item): bool => $item->status === 'issued')) {
                $keys = $items->map(fn (HireItem $item): string => "bulk-reservation-issue:{$idempotencyKey}:{$hire->id}:{$item->product_id}");
                if (StockMovement::query()->whereIn('idempotency_key', $keys)->count() === $items->count()) {
                    return $hire;
                }
                throw ValidationException::withMessages(['idempotency_key' => 'This reservation was already issued with a different handover key.']);
            }
            if ($hire->status !== 'reserved' || $items->isEmpty() || $items->contains(fn (HireItem $item): bool => $item->status !== 'reserved')) {
                throw ValidationException::withMessages(['hire' => 'This hire does not have an active bulk reservation.']);
            }

            $balances = BulkStockBalance::query()->whereIn('product_id', $items->pluck('product_id'))->where('location_id', $hire->location_id)->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
            if ($balances->count() !== $items->count()) {
                throw ValidationException::withMessages(['hire' => 'A reserved bulk stock balance is missing. Contact management.']);
            }

            foreach ($items as $item) {
                $balance = $balances->get($item->product_id);
                if ($balance->reserved < $item->quantity || $balance->on_hand < $item->quantity) {
                    throw ValidationException::withMessages(['hire' => 'Reserved bulk stock no longer reconciles to the available balance. Contact management.']);
                }
            }

            foreach ($items as $item) {
                $balance = $balances->get($item->product_id);
                $before = ['on_hand' => $balance->on_hand, 'reserved' => $balance->reserved];
                $balance->decrement('reserved', $item->quantity);
                $balance->decrement('on_hand', $item->quantity);
                $item->update(['status' => 'issued']);
                $key = "bulk-reservation-issue:{$idempotencyKey}:{$hire->id}:{$item->product_id}";
                $movement = StockMovement::query()->create([
                    'product_id' => $item->product_id, 'from_location_id' => $hire->location_id, 'actor_id' => $actor->id,
                    'movement_type' => 'bulk_hire_issued', 'from_status' => 'reserved', 'to_status' => 'hired',
                    'quantity' => $item->quantity, 'idempotency_key' => $key, 'notes' => "Issued on reserved hire {$hire->reference}.", 'occurred_at' => now(),
                ]);
                AuditLog::query()->create([
                    'actor_id' => $actor->id, 'location_id' => $hire->location_id, 'action' => 'hire.bulk_reservation_issued',
                    'subject_type' => Product::class, 'subject_id' => $item->product_id, 'before_state' => $before,
                    'after_state' => ['on_hand' => $balance->fresh()->on_hand, 'reserved' => $balance->fresh()->reserved, 'hire_id' => $hire->id, 'hire_item_id' => $item->id, 'quantity' => $item->quantity, 'movement_id' => $movement->id],
                    'reason' => "Issued reserved bulk stock on hire {$hire->reference}.", 'occurred_at' => now(),
                ]);
            }

            $hire->update(['status' => 'issued', 'issued_at' => now()]);
            $this->invoice->handle($hire);
            $this->queueSms->handle(
                'hire_issued',
                ['reference' => $hire->reference, 'due_date' => $hire->due_at?->timezone(config('app.timezone'))->format('d M Y') ?? '—'],
                'hire-issued:'.$hire->id,
                Customer::query()->findOrFail($hire->customer_id),
                $hire,
                location: Location::query()->findOrFail($hire->location_id),
                actor: $actor,
            );

            return $hire->refresh();
        }, attempts: 3);
    }
}
