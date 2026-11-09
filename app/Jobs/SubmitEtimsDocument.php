<?php

namespace App\Jobs;

use App\Models\EtimsSubmission;
use App\Services\EtimsPayloadBuilder;
use App\Services\KraVscuClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

class SubmitEtimsDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $submissionId) {}

    public function handle(EtimsPayloadBuilder $payloadBuilder, KraVscuClient $client): void
    {
        $claimed = DB::transaction(function (): ?EtimsSubmission {
            $submission = EtimsSubmission::query()->lockForUpdate()->find($this->submissionId);
            if ($submission === null || $submission->status !== 'pending') {
                return null;
            }

            $submission->forceFill([
                'status' => 'sending',
                'attempts' => $submission->attempts + 1,
                'last_attempt_at' => now(),
            ])->save();

            return $submission->refresh();
        });

        if ($claimed === null) {
            return;
        }

        try {
            $payload = $payloadBuilder->build($claimed);
            $claimed->forceFill(['request_payload' => $payload])->save();
        } catch (LogicException $exception) {
            $claimed->forceFill(['status' => 'configuration_required', 'last_error' => $exception->getMessage()])->save();

            return;
        }

        try {
            $response = $client->saveSales($payload);
        } catch (ConnectionException $exception) {
            $claimed->forceFill([
                'status' => 'manual_review',
                'last_error' => 'The VSCU connection ended without a confirmed response. Reconcile with KRA before any retry.',
            ])->save();

            return;
        } catch (Throwable $exception) {
            report($exception);
            $claimed->forceFill([
                'status' => 'manual_review',
                'last_error' => 'The VSCU response could not be confirmed. Reconcile with KRA before any retry.',
            ])->save();

            return;
        }

        $resultCode = (string) ($response['resultCd'] ?? '');
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        if ($resultCode === '000'
            && filled($data['rcptNo'] ?? null)
            && filled($data['intrlData'] ?? null)
            && filled($data['rcptSign'] ?? null)) {
            $claimed->forceFill([
                'status' => 'submitted',
                'response_payload' => $response,
                'result_code' => $resultCode,
                'result_message' => (string) ($response['resultMsg'] ?? 'Accepted by KRA VSCU'),
                'remote_invoice_number' => $payload['invcNo'],
                'remote_receipt_number' => (string) $data['rcptNo'],
                'internal_data' => (string) $data['intrlData'],
                'receipt_signature' => (string) $data['rcptSign'],
                'sdc_id' => isset($data['sdcId']) ? (string) $data['sdcId'] : null,
                'mrc_number' => isset($data['mrcNo']) ? (string) $data['mrcNo'] : null,
                'submitted_at' => now(),
                'last_error' => null,
            ])->save();

            return;
        }

        $confirmedRejection = filled($resultCode) && $resultCode !== '000';
        $claimed->forceFill([
            'status' => $confirmedRejection ? 'failed' : 'manual_review',
            'response_payload' => $response,
            'result_code' => filled($resultCode) ? $resultCode : null,
            'result_message' => (string) ($response['resultMsg'] ?? ''),
            'last_error' => $confirmedRejection
                ? 'KRA VSCU rejected this submission. Correct the reported issue before an authorized retry.'
                : 'The VSCU response did not include a complete acceptance receipt. Reconcile with KRA before any retry.',
        ])->save();
    }
}
