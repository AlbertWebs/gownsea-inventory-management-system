<?php

namespace App\Console\Commands;

use App\Services\EncryptedBackupService;
use Illuminate\Console\Command;
use Throwable;

class CreateGownseaBackup extends Command
{
    protected $signature = 'gownsea:backup';

    protected $description = 'Create a compressed, authenticated, encrypted GOWNSEA database and private-file backup.';

    public function handle(EncryptedBackupService $backup): int
    {
        try {
            $path = $backup->create();
            $this->info('Encrypted backup created: '.$path);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
