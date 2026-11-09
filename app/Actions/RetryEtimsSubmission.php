<?php

namespace App\Actions;

use App\Jobs\SubmitEtimsDocument;
use App\Models\CreditNote;
use App\Models\CustomOrderInvoice;
use App\Models\EtimsSubmission;
use App\Models\HireInvoice;
use App\Services\IntegrationSettings;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RetryEtimsSubmission
{
    public function __construct(private readonly IntegrationSettings $settings) {}

    public function handle(EtimsSubmission $candidate): void
    {
        $submission = DB::transaction(function () use ($candidate): EtimsSubmission {
            $submission = EtimsSubmission::query()->lockForUpdate()->findOrFail($candidate->id);
            abort_unless(in_array($submission->status, ['configuration_required', 'failed'], true), 409, 'Only an unsubmitted or confirmed-rejected record may be queued again.');

            $document = match ($submission->document_type) {
                'hire_invoice' => HireInvoice::query()->findOrFail($submission->document_id),
                'custom_order_invoice' => CustomOrderInvoice::query()->findOrFail($submission->document_id),
                'credit_note' => CreditNote::query()->findOrFail($submission->document_id),
                default => throw new HttpException(409, 'Unsupported eTIMS document type.'),
            };
            $settings = $this->settings->etims($document->issued_on);
            $taxSetup = $settings['tax_setup'] ?? [];
            abort_unless(($settings['ready'] ?? false) && filled($taxSetup['etims_live_from'] ?? null), 422, 'Approved eTIMS integration settings are incomplete.');
            abort_unless($document->issued_on->toDateString() >= $taxSetup['etims_live_from'], 422, 'Historical invoices require cutover approval and cannot be queued here.');

            $submission->forceFill(['status' => 'pending', 'last_error' => null, 'result_message' => null])->save();

            return $submission;
        });

        SubmitEtimsDocument::dispatch($submission->id)->afterCommit();
    }
}
