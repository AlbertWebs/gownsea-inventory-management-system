<?php

namespace Tests\Feature;

use App\Actions\QueueCustomerSms;
use App\Jobs\SendSmsMessage;
use App\Models\Customer;
use App\Models\Hire;
use App\Models\Location;
use App\Models\SmsMessage;
use App\Models\SmsNotificationRule;
use App\Models\SmsTemplate;
use App\Services\IntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SmsNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_event_notifications_are_queued_once_and_respect_consent_and_rule_settings(): void
    {
        $customer = Customer::factory()->create(['sms_consent_at' => now(), 'sms_opt_out' => false]);
        $template = SmsTemplate::query()->where('key', 'hire_issued')->firstOrFail();
        $queue = app(QueueCustomerSms::class);

        $first = $queue->handle('hire_issued', ['reference' => 'HIRE-001', 'due_date' => '12 Nov 2026'], 'hire-issued:1', $customer);
        $duplicate = $queue->handle('hire_issued', ['reference' => 'HIRE-001', 'due_date' => '12 Nov 2026'], 'hire-issued:1', $customer);
        $this->assertSame($first->id, $duplicate->id);
        $this->assertDatabaseCount('sms_messages', 1);
        $this->assertSame('queued', $first->status);
        $this->assertStringContainsString('HIRE-001', $first->body);

        SmsNotificationRule::query()->where('key', 'hire_issued')->update(['is_enabled' => false]);
        $disabled = $queue->handle('hire_issued', ['reference' => 'HIRE-002', 'due_date' => '13 Nov 2026'], 'hire-issued:2', $customer);
        $this->assertSame('suppressed', $disabled->status);

        $noConsent = Customer::factory()->create();
        $suppressed = $queue->handle('hire_issued', ['reference' => 'HIRE-003', 'due_date' => '14 Nov 2026'], 'hire-issued:3', $noConsent);
        $this->assertSame('suppressed', $suppressed->status);

        $preferencesOff = Customer::factory()->create(['sms_consent_at' => now(), 'sms_preferences' => ['transactional' => false]]);
        $preferenceSuppressed = $queue->handle('hire_issued', ['reference' => 'HIRE-004', 'due_date' => '15 Nov 2026'], 'hire-issued:4', $preferencesOff);
        $this->assertSame('suppressed', $preferenceSuppressed->status);
        $this->assertNotNull($template);
    }

    public function test_scheduled_hire_reminder_uses_configured_days_and_stops_after_return(): void
    {
        $customer = Customer::factory()->create(['sms_consent_at' => now()]);
        $location = Location::factory()->create();
        $hire = Hire::factory()->create(['customer_id' => $customer->id, 'location_id' => $location->id, 'status' => 'issued', 'due_at' => now()->addDays(3)]);

        $this->artisan('gownsea:queue-hire-sms-reminders')->assertSuccessful();
        $this->assertDatabaseHas('sms_messages', ['hire_id' => $hire->id, 'template_id' => SmsTemplate::query()->where('key', 'hire_due')->value('id'), 'status' => 'queued']);

        $hire->update(['status' => 'completed']);
        $returnedHire = Hire::factory()->create(['customer_id' => $customer->id, 'location_id' => $location->id, 'status' => 'completed', 'due_at' => now()->addDay()]);
        $this->artisan('gownsea:queue-hire-sms-reminders')->assertSuccessful();
        $this->assertDatabaseMissing('sms_messages', ['hire_id' => $returnedHire->id]);
    }

    public function test_queued_reminder_is_cancelled_before_sending_when_hire_is_closed(): void
    {
        $customer = Customer::factory()->create(['sms_consent_at' => now()]);
        $hire = Hire::factory()->create(['customer_id' => $customer->id, 'status' => 'completed']);
        $template = SmsTemplate::query()->where('key', 'hire_due')->firstOrFail();
        $message = SmsMessage::query()->create([
            'idempotency_key' => 'closed-hire-reminder:'.$hire->id,
            'customer_id' => $customer->id,
            'hire_id' => $hire->id,
            'location_id' => $hire->location_id,
            'template_id' => $template->id,
            'recipient' => $customer->phone,
            'body' => 'Reminder',
            'provider' => 'onfon',
            'status' => 'queued',
        ]);

        (new SendSmsMessage($message->id))->handle(app(IntegrationSettings::class));
        $this->assertSame('suppressed', $message->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_provider_throttle_is_retried_without_creating_a_second_outbox_message(): void
    {
        config(['services.onfon' => ['endpoint' => 'https://sms.example.test/send', 'client_id' => 'test-client', 'api_key' => 'test-key', 'access_key' => 'test-access', 'sender_id' => 'GOWNSEA']]);
        config(['services.sms.provider' => 'onfon']);
        Http::fake(['sms.example.test/*' => Http::response(['ErrorCode' => 429, 'ErrorDescription' => 'Rate limited'], 429)]);
        $customer = Customer::factory()->create(['sms_consent_at' => now()]);
        $template = SmsTemplate::query()->where('key', 'payment_received')->firstOrFail();
        $message = SmsMessage::query()->create([
            'idempotency_key' => 'payment-received:100', 'customer_id' => $customer->id, 'template_id' => $template->id,
            'recipient' => $customer->phone, 'body' => 'Receipt received', 'provider' => 'onfon', 'status' => 'queued',
        ]);

        (new SendSmsMessage($message->id))->handle(app(IntegrationSettings::class));
        $this->assertSame('queued', $message->fresh()->status);
        $this->assertSame(1, $message->fresh()->attempts);
        $this->assertDatabaseCount('sms_messages', 1);
        Http::assertSentCount(1);
    }

    public function test_delivery_report_updates_message_state_and_requires_the_private_callback_token(): void
    {
        $token = 'sms-dlr-callback-private-long-token-2026';
        config(['services.sms.onfon_dlr_token' => $token]);
        $message = SmsMessage::query()->create([
            'idempotency_key' => 'dlr-message-1', 'recipient' => '+254700000001', 'body' => 'Message',
            'provider' => 'onfon', 'status' => 'sent', 'provider_message_id' => 'ONFON-123', 'sent_at' => now(),
        ]);

        $this->get('/webhooks/sms/onfon/'.$token.'?messageId=ONFON-123&status=DELIVERED')
            ->assertOk()->assertSeeText('OK');
        $this->assertSame('delivered', $message->fresh()->status);
        $this->assertNotNull($message->fresh()->delivered_at);
        $this->get('/webhooks/sms/onfon/wrong-private-token-which-is-long-enough?messageId=ONFON-123&status=FAILED')->assertNotFound();
        $this->assertSame('delivered', $message->fresh()->status);
    }
}
