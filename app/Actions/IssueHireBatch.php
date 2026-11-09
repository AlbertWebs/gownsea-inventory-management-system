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

class IssueHireBatch
{
    public function __construct(private readonly QueueCustomerSms $queueSms, private readonly IssueHireInvoice $invoice) {}

    /** @param array{asset_codes: list<string>, customer_id: int, location_id: int, due_at: string, event_date?: string|null, graduation_event_id?: int|null, contract_reference?: string|null, idempotency_key: string, notes?: string|null} $data */
    public function handle(array $data, User $actor): Hire
    {
        if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($data['location_id'])->exists()) {
            abort(403);
        }

        return DB::transaction(function () use ($data, $actor): Hire {
            $existing = Hire::query()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                $existingCodes = $existing->items()->with('asset:id,asset_code')->get()->pluck('asset.asset_code')->sort()->values();
                $requestedCodes = collect($data['asset_codes'])->sort()->values();
                $sameDueDate = $existing->due_at?->equalTo(CarbonImmutable::parse($data['due_at'], config('app.timezone')));
                $sameEventDate = $existing->event_date?->toDateString() === ($data['event_date'] ?? null);
                if (
                    ! in_array($existing->status, ['issued', 'partially_returned', 'returned_pending_inspection', 'completed'], true)
                    ||
                    $existing->customer_id !== (int) $data['customer_id']
                    || $existing->location_id !== (int) $data['location_id']
                    || $existingCodes->all() !== $requestedCodes->all()
                    || ! $sameDueDate
                    || ! $sameEventDate
                    || $existing->graduation_event_id !== (isset($data['graduation_event_id']) ? (int) $data['graduation_event_id'] : null)
                    || $existing->contract_reference !== ($data['contract_reference'] ?? null)
                    || $existing->notes !== ($data['notes'] ?? null)
                ) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This submission key was already used for a different hire. Refresh the form before submitting again.']);
                }

                return $existing;
            }

            $assetCodes = collect($data['asset_codes'])->unique()->values();
            if ($assetCodes->count() !== count($data['asset_codes'])) {
                throw ValidationException::withMessages(['asset_codes' => 'Each scanned asset code must be unique.']);
            }

            $location = Location::query()->whereKey($data['location_id'])->where('is_active', true)->firstOrFail();
            $customer = Customer::query()->whereKey($data['customer_id'])->firstOrFail();
            $assets = PhysicalAsset::query()
                ->with(['product.classification'])
                ->whereIn('asset_code', $assetCodes)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($assets->count() !== $assetCodes->count()) {
                throw ValidationException::withMessages(['asset_codes' => 'One or more scanned asset codes were not found.']);
            }

            foreach ($assets as $asset) {
                if ($asset->status !== 'available' || $asset->current_location_id !== $location->id || $asset->current_transfer_id !== null) {
                    throw ValidationException::withMessages(['asset_codes' => "Asset {$asset->asset_code} is not available at the selected pickup location."]);
                }
            }
            $penaltyPolicies = LateReturnPolicy::resolveForProducts($assets->pluck('product_id')->unique()->all(), $customer->institution_id, $data['contract_reference'] ?? null);

            $now = now();
            $hire = Hire::query()->create([
                'reference' => 'HIRE-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'idempotency_key' => $data['idempotency_key'],
                'customer_id' => $customer->id,
                'institution_id' => $customer->institution_id,
                'graduation_event_id' => $data['graduation_event_id'] ?? null,
                'contract_reference' => $data['contract_reference'] ?? null,
                'location_id' => $location->id,
                'created_by' => $actor->id,
                'status' => 'issued',
                'issued_at' => $now,
                'event_date' => $data['event_date'] ?? null,
                'due_at' => $data['due_at'],
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($assets as $asset) {
                $item = HireItem::query()->create([
                    'hire_id' => $hire->id,
                    'product_id' => $asset->product_id,
                    'physical_asset_id' => $asset->id,
                    'product_name_snapshot' => $asset->product->name,
                    'classification_code_snapshot' => $asset->product->classification?->code,
                    'qualification_snapshot' => $asset->product->qualification_level,
                    'quantity' => 1,
                    'unit_rate_minor' => $asset->product->hire_rate_minor,
                    'penalty_policy_snapshot' => $penaltyPolicies[$asset->product_id],
                    'status' => 'issued',
                ]);

                $before = ['status' => $asset->status, 'custody_type' => $asset->custody_type, 'current_location_id' => $asset->current_location_id];
                $asset->update([
                    'current_location_id' => null,
                    'custody_type' => 'customer',
                    'custodian_user_id' => null,
                    'custodian_customer_id' => $customer->id,
                    'status' => 'hired',
                ]);
                $key = "hire-issue:{$data['idempotency_key']}:{$asset->id}";

                AssetCustodyEvent::query()->create([
                    'physical_asset_id' => $asset->id,
                    'actor_id' => $actor->id,
                    'from_location_id' => $location->id,
                    'customer_id' => $customer->id,
                    'event_type' => 'hire_issued',
                    'from_status' => 'available',
                    'to_status' => 'hired',
                    'from_custody_type' => $location->type,
                    'to_custody_type' => 'customer',
                    'idempotency_key' => $key,
                    'notes' => "Issued on hire {$hire->reference}.",
                    'occurred_at' => $now,
                ]);
                StockMovement::query()->create([
                    'product_id' => $asset->product_id,
                    'physical_asset_id' => $asset->id,
                    'from_location_id' => $location->id,
                    'actor_id' => $actor->id,
                    'movement_type' => 'hire_issued',
                    'from_status' => 'available',
                    'to_status' => 'hired',
                    'quantity' => 1,
                    'idempotency_key' => $key,
                    'occurred_at' => $now,
                ]);
                AuditLog::query()->create([
                    'actor_id' => $actor->id,
                    'location_id' => $location->id,
                    'action' => 'hire.asset_issued',
                    'subject_type' => PhysicalAsset::class,
                    'subject_id' => $asset->id,
                    'before_state' => $before,
                    'after_state' => ['status' => 'hired', 'custody_type' => 'customer', 'current_location_id' => null, 'customer_id' => $customer->id, 'hire_id' => $hire->id, 'hire_item_id' => $item->id],
                    'reason' => "Issued on hire {$hire->reference}.",
                    'occurred_at' => $now,
                ]);
            }

            $this->invoice->handle($hire);

            $this->queueSms->handle(
                'hire_issued',
                ['reference' => $hire->reference, 'due_date' => $hire->due_at?->timezone(config('app.timezone'))->format('d M Y') ?? '—'],
                'hire-issued:'.$hire->id,
                $customer,
                $hire,
                location: $location,
                actor: $actor,
            );

            return $hire;
        }, attempts: 3);
    }
}
