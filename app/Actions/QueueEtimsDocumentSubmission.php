<?php

namespace App\Actions;

use App\Jobs\SubmitEtimsDocument;
use App\Models\CreditNote;
use App\Models\CustomOrderInvoice;
use App\Models\EtimsSubmission;
use App\Models\HireInvoice;
use App\Models\User;
use App\Services\IntegrationSettings;

class QueueEtimsDocumentSubmission
{
    public function __construct(private readonly IntegrationSettings $settings) {}

    public function handle(HireInvoice|CustomOrderInvoice|CreditNote $document, ?User $actor = null): ?EtimsSubmission
    {
        $taxSetup = $this->settings->etims($document->issued_on);
        if (($taxSetup['tax_setup']['etims_solution'] ?? null) !== 'kra') {
            return null;
        }

        $liveFrom = $taxSetup['tax_setup']['etims_live_from'] ?? null;
        $requiresCutoverReview = ! filled($liveFrom) || $document->issued_on->toDateString() < $liveFrom;
        $isReady = ! $requiresCutoverReview && ($taxSetup['ready'] ?? false);

        $documentType = match (true) {
            $document instanceof HireInvoice => 'hire_invoice',
            $document instanceof CustomOrderInvoice => 'custom_order_invoice',
            default => 'credit_note',
        };
        $submission = EtimsSubmission::query()->firstOrCreate(
            ['document_type' => $documentType, 'document_id' => $document->id],
            [
                'credit_note_id' => $document instanceof CreditNote ? $document->id : null,
                'provider' => 'kra_vscu',
                'status' => $requiresCutoverReview ? 'historical_review' : ($isReady ? 'pending' : 'configuration_required'),
                'last_error' => $requiresCutoverReview
                    ? 'Invoice predates the approved eTIMS go-live date. Include it in the accountant-led cutover review.'
                    : ($isReady ? null : 'Approved KRA taxpayer, tax, device, endpoint, or certification settings are incomplete.'),
                'created_by' => $actor?->id,
            ],
        );

        if ($submission->wasRecentlyCreated && $submission->status === 'pending') {
            SubmitEtimsDocument::dispatch($submission->id)->afterCommit();
        }

        return $submission;
    }
}
