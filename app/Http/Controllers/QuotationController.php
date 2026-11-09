<?php

namespace App\Http\Controllers;

use App\Actions\ConvertQuotationToBulkHire;
use App\Actions\ConvertQuotationToBulkSale;
use App\Actions\ConvertQuotationToCustomOrder;
use App\Actions\ConvertQuotationToSerializedHire;
use App\Actions\ReviewQuotation;
use App\Actions\SaveQuotation;
use App\Actions\StoreQuotationAttachments;
use App\Http\Requests\StoreQuotationRequest;
use App\Http\Requests\UpdateQuotationRequest;
use App\Models\BulkSale;
use App\Models\CustomOrder;
use App\Models\Hire;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationAttachment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuotationController extends Controller
{
    public function downloadPdf(Request $request, Quotation $quotation): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->can('viewAny', Quotation::class), 403);
        abort_unless($user->mayViewAllLocations() || $user->locations()->whereKey($quotation->location_id)->exists(), 404);
        $quotation->load(['lead.institution:id,name', 'location:id,name', 'lines']);
        $lines = $quotation->lines->where('revision', $quotation->revision)->values();
        $pdf = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false])
            ->loadView('quotations.pdf', ['quotation' => $quotation, 'lines' => $lines])->setPaper('a4');

        return response()->streamDownload(
            fn (): int|false => print $pdf->output(),
            $quotation->reference.'-revision-'.$quotation->revision.'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function downloadAttachment(Request $request, Quotation $quotation, QuotationAttachment $attachment): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->can('viewAny', Quotation::class), 403);
        abort_unless($user->mayViewAllLocations() || $user->locations()->whereKey($quotation->location_id)->exists(), 404);
        abort_unless($attachment->quotation_id === $quotation->id, 404);
        abort_unless($attachment->disk === 'local' && Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('viewAny', Quotation::class), 403);
        $locationIds = $user->mayViewAllLocations() ? null : $user->locations()->where('is_active', true)->pluck('locations.id')->all();
        $quotePage = Quotation::query()->with(['lead.institution:id,name', 'lead.customer:id,name', 'location:id,name', 'lines', 'attachments', 'approver:id,name', 'convertedCustomOrder:id,reference,origin_quotation_id'])
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->latest('updated_at')->paginate(25)->withQueryString();
        $conversionKeys = $quotePage->getCollection()->flatMap(fn (Quotation $quotation): array => [
            'quote-bulk-hire:'.$quotation->id, 'quote-serialized-hire:'.$quotation->id,
        ])->all();
        $convertedHires = Hire::query()->whereIn('idempotency_key', $conversionKeys)->pluck('reference', 'idempotency_key');
        $saleKeys = $quotePage->getCollection()->map(fn (Quotation $quotation): string => 'quote-bulk-sale:'.$quotation->id)->all();
        $convertedSales = BulkSale::query()->whereIn('idempotency_key', $saleKeys)->pluck('reference', 'idempotency_key');

        return Inertia::render('Customers/Quotations', [
            'quotations' => $quotePage
                ->through(fn (Quotation $quotation): array => [
                    'id' => $quotation->id, 'reference' => $quotation->reference, 'leadId' => $quotation->lead_id,
                    'leadName' => $quotation->lead->contact_name, 'institution' => $quotation->lead->institution?->name,
                    'customer' => $quotation->lead->customer?->name, 'location' => $quotation->location?->name,
                    'customerId' => $quotation->customer_id ?? $quotation->lead->customer_id,
                    'customOrderReference' => $quotation->convertedCustomOrder?->reference,
                    'hireReference' => $convertedHires->get('quote-bulk-hire:'.$quotation->id) ?? $convertedHires->get('quote-serialized-hire:'.$quotation->id),
                    'saleReference' => $convertedSales->get('quote-bulk-sale:'.$quotation->id),
                    'revision' => $quotation->revision, 'status' => $quotation->status, 'validUntil' => $quotation->valid_until?->toDateString(),
                    'approvalStatus' => $quotation->approval_status, 'approvedBy' => $quotation->approver?->name, 'approvalReason' => $quotation->approval_reason,
                    'subtotalMinor' => $quotation->subtotal_minor, 'taxMinor' => $quotation->tax_minor, 'totalMinor' => $quotation->total_minor,
                    'notes' => $quotation->notes, 'lines' => $quotation->lines->where('revision', $quotation->revision)->values()->map(fn ($line) => [
                        'id' => $line->id, 'description' => $line->description, 'quantity' => $line->quantity,
                        'fulfillmentType' => $line->fulfillment_type, 'productId' => $line->product_id,
                        'unitPrice' => number_format($line->unit_price_minor / 100, 2, '.', ''),
                    ]),
                    'attachments' => $quotation->attachments->map(fn (QuotationAttachment $attachment): array => [
                        'id' => $attachment->id, 'revision' => $attachment->revision, 'name' => $attachment->original_name,
                        'sizeBytes' => $attachment->size_bytes,
                    ])->values(),
                ]),
            'leads' => Lead::query()->with(['institution:id,name', 'customer:id,name'])
                ->whereNotIn('stage', ['lost'])->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
                ->latest('updated_at')->limit(250)->get(['id', 'reference', 'location_id', 'contact_name', 'institution_id', 'customer_id', 'stage'])
                ->map(fn (Lead $lead): array => ['id' => $lead->id, 'label' => $lead->reference.' · '.$lead->contact_name.' · '.($lead->institution?->name ?? $lead->customer?->name ?? 'Prospect'), 'stage' => $lead->stage]),
            'canManage' => $user->can('create', Quotation::class),
            'canApprove' => $user->hasPermission('quotations.approve'),
            'canConvert' => $user->hasPermission('orders.manage') && $user->can('create', CustomOrder::class),
            'canConvertHire' => $user->hasPermission('hiring.issue'),
            'canConvertSale' => $user->hasPermission('sales.record'),
            'products' => Product::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku', 'tracking_type'])->map(fn (Product $product): array => [
                'id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'trackingType' => $product->tracking_type,
            ]),
        ]);
    }

    public function store(StoreQuotationRequest $request, SaveQuotation $save, StoreQuotationAttachments $storeAttachments): RedirectResponse
    {
        $quote = $save->create($request->validated(), $request->user(), $request->ip(), $request->userAgent());
        $storeAttachments->handle($quote, $request->file('attachments', []), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Quotation {$quote->reference} created as draft.");
    }

    public function update(UpdateQuotationRequest $request, Quotation $quotation, SaveQuotation $save, StoreQuotationAttachments $storeAttachments): RedirectResponse
    {
        $quote = $save->update($quotation, $request->validated(), $request->user(), $request->ip(), $request->userAgent());
        $storeAttachments->handle($quote, $request->file('attachments', []), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Quotation {$quote->reference} saved as revision {$quote->revision}.");
    }

    public function review(Request $request, Quotation $quotation, ReviewQuotation $review): RedirectResponse
    {
        abort_unless($request->user()->can('approve', $quotation), 403);
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $review->handle($quotation, $data['decision'], $data['reason'], $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Quotation {$quotation->reference} {$data['decision']}.");
    }

    public function convertToCustomOrder(Request $request, Quotation $quotation, ConvertQuotationToCustomOrder $convert): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('orders.manage') && $request->user()->can('create', CustomOrder::class), 403);
        $data = $request->validate([
            'expected_completion_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'deposit_required' => ['required', 'string', 'regex:/^\\d{1,10}(?:\\.\\d{1,2})?$/'],
        ]);
        $order = $convert->handle($quotation, $data, $request->user(), $request->ip(), $request->userAgent());

        return redirect()->route('custom-orders.index')->with('success', "Quotation {$quotation->reference} converted to {$order->reference}.");
    }

    public function convertToBulkHire(Request $request, Quotation $quotation, ConvertQuotationToBulkHire $convert): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('hiring.issue'), 403);
        $terms = $request->validate([
            'due_at' => ['required', 'date_format:Y-m-d', 'after:today'],
            'event_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:due_at'],
        ]);
        $hire = $convert->handle($quotation, $terms, $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Quotation {$quotation->reference} reserved as hire {$hire->reference}.");
    }

    public function convertToBulkSale(Request $request, Quotation $quotation, ConvertQuotationToBulkSale $convert): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('sales.record'), 403);
        $terms = $request->validate([
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'mpesa', 'other'])],
            'provider_reference' => ['nullable', 'required_if:method,mpesa', 'string', 'max:120'],
        ]);
        $sale = $convert->handle($quotation, $terms, $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Quotation {$quotation->reference} completed as paid bulk sale {$sale->reference}.");
    }

    public function convertToSerializedHire(Request $request, Quotation $quotation, ConvertQuotationToSerializedHire $convert): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('hiring.issue'), 403);
        $terms = $request->validate([
            'due_at' => ['required', 'date_format:Y-m-d', 'after:today'],
            'event_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:due_at'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.quotation_line_id' => ['required', 'integer', 'distinct'],
            'lines.*.asset_codes' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.asset_codes.*' => ['required', 'string', 'max:64', 'distinct'],
        ]);
        $hire = $convert->handle($quotation, $terms, $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Quotation {$quotation->reference} reserved as serialized hire {$hire->reference}.");
    }
}
