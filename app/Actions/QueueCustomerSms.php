<?php

namespace App\Actions;

use App\Jobs\SendSmsMessage;
use App\Models\Customer;
use App\Models\CustomOrder;
use App\Models\Hire;
use App\Models\Location;
use App\Models\SmsMessage;
use App\Models\SmsTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QueueCustomerSms
{
    /** @param array<string,string> $values */
    public function handle(string $templateKey, array $values, string $idempotencyKey, ?Customer $customer = null, ?Hire $hire = null, ?CustomOrder $order = null, ?Location $location = null, ?User $actor = null): SmsMessage
    {
        $existing = SmsMessage::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $template = SmsTemplate::query()->where('key', $templateKey)->where('is_active', true)->first();
        if ($template === null || $customer?->sms_opt_out || $customer?->sms_consent_at === null || blank($customer?->phone)) {
            return SmsMessage::query()->firstOrCreate(['idempotency_key' => $idempotencyKey], [
                'customer_id' => $customer?->id, 'hire_id' => $hire?->id, 'custom_order_id' => $order?->id,
                'requested_by' => $actor?->id, 'location_id' => $location?->id, 'template_id' => $template?->id,
                'recipient' => $customer?->phone ?? '', 'body' => '', 'status' => ($customer?->sms_opt_out || $customer?->sms_consent_at === null) ? 'suppressed' : 'failed',
                'provider_description' => $customer?->sms_opt_out ? 'Customer opted out of SMS.' : ($customer?->sms_consent_at === null ? 'Customer has not explicitly consented to SMS.' : 'No active template or customer phone number.'),
            ]);
        }

        $expected = $template->placeholders ?? [];
        if (array_diff($expected, array_keys($values)) !== []) {
            throw ValidationException::withMessages(['template' => 'The SMS template is missing required values.']);
        }
        $body = preg_replace_callback('/\{([a-z_]+)\}/', fn (array $match): string => (string) ($values[$match[1]] ?? $match[0]), $template->body);

        return DB::transaction(function () use ($idempotencyKey, $customer, $hire, $order, $location, $actor, $template, $body): SmsMessage {
            $message = SmsMessage::query()->firstOrCreate(['idempotency_key' => $idempotencyKey], [
                'customer_id' => $customer?->id, 'hire_id' => $hire?->id, 'custom_order_id' => $order?->id,
                'requested_by' => $actor?->id, 'location_id' => $location?->id, 'template_id' => $template->id,
                'recipient' => $customer->phone, 'body' => $body, 'provider' => config('services.sms.provider', 'techrise'), 'status' => 'queued', 'queued_at' => now(),
            ]);
            if ($message->wasRecentlyCreated) {
                SendSmsMessage::dispatch($message->id)->afterCommit();
            }

            return $message;
        });
    }
}
