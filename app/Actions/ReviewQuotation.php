<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\LeadActivity;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewQuotation
{
    public function handle(Quotation $quotation, string $decision, string $reason, User $actor, ?string $ipAddress = null, ?string $userAgent = null): Quotation
    {
        return DB::transaction(function () use ($quotation, $decision, $reason, $actor, $ipAddress, $userAgent): Quotation {
            $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            abort_unless($actor->can('approve', $quotation), 403);
            if ($quotation->status !== 'draft' || $quotation->approval_status !== 'pending') {
                throw ValidationException::withMessages(['approval' => 'Only a pending draft quotation can be reviewed.']);
            }

            $before = ['approval_status' => $quotation->approval_status, 'approved_by' => $quotation->approved_by];
            $approved = $decision === 'approved';
            $quotation->update([
                'approval_status' => $decision,
                'approved_by' => $approved ? $actor->id : null,
                'approved_at' => $approved ? now() : null,
                'approval_reason' => trim($reason),
            ]);
            $action = 'quotation.'.$decision;
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'location_id' => $quotation->location_id,
                'action' => $action,
                'subject_type' => Quotation::class,
                'subject_id' => $quotation->id,
                'before_state' => $before,
                'after_state' => ['approval_status' => $quotation->approval_status, 'approved_by' => $quotation->approved_by, 'revision' => $quotation->revision],
                'reason' => trim($reason),
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'occurred_at' => now(),
            ]);
            LeadActivity::query()->create([
                'lead_id' => $quotation->lead_id,
                'actor_id' => $actor->id,
                'activity_type' => $action,
                'details' => $quotation->reference.' revision '.$quotation->revision.': '.trim($reason),
                'before_state' => $before,
                'after_state' => ['approval_status' => $quotation->approval_status],
                'happened_at' => now(),
            ]);

            return $quotation->refresh();
        }, attempts: 3);
    }
}
