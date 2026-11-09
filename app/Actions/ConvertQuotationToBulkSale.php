<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BulkSale;
use App\Models\Customer;
use App\Models\CustomOrder;
use App\Models\Hire;
use App\Models\LeadActivity;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertQuotationToBulkSale
{
    public function __construct(private readonly SellBulkStock $sell) {}

    /** @param array{method:string,provider_reference?:?string} $terms */
    public function handle(Quotation $candidate, array $terms, User $actor, ?string $ipAddress, ?string $userAgent): BulkSale
    {
        return DB::transaction(function () use ($candidate, $terms, $actor, $ipAddress, $userAgent): BulkSale {
            $quotation = Quotation::query()->with('lead')->lockForUpdate()->findOrFail($candidate->id);
            abort_unless($actor->hasPermission('sales.record'), 403);
            abort_unless($actor->mayViewAllLocations() || $actor->locations()->whereKey($quotation->location_id)->exists(), 404);
            $this->assertConvertible($quotation);
            if (CustomOrder::query()->where('origin_quotation_id', $quotation->id)->exists()
                || Hire::query()->whereIn('idempotency_key', ['quote-bulk-hire:'.$quotation->id, 'quote-serialized-hire:'.$quotation->id])->exists()) {
                throw ValidationException::withMessages(['quotation' => 'This quotation has already been converted to another fulfillment workflow.']);
            }

            $lines = QuotationLine::query()->where('quotation_id', $quotation->id)->where('revision', $quotation->revision)->orderBy('id')->get();
            if ($lines->isEmpty() || $lines->contains(fn (QuotationLine $line): bool => $line->fulfillment_type !== 'bulk_sale' || $line->product_id === null)
                || $lines->pluck('product_id')->unique()->count() !== $lines->count()
                || $lines->sum(fn (QuotationLine $line): int => $line->line_total_minor) !== $quotation->total_minor) {
                throw ValidationException::withMessages(['quotation' => 'Map every current quote line to a different active bulk product for the same sale workflow before converting it.']);
            }

            $customerId = $quotation->customer_id ?? $quotation->lead->customer_id;
            if (! Customer::query()->whereKey($customerId)->whereHas('locations', fn ($query) => $query->whereKey($quotation->location_id))->exists()) {
                throw ValidationException::withMessages(['customer_id' => 'Assign this customer to the quotation branch before converting the offer.']);
            }

            $key = 'quote-bulk-sale:'.$quotation->id;
            $existing = BulkSale::query()->where('idempotency_key', $key)->first();
            $sale = $this->sell->handle([
                'location_id' => $quotation->location_id,
                'customer_id' => $customerId,
                'items' => $lines->map(fn (QuotationLine $line): array => [
                    'product_id' => $line->product_id, 'quantity' => $line->quantity,
                    'unit_price_minor' => $line->unit_price_minor,
                ])->all(),
                'method' => $terms['method'],
                'provider_reference' => $terms['provider_reference'] ?? null,
                'idempotency_key' => $key,
                'notes' => 'Paid bulk sale from accepted quotation '.$quotation->reference.' revision '.$quotation->revision.'.',
            ], $actor, $ipAddress, $userAgent);

            if ($existing === null) {
                AuditLog::query()->create([
                    'actor_id' => $actor->id, 'location_id' => $quotation->location_id,
                    'action' => 'quotation.converted_to_bulk_sale', 'subject_type' => Quotation::class,
                    'subject_id' => $quotation->id, 'after_state' => [
                        'sale_id' => $sale->id, 'sale_reference' => $sale->reference,
                        'revision' => $quotation->revision, 'total_minor' => $sale->total_minor,
                        'line_ids' => $lines->pluck('id')->all(),
                    ], 'ip_address' => $ipAddress, 'user_agent' => $userAgent, 'occurred_at' => now(),
                ]);
                LeadActivity::query()->create([
                    'lead_id' => $quotation->lead_id, 'actor_id' => $actor->id,
                    'activity_type' => 'quotation.converted_to_bulk_sale',
                    'details' => $quotation->reference.' completed as '.$sale->reference,
                    'after_state' => ['sale_reference' => $sale->reference, 'total_minor' => $sale->total_minor], 'happened_at' => now(),
                ]);
            }

            return $sale;
        }, attempts: 3);
    }

    private function assertConvertible(Quotation $quotation): void
    {
        if ($quotation->status !== 'accepted' || $quotation->approval_status !== 'approved') {
            throw ValidationException::withMessages(['quotation' => 'Only an accepted, approved quotation can be converted.']);
        }
        if ($quotation->valid_until?->isBefore(now(config('app.timezone'))->startOfDay())) {
            throw ValidationException::withMessages(['quotation' => 'This accepted quotation has expired.']);
        }
        if (($quotation->customer_id ?? $quotation->lead->customer_id) === null) {
            throw ValidationException::withMessages(['customer_id' => 'Link the won lead to a customer before converting its quotation.']);
        }
    }
}
