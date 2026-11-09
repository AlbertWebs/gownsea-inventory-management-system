<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\CustomOrder;
use App\Models\CustomOrderCost;
use App\Models\CustomOrderEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordCustomOrderCost
{
    /** @param array{category:string,description:string,amount:string,incurred_on:string,idempotency_key:string} $data */
    public function handle(CustomOrder $order, array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): CustomOrderCost
    {
        return DB::transaction(function () use ($order, $data, $actor, $ipAddress, $userAgent): CustomOrderCost {
            $order = CustomOrder::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($actor->can('update', $order), 403);
            $amountMinor = $this->toMinorUnits($data['amount']);
            if ($amountMinor <= 0) {
                throw ValidationException::withMessages(['amount' => 'Enter a cost greater than zero.']);
            }

            $existing = CustomOrderCost::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                if ($existing->custom_order_id !== $order->id || $existing->amount_minor !== $amountMinor || $existing->category !== $data['category'] || $existing->description !== trim($data['description']) || $existing->incurred_on->toDateString() !== $data['incurred_on']) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for different cost details.']);
                }

                return $existing;
            }

            $cost = CustomOrderCost::query()->create([
                'custom_order_id' => $order->id,
                'recorded_by' => $actor->id,
                'idempotency_key' => $data['idempotency_key'],
                'category' => $data['category'],
                'description' => trim($data['description']),
                'amount_minor' => $amountMinor,
                'incurred_on' => $data['incurred_on'],
            ]);
            CustomOrderEvent::query()->create([
                'custom_order_id' => $order->id,
                'actor_id' => $actor->id,
                'event_type' => 'cost.recorded',
                'reason' => 'Direct '.$data['category'].' cost recorded',
                'after_state' => ['cost_id' => $cost->id, 'amount_minor' => $amountMinor, 'category' => $cost->category, 'incurred_on' => $cost->incurred_on->toDateString()],
                'occurred_at' => now(),
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'location_id' => $order->location_id,
                'action' => 'custom_order.cost_recorded',
                'subject_type' => CustomOrderCost::class,
                'subject_id' => $cost->id,
                'after_state' => ['custom_order_id' => $order->id, 'amount_minor' => $amountMinor, 'category' => $cost->category, 'incurred_on' => $cost->incurred_on->toDateString()],
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'occurred_at' => now(),
            ]);

            return $cost;
        }, attempts: 3);
    }

    private function toMinorUnits(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
