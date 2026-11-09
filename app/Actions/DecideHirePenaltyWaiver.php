<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\BusinessConfiguration;
use App\Models\PenaltyWaiverRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DecideHirePenaltyWaiver
{
    public function __construct(private readonly WaiveHirePenalty $waive) {}

    /** @param array{status:string,decision_reason?:string|null} $data */
    public function handle(PenaltyWaiverRequest $candidate, array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): PenaltyWaiverRequest
    {
        return DB::transaction(function () use ($candidate, $data, $actor, $ipAddress, $userAgent): PenaltyWaiverRequest {
            $request = PenaltyWaiverRequest::query()->lockForUpdate()->findOrFail($candidate->id);
            if ($request->status !== 'pending') {
                if ($request->status === $data['status'] && $request->approved_by === $actor->id) {
                    return $request;
                }
                throw ValidationException::withMessages(['status' => 'This waiver request has already been decided.']);
            }
            if ($request->requested_by === $actor->id) {
                throw ValidationException::withMessages(['status' => 'A different staff member must decide this waiver request.']);
            }
            if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($request->location_id)->exists()) {
                abort(403);
            }
            if ($data['status'] === 'approved') {
                $configuration = BusinessConfiguration::query()->where('configuration_key', 'penalty_accounting')->where('review_status', 'approved')
                    ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()->toDateString()))
                    ->orderByDesc('effective_from')->orderByDesc('id')->first();
                if (($configuration?->configuration['independent_approval_required'] ?? true) !== true) {
                    throw ValidationException::withMessages(['status' => 'Independent approval is disabled. Use the configured direct waiver process.']);
                }
                $this->waive->handle([
                    'hire_id' => $request->hire_id,
                    'amount' => number_format($request->amount_minor / 100, 2, '.', ''),
                    'reason' => $request->reason,
                    'idempotency_key' => $request->idempotency_key,
                ], $actor, $ipAddress, $userAgent);
            }
            $request->update([
                'status' => $data['status'], 'approved_by' => $actor->id,
                'decision_reason' => $data['decision_reason'] ?? null, 'decided_at' => now(),
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $request->location_id,
                'action' => 'hire.penalty_waiver_'.$data['status'], 'subject_type' => PenaltyWaiverRequest::class, 'subject_id' => $request->id,
                'before_state' => ['status' => 'pending'], 'after_state' => ['status' => $request->status, 'requested_by' => $request->requested_by],
                'reason' => $data['decision_reason'] ?? $request->reason, 'ip_address' => $ipAddress, 'user_agent' => $userAgent, 'occurred_at' => now(),
            ]);

            return $request;
        }, attempts: 3);
    }
}
