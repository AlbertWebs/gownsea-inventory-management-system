<?php

namespace App\Jobs;

use App\Models\SmsMessage;
use App\Models\SmsNotificationRule;
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

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120];

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

        $templateKey = $message->template?->key;
        $category = match (true) {
            str_starts_with((string) $templateKey, 'hire_due'), str_starts_with((string) $templateKey, 'hire_overdue'), str_ends_with((string) $templateKey, '_reminder') => 'reminders',
            str_starts_with((string) $templateKey, 'payment_'), str_starts_with((string) $templateKey, 'invoice_') => 'payments',
            str_starts_with((string) $templateKey, 'custom_order_') => 'orders',
            default => 'transactional',
        };
        $ruleEnabled = $templateKey === null ? null : SmsNotificationRule::query()->where('key', $templateKey)->value('is_enabled');
        if (($customer->sms_preferences[$category] ?? true) === false || ($ruleEnabled !== null && ! (bool) $ruleEnabled) || $message->template?->is_active === false) {
            $message->update(['status' => 'suppressed', 'provider_description' => 'Customer preference or administrator notification rule disabled this message.']);

            return;
        }

        if (in_array($templateKey, ['hire_due', 'hire_overdue'], true) && ! in_array($message->hire?->status, ['issued', 'partially_returned'], true)) {
            $message->update(['status' => 'suppressed', 'provider_description' => 'Hire was returned or closed before the reminder could be sent.']);

            return;
        }
        if (in_array($templateKey, ['invoice_due', 'invoice_overdue'], true)) {
            $invoice = $message->hire?->invoice ?? $message->customOrder?->invoice;
            $outstanding = $invoice === null ? 0 : max(0, $invoice->total_minor - (int) $invoice->allocations()->sum('amount_minor'));
            if ($outstanding === 0) {
                $message->update(['status' => 'suppressed', 'provider_description' => 'The outstanding balance was settled before the reminder could be sent.']);

                return;
            }
        }

        $isTechrise = $message->provider === 'techrise';
        $config = $isTechrise ? $settings->techrise() : $settings->onfon();
        if (blank($config['client_id']) || blank($config['api_key']) || blank($config['sender_id']) || (! $isTechrise && blank($config['access_key']))) {
            $message->update(['status' => 'failed', 'attempts' => $message->attempts + 1, 'provider_description' => 'SMS provider credentials are not configured.']);

            return;
        }

        $message->update(['status' => 'sending', 'attempts' => $message->attempts + 1]);
        try {
            $request = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(15);
            if ($isTechrise) {
                $response = $request->post($config['endpoint'], [
                    'apiKey' => $config['api_key'],
                    'clientId' => $config['client_id'],
                    'senderId' => $config['sender_id'],
                    'message' => $message->body,
                    'mobile' => $this->techriseMobile($message->recipient),
                ]);
            } else {
                $response = $request->withHeader('AccessKey', $config['access_key'])
                    ->post($config['endpoint'], [
                        'SenderId' => $config['sender_id'],
                        'IsUnicode' => preg_match('/[^\x00-\x7F]/u', $message->body) === 1,
                        'IsFlash' => false,
                        'MessageParameters' => [['Number' => $message->recipient, 'Text' => $message->body]],
                        'ApiKey' => $config['api_key'],
                        'ClientId' => $config['client_id'],
                    ]);
            }

            $data = $response->json();
            if ($response->status() === 429 && $this->attempts() < $this->tries) {
                $message->update(['status' => 'queued', 'provider_code' => (string) $response->status(), 'provider_description' => 'Temporary provider response; scheduled for a safe retry.']);
                $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

                return;
            }
            $success = $response->successful() && ($isTechrise || (is_array($data) && (int) ($data['ErrorCode'] ?? -1) === 0));
            $providerMessageId = $isTechrise
                ? (data_get($data, 'messageId') ?? data_get($data, 'data.messageId'))
                : data_get($data, 'Data.0.MessageId');
            $providerMessageId = is_scalar($providerMessageId) ? (string) $providerMessageId : null;
            $unknownDelivery = ! $success && ($response->serverError() || $response->status() === 408);
            $message->update([
                'status' => $success ? 'sent' : ($unknownDelivery ? 'delivery_unknown' : 'failed'),
                'provider_message_id' => $providerMessageId,
                'provider_code' => isset($data['ErrorCode']) ? (string) $data['ErrorCode'] : (string) $response->status(),
                'provider_description' => $success
                    ? 'Accepted by SMS provider.'
                    : mb_substr($unknownDelivery ? 'Provider returned a server or timeout response; delivery is uncertain. Reconcile before retrying.' : (string) ($data['ErrorDescription'] ?? $data['message'] ?? 'SMS provider rejected the request.'), 0, 500),
                'sent_at' => $success ? now() : null,
            ]);
        } catch (ConnectionException|RequestException $exception) {
            $message->update(['status' => 'delivery_unknown', 'provider_description' => 'Provider connection failed; delivery is unknown. Reconcile with the SMS provider before resending.']);
            Log::warning('SMS provider request failed.', ['sms_message_id' => $message->id, 'exception' => $exception::class]);
        } catch (Throwable $exception) {
            $message->update(['status' => 'failed', 'provider_description' => 'Unexpected SMS processing failure.']);
            report($exception);
        }
    }

    public function failed(Throwable $exception): void
    {
        SmsMessage::query()->whereKey($this->smsMessageId)->where('status', 'sending')->update([
            'status' => 'delivery_unknown',
            'provider_description' => 'SMS worker stopped unexpectedly during provider submission. Reconcile with the provider before retrying.',
        ]);
        Log::warning('SMS job exhausted retries while submission was in progress.', ['sms_message_id' => $this->smsMessageId, 'exception' => $exception::class]);
    }

    private function techriseMobile(string $recipient): string
    {
        $digits = preg_replace('/\D+/', '', $recipient) ?? '';

        if (str_starts_with($digits, '0')) {
            return '254'.substr($digits, 1);
        }

        return $digits;
    }
}
