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

class IssueReservedHire
{
    public function __construct(private readonly IssueHireInvoice $invoice) {}

    /** @param list<string> $assetCodes */
    public function handle(Hire $hire, array $assetCodes, string $idempotencyKey, User $actor): Hire
    {
        if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($hire->location_id)->exists()) {
            abort(403);
        }

        return DB::transaction(function () use ($hire, $assetCodes, $idempotencyKey, $actor): Hire {
            $hire = Hire::query()->lockForUpdate()->findOrFail($hire->id);
            $items = HireItem::query()->where('hire_id', $hire->id)->with('asset:id,asset_code')->orderBy('id')->lockForUpdate()->get();
            $expectedCodes = $items->pluck('asset.asset_code')->sort()->values();
            $submittedCodes = collect($assetCodes)->unique()->sort()->values();
            if ($submittedCodes->count() !== count($assetCodes) || $expectedCodes->all() !== $submittedCodes->all()) {
                throw ValidationException::withMessages(['asset_codes' => 'Scan every reserved gown exactly once before confirming issue.']);
            }

            if (in_array($hire->status, ['issued', 'partially_returned', 'returned_pending_inspection', 'completed'], true)) {
                $eventCount = AssetCustodyEvent::query()->whereIn('idempotency_key', $items->map(fn (HireItem $item): string => "reservation-issue:{$idempotencyKey}:{$item->physical_asset_id}"))->count();
                if ($eventCount === $items->count()) {
                    return $hire;
                }
            }

            if ($hire->status !== 'reserved' || $items->isEmpty() || $items->contains(fn (HireItem $item): bool => $item->status !== 'reserved')) {
                throw ValidationException::withMessages(['hire' => 'This hire is not a complete active reservation.']);
            }

            $assets = PhysicalAsset::query()->with('product.classification')->whereIn('id', $items->pluck('physical_asset_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($assets->count() !== $items->count()) {
                throw ValidationException::withMessages(['hire' => 'A reserved asset record is missing. Contact management.']);
            }
            foreach ($assets as $asset) {
                if ($asset->status !== 'reserved' || $asset->current_location_id !== $hire->location_id || $asset->current_transfer_id !== null) {
                    throw ValidationException::withMessages(['asset_codes' => "Asset {$asset->asset_code} is not at the reservation location."]);
                }
            }

            $now = now();
            foreach ($items as $item) {
                $asset = $assets->get($item->physical_asset_id);
                $before = ['status' => $asset->status, 'custody_type' => $asset->custody_type, 'current_location_id' => $asset->current_location_id];
                $asset->update([
                    'current_location_id' => null,
                    'custody_type' => 'customer',
                    'custodian_user_id' => null,
                    'custodian_customer_id' => $hire->customer_id,
                    'status' => 'hired',
                ]);
                $item->update(['status' => 'issued']);
                $key = "reservation-issue:{$idempotencyKey}:{$asset->id}";
                AssetCustodyEvent::query()->create([
                    'physical_asset_id' => $asset->id,
                    'actor_id' => $actor->id,
                    'from_location_id' => $hire->location_id,
                    'customer_id' => $hire->customer_id,
                    'event_type' => 'hire_issued',
                    'from_status' => 'reserved',
                    'to_status' => 'hired',
                    'from_custody_type' => $hire->location->type,
                    'to_custody_type' => 'customer',
                    'idempotency_key' => $key,
                    'notes' => "Reserved gowns issued on hire {$hire->reference}.",
                    'occurred_at' => $now,
                ]);
                StockMovement::query()->create([
                    'product_id' => $asset->product_id,
                    'physical_asset_id' => $asset->id,
                    'from_location_id' => $hire->location_id,
                    'actor_id' => $actor->id,
                    'movement_type' => 'hire_issued',
                    'from_status' => 'reserved',
                    'to_status' => 'hired',
                    'quantity' => 1,
                    'idempotency_key' => $key,
                    'occurred_at' => $now,
                ]);
                AuditLog::query()->create([
                    'actor_id' => $actor->id,
                    'location_id' => $hire->location_id,
                    'action' => 'hire.asset_issued',
                    'subject_type' => PhysicalAsset::class,
                    'subject_id' => $asset->id,
                    'before_state' => $before,
                    'after_state' => ['status' => 'hired', 'custody_type' => 'customer', 'current_location_id' => null, 'customer_id' => $hire->customer_id, 'hire_id' => $hire->id],
                    'reason' => "Issued reserved gown on hire {$hire->reference}.",
                    'occurred_at' => $now,
                ]);
            }
            $hire->update(['status' => 'issued', 'issued_at' => $hire->issued_at ?? $now]);
            $this->invoice->handle($hire);

            return $hire->refresh();
        }, attempts: 3);
    }
}
