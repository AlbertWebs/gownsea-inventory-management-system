<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\CustomOrder;
use App\Models\CustomOrderEvent;
use App\Models\CustomOrderFitting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageCustomOrderFitting
{
    /** @param array{appointment_at:string,assigned_to?:int|string|null} $data */
    public function schedule(CustomOrder $order, array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): CustomOrderFitting
    {
        return DB::transaction(function () use ($order, $data, $actor, $ipAddress, $userAgent): CustomOrderFitting {
            $order = CustomOrder::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($actor->can('update', $order), 403);
            if ($order->status === 'cancelled') {
                throw ValidationException::withMessages(['custom_order' => 'Cancelled orders cannot have fitting appointments.']);
            }
            $assigneeId = filled($data['assigned_to'] ?? null) ? (int) $data['assigned_to'] : null;
            if ($assigneeId !== null && ! User::query()->whereKey($assigneeId)->where('is_active', true)->whereHas('locations', fn ($query) => $query->whereKey($order->location_id))->exists()) {
                throw ValidationException::withMessages(['assigned_to' => 'Choose active staff assigned to this order’s branch.']);
            }

            $fitting = CustomOrderFitting::query()->create([
                'custom_order_id' => $order->id,
                'assigned_to' => $assigneeId,
                'scheduled_by' => $actor->id,
                'appointment_at' => $data['appointment_at'],
                'status' => 'scheduled',
            ]);
            CustomOrderEvent::query()->create([
                'custom_order_id' => $order->id,
                'actor_id' => $actor->id,
                'event_type' => 'fitting.scheduled',
                'reason' => 'Fitting appointment scheduled',
                'after_state' => ['fitting_id' => $fitting->id, 'appointment_at' => $fitting->appointment_at->toIso8601String(), 'assigned_to' => $assigneeId],
                'occurred_at' => now(),
            ]);
            $this->audit($order, $fitting, $actor, 'custom_order.fitting_scheduled', null, ['status' => 'scheduled', 'appointment_at' => $fitting->appointment_at->toIso8601String(), 'assigned_to' => $assigneeId], $ipAddress, $userAgent);

            return $fitting;
        }, attempts: 3);
    }

    /** @param array{status:string,measurements?:array<string,string>|null,outcome?:string|null} $data */
    public function update(CustomOrder $order, CustomOrderFitting $fitting, array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): CustomOrderFitting
    {
        return DB::transaction(function () use ($order, $fitting, $data, $actor, $ipAddress, $userAgent): CustomOrderFitting {
            $order = CustomOrder::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($actor->can('update', $order), 403);
            $fitting = CustomOrderFitting::query()->where('custom_order_id', $order->id)->lockForUpdate()->findOrFail($fitting->id);
            if ($fitting->status !== 'scheduled') {
                throw ValidationException::withMessages(['status' => 'Only scheduled fittings can be completed, cancelled or marked no-show.']);
            }
            $before = ['status' => $fitting->status, 'appointment_at' => $fitting->appointment_at->toIso8601String()];
            $fitting->update([
                'status' => $data['status'],
                'measurements' => $data['measurements'] ?? null,
                'outcome' => isset($data['outcome']) ? trim($data['outcome']) : null,
                'completed_at' => $data['status'] === 'completed' ? now() : null,
            ]);
            CustomOrderEvent::query()->create([
                'custom_order_id' => $order->id,
                'actor_id' => $actor->id,
                'event_type' => 'fitting.'.$data['status'],
                'reason' => $data['outcome'] ?? 'Fitting '.$data['status'],
                'before_state' => $before,
                'after_state' => ['fitting_id' => $fitting->id, 'status' => $fitting->status, 'measurements' => $fitting->measurements, 'outcome' => $fitting->outcome],
                'occurred_at' => now(),
            ]);
            $this->audit($order, $fitting, $actor, 'custom_order.fitting_'.$data['status'], $before, ['status' => $fitting->status, 'measurements' => $fitting->measurements], $ipAddress, $userAgent);

            return $fitting->refresh();
        }, attempts: 3);
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed> $after */
    private function audit(CustomOrder $order, CustomOrderFitting $fitting, User $actor, string $action, ?array $before, array $after, ?string $ipAddress, ?string $userAgent): void
    {
        AuditLog::query()->create([
            'actor_id' => $actor->id,
            'location_id' => $order->location_id,
            'action' => $action,
            'subject_type' => CustomOrderFitting::class,
            'subject_id' => $fitting->id,
            'before_state' => $before,
            'after_state' => ['custom_order_id' => $order->id, ...$after],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'occurred_at' => now(),
        ]);
    }
}
