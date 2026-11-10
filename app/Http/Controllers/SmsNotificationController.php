<?php

namespace App\Http\Controllers;

use App\Jobs\SendSmsMessage;
use App\Models\SmsMessage;
use App\Models\SmsNotificationRule;
use App\Models\SmsTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SmsNotificationController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->mayViewAllLocations(), 403);

        return Inertia::render('Settings/SmsNotifications', [
            'rules' => SmsNotificationRule::query()->orderBy('id')->get(),
            'templates' => SmsTemplate::query()->orderBy('name')->get(),
            'messages' => SmsMessage::query()->with(['customer:id,name', 'template:id,name', 'location:id,name'])->latest()->paginate(25)->through(fn (SmsMessage $message): array => [
                'id' => $message->id,
                'created_at' => $message->created_at?->format('d M Y H:i'),
                'customer' => $message->customer?->name ?? 'Customer record unavailable',
                'recipient' => preg_replace('/(?<=.{3}).(?=.{3})/', '•', $message->recipient),
                'template' => $message->template?->name ?? 'Notification',
                'location' => $message->location?->name ?? '—',
                'status' => $message->status,
                'attempts' => $message->attempts,
                'description' => $message->provider_description,
            ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->mayViewAllLocations(), 403);
        $data = $request->validate([
            'rules' => ['required', 'array'],
            'rules.*.id' => ['required', 'integer', 'exists:sms_notification_rules,id'],
            'rules.*.is_enabled' => ['required', 'boolean'],
            'rules.*.intervals' => ['nullable', 'array', 'max:8'],
            'rules.*.intervals.*' => ['required', 'integer', 'min:1', 'max:365'],
            'templates' => ['required', 'array'],
            'templates.*.id' => ['required', 'integer', 'exists:sms_templates,id'],
            'templates.*.body' => ['required', 'string', 'max:1000'],
            'templates.*.is_active' => ['required', 'boolean'],
        ]);

        foreach ($data['rules'] as $rule) {
            SmsNotificationRule::query()->whereKey($rule['id'])->update([
                'is_enabled' => $rule['is_enabled'],
                'intervals' => isset($rule['intervals']) ? array_values(array_unique(array_map('intval', $rule['intervals']))) : null,
            ]);
        }
        foreach ($data['templates'] as $template) {
            SmsTemplate::query()->whereKey($template['id'])->update(['body' => $template['body'], 'is_active' => $template['is_active'], 'updated_by' => $request->user()->id]);
        }

        return back()->with('success', 'SMS notification settings saved.');
    }

    public function retry(Request $request, SmsMessage $message): RedirectResponse
    {
        abort_unless($request->user()->mayViewAllLocations(), 403);
        abort_unless($message->status === 'failed' && ! str_contains(strtolower((string) $message->provider_description), 'unknown'), 422, 'Only messages confirmed as rejected by the provider can be retried.');
        $retry = DB::transaction(function () use ($message, $request): SmsMessage {
            $count = SmsMessage::query()->where('idempotency_key', 'like', 'sms-retry:'.$message->id.':%')->count() + 1;

            return SmsMessage::query()->firstOrCreate(['idempotency_key' => 'sms-retry:'.$message->id.':'.$count], [
                'customer_id' => $message->customer_id,
                'hire_id' => $message->hire_id,
                'custom_order_id' => $message->custom_order_id,
                'requested_by' => $request->user()->id,
                'location_id' => $message->location_id,
                'template_id' => $message->template_id,
                'recipient' => $message->recipient,
                'body' => $message->body,
                'provider' => $message->provider,
                'status' => 'queued',
                'queued_at' => now(),
            ]);
        });
        if ($retry->wasRecentlyCreated) {
            SendSmsMessage::dispatch($retry->id)->afterCommit();
        }

        return back()->with('success', 'A new SMS retry has been queued.');
    }
}
