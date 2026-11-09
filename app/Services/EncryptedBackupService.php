<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

class EncryptedBackupService
{
    private const MAGIC = "GOWNSEA-BACKUP-V1\0";

    public function create(): string
    {
        $directory = $this->backupDirectory();
        $this->ensureKey();
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the configured backup directory.');
        }

        $work = storage_path('app/private/backup-tmp/'.bin2hex(random_bytes(12)));
        if (! mkdir($work, 0700, true) && ! is_dir($work)) {
            throw new RuntimeException('Unable to create a temporary backup workspace.');
        }

        try {
            $dumpPath = $this->dumpDatabase($work);
            $zipPath = $work.'/gownsea.zip';
            $this->createArchive($zipPath, $dumpPath);
            $encryptedPath = $directory.'/gownsea-'.now('UTC')->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.gwbak';
            $this->encryptArchive($zipPath, $encryptedPath);
            chmod($encryptedPath, 0600);
            $this->mirrorExternalBackup($encryptedPath);
            $this->pruneOldBackups($directory);

            return $encryptedPath;
        } finally {
            $this->removeTree($work);
        }
    }

    public function downloadExternalBackup(string $filename): ?string
    {
        if (basename($filename) !== $filename || ! str_ends_with($filename, '.gwbak')) {
            return null;
        }
        $disk = $this->externalDisk();
        if ($disk === null) {
            return null;
        }

        $stream = Storage::disk($disk)->readStream($this->externalObjectPath($filename));
        if (! is_resource($stream)) {
            return null;
        }

        $directory = $this->backupDirectory();
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            fclose($stream);
            throw new RuntimeException('Unable to create the configured backup directory.');
        }
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        $destination = fopen($path, 'wb');
        if ($destination === false) {
            fclose($stream);
            throw new RuntimeException('Unable to create a local copy of the external backup.');
        }

        try {
            if (stream_copy_to_stream($stream, $destination) === false) {
                @unlink($path);
                throw new RuntimeException('Unable to download the external backup archive.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_resource($destination)) {
                fclose($destination);
            }
        }
        chmod($path, 0600);

        return $path;
    }

    public function restore(string $backupPath): void
    {
        $this->ensureKey();
        $directory = realpath($this->backupDirectory());
        $backup = realpath($backupPath);
        if ($directory === false || $backup === false || ! str_starts_with($backup, $directory.DIRECTORY_SEPARATOR) || ! is_file($backup)) {
            throw new RuntimeException('Restore file must exist inside the configured private backup directory.');
        }

        $work = storage_path('app/private/restore-tmp/'.bin2hex(random_bytes(12)));
        if (! mkdir($work, 0700, true) && ! is_dir($work)) {
            throw new RuntimeException('Unable to create a temporary restore workspace.');
        }

        try {
            $zipPath = $work.'/restore.zip';
            $this->decryptArchive($backup, $zipPath);
            $archive = new ZipArchive;
            if ($archive->open($zipPath) !== true) {
                throw new RuntimeException('Backup archive is invalid.');
            }
            $manifest = json_decode((string) $archive->getFromName('manifest.json'), true);
            if (! is_array($manifest) || ($manifest['format'] ?? null) !== 1 || ($manifest['database_driver'] ?? null) !== DB::connection()->getDriverName()) {
                $archive->close();
                throw new RuntimeException('Backup manifest is invalid or its database driver differs from this environment.');
            }
            $dbEntry = $manifest['database_entry'] ?? '';
            if (! is_string($dbEntry) || ! in_array($dbEntry, ['database.sqlite', 'database.dump'], true)) {
                $archive->close();
                throw new RuntimeException('Backup does not contain its database dump.');
            }
            $dumpPath = $work.'/database.dump';
            $dumpStream = $archive->getStream($dbEntry);
            $dumpOutput = fopen($dumpPath, 'wb');
            if (! is_resource($dumpStream) || ! is_resource($dumpOutput) || stream_copy_to_stream($dumpStream, $dumpOutput) === false) {
                if (is_resource($dumpStream)) {
                    fclose($dumpStream);
                }
                if (is_resource($dumpOutput)) {
                    fclose($dumpOutput);
                }
                $archive->close();
                throw new RuntimeException('Backup database entry could not be read.');
            }
            fclose($dumpStream);
            fclose($dumpOutput);
            $storageEntries = [];
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $stat = $archive->statIndex($index);
                $name = $stat['name'] ?? '';
                if (str_starts_with($name, 'private-storage/') && ! str_contains($name, '../')) {
                    $storageEntries[] = $name;
                }
            }

            $this->restoreDatabase($dumpPath, $work);
            $this->restorePrivateFiles($archive, $storageEntries);
            $archive->close();
        } finally {
            $this->removeTree($work);
        }
    }

    private function dumpDatabase(string $work): string
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $database = (string) config('database.connections.sqlite.database');
            if ($database === ':memory:' || ! is_file($database)) {
                throw new RuntimeException('SQLite backup requires a file-backed database.');
            }
            $target = $work.'/database.sqlite';
            DB::connection()->getPdo()->exec('PRAGMA wal_checkpoint(FULL)');
            if (! copy($database, $target)) {
                throw new RuntimeException('Unable to copy the SQLite database.');
            }

            return $target;
        }

        if ($driver === 'pgsql') {
            $config = config('database.connections.pgsql');
            $target = $work.'/database.dump';
            $process = new Process(['pg_dump', '--format=custom', '--no-owner', '--no-acl', '--host='.$config['host'], '--port='.(string) $config['port'], '--username='.$config['username'], '--dbname='.$config['database'], '--file='.$target]);
            $process->setEnv(['PGPASSWORD' => (string) $config['password']]);
            $process->setTimeout(600);
            $process->mustRun();

            return $target;
        }

        throw new RuntimeException("Encrypted backup currently supports SQLite and PostgreSQL; {$driver} is unsupported.");
    }

    private function createArchive(string $zipPath, string $dumpPath): void
    {
        $driver = DB::connection()->getDriverName();
        $entry = $driver === 'sqlite' ? 'database.sqlite' : 'database.dump';
        $archive = new ZipArchive;
        if ($archive->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Unable to create the backup archive.');
        }
        $archive->addFile($dumpPath, $entry);
        $archive->addFromString('manifest.json', json_encode([
            'format' => 1,
            'created_at_utc' => now('UTC')->toIso8601String(),
            'database_driver' => $driver,
            'database_entry' => $entry,
            'laravel_version' => app()->version(),
            'storage_included' => 'storage/app/private except backups and temporary workspaces',
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        $privateStorage = $this->privateDirectory();
        if (is_dir($privateStorage)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($privateStorage, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file->isFile() || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR) || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'backup-tmp'.DIRECTORY_SEPARATOR) || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'restore-tmp'.DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($privateStorage) + 1);
                $archive->addFile($file->getPathname(), 'private-storage/'.$relative);
            }
        }
        $archive->close();
    }

    private function encryptArchive(string $archivePath, string $outputPath): void
    {
        $input = fopen($archivePath, 'rb');
        $output = fopen($outputPath, 'wb');
        if (! is_resource($input) || ! is_resource($output)) {
            throw new RuntimeException('Unable to open backup archive streams.');
        }
        $salt = random_bytes(16);
        $key = hash_hkdf('sha256', (string) config('services.gownsea_backup.encryption_key'), 32, 'gownsea-backup-v1', $salt);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        fwrite($output, self::MAGIC.$salt.$header);
        $current = fread($input, 1_048_576);
        while (is_string($current) && $current !== '') {
            $next = fread($input, 1_048_576);
            $final = $next === '';
            $frame = sodium_crypto_secretstream_xchacha20poly1305_push($state, $current, '', $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            $written = fwrite($output, pack('N', strlen($frame)).$frame);
            if ($written !== strlen($frame) + 4) {
                throw new RuntimeException('Unable to write encrypted backup file.');
            }
            if ($final) {
                break;
            }
            $current = $next;
        }
        fclose($input);
        fclose($output);
    }

    private function decryptArchive(string $backupPath, string $zipPath): void
    {
        $input = fopen($backupPath, 'rb');
        $output = fopen($zipPath, 'wb');
        if (! is_resource($input) || ! is_resource($output)) {
            throw new RuntimeException('Unable to open encrypted backup streams.');
        }
        $magic = fread($input, strlen(self::MAGIC));
        $salt = fread($input, 16);
        $header = fread($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        if ($magic !== self::MAGIC || strlen((string) $salt) !== 16 || strlen((string) $header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            throw new RuntimeException('Backup format is invalid.');
        }
        $key = hash_hkdf('sha256', (string) config('services.gownsea_backup.encryption_key'), 32, 'gownsea-backup-v1', $salt);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        $sawFinal = false;
        while (! feof($input)) {
            $lengthBytes = fread($input, 4);
            if ($lengthBytes === '') {
                break;
            }
            if (strlen($lengthBytes) !== 4) {
                throw new RuntimeException('Backup authentication failed; truncated file.');
            }
            $length = unpack('Nlength', $lengthBytes)['length'];
            if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                throw new RuntimeException('Backup authentication failed; invalid frame.');
            }
            $frame = '';
            while (strlen($frame) < $length && ! feof($input)) {
                $part = fread($input, $length - strlen($frame));
                if ($part === false || $part === '') {
                    break;
                }
                $frame .= $part;
            }
            $pulled = strlen($frame) === $length ? sodium_crypto_secretstream_xchacha20poly1305_pull($state, $frame) : false;
            if (! is_array($pulled) || fwrite($output, $pulled[0]) !== strlen($pulled[0])) {
                throw new RuntimeException('Backup authentication failed; wrong key or corrupted file.');
            }
            if ($pulled[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                $sawFinal = true;
                if (fread($input, 1) !== '') {
                    throw new RuntimeException('Backup contains data after its authenticated final frame.');
                }
                break;
            }
        }
        fclose($input);
        fclose($output);
        if (! $sawFinal) {
            throw new RuntimeException('Backup authentication failed; final frame is missing.');
        }
        chmod($zipPath, 0600);
    }

    private function restoreDatabase(string $dumpPath, string $work): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $database = (string) config('database.connections.sqlite.database');
            DB::purge();
            if (! copy($dumpPath, $database.'.restore') || ! rename($database.'.restore', $database)) {
                throw new RuntimeException('Unable to replace the SQLite database from backup.');
            }
            DB::reconnect();

            return;
        }

        if ($driver === 'pgsql') {
            $config = config('database.connections.pgsql');
            $process = new Process([
                'pg_restore', '--clean', '--if-exists', '--no-owner', '--no-acl', '--single-transaction',
                '--host='.(string) $config['host'],
                '--port='.(string) $config['port'],
                '--username='.(string) $config['username'],
                '--dbname='.(string) $config['database'],
                $dumpPath,
            ]);
            $process->setEnv(['PGPASSWORD' => (string) $config['password']]);
            $process->setTimeout(1800);
            $process->mustRun();

            return;
        }

        throw new RuntimeException("Restore does not support {$driver}.");
    }

    /** @param list<string> $entries */
    private function restorePrivateFiles(ZipArchive $archive, array $entries): void
    {
        $root = $this->privateDirectory();
        foreach (glob($root.'/*') ?: [] as $path) {
            if (basename($path) === 'backups' || basename($path) === 'backup-tmp' || basename($path) === 'restore-tmp') {
                continue;
            }
            $this->removeTree($path);
        }
        foreach ($entries as $name) {
            $relative = substr($name, strlen('private-storage/'));
            if ($relative === '' || str_starts_with($relative, '/') || in_array('..', explode('/', $relative), true)) {
                throw new RuntimeException('Backup contains an unsafe storage path.');
            }
            $target = $root.'/'.$relative;
            $parent = dirname($target);
            if (! is_dir($parent)) {
                mkdir($parent, 0700, true);
            }
            $input = $archive->getStream($name);
            $output = fopen($target, 'wb');
            if (! is_resource($input) || ! is_resource($output)) {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
                throw new RuntimeException('Unable to restore a private storage file from the archive.');
            }
            if (stream_copy_to_stream($input, $output) === false) {
                fclose($input);
                fclose($output);
                throw new RuntimeException('Unable to write a private storage file from the archive.');
            }
            fclose($input);
            fclose($output);
            chmod($target, 0600);
        }
    }

    private function ensureKey(): void
    {
        if (strlen((string) config('services.gownsea_backup.encryption_key')) < 32) {
            throw new RuntimeException('Set GOWNSEA_BACKUP_ENCRYPTION_KEY to a dedicated secret of at least 32 characters.');
        }
    }

    private function backupDirectory(): string
    {
        return (string) config('services.gownsea_backup.directory');
    }

    private function mirrorExternalBackup(string $path): void
    {
        $disk = $this->externalDisk();
        if ($disk === null) {
            return;
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to open the encrypted backup for external storage.');
        }

        try {
            if (! Storage::disk($disk)->put($this->externalObjectPath(basename($path)), $stream)) {
                throw new RuntimeException('Encrypted backup was created locally but could not be copied to external storage.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function externalDisk(): ?string
    {
        $disk = config('services.gownsea_backup.external_disk');

        return filled($disk) ? (string) $disk : null;
    }

    private function externalObjectPath(string $filename): string
    {
        $prefix = trim((string) config('services.gownsea_backup.external_prefix', 'gownsea-backups'), '/');
        if ($prefix !== '' && in_array('..', explode('/', $prefix), true)) {
            throw new RuntimeException('External backup prefix cannot contain parent-directory segments.');
        }

        return ($prefix === '' ? '' : $prefix.'/').$filename;
    }

    private function privateDirectory(): string
    {
        return (string) config('services.gownsea_backup.private_directory', storage_path('app/private'));
    }

    private function pruneOldBackups(string $directory): void
    {
        $cutoff = now()->subDays(max(1, (int) config('services.gownsea_backup.retention_days')))->timestamp;
        foreach (glob($directory.'/gownsea-*.gwbak') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }

            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}
