<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BulkSale;
use App\Models\Customer;
use App\Models\CustomOrder;
use App\Models\Hire;
use App\Models\LeadActivity;
use App\Models\PhysicalAsset;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertQuotationToSerializedHire
{
    public function __construct(private readonly ReserveHireBatch $reserve) {}

    /** @param array{due_at:string,event_date?:string|null,lines:list<array{quotation_line_id:int,asset_codes:list<string>}>} $terms */
    public function handle(Quotation $candidate, array $terms, User $actor, ?string $ipAddress, ?string $userAgent): Hire
    {
        return DB::transaction(function () use ($candidate, $terms, $actor, $ipAddress, $userAgent): Hire {
            $quotation = Quotation::query()->with('lead')->lockForUpdate()->findOrFail($candidate->id);
            abort_unless($actor->hasPermission('hiring.issue'), 403);
            abort_unless($actor->mayViewAllLocations() || $actor->locations()->whereKey($quotation->location_id)->exists(), 404);
            if ($quotation->status !== 'accepted' || $quotation->approval_status !== 'approved') {
                throw ValidationException::withMessages(['quotation' => 'Only an accepted, approved quotation can be converted.']);
            }
            if ($quotation->valid_until?->isBefore(now(config('app.timezone'))->startOfDay())) {
                throw ValidationException::withMessages(['quotation' => 'This accepted quotation has expired.']);
            }
            if (CustomOrder::query()->where('origin_quotation_id', $quotation->id)->exists()
                || BulkSale::query()->where('idempotency_key', 'quote-bulk-sale:'.$quotation->id)->exists()
                || Hire::query()->where('idempotency_key', 'quote-bulk-hire:'.$quotation->id)->exists()) {
                throw ValidationException::withMessages(['quotation' => 'This quotation has already been converted to another fulfillment workflow.']);
            }

            $customerId = $quotation->customer_id ?? $quotation->lead->customer_id;
            if ($customerId === null || ! Customer::query()->whereKey($customerId)->whereHas('locations', fn ($query) => $query->whereKey($quotation->location_id))->exists()) {
                throw ValidationException::withMessages(['customer_id' => 'Assign a linked customer to the quotation branch before converting this offer.']);
            }
            $quoteLines = QuotationLine::query()->where('quotation_id', $quotation->id)->where('revision', $quotation->revision)->orderBy('id')->get();
            if ($quoteLines->isEmpty() || $quoteLines->contains(fn (QuotationLine $line): bool => $line->fulfillment_type !== 'serialized_hire' || $line->product_id === null)
                || $quoteLines->pluck('product_id')->unique()->count() !== $quoteLines->count()
                || $quoteLines->sum(fn (QuotationLine $line): int => $line->line_total_minor) !== $quotation->total_minor) {
                throw ValidationException::withMessages(['quotation' => 'Every current quotation line must map to a different serialized product for this hire workflow.']);
            }
            if (Product::query()->whereIn('id', $quoteLines->pluck('product_id'))->where('tracking_type', 'serialized')->where('is_active', true)->count() !== $quoteLines->count()) {
                throw ValidationException::withMessages(['quotation' => 'A quoted serialized product is no longer active for hire.']);
            }
            $submittedLines = collect($terms['lines'])->keyBy(fn (array $line): int => (int) $line['quotation_line_id']);
            if ($submittedLines->count() !== $quoteLines->count() || $quoteLines->contains(fn (QuotationLine $line): bool => ! $submittedLines->has($line->id))) {
                throw ValidationException::withMessages(['lines' => 'Scan assets against every current quotation line.']);
            }
            $codes = [];
            foreach ($quoteLines as $line) {
                $assetCodes = $submittedLines->get($line->id)['asset_codes'];
                if (count($assetCodes) !== $line->quantity) {
                    throw ValidationException::withMessages(['lines' => "Scan exactly {$line->quantity} asset code(s) for {$line->description}."]);
                }
                if (count($codes) + count($assetCodes) > 200) {
                    throw ValidationException::withMessages(['lines' => 'A serialized hire conversion supports up to 200 asset codes.']);
                }
                array_push($codes, ...$assetCodes);
            }
            if (count($codes) > 200 || count($codes) !== count(array_unique($codes))) {
                throw ValidationException::withMessages(['lines' => 'A serialized hire conversion supports up to 200 unique asset codes.']);
            }
            $assets = PhysicalAsset::query()->whereIn('asset_code', $codes)->get(['id', 'asset_code', 'product_id'])->keyBy('asset_code');
            if ($assets->count() !== count($codes)) {
                throw ValidationException::withMessages(['lines' => 'One or more scanned asset codes were not found.']);
            }
            foreach ($quoteLines as $line) {
                foreach ($submittedLines->get($line->id)['asset_codes'] as $code) {
                    if ((int) $assets->get($code)->product_id !== (int) $line->product_id) {
                        throw ValidationException::withMessages(['lines' => "Asset {$code} does not match the product quoted on {$line->description}."]);
                    }
                }
            }

            $key = 'quote-serialized-hire:'.$quotation->id;
            $existing = Hire::query()->where('idempotency_key', $key)->first();
            $hire = $this->reserve->handle([
                'asset_codes' => $codes,
                'customer_id' => $customerId,
                'location_id' => $quotation->location_id,
                'due_at' => CarbonImmutable::parse($terms['due_at'], config('app.timezone'))->endOfDay()->toDateTimeString(),
                'event_date' => $terms['event_date'] ?? null,
                'idempotency_key' => $key,
                'notes' => 'Reserved from accepted quotation '.$quotation->reference.' revision '.$quotation->revision.'.',
                'quoted_unit_rates' => $quoteLines->mapWithKeys(fn (QuotationLine $line): array => [(int) $line->product_id => $line->unit_price_minor])->all(),
            ], $actor);

            if ($existing === null) {
                AuditLog::query()->create([
                    'actor_id' => $actor->id, 'location_id' => $quotation->location_id,
                    'action' => 'quotation.converted_to_serialized_hire', 'subject_type' => Quotation::class,
                    'subject_id' => $quotation->id, 'after_state' => [
                        'hire_id' => $hire->id, 'hire_reference' => $hire->reference,
                        'revision' => $quotation->revision, 'due_at' => $hire->due_at?->toISOString(), 'asset_codes' => $codes,
                    ], 'ip_address' => $ipAddress, 'user_agent' => $userAgent, 'occurred_at' => now(),
                ]);
                LeadActivity::query()->create([
                    'lead_id' => $quotation->lead_id, 'actor_id' => $actor->id,
                    'activity_type' => 'quotation.converted_to_serialized_hire',
                    'details' => $quotation->reference.' reserved as '.$hire->reference,
                    'after_state' => ['hire_reference' => $hire->reference, 'asset_count' => count($codes)], 'happened_at' => now(),
                ]);
            }

            return $hire;
        }, attempts: 3);
    }
}
