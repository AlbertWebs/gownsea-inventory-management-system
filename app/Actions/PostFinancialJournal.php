<?php

namespace App\Actions;

use App\Models\ChartAccount;
use App\Models\FinancialJournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class PostFinancialJournal
{
    /** @param list<array{account:string,debit_minor:int,credit_minor:int,description?:string|null,location_id?:int|null}> $lines */
    public function handle(
        string $sourceType,
        int $sourceId,
        string $entryType,
        string $reference,
        string $description,
        ?int $locationId,
        ?int $actorId,
        string $occurredOn,
        array $lines,
        ?int $reversalOfId = null,
        ?string $reversalReason = null,
    ): FinancialJournalEntry {
        if (count($lines) < 2) {
            throw new InvalidArgumentException('A financial journal must contain at least two lines.');
        }

        $normalized = [];
        $debits = 0;
        $credits = 0;
        foreach ($lines as $line) {
            $debit = (int) $line['debit_minor'];
            $credit = (int) $line['credit_minor'];
            if ($debit < 0 || $credit < 0 || (($debit > 0) === ($credit > 0))) {
                throw new InvalidArgumentException('Each financial journal line must have one positive side only.');
            }
            $debits += $debit;
            $credits += $credit;
            $normalized[] = [...$line, 'debit_minor' => $debit, 'credit_minor' => $credit];
        }
        if ($debits <= 0 || $debits !== $credits) {
            throw new InvalidArgumentException('Financial journal debits and credits must balance to a positive amount.');
        }

        return DB::transaction(function () use ($sourceType, $sourceId, $entryType, $reference, $description, $locationId, $actorId, $occurredOn, $normalized, $debits, $credits, $reversalOfId, $reversalReason): FinancialJournalEntry {
            $existing = FinancialJournalEntry::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->where('entry_type', $entryType)->with('lines.account')->first();
            if ($existing !== null) {
                $postedLines = $existing->lines->map(fn ($line): array => [
                    'account' => $line->account->code,
                    'debit_minor' => $line->debit_minor,
                    'credit_minor' => $line->credit_minor,
                    'description' => $line->description,
                    'location_id' => $line->location_id,
                ])->sortBy(fn (array $line): string => $line['account'].'|'.($line['location_id'] ?? ''))->values()->all();
                $requestedLines = collect($normalized)->map(fn (array $line): array => [
                    'account' => $line['account'],
                    'debit_minor' => $line['debit_minor'],
                    'credit_minor' => $line['credit_minor'],
                    'description' => $line['description'] ?? null,
                    'location_id' => $line['location_id'] ?? $locationId,
                ])->sortBy(fn (array $line): string => $line['account'].'|'.($line['location_id'] ?? ''))->values()->all();
                if ($existing->source_reference !== $reference || $existing->debit_total_minor !== $debits || $existing->credit_total_minor !== $credits || $postedLines !== $requestedLines) {
                    throw new LogicException('The journal source was already posted with different amounts.');
                }

                return $existing;
            }

            $accounts = ChartAccount::query()->whereIn('code', collect($normalized)->pluck('account')->unique())->where('is_active', true)->get()->keyBy('code');
            if ($accounts->count() !== collect($normalized)->pluck('account')->unique()->count()) {
                throw new LogicException('A financial journal references an unknown or inactive chart account.');
            }

            $entry = FinancialJournalEntry::query()->create([
                'reference' => 'GJ-'.Carbon::parse($occurredOn)->format('ymd').'-'.Str::upper(Str::random(8)),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_reference' => $reference,
                'entry_type' => $entryType,
                'location_id' => $locationId,
                'posted_by' => $actorId,
                'reversal_of_id' => $reversalOfId,
                'reversal_reason' => $reversalReason,
                'occurred_on' => $occurredOn,
                'description' => $description,
                'debit_total_minor' => $debits,
                'credit_total_minor' => $credits,
                'posted_at' => now(),
            ]);

            $entry->lines()->createMany(collect($normalized)->values()->map(fn (array $line, int $index): array => [
                'chart_account_id' => $accounts[$line['account']]->id,
                'location_id' => $line['location_id'] ?? $locationId,
                'line_number' => $index + 1,
                'debit_minor' => $line['debit_minor'],
                'credit_minor' => $line['credit_minor'],
                'description' => $line['description'] ?? null,
            ])->all());

            return $entry;
        }, attempts: 3);
    }
}
