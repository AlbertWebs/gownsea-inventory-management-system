<?php

namespace App\Http\Controllers;

use App\Actions\CreateStaffUser;
use App\Actions\DeleteLateReturnPolicyOverride;
use App\Actions\SaveLateReturnPolicyOverride;
use App\Actions\UpdateLateReturnPolicy as SaveLateReturnPolicy;
use App\Actions\UpdateStaffAccess;
use App\Http\Requests\ApproveBusinessConfigurationRequest;
use App\Http\Requests\SaveBusinessConfigurationRequest;
use App\Http\Requests\StoreStaffUserRequest;
use App\Http\Requests\UpdateAcademicClassificationRequest;
use App\Http\Requests\UpdateLateReturnPolicyRequest;
use App\Http\Requests\UpdateStaffAccessRequest;
use App\Http\Requests\UpsertLateReturnPolicyOverrideRequest;
use App\Models\AcademicClassification;
use App\Models\AuditLog;
use App\Models\BusinessConfiguration;
use App\Models\ChartAccount;
use App\Models\Institution;
use App\Models\LateReturnPolicy;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Security\PermissionCatalog;
use App\Services\IntegrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function staffAccess(Request $request): Response
    {
        abort_unless($request->user()->mayViewAllLocations(), 403);

        return Inertia::render('RolesPermissions/Index', [
            'locations' => Location::query()->orderBy('type')->orderBy('name')->get(['id', 'name', 'is_active']),
            'staff' => User::query()->with('locations:id,name')->orderBy('name')->get(['id', 'name', 'email', 'role', 'is_active', 'permissions'])->map(function (User $staff): array {
                return [
                    ...$staff->only(['id', 'name', 'email', 'role', 'is_active']),
                    'permissions' => $staff->effectivePermissions(),
                    'locations' => $staff->locations,
                ];
            }),
            'permissionOptions' => collect(['storekeeper', 'sales_crm', 'finance', 'production', 'management'])
                ->mapWithKeys(fn (string $role): array => [$role => PermissionCatalog::labelsForRole($role)]),
            'actorRole' => $request->user()->role,
        ]);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->mayViewAllLocations(), 403);
        $backupDirectory = rtrim((string) config('services.gownsea_backup.directory'), DIRECTORY_SEPARATOR);
        $backups = glob($backupDirectory.DIRECTORY_SEPARATOR.'*.gwbak') ?: [];
        usort($backups, fn (string $left, string $right): int => filemtime($right) <=> filemtime($left));
        $smsProvider = (string) config('services.sms.provider', 'techrise');
        $smsConfiguration = $smsProvider === 'onfon'
            ? app(IntegrationSettings::class)->onfon()
            : app(IntegrationSettings::class)->techrise();
        $daraja = app(IntegrationSettings::class)->daraja();
        $smsConfigured = filled($smsConfiguration['client_id'])
            && filled($smsConfiguration['api_key'])
            && filled($smsConfiguration['sender_id'])
            && ($smsProvider !== 'onfon' || filled($smsConfiguration['access_key']));
        $darajaConfigured = app(IntegrationSettings::class)->darajaEnabled($daraja);
        $databaseAvailable = true;
        try {
            DB::select('SELECT 1');
        } catch (\Throwable) {
            $databaseAvailable = false;
        }
        $jobsTableAvailable = $databaseAvailable && Schema::hasTable('jobs');
        $failedJobsTableAvailable = $databaseAvailable && Schema::hasTable('failed_jobs');
        $smsTableAvailable = $databaseAvailable && Schema::hasTable('sms_messages');
        $queueDriver = (string) config('queue.default');

        return Inertia::render('Settings/Index', [
            'locations' => Location::query()->withCount('assets')->orderBy('type')->orderBy('name')->get(),
            'classifications' => AcademicClassification::query()->orderBy('sort_order')->get(),
            'institutions' => Institution::query()->withCount(['customers', 'events'])->orderBy('name')->get(),
            'lateReturnPolicy' => LateReturnPolicy::formValues(),
            'financeConfigurations' => $this->financeConfigurations(),
            'lateReturnOverrides' => $this->lateReturnOverrides(),
            'products' => Product::query()->where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name']),
            'canManageLocations' => $request->user()->can('create', Location::class),
            'actorRole' => $request->user()->role,
            'actorId' => $request->user()->id,
            'canManageClassifications' => $request->user()->role === 'super_admin',
            'health' => [
                'smsProviderLabel' => $smsProvider === 'onfon' ? 'Onfon' : 'Techrise',
                'database' => $databaseAvailable ? 'available' : 'unavailable',
                'queueDriver' => $queueDriver,
                'queuedJobs' => $queueDriver === 'database' && $jobsTableAvailable ? DB::table('jobs')->count() : null,
                'failedJobs' => $failedJobsTableAvailable ? DB::table('failed_jobs')->count() : null,
                'smsConfigured' => $smsConfigured,
                'smsQueued' => $smsTableAvailable ? DB::table('sms_messages')->whereIn('status', ['queued', 'pending'])->count() : null,
                'darajaConfigured' => $darajaConfigured,
                'darajaEnvironment' => $daraja['environment'],
                'backupEncryptionConfigured' => mb_strlen((string) config('services.gownsea_backup.encryption_key')) >= 32,
                'backupExternalDisk' => config('services.gownsea_backup.external_disk'),
                'lastBackupAt' => isset($backups[0]) ? date(DATE_ATOM, filemtime($backups[0])) : null,
                'backupRetentionDays' => config('services.gownsea_backup.retention_days'),
                'schedulerInstruction' => 'Configure the host scheduler to run php artisan schedule:run every minute.',
            ],
            'app' => [
                'name' => config('app.name'),
                'timezone' => config('app.timezone'),
                'currency' => 'KES',
            ],
        ]);
    }

    public function updateAcademicClassification(UpdateAcademicClassificationRequest $request, AcademicClassification $classification): RedirectResponse
    {
        $before = $classification->only(['code', 'label', 'sort_order', 'is_active']);
        $classification->fill($request->validated())->save();
        AuditLog::query()->create([
            'actor_id' => $request->user()->id,
            'action' => 'settings.academic_classification_updated',
            'subject_type' => AcademicClassification::class,
            'subject_id' => $classification->id,
            'before_state' => $before,
            'after_state' => $classification->only(['code', 'label', 'sort_order', 'is_active']),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'reason' => 'Academic classification configuration updated.',
            'occurred_at' => now(),
        ]);

        return back()->with('success', "Academic classification {$classification->code} updated.");
    }

    public function updateStaff(UpdateStaffAccessRequest $request, User $staff, UpdateStaffAccess $update): RedirectResponse
    {
        $updated = $update->handle($staff, $request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Access updated for {$updated->name}.");
    }

    public function storeStaff(StoreStaffUserRequest $request, CreateStaffUser $create): RedirectResponse
    {
        $staff = $create->handle($request->validated(), $request->user(), $request->ip(), $request->userAgent());
        $status = Password::sendResetLink(['email' => $staff->email]);

        return back()->with('success', $status === Password::RESET_LINK_SENT
            ? "Account created for {$staff->name}; a secure password setup link has been queued."
            : "Account created for {$staff->name}; password setup link could not be queued. Use Forgot password to retry.");
    }

    public function updateLateReturnPolicy(UpdateLateReturnPolicyRequest $request, SaveLateReturnPolicy $save): RedirectResponse
    {
        $save->handle($request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', 'Late return policy updated. New hires will use these terms; existing hires retain their original terms.');
    }

    public function saveBusinessConfiguration(SaveBusinessConfigurationRequest $request): RedirectResponse
    {
        $section = (string) $request->route('configuration');
        $configurationKey = match ($section) {
            'business-profile' => 'business_profile',
            'tax-setup' => 'tax_setup',
            'penalty-accounting' => 'penalty_accounting',
            'historical-cutover' => 'historical_cutover',
            'provider-integrations' => 'provider_integrations',
            'deployment-profile' => 'deployment_profile',
        };
        $data = $request->validated();
        $effectiveFrom = $data['effective_from'] ?? null;
        unset($data['effective_from']);

        $previous = BusinessConfiguration::query()->where('configuration_key', $configurationKey)->latest('id')->first();
        $previousConfiguration = $previous?->configuration ?? [];
        if ($section === 'business-profile' && blank($data['kra_pin'] ?? null)) {
            if (filled($previousConfiguration['kra_pin'] ?? null)) {
                $data['kra_pin'] = $previousConfiguration['kra_pin'];
            } else {
                unset($data['kra_pin']);
            }
        }

        if ($section === 'provider-integrations') {
            foreach (['techrise_client_id', 'techrise_api_key', 'daraja_consumer_key', 'daraja_consumer_secret', 'daraja_shortcode', 'daraja_passkey', 'daraja_callback_token'] as $key) {
                if (blank($data[$key] ?? null)) {
                    if (array_key_exists($key, $previousConfiguration)) {
                        $data[$key] = $previousConfiguration[$key];
                    } else {
                        unset($data[$key]);
                    }
                }
            }
        }

        $reviewStatus = $section === 'provider-integrations' ? 'approved' : 'draft';

        $record = BusinessConfiguration::query()->create([
            'configuration_key' => $configurationKey,
            'configuration' => $data,
            'effective_from' => $effectiveFrom,
            'review_status' => $reviewStatus,
            'created_by' => $request->user()->id,
        ]);

        AuditLog::query()->create([
            'actor_id' => $request->user()->id,
            'action' => 'settings.business_configuration_saved',
            'subject_type' => BusinessConfiguration::class,
            'subject_id' => $record->id,
            'before_state' => [
                ...$this->auditSafeConfiguration($previousConfiguration),
                'effective_from' => $previous?->effective_from?->format('Y-m-d'),
                'review_status' => $previous?->review_status ?? 'not_configured',
            ],
            'after_state' => [
                ...$this->auditSafeConfiguration($data),
                'effective_from' => $effectiveFrom,
                'review_status' => $reviewStatus,
            ],
            'reason' => $section === 'provider-integrations'
                ? 'Provider configuration saved; secret values are encrypted and omitted from the audit record.'
                : 'Draft business configuration saved for review; operational accounting and tax behavior is unchanged.',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'occurred_at' => now(),
        ]);

        return back()->with('success', $section === 'provider-integrations'
            ? 'Provider settings saved. Credentials are stored encrypted and will be used by new provider requests.'
            : 'Configuration saved as a draft. It does not change tax calculations or accounting postings.');
    }

    public function approveBusinessConfiguration(ApproveBusinessConfigurationRequest $request, string $configuration, BusinessConfiguration $record): RedirectResponse
    {
        $expectedKey = match ($configuration) {
            'business-profile' => 'business_profile',
            'tax-setup' => 'tax_setup',
            'penalty-accounting' => 'penalty_accounting',
            'historical-cutover' => 'historical_cutover',
        };
        abort_unless($record->configuration_key === $expectedKey, 404);
        abort_unless($record->review_status === 'draft', 409, 'Only a draft configuration can be approved.');
        abort_unless($record->created_by !== $request->user()->id, 422, 'The person who submitted a configuration cannot approve it.');
        abort_unless(in_array($request->user()->role, ['super_admin', 'management', 'finance'], true), 403);

        $configurationData = $record->configuration;
        if ($configuration === 'business-profile') {
            abort_unless($request->user()->role !== 'finance', 403);
            abort_if(blank($configurationData['legal_name'] ?? null) || blank($configurationData['registered_address'] ?? null) || blank($configurationData['kra_pin'] ?? null), 422, 'Complete the registered legal name, address and KRA PIN before approving the business profile.');
        }

        if ($configuration === 'tax-setup') {
            abort_if(blank($configurationData['accountant_name'] ?? null) || blank($configurationData['accountant_approval_reference'] ?? null) || blank($configurationData['accountant_approval_date'] ?? null), 422, 'Record the accountant and their approval reference/date before approving tax setup.');
            if (($configurationData['registration_status'] ?? 'unknown') === 'vat_registered') {
                abort_if(blank($configurationData['vat_effective_date'] ?? null), 422, 'VAT registration requires an effective date.');
                foreach ($configurationData['categories'] ?? [] as $category) {
                    abort_if(($category['treatment'] ?? 'unconfigured') === 'unconfigured', 422, 'Classify every supply category before approving the tax schedule.');
                    if (($category['treatment'] ?? null) === 'taxable') {
                        abort_if(blank($category['rate'] ?? null) || blank($category['output_account_code'] ?? null) || blank($category['revenue_account_code'] ?? null), 422, 'Taxable categories require a rate and mapped tax and revenue accounts.');
                        abort_unless(ChartAccount::query()->where('code', $category['output_account_code'])->where('account_type', 'liability')->where('is_active', true)->exists(), 422, 'Each taxable category needs an active liability account for output tax.');
                        abort_unless(ChartAccount::query()->where('code', $category['revenue_account_code'])->where('account_type', 'revenue')->where('is_active', true)->exists(), 422, 'Each taxable category needs an active revenue account.');
                    }
                }
            }
            if (($configurationData['etims_solution'] ?? null) === 'kra') {
                abort_if(blank($configurationData['etims_live_from'] ?? null)
                    || blank($configurationData['etims_branch_id'] ?? null)
                    || blank($configurationData['etims_device_serial'] ?? null)
                    || blank($configurationData['etims_registrant_name'] ?? null)
                    || blank($configurationData['etims_registrant_id'] ?? null)
                    || blank($configurationData['etims_receipt_type_code'] ?? null)
                    || blank($configurationData['etims_payment_type_code'] ?? null), 422,
                    'KRA system integration requires the approved go-live date, branch, registered device, operator and transaction codes.');
                foreach ($configurationData['categories'] ?? [] as $category) {
                    abort_if(blank($category['etims_tax_type_code'] ?? null)
                        || blank($category['etims_item_code'] ?? null)
                        || blank($category['etims_item_class_code'] ?? null)
                        || blank($category['etims_packaging_unit_code'] ?? null)
                        || blank($category['etims_quantity_unit_code'] ?? null), 422,
                        'Every supply category needs a complete accountant-approved KRA item mapping.');
                }
            }
        }

        if ($configuration === 'penalty-accounting') {
            abort_if(blank($configurationData['accountant_name'] ?? null) || blank($configurationData['approval_reference'] ?? null) || blank($record->effective_from), 422, 'Record accountant approval and an effective date before approving penalty accounting.');
            abort_unless(ChartAccount::query()->where('code', $configurationData['receivable_account_code'] ?? '')->where('account_type', 'asset')->where('is_active', true)->exists(), 422, 'Select an active asset account for penalty receivables.');
            foreach (['penalty_income_account_code', 'waiver_account_code'] as $key) {
                abort_unless(ChartAccount::query()->where('code', $configurationData[$key] ?? '')->where('account_type', 'revenue')->where('is_active', true)->exists(), 422, 'Select active revenue accounts for penalty income and waivers.');
            }
            abort_unless(ChartAccount::query()->where('code', $configurationData['refund_account_code'] ?? '')->where('account_type', 'asset')->where('is_active', true)->exists(), 422, 'Select an active asset account for penalty refunds.');
        }

        if ($configuration === 'historical-cutover') {
            abort_if(blank($configurationData['source_snapshot_reference'] ?? null) || blank($configurationData['source_snapshot_date'] ?? null) || blank($configurationData['proposed_cutover_date'] ?? null), 422, 'Record the source snapshot and cutover date before approving the migration.');
            abort_if(! isset($configurationData['candidate_hire_count'], $configurationData['candidate_order_count']), 422, 'Record the reviewed hire and custom-order candidate counts before approving the migration.');
            abort_if(blank($configurationData['finance_approval_reference'] ?? null) || blank($configurationData['finance_approval_date'] ?? null) || blank($configurationData['management_approval_reference'] ?? null) || blank($configurationData['management_approval_date'] ?? null) || blank($configurationData['reconciliation_reference'] ?? null) || blank($configurationData['backup_restore_reference'] ?? null), 422, 'Record finance, management, reconciliation and restore evidence before approving cutover.');
            abort_if((int) ($configurationData['unresolved_exceptions'] ?? 0) > 0, 422, 'Resolve or formally exclude all cutover exceptions before approval.');
        }

        $before = ['review_status' => $record->review_status, 'approved_by' => $record->approved_by];
        $record->forceFill([
            'review_status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'approval_reference' => $request->validated('approval_reference'),
        ])->save();

        AuditLog::query()->create([
            'actor_id' => $request->user()->id,
            'action' => 'settings.business_configuration_approved',
            'subject_type' => BusinessConfiguration::class,
            'subject_id' => $record->id,
            'before_state' => $before,
            'after_state' => ['review_status' => 'approved', 'approved_by' => $request->user()->id, 'approval_reference' => $request->validated('approval_reference')],
            'reason' => 'Configuration approved by an independent reviewer.',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'occurred_at' => now(),
        ]);

        return back()->with('success', 'Configuration approved. It becomes effective on its configured effective date.');
    }

    public function saveLateReturnOverride(UpsertLateReturnPolicyOverrideRequest $request, SaveLateReturnPolicyOverride $save): RedirectResponse
    {
        $policy = $save->handle($request->validated(), $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', "Late return terms saved for {$policy->scope_type} override.");
    }

    public function deleteLateReturnOverride(Request $request, LateReturnPolicy $policy, DeleteLateReturnPolicyOverride $delete): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['super_admin', 'management'], true), 403);
        $delete->handle($policy, $request->user(), $request->ip(), $request->userAgent());

        return back()->with('success', 'Late return override removed. Existing hires keep their snapshotted terms.');
    }

    /** @return list<array{id:int,scope_type:string,scope_id:?int,scope_reference:?string,target:string,per_day_amount:string,grace_days:int,cap_amount:string,count_return_day:bool}> */
    private function lateReturnOverrides(): array
    {
        $policies = LateReturnPolicy::query()->where('scope_type', '!=', 'default')->orderBy('scope_type')->orderBy('scope_reference')->orderBy('scope_id')->get();
        $products = Product::query()->whereIn('id', $policies->where('scope_type', 'product')->pluck('scope_id'))->get(['id', 'name', 'sku'])->keyBy('id');
        $institutions = Institution::query()->whereIn('id', $policies->where('scope_type', 'institution')->pluck('scope_id'))->pluck('name', 'id');

        return $policies->map(function (LateReturnPolicy $policy) use ($products, $institutions): array {
            $target = match ($policy->scope_type) {
                'product' => ($products->get($policy->scope_id)?->name ?? 'Inactive product').' · '.($products->get($policy->scope_id)?->sku ?? 'missing SKU'),
                'institution' => $institutions->get($policy->scope_id, 'Inactive institution'),
                'contract' => $policy->scope_reference ?? '',
                default => '',
            };

            return [
                'id' => $policy->id,
                'scope_type' => $policy->scope_type,
                'scope_id' => $policy->scope_id,
                'scope_reference' => $policy->scope_reference,
                'target' => $target,
                'per_day_amount' => number_format($policy->per_day_minor / 100, 2, '.', ''),
                'grace_days' => $policy->grace_days,
                'cap_amount' => number_format($policy->cap_minor / 100, 2, '.', ''),
                'count_return_day' => $policy->count_return_day,
            ];
        })->values()->all();
    }

    /** @return array<string,array<string,mixed>> */
    private function financeConfigurations(): array
    {
        $keys = ['business_profile', 'tax_setup', 'penalty_accounting', 'historical_cutover', 'provider_integrations', 'deployment_profile'];
        $latest = BusinessConfiguration::query()->whereIn('configuration_key', $keys)
            ->orderByDesc('id')->get()->unique('configuration_key')->keyBy('configuration_key');

        $valueFor = fn (string $key, array $defaults): array => [
            ...$defaults,
            ...($latest->get($key)?->configuration ?? []),
            'record_id' => $latest->get($key)?->id,
            'created_by' => $latest->get($key)?->created_by,
            'approved_by' => $latest->get($key)?->approved_by,
            'approved_at' => $latest->get($key)?->approved_at?->toIso8601String(),
            'approval_reference' => $latest->get($key)?->approval_reference,
            'effective_from' => $latest->get($key)?->effective_from?->format('Y-m-d') ?? '',
            'review_status' => $latest->get($key)?->review_status ?? 'not_configured',
        ];

        $businessProfile = $valueFor('business_profile', [
            'legal_name' => 'Graduation Gowns East Africa Limited', 'trading_name' => 'GOWNSEA', 'registered_address' => '',
            'receipt_phone' => '', 'receipt_email' => '', 'receipt_website' => '', 'receipt_footer' => '',
        ]);
        $businessProfile['legal_name'] = filled($businessProfile['legal_name'] ?? null) ? $businessProfile['legal_name'] : 'Graduation Gowns East Africa Limited';
        $businessProfile['trading_name'] = filled($businessProfile['trading_name'] ?? null) ? $businessProfile['trading_name'] : 'GOWNSEA';
        $businessProfile['kra_pin_configured'] = filled($businessProfile['kra_pin'] ?? null);
        unset($businessProfile['kra_pin']);

        return [
            'businessProfile' => $businessProfile,
            'taxSetup' => $valueFor('tax_setup', [
                'registration_status' => 'unknown', 'vat_effective_date' => '', 'price_mode' => 'not_configured',
                'discount_treatment' => 'not_configured', 'rounding_mode' => 'not_configured',
                'etims_solution' => 'unselected', 'etims_provider' => '', 'accountant_name' => '',
                'etims_live_from' => '',
                'etims_branch_id' => '', 'etims_device_serial' => '', 'etims_registrant_name' => '', 'etims_registrant_id' => '',
                'etims_receipt_type_code' => '', 'etims_payment_type_code' => '', 'etims_buyer_acceptance' => 'N',
                'accountant_approval_reference' => '', 'accountant_approval_date' => '',
                'categories' => [
                    ['code' => 'gown_hire', 'label' => 'Gown hire', 'treatment' => 'unconfigured', 'rate' => '', 'effective_from' => '', 'effective_to' => '', 'output_account_code' => '', 'revenue_account_code' => '', 'basis_reference' => '', 'etims_tax_type_code' => '', 'etims_item_code' => '', 'etims_item_class_code' => '', 'etims_packaging_unit_code' => '', 'etims_quantity_unit_code' => ''],
                    ['code' => 'product_sales', 'label' => 'Gown and accessory sales', 'treatment' => 'unconfigured', 'rate' => '', 'effective_from' => '', 'effective_to' => '', 'output_account_code' => '', 'revenue_account_code' => '', 'basis_reference' => '', 'etims_tax_type_code' => '', 'etims_item_code' => '', 'etims_item_class_code' => '', 'etims_packaging_unit_code' => '', 'etims_quantity_unit_code' => ''],
                    ['code' => 'custom_orders', 'label' => 'Custom orders', 'treatment' => 'unconfigured', 'rate' => '', 'effective_from' => '', 'effective_to' => '', 'output_account_code' => '', 'revenue_account_code' => '', 'basis_reference' => '', 'etims_tax_type_code' => '', 'etims_item_code' => '', 'etims_item_class_code' => '', 'etims_packaging_unit_code' => '', 'etims_quantity_unit_code' => ''],
                    ['code' => 'penalties', 'label' => 'Late-return penalties', 'treatment' => 'unconfigured', 'rate' => '', 'effective_from' => '', 'effective_to' => '', 'output_account_code' => '', 'revenue_account_code' => '', 'basis_reference' => '', 'etims_tax_type_code' => '', 'etims_item_code' => '', 'etims_item_class_code' => '', 'etims_packaging_unit_code' => '', 'etims_quantity_unit_code' => ''],
                    ['code' => 'other_services', 'label' => 'Other services', 'treatment' => 'unconfigured', 'rate' => '', 'effective_from' => '', 'effective_to' => '', 'output_account_code' => '', 'revenue_account_code' => '', 'basis_reference' => '', 'etims_tax_type_code' => '', 'etims_item_code' => '', 'etims_item_class_code' => '', 'etims_packaging_unit_code' => '', 'etims_quantity_unit_code' => ''],
                ],
            ]),
            'penaltyAccounting' => $valueFor('penalty_accounting', [
                'recognition_basis' => 'unconfigured', 'receivable_account_code' => '', 'penalty_income_account_code' => '',
                'waiver_account_code' => '', 'refund_account_code' => '', 'accountant_name' => '', 'approval_reference' => '',
                'waiver_branch_limit' => 0, 'waiver_finance_limit' => 0, 'independent_approval_required' => true,
            ]),
            'historicalCutover' => $valueFor('historical_cutover', [
                'source_snapshot_reference' => '', 'source_snapshot_date' => '', 'proposed_cutover_date' => '',
                'candidate_hire_count' => null, 'candidate_order_count' => null, 'opening_receivables' => '',
                'opening_deposits' => '', 'opening_refunds' => '', 'unresolved_exceptions' => null,
                'finance_reviewer' => '', 'finance_approver_title' => '', 'finance_approval_reference' => '',
                'finance_approval_date' => '', 'management_approver' => '', 'management_approval_reference' => '',
                'management_approval_date' => '', 'reconciliation_reference' => '', 'backup_restore_reference' => '',
            ]),
            'providerIntegrations' => $this->providerIntegrationFormValues($valueFor('provider_integrations', [
                'techrise_endpoint' => config('services.techrise.endpoint'), 'techrise_sender_id' => config('services.techrise.sender_id'),
                'daraja_enabled' => (bool) config('services.daraja.enabled'), 'daraja_environment' => config('services.daraja.environment'),
                'daraja_transaction_type' => config('services.daraja.transaction_type'), 'techrise_test_reference' => '',
                'techrise_tested_at' => '', 'daraja_test_reference' => '', 'daraja_tested_at' => '', 'daraja_callback_reference' => '',
            ])),
            'deploymentProfile' => $valueFor('deployment_profile', [
                'deployment_stage' => 'planned', 'aws_region' => 'af-south-1', 'aws_account_alias' => '',
                'ec2_reference' => '', 'database_endpoint' => '', 'cache_endpoint' => '', 'application_domain' => '',
                's3_bucket' => '', 's3_prefix' => 'gownsea-backups', 'mail_provider' => '', 'mail_from_address' => '',
                'backup_retention_days' => 90, 'rpo_hours' => 24, 'rto_hours' => 8, 'support_owner' => '',
                'critical_response_hours' => 2, 'management_release_approver' => '', 'release_approval_reference' => '',
            ]),
        ];
    }

    /** @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function providerIntegrationFormValues(array $values): array
    {
        foreach (['techrise_client_id', 'techrise_api_key', 'daraja_consumer_key', 'daraja_consumer_secret', 'daraja_shortcode', 'daraja_passkey', 'daraja_callback_token'] as $key) {
            $environmentKey = match ($key) {
                'techrise_client_id' => 'services.techrise.client_id',
                'techrise_api_key' => 'services.techrise.api_key',
                'daraja_consumer_key' => 'services.daraja.consumer_key',
                'daraja_consumer_secret' => 'services.daraja.consumer_secret',
                'daraja_shortcode' => 'services.daraja.shortcode',
                'daraja_passkey' => 'services.daraja.passkey',
                default => 'services.daraja.callback_token',
            };
            $values[$key.'_configured'] = filled($values[$key] ?? null) || filled(config($environmentKey));
            unset($values[$key]);
        }

        foreach (['onfon_client_id', 'onfon_api_key', 'onfon_access_key'] as $legacySecretKey) {
            unset($values[$legacySecretKey], $values[$legacySecretKey.'_configured']);
        }

        return $values;
    }

    /** @param array<string,mixed> $configuration
     * @return array<string,mixed>
     */
    private function auditSafeConfiguration(array $configuration): array
    {
        foreach ($configuration as $key => $value) {
            if (preg_match('/(kra_pin|api_key|access_key|secret|passkey|callback_token|consumer_key|client_id)/i', (string) $key)) {
                $configuration[$key.'_configured'] = filled($value);
                unset($configuration[$key]);
            }
        }

        return $configuration;
    }
}
