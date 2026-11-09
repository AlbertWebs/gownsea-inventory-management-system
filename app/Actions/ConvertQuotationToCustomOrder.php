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

class ConvertQuotationToCustomOrder
{
    public function __construct(private readonly SaveCustomOrder $orders) {}

    /** @param array{expected_completion_date:string,deposit_required:string} $terms */
    public function handle(Quotation $candidate, array $terms, User $actor, ?string $ipAddress = null, ?string $userAgent = null): CustomOrder
    {
        return DB::transaction(function () use ($candidate, $terms, $actor, $ipAddress, $userAgent): CustomOrder {
            $quotation = Quotation::query()->with('lead')->lockForUpdate()->findOrFail($candidate->id);
            abort_unless($actor->hasPermission('orders.manage') && $actor->can('create', CustomOrder::class), 403);
            abort_unless($actor->mayViewAllLocations() || $actor->locations()->whereKey($quotation->location_id)->exists(), 404);

            $existing = CustomOrder::query()->where('origin_quotation_id', $quotation->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            if (Hire::query()->whereIn('idempotency_key', ['quote-bulk-hire:'.$quotation->id, 'quote-serialized-hire:'.$quotation->id])->exists()
                || BulkSale::query()->where('idempotency_key', 'quote-bulk-sale:'.$quotation->id)->exists()) {
                throw ValidationException::withMessages(['quotation' => 'This quotation has already been converted to another fulfillment workflow.']);
            }
            if ($quotation->status !== 'accepted' || $quotation->approval_status !== 'approved') {
                throw ValidationException::withMessages(['quotation' => 'Only an accepted, approved quotation can become a custom order.']);
            }
            $customerId = $quotation->customer_id ?? $quotation->lead->customer_id;
            if ($customerId === null) {
                throw ValidationException::withMessages(['customer_id' => 'Link this won lead to a customer record before converting its quotation.']);
            }
            if (! Customer::query()->whereKey($customerId)->whereHas('locations', fn ($query) => $query->whereKey($quotation->location_id))->exists()) {
                throw ValidationException::withMessages(['customer_id' => 'Assign this customer to the quotation branch before converting the offer.']);
            }
            $lines = QuotationLine::query()->where('quotation_id', $quotation->id)->where('revision', $quotation->revision)->orderBy('id')->get();
            if ($lines->isEmpty() || $lines->sum(fn ($line): int => $line->line_total_minor) !== $quotation->total_minor) {
                throw ValidationException::withMessages(['quotation' => 'The current quotation lines do not reconcile to the approved total.']);
            }

            $order = $this->orders->create([
                'location_id' => $quotation->location_id,
                'customer_id' => $customerId,
                'institution_id' => $quotation->lead->institution_id,
                'origin_quotation_id' => $quotation->id,
                'item_type' => 'Quotation '.$quotation->reference.' custom order',
                'quantity' => max(1, $lines->sum('quantity')),
                'design_specs' => $lines->map(fn ($line): string => $line->description.' — '.$line->quantity.' × KES '.number_format($line->unit_price_minor / 100, 2))->implode("\n"),
                'special_requirements' => 'Created from accepted quotation '.$quotation->reference.' revision '.$quotation->revision.'. Confirm measurements and completion date with the customer.',
                'agreed_total' => number_format($quotation->total_minor / 100, 2, '.', ''),
                'deposit_required' => $terms['deposit_required'],
                'order_date' => now(config('app.timezone'))->toDateString(),
                'expected_completion_date' => $terms['expected_completion_date'],
                'priority' => 'normal',
                'delivery_method' => 'collection',
                'notes' => $quotation->notes,
            ], $actor, $ipAddress, $userAgent);

            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $quotation->location_id,
                'action' => 'quotation.converted_to_custom_order', 'subject_type' => Quotation::class,
                'subject_id' => $quotation->id, 'after_state' => [
                    'order_id' => $order->id, 'order_reference' => $order->reference,
                    'quotation_revision' => $quotation->revision, 'total_minor' => $quotation->total_minor,
                ], 'ip_address' => $ipAddress, 'user_agent' => $userAgent, 'occurred_at' => now(),
            ]);
            LeadActivity::query()->create([
                'lead_id' => $quotation->lead_id, 'actor_id' => $actor->id,
                'activity_type' => 'quotation.converted_to_custom_order',
                'details' => $quotation->reference.' converted to '.$order->reference,
                'after_state' => ['order_id' => $order->id, 'order_reference' => $order->reference], 'happened_at' => now(),
            ]);

            return $order;
        }, attempts: 3);
    }
}
