<?php

namespace App\Http\Controllers;

use App\Models\BusinessConfiguration;
use App\Models\CreditNote;
use App\Models\CustomOrderInvoice;
use App\Models\EtimsSubmission;
use App\Models\HireInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HireInvoiceController extends Controller
{
    public function downloadPdf(Request $request, HireInvoice $invoice): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->mayViewAllLocations() || $user->locations()->whereKey($invoice->location_id)->exists(), 404);
        $invoice->load(['customer:id,name,phone,email', 'hire:id,reference', 'location:id,name', 'lines', 'allocations']);
        $allocated = $invoice->allocations->sum(fn ($allocation): int => $allocation->kind === 'refund' ? -$allocation->amount_minor : $allocation->amount_minor);
        $receipt = $this->etimsReceipt('hire_invoice', $invoice->id);
        $pdf = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false])
            ->loadView('invoices.hire', ['invoice' => $invoice, 'balanceMinor' => max(0, $invoice->total_minor - $allocated), 'businessProfile' => $this->businessProfileFor($invoice->issued_on->toDateString()), 'etimsReceipt' => $receipt])
            ->setPaper('a4');

        return response()->streamDownload(
            fn (): int|false => print $pdf->output(),
            $invoice->number.'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function downloadCustomOrderPdf(Request $request, CustomOrderInvoice $invoice): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->mayViewAllLocations() || $user->locations()->whereKey($invoice->location_id)->exists(), 404);
        $invoice->load(['customer:id,name,phone,email', 'order:id,reference', 'location:id,name', 'lines', 'allocations']);
        $allocated = $invoice->allocations->sum(fn ($allocation): int => $allocation->kind === 'refund' ? -$allocation->amount_minor : $allocation->amount_minor);
        $pdf = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false])
            ->loadView('invoices.custom-order', ['invoice' => $invoice, 'balanceMinor' => max(0, $invoice->total_minor - $allocated), 'businessProfile' => $this->businessProfileFor($invoice->issued_on->toDateString()), 'etimsReceipt' => $this->etimsReceipt('custom_order_invoice', $invoice->id)])
            ->setPaper('a4');

        return response()->streamDownload(
            fn (): int|false => print $pdf->output(),
            $invoice->number.'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function downloadCreditNotePdf(Request $request, CreditNote $creditNote): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->mayViewAllLocations() || $user->locations()->whereKey($creditNote->location_id)->exists(), 404);
        $creditNote->load(['customer:id,name,phone,email', 'location:id,name', 'lines', 'refund:id,reference']);
        $pdf = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false])
            ->loadView('invoices.credit-note', [
                'creditNote' => $creditNote,
                'businessProfile' => $this->businessProfileFor($creditNote->issued_on->toDateString()),
                'etimsReceipt' => $this->etimsReceipt('credit_note', $creditNote->id),
            ])->setPaper('a4');

        return response()->streamDownload(
            fn (): int|false => print $pdf->output(),
            $creditNote->number.'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    /** @return array{legal_name:string,trading_name:string,registered_address:string,kra_pin:?string} */
    private function businessProfileFor(string $issuedOn): array
    {
        $record = BusinessConfiguration::query()
            ->where('configuration_key', 'business_profile')
            ->where('review_status', 'approved')
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $issuedOn))
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
        $profile = $record?->configuration ?? [];

        return [
            'legal_name' => (string) ($profile['legal_name'] ?? 'GOWNSEA'),
            'trading_name' => (string) ($profile['trading_name'] ?? ''),
            'registered_address' => (string) ($profile['registered_address'] ?? ''),
            'kra_pin' => $profile['kra_pin'] ?? null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function etimsReceipt(string $documentType, int $documentId): ?array
    {
        $submission = EtimsSubmission::query()
            ->where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->where('status', 'submitted')
            ->first();

        if ($submission === null) {
            return null;
        }

        return [
            'invoice_number' => $submission->remote_invoice_number,
            'receipt_number' => $submission->remote_receipt_number,
            'internal_data' => $submission->internal_data,
            'signature' => $submission->receipt_signature,
            'sdc_id' => $submission->sdc_id,
            'mrc_number' => $submission->mrc_number,
            'submitted_at' => $submission->submitted_at?->format('d M Y H:i:s'),
        ];
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $query = HireInvoice::query()
            ->with(['customer:id,name', 'hire:id,reference', 'location:id,name', 'lines', 'allocations', 'creditNotes:id,number,total_minor,issued_on'])
            ->when(! $user->mayViewAllLocations(), fn ($builder) => $builder->whereIn('location_id', $user->locations()->select('locations.id')))
            ->latest('issued_on')->latest('id');

        $invoicePage = $query->paginate(25)->withQueryString();
        $customOrderPage = CustomOrderInvoice::query()
            ->with(['customer:id,name', 'order:id,reference', 'location:id,name', 'lines', 'allocations', 'creditNotes:id,number,total_minor,issued_on'])
            ->when(! $user->mayViewAllLocations(), fn ($builder) => $builder->whereIn('location_id', $user->locations()->select('locations.id')))
            ->latest('issued_on')->latest('id')->paginate(25, ['*'], 'custom_orders_page')->withQueryString();
        $creditNoteIds = $invoicePage->getCollection()->flatMap(fn (HireInvoice $invoice): array => $invoice->creditNotes->modelKeys())
            ->merge($customOrderPage->getCollection()->flatMap(fn (CustomOrderInvoice $invoice): array => $invoice->creditNotes->modelKeys()))
            ->values()->all();
        $submissions = EtimsSubmission::query()
            ->where(function ($builder) use ($invoicePage, $customOrderPage, $creditNoteIds): void {
                $builder->where(fn ($hire) => $hire->where('document_type', 'hire_invoice')->whereIn('document_id', $invoicePage->getCollection()->modelKeys()))
                    ->orWhere(fn ($orders) => $orders->where('document_type', 'custom_order_invoice')->whereIn('document_id', $customOrderPage->getCollection()->modelKeys()))
                    ->orWhere(fn ($notes) => $notes->where('document_type', 'credit_note')->whereIn('document_id', $creditNoteIds));
            })->get()->keyBy(fn (EtimsSubmission $submission): string => $submission->document_type.':'.$submission->document_id);
        $submissionSummary = fn (string $type, int $id): ?array => ($submission = $submissions->get($type.':'.$id)) ? [
            'id' => $submission->id,
            'status' => $submission->status,
            'receiptNumber' => $submission->remote_receipt_number,
            'message' => $submission->result_message ?: $submission->last_error,
        ] : null;

        $invoices = $invoicePage->through(function (HireInvoice $invoice) use ($submissionSummary): array {
            $allocated = $invoice->allocations->sum(fn ($allocation): int => $allocation->kind === 'refund' ? -$allocation->amount_minor : $allocation->amount_minor);

            return [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'hireReference' => $invoice->hire->reference,
                'customer' => $invoice->customer->name,
                'location' => $invoice->location->name,
                'issuedOn' => $invoice->issued_on->format('d M Y'),
                'dueOn' => $invoice->due_on?->format('d M Y'),
                'totalMinor' => $invoice->total_minor,
                'allocatedMinor' => $allocated,
                'balanceMinor' => max(0, $invoice->total_minor - $allocated),
                'etims' => $submissionSummary('hire_invoice', $invoice->id),
                'creditNotes' => $invoice->creditNotes->map(fn (CreditNote $note): array => [
                    'id' => $note->id, 'number' => $note->number, 'totalMinor' => $note->total_minor,
                    'issuedOn' => $note->issued_on->format('d M Y'),
                    'etims' => $submissionSummary('credit_note', $note->id),
                ])->all(),
                'lines' => $invoice->lines->map(fn ($line): array => [
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unitRateMinor' => $line->unit_rate_minor,
                    'totalMinor' => $line->line_total_minor,
                ])->all(),
            ];
        });

        $customOrderInvoices = $customOrderPage->through(function (CustomOrderInvoice $invoice) use ($submissionSummary): array {
            $allocated = $invoice->allocations->sum(fn ($allocation): int => $allocation->kind === 'refund' ? -$allocation->amount_minor : $allocation->amount_minor);

            return [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'orderReference' => $invoice->order->reference,
                'customer' => $invoice->customer->name,
                'location' => $invoice->location->name,
                'issuedOn' => $invoice->issued_on->format('d M Y'),
                'dueOn' => $invoice->due_on?->format('d M Y'),
                'totalMinor' => $invoice->total_minor,
                'allocatedMinor' => $allocated,
                'balanceMinor' => max(0, $invoice->total_minor - $allocated),
                'etims' => $submissionSummary('custom_order_invoice', $invoice->id),
                'creditNotes' => $invoice->creditNotes->map(fn (CreditNote $note): array => [
                    'id' => $note->id, 'number' => $note->number, 'totalMinor' => $note->total_minor,
                    'issuedOn' => $note->issued_on->format('d M Y'),
                    'etims' => $submissionSummary('credit_note', $note->id),
                ])->all(),
                'lines' => $invoice->lines->map(fn ($line): array => [
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'totalMinor' => $line->line_total_minor,
                ])->all(),
            ];
        });

        return Inertia::render('Finance/Invoices', [
            'invoices' => $invoices,
            'customOrderInvoices' => $customOrderInvoices,
            'outstandingMinor' => $invoices->getCollection()->sum('balanceMinor') + $customOrderInvoices->getCollection()->sum('balanceMinor'),
            'canRetryEtims' => $user->hasPermission('finance.manage'),
        ]);
    }
}
