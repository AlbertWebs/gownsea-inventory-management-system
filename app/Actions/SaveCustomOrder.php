<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomOrder;
use App\Models\CustomOrderDepositApplication;
use App\Models\CustomOrderEvent;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaveCustomOrder
{
    public function __construct(private readonly QueueCustomerSms $queueSms, private readonly IssueCustomOrderInvoice $invoice) {}

    /** @param array<string,mixed> $data */
    public function create(array $data, User $actor, ?string $ip = null, ?string $userAgent = null): CustomOrder
    {
        return DB::transaction(function () use ($data, $actor, $ip, $userAgent): CustomOrder {
            $this->authorizeLocation((int) $data['location_id'], $actor);
            $customer = Customer::query()->findOrFail($data['customer_id']);
            if (! $customer->locations()->whereKey($data['location_id'])->exists()) {
                throw ValidationException::withMessages(['customer_id' => 'Assign this customer to the selected branch before creating the order.']);
            }
            $data['institution_id'] = ($data['institution_id'] ?? null) ?: $customer->institution_id;
            $data['agreed_total_minor'] = $this->toMinorUnits($data['agreed_total']);
            $data['deposit_required_minor'] = $this->toMinorUnits($data['deposit_required'] ?? '0');
            if ($data['agreed_total_minor'] <= 0) {
                throw ValidationException::withMessages(['agreed_total' => 'The agreed order total must be greater than zero.']);
            }
            if ($data['deposit_required_minor'] > $data['agreed_total_minor']) {
                throw ValidationException::withMessages(['deposit_required' => 'The required deposit cannot exceed the agreed order total.']);
            }
            unset($data['agreed_total'], $data['deposit_required']);
            $order = CustomOrder::query()->create([...$data, 'reference' => 'CO-'.now()->format('ymd').'-'.Str::upper(Str::random(6)), 'created_by' => $actor->id, 'owner_id' => $actor->id, 'order_date' => $data['order_date']]);
            $this->record($order, $actor, 'order.created', null, $this->snapshot($order), null, $ip, $userAgent);
            $this->queueSms->handle(
                'custom_order_confirmation',
                ['reference' => $order->reference, 'item' => $order->item_type, 'expected_date' => $order->expected_completion_date?->format('d M Y') ?? 'to be confirmed'],
                'custom-order-created:'.$order->id,
                Customer::query()->findOrFail($order->customer_id),
                order: $order,
                location: Location::query()->findOrFail($order->location_id),
                actor: $actor,
            );

            return $order;
        }, attempts: 3);
    }

    /** @param array<string,mixed> $data */
    public function update(CustomOrder $order, array $data, User $actor, ?string $ip = null, ?string $userAgent = null): CustomOrder
    {
        return DB::transaction(function () use ($order, $data, $actor, $ip, $userAgent): CustomOrder {
            $order = CustomOrder::query()->lockForUpdate()->findOrFail($order->id);
            $previousStatus = $order->status;
            $this->authorizeLocation($order->location_id, $actor);
            if (! $this->canTransition($order->status, $data['status'])) {
                throw ValidationException::withMessages(['status' => 'That custom order transition is not allowed.']);
            }
            if (in_array($order->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['status' => 'Completed and cancelled orders are closed.']);
            }
            if ($actor->role === 'production' && $this->productionChangesForbidden($order, $data)) {
                abort(403);
            }
            if ($actor->role === 'production' && $data['status'] === 'cancelled') {
                abort(403);
            }
            if ($data['status'] === 'cancelled') {
                $depositReceived = (int) Payment::query()->where('custom_order_id', $order->id)->where('purpose', 'custom_order_deposit')->where('status', 'received')->sum('amount_minor');
                $depositRefunded = (int) PaymentRefund::query()->whereHas('payment', fn ($query) => $query->where('custom_order_id', $order->id)->where('purpose', 'custom_order_deposit'))->sum('amount_minor');
                $depositApplied = (int) CustomOrderDepositApplication::query()->where('custom_order_id', $order->id)->sum('amount_minor');
                if ($depositReceived > $depositRefunded + $depositApplied) {
                    throw ValidationException::withMessages(['status' => 'Refund the remaining custom order deposit before cancelling this order.']);
                }
            }
            $reason = $data['reason'];
            $before = $this->snapshot($order);
            if (isset($data['measurements']) && $data['measurements'] === []) {
                $data['measurements'] = null;
            }
            $data['agreed_total_minor'] = $this->toMinorUnits($data['agreed_total']);
            $data['deposit_required_minor'] = $this->toMinorUnits($data['deposit_required'] ?? '0');
            if ($data['agreed_total_minor'] <= 0) {
                throw ValidationException::withMessages(['agreed_total' => 'The agreed order total must be greater than zero.']);
            }
            if ($data['deposit_required_minor'] > $data['agreed_total_minor']) {
                throw ValidationException::withMessages(['deposit_required' => 'The required deposit cannot exceed the agreed order total.']);
            }
            unset($data['agreed_total'], $data['deposit_required'], $data['reason']);
            $now = now();
            if ($data['status'] === 'dispatched' && ! $order->dispatched_at) {
                $data['dispatched_at'] = $now;
            }
            if ($data['status'] === 'delivered' && ! $order->delivered_at) {
                $data['delivered_at'] = $now;
            }
            if ($data['status'] === 'completed' && ! $order->completed_at) {
                $data['completed_at'] = $now;
            }
            $order->update($data);
            $this->record($order, $actor, 'order.updated', $before, $this->snapshot($order), $reason, $ip, $userAgent);
            $this->queueStatusSms($order, $previousStatus, $actor);

            if ($previousStatus !== 'completed' && $order->status === 'completed') {
                $this->invoice->handle($order, $actor);
            }

            return $order->refresh();
        }, attempts: 3);
    }

    private function queueStatusSms(CustomOrder $order, string $previousStatus, User $actor): void
    {
        if ($previousStatus === $order->status) {
            return;
        }

        $template = match ($order->status) {
            'ready_for_collection' => 'custom_order_ready',
            'dispatched' => 'custom_order_dispatched',
            'delivered' => 'custom_order_delivered',
            default => null,
        };
        if ($template === null) {
            return;
        }

        $values = match ($order->status) {
            'dispatched' => ['reference' => $order->reference, 'tracking' => $order->tracking_number ?: 'not provided'],
            default => ['reference' => $order->reference],
        };
        $this->queueSms->handle(
            $template,
            $values,
            "custom-order-status:{$order->id}:{$order->status}",
            Customer::query()->findOrFail($order->customer_id),
            order: $order,
            location: Location::query()->findOrFail($order->location_id),
            actor: $actor,
        );
    }

    private function authorizeLocation(int $locationId, User $actor): void
    {
        if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($locationId)->exists()) {
            abort(403);
        }
    }

    private function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return match ($from) {
            'new' => in_array($to, ['quoted', 'cancelled'], true),
            'quoted' => in_array($to, ['in_production', 'cancelled'], true),
            'in_production' => in_array($to, ['ready_for_collection', 'cancelled'], true),
            'ready_for_collection' => in_array($to, ['dispatched', 'delivered', 'completed', 'cancelled'], true),
            'dispatched' => $to === 'delivered',
            'delivered' => $to === 'completed',
            default => false,
        };
    }

    /** @param array<string,mixed> $data */
    private function productionChangesForbidden(CustomOrder $order, array $data): bool
    {
        foreach (['item_type', 'size', 'color', 'quantity', 'measurements', 'design_specs', 'special_requirements', 'agreed_total', 'deposit_required', 'expected_completion_date', 'priority', 'delivery_method', 'delivery_address'] as $field) {
            $current = $field === 'agreed_total' ? number_format($order->agreed_total_minor / 100, 2, '.', '') : ($field === 'deposit_required' ? number_format($order->deposit_required_minor / 100, 2, '.', '') : $order->{$field});
            $incoming = $data[$field] ?? null;
            if ($field === 'measurements') {
                $incoming = $incoming === [] ? null : $incoming;
                if (is_array($incoming) && is_array($current)) {
                    ksort($incoming);
                    ksort($current);
                }
                if (json_encode($incoming) !== json_encode($current)) {
                    return true;
                }

                continue;
            }
            if ($field === 'expected_completion_date') {
                $current = $order->expected_completion_date?->toDateString();
            }
            if (in_array($field, ['agreed_total', 'deposit_required'], true)) {
                $incoming = number_format($this->toMinorUnits((string) $incoming) / 100, 2, '.', '');
            }
            if ((string) ($incoming ?? '') !== (string) ($current ?? '')) {
                return true;
            }
        }

        return false;
    }

    private function toMinorUnits(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    /** @return array<string,mixed> */
    private function snapshot(CustomOrder $order): array
    {
        return ['reference' => $order->reference, 'location_id' => $order->location_id, 'customer_id' => $order->customer_id, 'institution_id' => $order->institution_id, 'status' => $order->status, 'item_type' => $order->item_type, 'quantity' => $order->quantity, 'agreed_total_minor' => $order->agreed_total_minor, 'deposit_required_minor' => $order->deposit_required_minor, 'expected_completion_date' => $order->expected_completion_date?->toDateString(), 'priority' => $order->priority, 'delivery_method' => $order->delivery_method, 'courier' => $order->courier, 'tracking_number' => $order->tracking_number];
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed> $after */
    private function record(CustomOrder $order, User $actor, string $type, ?array $before, array $after, ?string $reason, ?string $ip, ?string $userAgent): void
    {
        CustomOrderEvent::query()->create(['custom_order_id' => $order->id, 'actor_id' => $actor->id, 'event_type' => $type, 'reason' => $reason, 'before_state' => $before, 'after_state' => $after, 'occurred_at' => now()]);
        AuditLog::query()->create(['actor_id' => $actor->id, 'location_id' => $order->location_id, 'action' => $type, 'subject_type' => CustomOrder::class, 'subject_id' => $order->id, 'before_state' => $before, 'after_state' => $after, 'reason' => $reason, 'ip_address' => $ip, 'user_agent' => $userAgent, 'occurred_at' => now()]);
    }
}
