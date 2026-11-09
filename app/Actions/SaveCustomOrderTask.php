<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\CustomOrder;
use App\Models\CustomOrderEvent;
use App\Models\CustomOrderTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveCustomOrderTask
{
    /** @param array<string, mixed> $data */
    public function create(CustomOrder $order, array $data, User $actor, ?string $ip, ?string $userAgent): CustomOrderTask
    {
        return DB::transaction(function () use ($order, $data, $actor, $ip, $userAgent): CustomOrderTask {
            $order = CustomOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->ensureOrderIsOpen($order);
            $this->ensureAssigneeInLocation($order, $data['assigned_to'] ?? null);
            $task = $order->tasks()->create([...$data, 'status' => 'todo']);
            $this->record($order, $task, $actor, 'task.created', null, $this->snapshot($task), 'Production task created', $ip, $userAgent);

            return $task;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    public function update(CustomOrder $order, CustomOrderTask $task, array $data, User $actor, ?string $ip, ?string $userAgent): CustomOrderTask
    {
        return DB::transaction(function () use ($order, $task, $data, $actor, $ip, $userAgent): CustomOrderTask {
            $order = CustomOrder::query()->lockForUpdate()->findOrFail($order->id);
            $task = CustomOrderTask::query()->lockForUpdate()->where('custom_order_id', $order->id)->findOrFail($task->id);
            $this->ensureOrderIsOpen($order);
            $this->ensureAssigneeInLocation($order, $data['assigned_to'] ?? null);

            if (! $this->canTransition($task->status, $data['status'])) {
                throw ValidationException::withMessages(['status' => 'That production task transition is not allowed. Completed and cancelled tasks cannot be changed.']);
            }

            $reason = $data['reason'];
            unset($data['reason']);
            $before = $this->snapshot($task);
            $data['completed_at'] = $data['status'] === 'completed' ? ($task->completed_at ?? now()) : null;
            $task->update($data);
            $this->record($order, $task, $actor, 'task.updated', $before, $this->snapshot($task), $reason, $ip, $userAgent);

            return $task->refresh();
        }, attempts: 3);
    }

    private function ensureOrderIsOpen(CustomOrder $order): void
    {
        if (in_array($order->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages(['order' => 'Production tasks cannot change after an order is completed or cancelled.']);
        }
    }

    private function ensureAssigneeInLocation(CustomOrder $order, int|string|null $userId): void
    {
        if ($userId === null) {
            return;
        }

        $assignee = User::query()->whereKey((int) $userId)->where('is_active', true)->firstOrFail();
        if (! $assignee->mayViewAllLocations() && ! $assignee->locations()->whereKey($order->location_id)->exists()) {
            throw ValidationException::withMessages(['assigned_to' => 'Assign this task to staff who can access the order branch.']);
        }
    }

    private function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return ! in_array($from, ['completed', 'cancelled'], true);
        }

        return match ($from) {
            'todo' => in_array($to, ['in_progress', 'blocked', 'cancelled'], true),
            'in_progress' => in_array($to, ['todo', 'blocked', 'completed', 'cancelled'], true),
            'blocked' => in_array($to, ['todo', 'in_progress', 'cancelled'], true),
            default => false,
        };
    }

    /** @return array<string, mixed> */
    private function snapshot(CustomOrderTask $task): array
    {
        return [
            'task_id' => $task->id,
            'task_type' => $task->task_type,
            'title' => $task->title,
            'assigned_to' => $task->assigned_to,
            'due_date' => $task->due_date?->toDateString(),
            'status' => $task->status,
            'completed_at' => $task->completed_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed> $after */
    private function record(CustomOrder $order, CustomOrderTask $task, User $actor, string $type, ?array $before, array $after, string $reason, ?string $ip, ?string $userAgent): void
    {
        CustomOrderEvent::query()->create([
            'custom_order_id' => $order->id,
            'actor_id' => $actor->id,
            'event_type' => $type,
            'reason' => $reason,
            'before_state' => $before,
            'after_state' => $after,
            'occurred_at' => now(),
        ]);
        AuditLog::query()->create([
            'actor_id' => $actor->id,
            'location_id' => $order->location_id,
            'action' => $type,
            'subject_type' => CustomOrderTask::class,
            'subject_id' => $task->id,
            'before_state' => $before,
            'after_state' => $after,
            'reason' => $reason,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'occurred_at' => now(),
        ]);
    }
}
