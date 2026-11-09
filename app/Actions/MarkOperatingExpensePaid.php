<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\OperatingExpense;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarkOperatingExpensePaid
{
    /** @param array{payment_method:string,payment_reference?:string|null,paid_on:string} $data */
    public function handle(OperatingExpense $candidate, array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): OperatingExpense
    {
        abort_unless(in_array($actor->role, ['super_admin', 'management', 'finance'], true), 403);

        return DB::transaction(function () use ($candidate, $data, $actor, $ipAddress, $userAgent): OperatingExpense {
            $expense = OperatingExpense::query()->lockForUpdate()->findOrFail($candidate->id);
            if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($expense->location_id)->exists()) {
                abort(403);
            }
            if ($expense->status !== 'approved' || $expense->payment_status !== 'unpaid') {
                throw ValidationException::withMessages(['expense' => 'Only approved, unpaid expenses can be marked paid.']);
            }

            $expense->update([
                'payment_status' => 'paid', 'paid_by' => $actor->id, 'payment_method' => $data['payment_method'],
                'payment_reference' => filled($data['payment_reference'] ?? null) ? trim($data['payment_reference']) : null,
                'paid_on' => $data['paid_on'],
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'location_id' => $expense->location_id, 'action' => 'finance.expense_paid',
                'subject_type' => OperatingExpense::class, 'subject_id' => $expense->id,
                'before_state' => ['payment_status' => 'unpaid'],
                'after_state' => ['payment_status' => 'paid', 'amount_minor' => $expense->amount_minor, 'payment_method' => $expense->payment_method, 'payment_reference' => $expense->payment_reference],
                'ip_address' => $ipAddress, 'user_agent' => $userAgent, 'occurred_at' => now(),
            ]);

            return $expense->refresh();
        }, attempts: 3);
    }
}
