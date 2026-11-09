<?php

namespace App\Http\Controllers;

use App\Actions\RetryEtimsSubmission;
use App\Models\CreditNote;
use App\Models\CustomOrderInvoice;
use App\Models\EtimsSubmission;
use App\Models\HireInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EtimsSubmissionController extends Controller
{
    public function retry(Request $request, EtimsSubmission $submission, RetryEtimsSubmission $retry): RedirectResponse
    {
        $locationId = match ($submission->document_type) {
            'hire_invoice' => HireInvoice::query()->whereKey($submission->document_id)->value('location_id'),
            'custom_order_invoice' => CustomOrderInvoice::query()->whereKey($submission->document_id)->value('location_id'),
            'credit_note' => CreditNote::query()->whereKey($submission->document_id)->value('location_id'),
            default => null,
        };
        abort_if($locationId === null, 404);
        abort_unless($request->user()->mayViewAllLocations() || $request->user()->locations()->whereKey($locationId)->exists(), 404);

        $retry->handle($submission);

        return back()->with('success', 'The eTIMS document has been queued. A confirmed KRA rejection can be corrected and retried; uncertain submissions stay blocked for reconciliation.');
    }
}
