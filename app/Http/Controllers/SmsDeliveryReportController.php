<?php

namespace App\Http\Controllers;

use App\Models\SmsMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SmsDeliveryReportController extends Controller
{
    public function __invoke(Request $request, string $token): Response
    {
        $configuredToken = (string) config('services.sms.onfon_dlr_token');
        abort_if($configuredToken === '' || ! hash_equals($configuredToken, $token), 404);

        $providerId = (string) ($request->query('messageId') ?? $request->query('MessageId') ?? '');
        abort_if($providerId === '', 422, 'Missing message identifier.');
        $message = SmsMessage::query()->where('provider_message_id', $providerId)->firstOrFail();
        $providerStatus = strtoupper((string) ($request->query('status') ?? ''));
        $delivered = in_array($providerStatus, ['DELIVERED', 'SUCCESS'], true);
        $failed = in_array($providerStatus, ['FAILED', 'REJECTED', 'EXPIRED', 'UNDELIVERED'], true);
        if (($delivered || $failed) && in_array($message->status, ['sent', 'delivery_unknown', 'failed'], true)) {
            $message->update([
                'status' => $delivered ? 'delivered' : 'failed',
                'delivered_at' => $delivered ? now() : null,
                'provider_code' => (string) ($request->query('errorCode') ?? $message->provider_code ?? ''),
                'provider_description' => mb_substr((string) ($request->query('shortMessage') ?? $providerStatus), 0, 500),
            ]);
        }

        return response('OK', 200);
    }
}
