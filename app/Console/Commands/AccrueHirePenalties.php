<?php

namespace App\Console\Commands;

use App\Actions\RecognizeHirePenalty;
use App\Models\Hire;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gownsea:accrue-hire-penalties')]
#[Description('Post incremental daily late-return penalty accruals using the approved accounting policy')]
class AccrueHirePenalties extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RecognizeHirePenalty $recognize): int
    {
        $created = 0;
        Hire::query()
            ->whereHas('items', fn ($query) => $query->whereIn('status', ['issued', 'partially_returned'])->orWhere('penalty_assessed_minor', '>', 0))
            ->orderBy('id')
            ->chunkById(200, function ($hires) use ($recognize, &$created): void {
                foreach ($hires as $hire) {
                    if ($recognize->handle($hire) !== null) {
                        $created++;
                    }
                }
            });

        $this->info("Posted {$created} incremental late-return penalty accruals.");

        return self::SUCCESS;
    }
}
