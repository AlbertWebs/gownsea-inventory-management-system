<?php

namespace App\Actions;

use App\Models\AssetCustodyEvent;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Hire;
use App\Models\HireItem;
use App\Models\LateReturnPolicy;
use App\Models\Location;
use App\Models\PhysicalAsset;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnAsset
{
    public function __construct(private readonly QueueCustomerSms $queueSms, private readonly RecognizeHirePenalty $recognizePenalty) {}

    public function handle(string $assetCode, Location $receivingLocation, User $actor, string $idempotencyKey, string $condition = 'good', ?string $notes = null): AssetCustodyEvent
    {
        if (! in_array($condition, ['good', 'damaged'], true)) {
            throw ValidationException::withMessages(['condition' => 'A physical return must be marked good or damaged. Report missing assets through a loss workflow.']);
        }

        if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($receivingLocation->id)->exists()) {
            abort(403);
        }

        return DB::transaction(function () use ($assetCode, $receivingLocation, $actor, $idempotencyKey, $condition, $notes): AssetCustodyEvent {
            $existingEvent = AssetCustodyEvent::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existingEvent !== null) {
                return $existingEvent;
            }

            $asset = PhysicalAsset::query()->where('asset_code', $assetCode)->lockForUpdate()->firstOrFail();
            $item = HireItem::query()->where('physical_asset_id', $asset->id)->where('status', 'issued')->latest('id')->lockForUpdate()->first();

            if ($asset->status !== 'hired' || $asset->custody_type !== 'customer' || $item === null || $item->status !== 'issued') {
                $priorReturn = AssetCustodyEvent::query()
                    ->where('physical_asset_id', $asset->id)
                    ->where('event_type', 'hire_returned')
                    ->latest('occurred_at')
                    ->first();

                if ($priorReturn !== null) {
                    return $priorReturn;
                }

                throw ValidationException::withMessages(['asset_code' => 'This asset is not currently issued to a customer.']);
            }

            $hire = Hire::query()->lockForUpdate()->findOrFail($item->hire_id);
            $now = now();
            $penalty = $this->assessPenalty($hire, $item, $now);
            $newStatus = $condition === 'damaged' ? 'damaged' : 'returned_pending_inspection';
            $custodyType = $receivingLocation->type === 'warehouse' ? 'warehouse' : 'branch';
            $before = [
                'status' => $asset->status,
                'custody_type' => $asset->custody_type,
                'current_location_id' => $asset->current_location_id,
                'customer_id' => $asset->custodian_customer_id,
            ];

            $asset->update([
                'current_location_id' => $receivingLocation->id,
                'custodian_user_id' => null,
                'custodian_customer_id' => null,
                'custody_type' => $custodyType,
                'status' => $newStatus,
                'condition' => $condition,
                'last_verified_at' => $now,
            ]);

            $item->update([
                'returned_quantity' => 1,
                'returned_at' => $now,
                'return_condition' => $condition,
                'status' => $newStatus,
                'penalty_assessed_minor' => $penalty['amount_minor'],
            ]);
            $this->recognizePenalty->handle($hire, $actor->id, Carbon::parse($now), 'return');

            $stillIssued = HireItem::query()->where('hire_id', $hire->id)->where('status', 'issued')->exists();
            $hire->update(['status' => $stillIssued ? 'partially_returned' : 'returned_pending_inspection']);

            $event = AssetCustodyEvent::query()->create([
                'physical_asset_id' => $asset->id,
                'actor_id' => $actor->id,
                'to_location_id' => $receivingLocation->id,
                'customer_id' => $hire->customer_id,
                'event_type' => 'hire_returned',
                'from_status' => 'hired',
                'to_status' => $newStatus,
                'from_custody_type' => 'customer',
                'to_custody_type' => $custodyType,
                'idempotency_key' => $idempotencyKey,
                'notes' => $notes,
                'occurred_at' => $now,
            ]);

            StockMovement::query()->create([
                'product_id' => $asset->product_id,
                'physical_asset_id' => $asset->id,
                'to_location_id' => $receivingLocation->id,
                'actor_id' => $actor->id,
                'movement_type' => 'hire_returned',
                'from_status' => 'hired',
                'to_status' => $newStatus,
                'quantity' => 1,
                'idempotency_key' => $idempotencyKey,
                'notes' => $notes,
                'occurred_at' => $now,
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'location_id' => $receivingLocation->id,
                'action' => 'hire.asset_returned',
                'subject_type' => PhysicalAsset::class,
                'subject_id' => $asset->id,
                'before_state' => $before,
                'after_state' => [
                    'status' => $asset->status,
                    'custody_type' => $asset->custody_type,
                    'current_location_id' => $asset->current_location_id,
                    'penalty' => $penalty,
                ],
                'reason' => $notes,
                'occurred_at' => $now,
            ]);

            if (! $stillIssued) {
                $customer = Customer::query()->find($hire->customer_id);
                $this->queueSms->handle(
                    'hire_returned',
                    ['reference' => $hire->reference],
                    'hire-returned:'.$hire->id,
                    $customer,
                    $hire,
                    location: $receivingLocation,
                    actor: $actor,
                );
            }

            return $event;
        }, attempts: 3);
    }

    /** @return array{amount_minor:int,rate_per_day_minor:int,overdue_days:int,billable_days:int,grace_days:int,cap_minor:int,count_return_day:bool,due_at:?string} */
    private function assessPenalty(Hire $hire, HireItem $item, Carbon $returnedAt): array
    {
        // Preserve legacy environment terms for hires created before policy snapshots existed.
        $policy = $item->penalty_policy_snapshot ?? config('gownsea.penalties');

        return LateReturnPolicy::assess($hire->due_at, $returnedAt, $policy);
    }
}
