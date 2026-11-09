<?php

namespace App\Actions;

use App\Models\AssetCustodyEvent;
use App\Models\AuditLog;
use App\Models\Hire;
use App\Models\HireItem;
use App\Models\PhysicalAsset;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssueHireAsset
{
    public function __construct(private readonly IssueHireInvoice $invoice) {}

    public function handle(Hire $hire, HireItem $item, User $actor, string $idempotencyKey): HireItem
    {
        if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($hire->location_id)->exists()) {
            abort(403);
        }

        return DB::transaction(function () use ($hire, $item, $actor, $idempotencyKey): HireItem {
            $hire = Hire::query()->lockForUpdate()->findOrFail($hire->id);
            $item = HireItem::query()->lockForUpdate()->findOrFail($item->id);

            if (AssetCustodyEvent::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                return $item->refresh();
            }

            if ($item->hire_id !== $hire->id || $item->physical_asset_id === null || $item->status !== 'reserved') {
                throw ValidationException::withMessages(['item' => 'This hire line is not an unissued serialized reservation.']);
            }

            $asset = PhysicalAsset::query()->lockForUpdate()->findOrFail($item->physical_asset_id);
            if ($asset->current_location_id !== $hire->location_id || $asset->status !== 'available' || $asset->current_transfer_id !== null) {
                throw ValidationException::withMessages(['asset' => "Asset {$asset->asset_code} is not available at the selected pickup location."]);
            }

            $now = now();
            $before = [
                'status' => $asset->status,
                'custody_type' => $asset->custody_type,
                'current_location_id' => $asset->current_location_id,
            ];
            $asset->update([
                'current_location_id' => null,
                'custody_type' => 'customer',
                'custodian_user_id' => null,
                'custodian_customer_id' => $hire->customer_id,
                'status' => 'hired',
            ]);
            $item->update(['status' => 'issued']);
            $hire->update(['status' => 'issued', 'issued_at' => $hire->issued_at ?? $now]);

            AssetCustodyEvent::query()->create([
                'physical_asset_id' => $asset->id,
                'actor_id' => $actor->id,
                'from_location_id' => $hire->location_id,
                'customer_id' => $hire->customer_id,
                'event_type' => 'hire_issued',
                'from_status' => 'available',
                'to_status' => 'hired',
                'from_custody_type' => $hire->location->type === 'warehouse' ? 'warehouse' : 'branch',
                'to_custody_type' => 'customer',
                'idempotency_key' => $idempotencyKey,
                'notes' => "Issued on hire {$hire->reference}.",
                'occurred_at' => $now,
            ]);

            StockMovement::query()->create([
                'product_id' => $asset->product_id,
                'physical_asset_id' => $asset->id,
                'from_location_id' => $hire->location_id,
                'actor_id' => $actor->id,
                'movement_type' => 'hire_issued',
                'from_status' => 'available',
                'to_status' => 'hired',
                'quantity' => 1,
                'idempotency_key' => $idempotencyKey,
                'occurred_at' => $now,
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'location_id' => $hire->location_id,
                'action' => 'hire.asset_issued',
                'subject_type' => PhysicalAsset::class,
                'subject_id' => $asset->id,
                'before_state' => $before,
                'after_state' => ['status' => $asset->status, 'custody_type' => $asset->custody_type, 'current_location_id' => $asset->current_location_id, 'customer_id' => $hire->customer_id],
                'reason' => "Issued on hire {$hire->reference}.",
                'occurred_at' => $now,
            ]);

            $this->invoice->handle($hire);

            return $item->refresh();
        }, attempts: 3);
    }
}
