<?php

namespace App\Console\Commands;

use App\Services\EncryptedBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class RestoreGownseaBackup extends Command
{
    protected $signature = 'gownsea:restore {backup : Encrypted backup filename inside the configured backup directory} {--force : Skip interactive confirmation}';

    protected $description = 'Restore a GOWNSEA database and private files from an authenticated encrypted backup.';

    public function handle(EncryptedBackupService $restore): int
    {
        $filename = basename((string) $this->argument('backup'));
        if ($filename !== $this->argument('backup') || ! str_ends_with($filename, '.gwbak')) {
            $this->error('Provide a backup filename ending in .gwbak from the private backup directory.');

            return self::FAILURE;
        }

        $path = rtrim((string) config('services.gownsea_backup.directory'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
        if (! is_file($path)) {
            $path = $restore->downloadExternalBackup($filename);
        }
        if (! is_string($path) || ! is_file($path)) {
            $this->error('The requested backup does not exist in local or configured external backup storage.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->warn('Restore replaces the current database and private files. A pre-restore backup will be created first.');
            if (! $this->confirm('Continue with this destructive restore?')) {
                return self::SUCCESS;
            }
        }

        try {
            $this->info('Creating pre-restore backup…');
            $preRestore = $restore->create();
            $this->info('Pre-restore backup: '.$preRestore);
            $this->info('Putting the application into maintenance mode…');
            Artisan::call('down', ['--retry' => 60]);
            $this->info('Validating archive and restoring database/private files…');
            $restore->restore($path);
            Artisan::call('up');
            $this->info('Restore completed. Verify critical records and payment balances before returning the application to service.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Restore failed: '.$exception->getMessage().' If maintenance mode was entered, keep the application offline until the database and private files have been checked.');

            return self::FAILURE;
        }
    }
}
