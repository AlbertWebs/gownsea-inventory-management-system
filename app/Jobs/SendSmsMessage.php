<?php

namespace App\Jobs;

use App\Models\SmsMessage;
use App\Services\IntegrationSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendSmsMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(public readonly int $smsMessageId) {}

    public function handle(IntegrationSettings $settings): void
    {
        $message = SmsMessage::query()->find($this->smsMessageId);
        if ($message === null || $message->status !== 'queued') {
            return;
        }

        $customer = $message->customer;
        if ($customer === null || $customer->sms_opt_out || $customer->sms_consent_at === null) {
            $message->update(['status' => 'suppressed', 'provider_description' => 'Customer has no active SMS consent.']);

            return;
        }

        $config = $settings->onfon();
        if (blank($config['client_id']) || blank($config['api_key']) || blank($config['access_key']) || blank($config['sender_id'])) {
            $message->update(['status' => 'failed', 'attempts' => $message->attempts + 1, 'provider_description' => 'SMS provider credentials are not configured.']);

            return;
        }

        $message->update(['status' => 'sending', 'attempts' => $message->attempts + 1]);
        try {
            $response = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(15)
                ->withHeader('AccessKey', $config['access_key'])
                ->post($config['endpoint'], [
                    'SenderId' => $config['sender_id'],
                    'IsUnicode' => preg_match('/[^\x00-\x7F]/u', $message->body) === 1,
                    'IsFlash' => false,
                    'MessageParameters' => [['Number' => $message->recipient, 'Text' => $message->body]],
                    'ApiKey' => $config['api_key'],
                    'ClientId' => $config['client_id'],
                ]);

            $data = $response->json();
            $success = $response->successful() && is_array($data) && (int) ($data['ErrorCode'] ?? -1) === 0;
            $providerMessageId = data_get($data, 'Data.0.MessageId');
            $message->update([
                'status' => $success ? 'sent' : 'failed',
                'provider_message_id' => is_string($providerMessageId) ? $providerMessageId : null,
                'provider_code' => isset($data['ErrorCode']) ? (string) $data['ErrorCode'] : (string) $response->status(),
                'provider_description' => mb_substr((string) ($data['ErrorDescription'] ?? 'SMS provider rejected the request.'), 0, 500),
                'sent_at' => $success ? now() : null,
            ]);
        } catch (ConnectionException|RequestException $exception) {
            $message->update(['status' => 'failed', 'provider_description' => 'Provider connection failed; delivery is unknown. Reconcile with the SMS provider before resending.']);
            Log::warning('SMS provider request failed.', ['sms_message_id' => $message->id, 'exception' => $exception::class]);
        } catch (Throwable $exception) {
            $message->update(['status' => 'failed', 'provider_description' => 'Unexpected SMS processing failure.']);
            report($exception);
        }
    }
}
