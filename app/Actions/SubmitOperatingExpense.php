<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\Location;
use App\Models\OperatingExpense;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubmitOperatingExpense
{
    /** @param array<string, mixed> $data */
    public function handle(array $data, User $actor, ?string $ipAddress = null, ?string $userAgent = null): OperatingExpense
    {
        $location = Location::query()->whereKey($data['location_id'])->where('is_active', true)->firstOrFail();
        if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($location->id)->exists()) {
            abort(403);
        }

        $amount = self::toMinor((string) $data['amount']);

        return DB::transaction(function () use ($data, $actor, $location, $amount, $ipAddress, $userAgent): OperatingExpense {
            $existing = OperatingExpense::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                $matches = $existing->created_by === $actor->id
                    && $existing->location_id === $location->id
                    && $existing->amount_minor === $amount
                    && $existing->category === $data['category']
                    && $existing->vendor === (filled($data['vendor'] ?? null) ? trim($data['vendor']) : null)
                    && $existing->receipt_reference === (filled($data['receipt_reference'] ?? null) ? trim($data['receipt_reference']) : null)
                    && $existing->incurred_on->toDateString() === $data['incurred_on']
                    && $existing->description === trim($data['description']);
                if (! $matches) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used for a different expense.']);
                }

                return $existing;
            }

            $receiptPath = isset($data['receipt']) ? $data['receipt']->store('operating-expenses', 'local') : null;
            $expense = OperatingExpense::query()->create([
                'reference' => 'EXP-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'idempotency_key' => $data['idempotency_key'],
                'location_id' => $location->id,
                'created_by' => $actor->id,
                'amount_minor' => $amount,
                'category' => $data['category'],
                'vendor' => filled($data['vendor'] ?? null) ? trim($data['vendor']) : null,
                'receipt_reference' => filled($data['receipt_reference'] ?? null) ? trim($data['receipt_reference']) : null,
                'incurred_on' => $data['incurred_on'],
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'receipt_path' => $receiptPath,
                'description' => trim($data['description']),
            ]);

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'location_id' => $location->id,
                'action' => 'finance.expense_submitted',
                'subject_type' => OperatingExpense::class,
                'subject_id' => $expense->id,
                'after_state' => ['reference' => $expense->reference, 'amount_minor' => $amount, 'category' => $expense->category, 'status' => $expense->status],
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'occurred_at' => now(),
            ]);

            return $expense;
        }, attempts: 3);
    }

    private static function toMinor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
