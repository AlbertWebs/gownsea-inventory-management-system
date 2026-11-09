<?php

namespace App\Actions;

use App\Jobs\SendQuotationEmail;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaveQuotation
{
    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): Quotation
    {
        return DB::transaction(function () use ($data, $actor, $ipAddress, $userAgent): Quotation {
            $lead = Lead::query()->lockForUpdate()->findOrFail($data['lead_id']);
            $this->authorizeLocation($lead->location_id, $actor);
            if ($lead->stage === 'lost') {
                throw ValidationException::withMessages(['lead_id' => 'A quotation cannot be created for a lost lead.']);
            }
            [$lines, $subtotal] = $this->normalizeLines($data['lines']);
            $quote = Quotation::query()->create([
                'reference' => 'QUO-'.now()->format('ymd').'-'.Str::upper(Str::random(6)), 'lead_id' => $lead->id,
                'customer_id' => $lead->customer_id, 'location_id' => $lead->location_id, 'created_by' => $actor->id,
                'revision' => 1, 'status' => 'draft',
                'approval_status' => $actor->hasPermission('quotations.approve') ? 'approved' : 'pending',
                'approved_by' => $actor->hasPermission('quotations.approve') ? $actor->id : null,
                'approved_at' => $actor->hasPermission('quotations.approve') ? now() : null,
                'approval_reason' => $actor->hasPermission('quotations.approve') ? 'Created by a quotation approver.' : null,
                'valid_until' => $data['valid_until'] ?? null,
                'subtotal_minor' => $subtotal, 'tax_minor' => 0, 'total_minor' => $subtotal, 'notes' => $data['notes'] ?? null,
            ]);
            $this->saveLines($quote, $lines);
            $this->history($quote, $lead, $actor, 'quotation.created', null, $this->snapshot($quote, $lines), null, $ipAddress, $userAgent);

            return $quote;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    public function update(Quotation $quotation, array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): Quotation
    {
        return DB::transaction(function () use ($quotation, $data, $actor, $ipAddress, $userAgent): Quotation {
            $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            $this->authorizeLocation($quotation->location_id, $actor);
            if (in_array($quotation->status, ['accepted', 'declined', 'expired'], true)) {
                throw ValidationException::withMessages(['status' => 'Accepted, declined or expired quotations are closed. Create a new quotation to revise the offer.']);
            }
            [$lines, $subtotal] = $this->normalizeLines($data['lines']);
            $current = $quotation->currentLines()->orderBy('id')->get()->map(fn (QuotationLine $line): array => [
                'description' => $line->description, 'fulfillment_type' => $line->fulfillment_type,
                'product_id' => $line->product_id, 'quantity' => $line->quantity, 'unit_price_minor' => $line->unit_price_minor,
            ])->all();
            $termsChanged = $current !== $lines
                || $quotation->valid_until?->toDateString() !== ($data['valid_until'] ?? null)
                || $quotation->notes !== ($data['notes'] ?? null);
            if (in_array($data['status'], ['accepted', 'declined'], true) && $termsChanged) {
                throw ValidationException::withMessages(['lines' => 'Send an approved revision before accepting or declining changed quotation terms.']);
            }
            if (! $this->canTransition($quotation->status, $data['status'])) {
                throw ValidationException::withMessages(['status' => 'That quotation status change is not allowed.']);
            }
            if ($data['status'] === 'sent' && $termsChanged && ! $actor->hasPermission('quotations.approve')) {
                throw ValidationException::withMessages(['status' => 'Revised quotation terms need approval before they can be sent.']);
            }
            if ($data['status'] === 'sent' && ! $actor->hasPermission('quotations.approve') && $quotation->approval_status !== 'approved') {
                throw ValidationException::withMessages(['status' => 'A manager must approve this quotation before it can be sent.']);
            }
            if ($data['status'] === 'sent') {
                $leadForDelivery = Lead::query()->with('customer:id,email')->findOrFail($quotation->lead_id);
                if (blank($leadForDelivery->email ?: $leadForDelivery->customer?->email)) {
                    throw ValidationException::withMessages(['status' => 'Add an email address to the lead or linked customer before sending this quotation.']);
                }
            }
            $before = $this->snapshot($quotation, $current);
            $revision = $quotation->revision + 1;
            $approvalStatus = $termsChanged
                ? ($actor->hasPermission('quotations.approve') ? 'approved' : 'pending')
                : $quotation->approval_status;
            $approvedBy = $approvalStatus === 'approved'
                ? ($actor->hasPermission('quotations.approve') && $termsChanged ? $actor->id : $quotation->approved_by)
                : null;
            $approvedAt = $approvalStatus === 'approved'
                ? ($actor->hasPermission('quotations.approve') && $termsChanged ? now() : $quotation->approved_at)
                : null;
            $approvalReason = $approvalStatus === 'approved'
                ? ($actor->hasPermission('quotations.approve') && $termsChanged ? 'Terms updated and approved by an authorized manager.' : $quotation->approval_reason)
                : null;
            $quotation->update([
                'revision' => $revision, 'status' => $data['status'], 'valid_until' => $data['valid_until'] ?? null,
                'subtotal_minor' => $subtotal, 'tax_minor' => 0, 'total_minor' => $subtotal, 'notes' => $data['notes'] ?? null,
                'approval_status' => $approvalStatus,
                'approved_by' => $approvedBy,
                'approved_at' => $approvedAt,
                'approval_reason' => $approvalReason,
            ]);
            $this->saveLines($quotation, $lines);
            $lead = Lead::query()->lockForUpdate()->findOrFail($quotation->lead_id);
            $leadBefore = ['stage' => $lead->stage];
            if ($quotation->status === 'sent' && $lead->stage !== 'won') {
                $lead->update(['stage' => 'quotation_sent']);
            }
            if ($quotation->status === 'accepted') {
                $lead->update(['stage' => 'won']);
            }
            $this->history($quotation, $lead, $actor, 'quotation.updated', $before, $this->snapshot($quotation, $lines), $data['change_reason'], $ipAddress, $userAgent, $leadBefore);

            if ($data['status'] === 'sent') {
                SendQuotationEmail::dispatch($quotation->id, $quotation->revision);
            }

            return $quotation->refresh();
        }, attempts: 3);
    }

    private function authorizeLocation(int $locationId, User $actor): void
    {
        if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($locationId)->exists()) {
            abort(403);
        }
    }

    /** @param array<int, array{description:string,quantity:int,unit_price:string,fulfillment_type?:string,product_id?:int|string|null}> $payload @return array{array<int,array{description:string,fulfillment_type:string,product_id:?int,quantity:int,unit_price_minor:int}>,int} */
    private function normalizeLines(array $payload): array
    {
        $lines = [];
        $subtotal = 0;
        foreach ($payload as $line) {
            $fulfillmentType = $line['fulfillment_type'] ?? 'custom_order';
            $productId = filled($line['product_id'] ?? null) ? (int) $line['product_id'] : null;
            if (($fulfillmentType === 'custom_order') !== ($productId === null)) {
                throw ValidationException::withMessages(['lines' => 'Custom work must not be mapped to inventory; bulk hire and sale lines require a product.']);
            }
            $trackingType = $fulfillmentType === 'serialized_hire' ? 'serialized' : 'bulk';
            if ($productId !== null && ! Product::query()->whereKey($productId)->where('tracking_type', $trackingType)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['lines' => 'Map each hire or sale line to an active product with the matching stock tracking type.']);
            }
            $unitPrice = $this->toMinorUnits((string) $line['unit_price']);
            $lineTotal = $unitPrice * (int) $line['quantity'];
            if ($unitPrice <= 0 || $lineTotal > PHP_INT_MAX - $subtotal) {
                throw ValidationException::withMessages(['lines' => 'Quote line totals must be positive and within supported limits.']);
            }
            $subtotal += $lineTotal;
            $lines[] = [
                'description' => trim($line['description']), 'fulfillment_type' => $fulfillmentType,
                'product_id' => $productId, 'quantity' => (int) $line['quantity'], 'unit_price_minor' => $unitPrice,
            ];
        }

        return [$lines, $subtotal];
    }

    /** @param array<int,array{description:string,fulfillment_type:string,product_id:?int,quantity:int,unit_price_minor:int}> $lines */
    private function saveLines(Quotation $quote, array $lines): void
    {
        foreach ($lines as $line) {
            QuotationLine::query()->create([...$line, 'quotation_id' => $quote->id, 'revision' => $quote->revision, 'line_total_minor' => $line['unit_price_minor'] * $line['quantity']]);
        }
    }

    private function canTransition(string $from, string $to): bool
    {
        return match ($from) {
            'draft' => in_array($to, ['draft', 'sent'], true),
            'sent' => in_array($to, ['sent', 'accepted', 'declined'], true),
            default => false,
        };
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed> $after @param array<string,mixed>|null $leadBefore */
    private function history(Quotation $quote, Lead $lead, User $actor, string $action, ?array $before, array $after, ?string $reason, ?string $ipAddress, ?string $userAgent, ?array $leadBefore = null): void
    {
        AuditLog::query()->create(['actor_id' => $actor->id, 'location_id' => $quote->location_id, 'action' => $action, 'subject_type' => Quotation::class, 'subject_id' => $quote->id, 'before_state' => $before, 'after_state' => $after, 'reason' => $reason, 'ip_address' => $ipAddress, 'user_agent' => $userAgent, 'occurred_at' => now()]);
        LeadActivity::query()->create(['lead_id' => $lead->id, 'actor_id' => $actor->id, 'activity_type' => $action, 'details' => $quote->reference.' revision '.$quote->revision, 'before_state' => $leadBefore, 'after_state' => ['quotation_reference' => $quote->reference, 'status' => $quote->status, 'total_minor' => $quote->total_minor], 'happened_at' => now()]);
    }

    /** @param array<int,array{description:string,fulfillment_type:string,product_id:?int,quantity:int,unit_price_minor:int}> $lines @return array<string,mixed> */
    private function snapshot(Quotation $quote, array $lines): array
    {
        return ['reference' => $quote->reference, 'revision' => $quote->revision, 'status' => $quote->status, 'valid_until' => $quote->valid_until?->toDateString(), 'subtotal_minor' => $quote->subtotal_minor, 'tax_minor' => $quote->tax_minor, 'total_minor' => $quote->total_minor, 'notes' => $quote->notes, 'lines' => $lines];
    }

    private function toMinorUnits(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
