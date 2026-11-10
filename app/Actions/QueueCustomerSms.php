<?php

namespace App\Actions;

use App\Jobs\SendSmsMessage;
use App\Models\Customer;
use App\Models\CustomOrder;
use App\Models\Hire;
use App\Models\Location;
use App\Models\SmsMessage;
use App\Models\SmsNotificationRule;
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
        $rule = SmsNotificationRule::query()->where('key', $templateKey)->first();
        $category = match (true) {
            str_starts_with($templateKey, 'hire_due'), str_starts_with($templateKey, 'hire_overdue'), str_ends_with($templateKey, '_reminder') => 'reminders',
            str_starts_with($templateKey, 'payment_'), str_starts_with($templateKey, 'invoice_') => 'payments',
            str_starts_with($templateKey, 'custom_order_') => 'orders',
            default => 'transactional',
        };
        $preferences = $customer?->sms_preferences ?? [];
        $blockedReason = match (true) {
            $customer?->sms_opt_out => 'Customer opted out of SMS.',
            $customer?->sms_consent_at === null => 'Customer has not explicitly consented to SMS.',
            blank($customer?->phone) => 'Customer has no phone number.',
            $rule !== null && ! $rule->is_enabled => 'This notification is disabled by an administrator.',
            is_array($preferences) && array_key_exists($category, $preferences) && ! $preferences[$category] => 'Customer has disabled this notification category.',
            $template === null => 'No active SMS template is configured.',
            default => null,
        };
        if ($blockedReason !== null) {
            return SmsMessage::query()->firstOrCreate(['idempotency_key' => $idempotencyKey], [
                'customer_id' => $customer?->id, 'hire_id' => $hire?->id, 'custom_order_id' => $order?->id,
                'requested_by' => $actor?->id, 'location_id' => $location?->id, 'template_id' => $template?->id,
                'recipient' => $customer?->phone ?? '', 'body' => '', 'status' => in_array($blockedReason, ['No active SMS template is configured.'], true) ? 'failed' : 'suppressed',
                'provider_description' => $blockedReason,
            ]);
        }

        $dayLimit = max(1, (int) config('services.sms.customer_daily_limit', 3));
        $sentToday = SmsMessage::query()->where('customer_id', $customer->id)->whereIn('status', ['queued', 'sending', 'sent', 'delivered'])->where('created_at', '>=', now()->startOfDay())->count();
        if ($sentToday >= $dayLimit) {
            return SmsMessage::query()->firstOrCreate(['idempotency_key' => $idempotencyKey], [
                'customer_id' => $customer->id, 'hire_id' => $hire?->id, 'custom_order_id' => $order?->id,
                'requested_by' => $actor?->id, 'location_id' => $location?->id, 'template_id' => $template->id,
                'recipient' => $customer->phone, 'body' => '', 'status' => 'suppressed',
                'provider_description' => 'Customer daily SMS limit reached.',
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
