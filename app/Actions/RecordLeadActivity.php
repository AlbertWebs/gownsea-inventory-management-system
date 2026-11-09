<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecordLeadActivity
{
    /** @param array{activity_type:string,details:string,happened_at?:string|null} $data */
    public function handle(Lead $lead, array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): LeadActivity
    {
        return DB::transaction(function () use ($lead, $data, $actor, $ipAddress, $userAgent): LeadActivity {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);
            abort_unless($actor->can('update', $lead), 403);

            $activity = LeadActivity::query()->create([
                'lead_id' => $lead->id,
                'actor_id' => $actor->id,
                'activity_type' => 'conversation.'.$data['activity_type'],
                'details' => trim($data['details']),
                'happened_at' => $data['happened_at'] ?? now(),
            ]);

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'location_id' => $lead->location_id,
                'action' => 'lead.conversation_recorded',
                'subject_type' => LeadActivity::class,
                'subject_id' => $activity->id,
                'after_state' => ['lead_id' => $lead->id, 'activity_type' => $activity->activity_type, 'happened_at' => $activity->happened_at->toIso8601String()],
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'occurred_at' => now(),
            ]);

            return $activity;
        }, attempts: 3);
    }
}
