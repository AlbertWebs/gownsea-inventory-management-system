<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\FinancialJournalEntry;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReverseFinancialJournalEntry
{
    public function __construct(private readonly PostFinancialJournal $journal) {}

    public function handle(FinancialJournalEntry $candidate, string $reason, User $actor): FinancialJournalEntry
    {
        abort_unless(in_array($actor->role, ['super_admin', 'management', 'finance'], true), 403);

        return DB::transaction(function () use ($candidate, $reason, $actor): FinancialJournalEntry {
            $entry = FinancialJournalEntry::query()->with('lines.account')->lockForUpdate()->findOrFail($candidate->id);
            if ($entry->source_type === StockMovement::class && $entry->entry_type === 'inventory_valuation') {
                throw ValidationException::withMessages(['entry' => 'Inventory valuation entries are locked to stock movements. Record a stock correction or return to update both the journal and inventory balance.']);
            }
            if ($entry->reversal_of_id !== null) {
                throw ValidationException::withMessages(['entry' => 'A reversal entry cannot itself be reversed.']);
            }
            if (FinancialJournalEntry::query()->where('reversal_of_id', $entry->id)->exists()) {
                throw ValidationException::withMessages(['entry' => 'This journal entry already has a reversal.']);
            }
            if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($entry->location_id)->exists()) {
                abort(403);
            }

            $reversal = $this->journal->handle(
                FinancialJournalEntry::class,
                $entry->id,
                'reversal',
                $entry->reference,
                'Reversal of '.$entry->reference,
                $entry->location_id,
                $actor->id,
                now()->toDateString(),
                $entry->lines->map(fn ($line): array => [
                    'account' => $line->account->code,
                    'debit_minor' => $line->credit_minor,
                    'credit_minor' => $line->debit_minor,
                    'description' => 'Reversal: '.$line->description,
                ])->all(),
                $entry->id,
                trim($reason),
            );

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'location_id' => $entry->location_id,
                'action' => 'finance.journal_reversed',
                'subject_type' => FinancialJournalEntry::class,
                'subject_id' => $reversal->id,
                'before_state' => ['entry_reference' => $entry->reference, 'debit_minor' => $entry->debit_total_minor, 'credit_minor' => $entry->credit_total_minor],
                'after_state' => ['reversal_reference' => $reversal->reference, 'reversal_of_id' => $entry->id],
                'reason' => trim($reason),
                'occurred_at' => now(),
            ]);

            return $reversal;
        }, attempts: 3);
    }
}
