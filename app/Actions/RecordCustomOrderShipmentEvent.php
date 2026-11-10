<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\CustomOrder;
use App\Models\CustomOrderEvent;
use App\Models\CustomOrderShipmentEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordCustomOrderShipmentEvent
{
    public function __construct(private readonly QueueCustomerSms $queueSms) {}

    /** @param array{event_type:string,provider_reference?:string|null,details?:string|null,occurred_at:string,idempotency_key:string} $data */
    public function handle(CustomOrder $order, array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): CustomOrderShipmentEvent
    {
        return DB::transaction(function () use ($order, $data, $actor, $ipAddress, $userAgent): CustomOrderShipmentEvent {
            $order = CustomOrder::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($actor->can('update', $order), 403);
            $existing = CustomOrderShipmentEvent::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                if ($existing->custom_order_id !== $order->id || $existing->event_type !== $data['event_type'] || $existing->provider_reference !== ($data['provider_reference'] ?? null) || $existing->details !== (isset($data['details']) ? trim($data['details']) : null) || ! $existing->occurred_at->equalTo(Carbon::parse($data['occurred_at']))) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for different shipment details.']);
                }

                return $existing;
            }
            if (! in_array($order->status, ['dispatched', 'delivered', 'completed'], true)) {
                throw ValidationException::withMessages(['event_type' => 'Shipment events can only be recorded after dispatch.']);
            }

            $event = CustomOrderShipmentEvent::query()->create([
                'custom_order_id' => $order->id,
                'recorded_by' => $actor->id,
                'idempotency_key' => $data['idempotency_key'],
                'event_type' => $data['event_type'],
                'provider_reference' => $data['provider_reference'] ?? null,
                'details' => isset($data['details']) ? trim($data['details']) : null,
                'occurred_at' => $data['occurred_at'],
            ]);
            CustomOrderEvent::query()->create([
                'custom_order_id' => $order->id,
                'actor_id' => $actor->id,
                'event_type' => 'shipment.'.$event->event_type,
                'reason' => $event->details ?? 'Courier shipment update',
                'after_state' => ['shipment_event_id' => $event->id, 'provider_reference' => $event->provider_reference, 'occurred_at' => $event->occurred_at->toIso8601String()],
                'occurred_at' => now(),
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'location_id' => $order->location_id,
                'action' => 'custom_order.shipment_event_recorded',
                'subject_type' => CustomOrderShipmentEvent::class,
                'subject_id' => $event->id,
                'after_state' => ['custom_order_id' => $order->id, 'event_type' => $event->event_type, 'provider_reference' => $event->provider_reference, 'occurred_at' => $event->occurred_at->toIso8601String()],
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'occurred_at' => now(),
            ]);

            $order->loadMissing('customer', 'location');
            $this->queueSms->handle('custom_order_shipment_update', [
                'reference' => $order->reference,
                'status' => str_replace('_', ' ', $event->event_type),
                'tracking' => $event->provider_reference ?: $order->tracking_number ?: 'not provided',
            ], 'custom-order-shipment:'.$event->id, $order->customer, order: $order, location: $order->location, actor: $actor);

            return $event;
        }, attempts: 3);
    }
}
