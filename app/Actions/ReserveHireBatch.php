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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReserveHireBatch
{
    /** @param array{asset_codes: list<string>, customer_id: int, location_id: int, due_at: string, event_date?: string|null, graduation_event_id?: int|null, contract_reference?: string|null, idempotency_key: string, notes?: string|null, quoted_unit_rates?:array<int,int>} $data */
    public function handle(array $data, User $actor): Hire
    {
        if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($data['location_id'])->exists()) {
            abort(403);
        }

        return DB::transaction(function () use ($data, $actor): Hire {
            $existing = Hire::query()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                $existingItems = $existing->items()->with('asset:id,asset_code')->get(['product_id', 'physical_asset_id', 'unit_rate_minor']);
                $codes = $existingItems->pluck('asset.asset_code')->sort()->values();
                $sameQuotedRates = collect($data['quoted_unit_rates'] ?? [])->every(fn (int $rate, int $productId): bool => (int) $existingItems->firstWhere('product_id', $productId)?->unit_rate_minor === $rate);
                if (
                    $existing->customer_id !== (int) $data['customer_id']
                    || $existing->location_id !== (int) $data['location_id']
                    || $codes->all() !== collect($data['asset_codes'])->sort()->values()->all()
                    || ! $existing->due_at?->equalTo(CarbonImmutable::parse($data['due_at'], config('app.timezone')))
                    || $existing->event_date?->toDateString() !== ($data['event_date'] ?? null)
                    || $existing->graduation_event_id !== (isset($data['graduation_event_id']) ? (int) $data['graduation_event_id'] : null)
                    || $existing->contract_reference !== ($data['contract_reference'] ?? null)
                    || $existing->notes !== ($data['notes'] ?? null)
                    || ! $sameQuotedRates
                ) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This reservation key was already used for different details.']);
                }

                return $existing;
            }

            $assetCodes = collect($data['asset_codes'])->unique()->values();
            if ($assetCodes->isEmpty() || $assetCodes->count() > 200 || $assetCodes->count() !== count($data['asset_codes'])) {
                throw ValidationException::withMessages(['asset_codes' => 'Reserve between 1 and 200 unique asset codes at a time.']);
            }
            $location = Location::query()->whereKey($data['location_id'])->where('is_active', true)->firstOrFail();
            $customer = Customer::query()->whereKey($data['customer_id'])->firstOrFail();
            $assets = PhysicalAsset::query()->with('product.classification')->whereIn('asset_code', $assetCodes)->orderBy('id')->lockForUpdate()->get();
            if ($assets->count() !== $assetCodes->count()) {
                throw ValidationException::withMessages(['asset_codes' => 'One or more scanned asset codes were not found.']);
            }
            foreach ($assets as $asset) {
                if ($asset->status !== 'available' || $asset->current_location_id !== $location->id || $asset->current_transfer_id !== null) {
                    throw ValidationException::withMessages(['asset_codes' => "Asset {$asset->asset_code} is not available to reserve at this location."]);
                }
                if (isset($data['quoted_unit_rates'][$asset->product_id]) && (int) $data['quoted_unit_rates'][$asset->product_id] <= 0) {
                    throw ValidationException::withMessages(['asset_codes' => 'Quoted hire rates must be greater than zero.']);
                }
            }
            $penaltyPolicies = LateReturnPolicy::resolveForProducts($assets->pluck('product_id')->unique()->all(), $customer->institution_id, $data['contract_reference'] ?? null);

            $hire = Hire::query()->create([
                'reference' => 'HIRE-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'idempotency_key' => $data['idempotency_key'],
                'customer_id' => $customer->id,
                'institution_id' => $customer->institution_id,
                'graduation_event_id' => $data['graduation_event_id'] ?? null,
                'contract_reference' => $data['contract_reference'] ?? null,
                'location_id' => $location->id,
                'created_by' => $actor->id,
                'status' => 'reserved',
                'event_date' => $data['event_date'] ?? null,
                'due_at' => $data['due_at'],
                'notes' => $data['notes'] ?? null,
            ]);
            $now = now();

            foreach ($assets as $asset) {
                $item = HireItem::query()->create([
                    'hire_id' => $hire->id,
                    'product_id' => $asset->product_id,
                    'physical_asset_id' => $asset->id,
                    'product_name_snapshot' => $asset->product->name,
                    'classification_code_snapshot' => $asset->product->classification?->code,
                    'qualification_snapshot' => $asset->product->qualification_level,
                    'quantity' => 1,
                    'unit_rate_minor' => $data['quoted_unit_rates'][$asset->product_id] ?? $asset->product->hire_rate_minor,
                    'penalty_policy_snapshot' => $penaltyPolicies[$asset->product_id],
                    'status' => 'reserved',
                ]);
                $asset->update(['status' => 'reserved']);
                $key = "hire-reservation:{$data['idempotency_key']}:{$asset->id}";
                AssetCustodyEvent::query()->create([
                    'physical_asset_id' => $asset->id,
                    'actor_id' => $actor->id,
                    'from_location_id' => $location->id,
                    'to_location_id' => $location->id,
                    'customer_id' => $customer->id,
                    'event_type' => 'hire_reserved',
                    'from_status' => 'available',
                    'to_status' => 'reserved',
                    'from_custody_type' => $asset->custody_type,
                    'to_custody_type' => $asset->custody_type,
                    'idempotency_key' => $key,
                    'notes' => "Reserved on hire {$hire->reference}.",
                    'occurred_at' => $now,
                ]);
                StockMovement::query()->create([
                    'product_id' => $asset->product_id,
                    'physical_asset_id' => $asset->id,
                    'from_location_id' => $location->id,
                    'to_location_id' => $location->id,
                    'actor_id' => $actor->id,
                    'movement_type' => 'hire_reserved',
                    'from_status' => 'available',
                    'to_status' => 'reserved',
                    'quantity' => 1,
                    'idempotency_key' => $key,
                    'occurred_at' => $now,
                ]);
                AuditLog::query()->create([
                    'actor_id' => $actor->id,
                    'location_id' => $location->id,
                    'action' => 'hire.asset_reserved',
                    'subject_type' => PhysicalAsset::class,
                    'subject_id' => $asset->id,
                    'before_state' => ['status' => 'available', 'current_location_id' => $location->id],
                    'after_state' => ['status' => 'reserved', 'current_location_id' => $location->id, 'hire_id' => $hire->id, 'hire_item_id' => $item->id],
                    'reason' => "Reserved for customer {$customer->customer_number}.",
                    'occurred_at' => $now,
                ]);
            }

            return $hire;
        }, attempts: 3);
    }
}
