<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveLeadTask
{
    /** @param array{title:string,description?:string|null,due_at:string,assigned_to?:int|string|null} $data */
    public function create(Lead $lead, array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): LeadTask
    {
        return DB::transaction(function () use ($lead, $data, $actor, $ipAddress, $userAgent): LeadTask {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);
            abort_unless($actor->can('update', $lead), 403);
            $assigneeId = filled($data['assigned_to'] ?? null) ? (int) $data['assigned_to'] : null;
            if ($assigneeId !== null && ! User::query()->whereKey($assigneeId)->where('is_active', true)->whereHas('locations', fn ($query) => $query->whereKey($lead->location_id))->exists()) {
                throw ValidationException::withMessages(['assigned_to' => 'Choose an active staff member assigned to this lead’s branch.']);
            }

            $task = LeadTask::query()->create([
                'lead_id' => $lead->id,
                'assigned_to' => $assigneeId,
                'created_by' => $actor->id,
                'title' => trim($data['title']),
                'description' => isset($data['description']) ? trim($data['description']) : null,
                'due_at' => $data['due_at'],
            ]);
            LeadActivity::query()->create([
                'lead_id' => $lead->id,
                'actor_id' => $actor->id,
                'activity_type' => 'task.created',
                'details' => $task->title,
                'after_state' => ['task_id' => $task->id, 'assigned_to' => $task->assigned_to, 'due_at' => $task->due_at->toIso8601String()],
                'happened_at' => now(),
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'location_id' => $lead->location_id,
                'action' => 'lead.task_created',
                'subject_type' => LeadTask::class,
                'subject_id' => $task->id,
                'after_state' => ['lead_id' => $lead->id, 'title' => $task->title, 'assigned_to' => $task->assigned_to, 'due_at' => $task->due_at->toIso8601String()],
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'occurred_at' => now(),
            ]);

            return $task;
        }, attempts: 3);
    }

    public function complete(Lead $lead, LeadTask $task, User $actor, ?string $ipAddress = null, ?string $userAgent = null): LeadTask
    {
        return DB::transaction(function () use ($lead, $task, $actor, $ipAddress, $userAgent): LeadTask {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);
            abort_unless($actor->can('update', $lead), 403);
            $task = LeadTask::query()->where('lead_id', $lead->id)->lockForUpdate()->findOrFail($task->id);
            if ($task->completed_at !== null) {
                return $task;
            }

            $task->update(['completed_at' => now(), 'completed_by' => $actor->id]);
            LeadActivity::query()->create([
                'lead_id' => $lead->id,
                'actor_id' => $actor->id,
                'activity_type' => 'task.completed',
                'details' => $task->title,
                'after_state' => ['task_id' => $task->id, 'completed_at' => $task->completed_at->toIso8601String()],
                'happened_at' => now(),
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'location_id' => $lead->location_id,
                'action' => 'lead.task_completed',
                'subject_type' => LeadTask::class,
                'subject_id' => $task->id,
                'after_state' => ['lead_id' => $lead->id, 'completed_by' => $actor->id, 'completed_at' => $task->completed_at->toIso8601String()],
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'occurred_at' => now(),
            ]);

            return $task->refresh();
        }, attempts: 3);
    }
}
