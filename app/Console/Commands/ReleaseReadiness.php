<?php

namespace App\Console\Commands;

use App\Models\BusinessConfiguration;
use App\Models\EtimsSubmission;
use App\Services\IntegrationSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

#[Signature('gownsea:release-readiness {--strict : Exit non-zero when any production gate is blocked or needs external evidence} {--json : Print machine-readable JSON}')]
#[Description('Report the GOWNSEA production release gates without exposing configured secrets')]
class ReleaseReadiness extends Command
{
    /** @var list<array{status:string,gate:string,detail:string}> */
    private array $checks = [];

    public function handle(IntegrationSettings $integrations): int
    {
        $this->check('pass', 'Application settings', app()->environment('production') && ! config('app.debug') && str_starts_with((string) config('app.url'), 'https://'), 'Production environment, debug mode, and HTTPS application URL');
        $this->check('pass', 'Application key', filled(config('app.key')), 'APP_KEY is configured');
        $this->check('pass', 'Database', config('database.default') === 'pgsql', 'PostgreSQL is selected');
        $this->check('pass', 'Runtime stores', config('cache.default') === 'redis' && config('session.driver') === 'redis' && config('queue.default') === 'redis', 'Redis is selected for cache, session, and queue');
        $mailDriver = (string) config('mail.default');
        $mailConfiguration = config('mail.mailers.'.$mailDriver, []);
        $mailTransport = $mailConfiguration['transport'] ?? $mailDriver;
        $mailReady = match ($mailTransport) {
            'ses' => filled(config('services.ses.region')) && filled(config('mail.from.address')),
            'smtp' => filled($mailConfiguration['host'] ?? null)
                && filled($mailConfiguration['port'] ?? null)
                && filled($mailConfiguration['username'] ?? null)
                && filled($mailConfiguration['password'] ?? null)
                && filled(config('mail.from.address')),
            default => $mailDriver !== 'log'
                && filled($mailConfiguration['host'] ?? null)
                && filled(config('mail.from.address')),
        };
        $this->check('pass', 'Mail delivery', $mailReady, 'Production mail transport is configured');
        $this->check('pass', 'Backup encryption', strlen((string) config('services.gownsea_backup.encryption_key')) >= 32, 'Dedicated backup encryption key is configured');
        $externalDisk = (string) config('services.gownsea_backup.external_disk');
        $this->check('pass', $externalDisk === 's3' ? 'S3 backup target' : 'External backup target', filled($externalDisk) && filled(config('filesystems.disks.'.$externalDisk.'.bucket')), 'A private external backup bucket and disk are configured');

        $schemaReady = Schema::hasTable('business_configurations') && Schema::hasTable('etims_submissions');
        $this->check('pass', 'Database migrations', $schemaReady, 'Business configuration and eTIMS outbox schemas exist');
        $profile = $schemaReady ? $this->approved('business_profile') : null;
        $tax = $schemaReady ? $this->approved('tax_setup') : null;
        $penalties = $schemaReady ? $this->approved('penalty_accounting') : null;
        $cutover = $schemaReady ? $this->approved('historical_cutover') : null;
        $this->check('pass', 'Legal entity', $profile !== null && filled($profile['legal_name'] ?? null) && filled($profile['kra_pin'] ?? null), 'Approved registered legal name and KRA PIN');
        $this->check('pass', 'Tax and statutory mapping', $tax !== null && ($tax['registration_status'] ?? 'unknown') !== 'unknown' && filled($tax['accountant_approval_reference'] ?? null), 'Approved, effective tax categories and accountant mapping reference');
        $this->check('pass', 'Penalty accounting', $penalties !== null && ($penalties['recognition_basis'] ?? 'unconfigured') !== 'unconfigured', 'Approved late-fee recognition and journal accounts');
        $this->check('pass', 'Historical cutover', $cutover !== null && filled($cutover['reconciliation_reference'] ?? null) && filled($cutover['backup_restore_reference'] ?? null), 'Finance/management cutover approvals and referenced reconciliation');
        $this->check('evidence', 'Historical data reconciliation', false, 'Verify source rows and opening control totals against the approved cutover schedule');

        $etims = $schemaReady ? $integrations->etims() : ['ready' => false, 'tax_setup' => []];
        $this->check('pass', 'eTIMS configuration', ($etims['ready'] ?? false) && ($etims['tax_setup']['etims_solution'] ?? null) === 'kra', 'Approved legal profile, KRA mapping, bridge endpoint, and enabled adapter');
        $this->check('evidence', 'eTIMS certification', false, 'Complete KRA onboarding/certification and a controlled accepted invoice/credit-note check');
        $unresolvedEtims = $schemaReady ? EtimsSubmission::query()->where('status', '!=', 'submitted')->count() : 0;
        $this->check('pass', 'eTIMS outbox', $unresolvedEtims === 0, $unresolvedEtims === 0
            ? 'No pending, failed, historical, or uncertain eTIMS submissions'
            : "Resolve {$unresolvedEtims} pending, failed, historical, or uncertain eTIMS submission(s)");

        $providers = $schemaReady ? (BusinessConfiguration::query()->where('configuration_key', 'provider_integrations')->where('review_status', 'approved')->latest('id')->first()?->configuration ?? []) : [];
        $this->check('evidence', 'Onfon live delivery', filled($providers['onfon_test_reference'] ?? null) && filled($providers['onfon_tested_at'] ?? null), 'Use rotated GOWNSEA credentials and retain a real delivery receipt reference');
        $this->check('evidence', 'Daraja sandbox/callback', filled($providers['daraja_test_reference'] ?? null) && filled($providers['daraja_callback_reference'] ?? null), 'Complete a sandbox STK transaction and reconcile its callback');
        $this->check('evidence', 'Remote CI', false, 'Verify the PostgreSQL/Redis workflow on the remote CI runner');
        $this->check('evidence', 'Performance and devices', false, 'Record agreed load results and acceptance on GOWNSEA Android/scanner devices');
        $this->check('evidence', 'AWS recovery drill', false, 'Provision the client AWS account, mirror backups to S3, and verify an isolated restore against RPO/RTO');
        $this->check('evidence', 'Production operations', false, 'Verify EC2 release, TLS, worker/scheduler, monitoring, mail, and named support contacts');

        if ($this->option('json')) {
            $this->line(json_encode(['ready' => collect($this->checks)->every(fn (array $check): bool => $check['status'] === 'pass'), 'checks' => $this->checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Status', 'Gate', 'Detail'], array_map(fn (array $check): array => [strtoupper($check['status']), $check['gate'], $check['detail']], $this->checks));
            $this->newLine();
            $passed = collect($this->checks)->where('status', 'pass')->count();
            $this->info("{$passed} of ".count($this->checks).' checks are configured and approved. External evidence gates must also pass before release.');
        }

        return $this->option('strict') && collect($this->checks)->contains(fn (array $check): bool => $check['status'] !== 'pass')
            ? self::FAILURE
            : self::SUCCESS;
    }

    /** @return array<string,mixed>|null */
    private function approved(string $key): ?array
    {
        return BusinessConfiguration::query()
            ->where('configuration_key', $key)
            ->where('review_status', 'approved')
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()->toDateString()))
            ->latest('effective_from')->latest('id')->first()?->configuration;
    }

    private function check(string $statusWhenTrue, string $gate, bool $passed, string $detail): void
    {
        $this->checks[] = [
            'status' => $passed ? $statusWhenTrue : ($statusWhenTrue === 'evidence' ? 'evidence' : 'blocked'),
            'gate' => $gate,
            'detail' => $detail,
        ];
    }
}
