<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\OperatingExpense;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DecideOperatingExpense
{
    /** @param array{decision:string,reason?:string|null} $data */
    public function handle(OperatingExpense $expense, array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): OperatingExpense
    {
        return DB::transaction(function () use ($expense, $data, $actor, $ipAddress, $userAgent): OperatingExpense {
            $expense = OperatingExpense::query()->lockForUpdate()->findOrFail($expense->id);
            if ($expense->status !== 'pending') {
                throw ValidationException::withMessages(['expense' => 'Only pending expenses can be approved or rejected.']);
            }
            if ($expense->created_by === $actor->id) {
                throw ValidationException::withMessages(['expense' => 'A staff member cannot approve or reject their own expense.']);
            }

            $before = ['status' => $expense->status, 'decided_by' => $expense->decided_by];
            $expense->update([
                'status' => $data['decision'] === 'approve' ? 'approved' : 'rejected',
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_reason' => filled($data['reason'] ?? null) ? trim($data['reason']) : null,
            ]);

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'location_id' => $expense->location_id,
                'action' => $data['decision'] === 'approve' ? 'finance.expense_approved' : 'finance.expense_rejected',
                'subject_type' => OperatingExpense::class,
                'subject_id' => $expense->id,
                'before_state' => $before,
                'after_state' => ['status' => $expense->status, 'amount_minor' => $expense->amount_minor, 'reason' => $expense->decision_reason],
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'occurred_at' => now(),
            ]);

            return $expense->refresh();
        }, attempts: 3);
    }
}
