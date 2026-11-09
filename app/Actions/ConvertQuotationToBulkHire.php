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
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertQuotationToBulkHire
{
    public function __construct(private readonly ReserveBulkHire $reserve) {}

    /** @param array{due_at:string,event_date?:string|null} $terms */
    public function handle(Quotation $candidate, array $terms, User $actor, ?string $ipAddress, ?string $userAgent): Hire
    {
        return DB::transaction(function () use ($candidate, $terms, $actor, $ipAddress, $userAgent): Hire {
            $quotation = Quotation::query()->with('lead')->lockForUpdate()->findOrFail($candidate->id);
            abort_unless($actor->hasPermission('hiring.issue'), 403);
            abort_unless($actor->mayViewAllLocations() || $actor->locations()->whereKey($quotation->location_id)->exists(), 404);
            $this->assertConvertible($quotation);
            if (CustomOrder::query()->where('origin_quotation_id', $quotation->id)->exists()
                || BulkSale::query()->where('idempotency_key', 'quote-bulk-sale:'.$quotation->id)->exists()
                || Hire::query()->where('idempotency_key', 'quote-serialized-hire:'.$quotation->id)->exists()) {
                throw ValidationException::withMessages(['quotation' => 'This quotation has already been converted to another fulfillment workflow.']);
            }
            $customerId = $quotation->customer_id ?? $quotation->lead->customer_id;
            if (! Customer::query()->whereKey($customerId)->whereHas('locations', fn ($query) => $query->whereKey($quotation->location_id))->exists()) {
                throw ValidationException::withMessages(['customer_id' => 'Assign this customer to the quotation branch before converting the offer.']);
            }

            $lines = $this->mappedLines($quotation, 'bulk_hire');
            $existing = Hire::query()->where('idempotency_key', 'quote-bulk-hire:'.$quotation->id)->first();
            $hire = $this->reserve->handle([
                'items' => $lines->map(fn (QuotationLine $line): array => [
                    'product_id' => $line->product_id, 'quantity' => $line->quantity,
                    'unit_rate_minor' => $line->unit_price_minor,
                ])->all(),
                'customer_id' => $customerId,
                'location_id' => $quotation->location_id,
                'due_at' => CarbonImmutable::parse($terms['due_at'], config('app.timezone'))->endOfDay()->toDateTimeString(),
                'event_date' => $terms['event_date'] ?? null,
                'idempotency_key' => 'quote-bulk-hire:'.$quotation->id,
                'notes' => 'Reserved from accepted quotation '.$quotation->reference.' revision '.$quotation->revision.'.',
            ], $actor);

            if ($existing === null) {
                AuditLog::query()->create([
                    'actor_id' => $actor->id, 'location_id' => $quotation->location_id,
                    'action' => 'quotation.converted_to_bulk_hire', 'subject_type' => Quotation::class,
                    'subject_id' => $quotation->id, 'after_state' => [
                        'hire_id' => $hire->id, 'hire_reference' => $hire->reference,
                        'revision' => $quotation->revision, 'due_at' => $hire->due_at?->toISOString(),
                        'line_ids' => $lines->pluck('id')->all(),
                    ], 'ip_address' => $ipAddress, 'user_agent' => $userAgent, 'occurred_at' => now(),
                ]);
                LeadActivity::query()->create([
                    'lead_id' => $quotation->lead_id, 'actor_id' => $actor->id,
                    'activity_type' => 'quotation.converted_to_bulk_hire',
                    'details' => $quotation->reference.' reserved as '.$hire->reference,
                    'after_state' => ['hire_reference' => $hire->reference], 'happened_at' => now(),
                ]);
            }

            return $hire;
        }, attempts: 3);
    }

    /** @return Collection<int,QuotationLine> */
    private function mappedLines(Quotation $quotation, string $type): Collection
    {
        $lines = QuotationLine::query()->where('quotation_id', $quotation->id)->where('revision', $quotation->revision)->orderBy('id')->get();
        if ($lines->isEmpty() || $lines->contains(fn (QuotationLine $line): bool => $line->fulfillment_type !== $type || $line->product_id === null)
            || $lines->pluck('product_id')->unique()->count() !== $lines->count()
            || $lines->sum(fn (QuotationLine $line): int => $line->line_total_minor) !== $quotation->total_minor) {
            throw ValidationException::withMessages(['quotation' => 'Map every current quote line to a different active bulk product for the same hire workflow before converting it.']);
        }

        return $lines;
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
