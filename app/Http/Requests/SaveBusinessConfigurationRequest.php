<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBusinessConfigurationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['super_admin', 'management'], true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $section = $this->route('configuration');

        return match ($section) {
            'business-profile' => [
                'effective_from' => ['nullable', 'date'],
                'legal_name' => ['nullable', 'string', 'max:255'],
                'trading_name' => ['nullable', 'string', 'max:255'],
                'registered_address' => ['nullable', 'string', 'max:1000'],
                'kra_pin' => ['nullable', 'string', 'max:64'],
            ],
            'tax-setup' => [
                'effective_from' => ['nullable', 'date'],
                'registration_status' => ['required', Rule::in(['unknown', 'vat_registered', 'not_vat_registered'])],
                'vat_effective_date' => ['nullable', 'date'],
                'price_mode' => ['required', Rule::in(['not_configured', 'inclusive', 'exclusive'])],
                'discount_treatment' => ['required', Rule::in(['not_configured', 'before_tax', 'after_tax'])],
                'rounding_mode' => ['required', Rule::in(['not_configured', 'line', 'invoice'])],
                'etims_solution' => ['required', Rule::in(['unselected', 'kra', 'third_party', 'none'])],
                'etims_provider' => ['nullable', 'string', 'max:255'],
                'etims_live_from' => ['nullable', 'date'],
                'etims_branch_id' => ['nullable', 'string', 'size:2', 'alpha_num:ascii'],
                'etims_device_serial' => ['nullable', 'string', 'max:64'],
                'etims_registrant_name' => ['nullable', 'string', 'max:60'],
                'etims_registrant_id' => ['nullable', 'string', 'max:20'],
                'etims_receipt_type_code' => ['nullable', Rule::in(['S'])],
                'etims_payment_type_code' => ['nullable', Rule::in(['01', '02', '03', '04', '05', '06', '07'])],
                'etims_buyer_acceptance' => ['nullable', Rule::in(['Y', 'N'])],
                'accountant_name' => ['nullable', 'string', 'max:255'],
                'accountant_approval_reference' => ['nullable', 'string', 'max:255'],
                'accountant_approval_date' => ['nullable', 'date'],
                'categories' => ['required', 'array', 'min:1', 'max:20'],
                'categories.*.code' => ['required', 'string', 'alpha_dash:ascii', 'distinct', 'max:48'],
                'categories.*.label' => ['required', 'string', 'max:120'],
                'categories.*.treatment' => ['required', Rule::in(['unconfigured', 'taxable', 'zero_rated', 'exempt', 'out_of_scope'])],
                'categories.*.rate' => ['nullable', 'string', 'regex:/^\d{1,3}(?:\.\d{1,4})?$/'],
                'categories.*.effective_from' => ['nullable', 'date'],
                'categories.*.effective_to' => ['nullable', 'date', 'after_or_equal:categories.*.effective_from'],
                'categories.*.output_account_code' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9_-]+$/'],
                'categories.*.revenue_account_code' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9_-]+$/'],
                'categories.*.basis_reference' => ['nullable', 'string', 'max:255'],
                'categories.*.etims_tax_type_code' => ['nullable', Rule::in(['A', 'B', 'C', 'D', 'E'])],
                'categories.*.etims_item_code' => ['nullable', 'string', 'max:20'],
                'categories.*.etims_item_class_code' => ['nullable', 'string', 'size:10', 'alpha_num:ascii'],
                'categories.*.etims_packaging_unit_code' => ['nullable', 'string', 'max:5'],
                'categories.*.etims_quantity_unit_code' => ['nullable', 'string', 'max:5'],
            ],
            'penalty-accounting' => [
                'effective_from' => ['nullable', 'date'],
                'recognition_basis' => ['required', Rule::in(['unconfigured', 'daily', 'assessment'])],
                'receivable_account_code' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9_-]+$/'],
                'penalty_income_account_code' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9_-]+$/'],
                'waiver_account_code' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9_-]+$/'],
                'refund_account_code' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9_-]+$/'],
                'accountant_name' => ['nullable', 'string', 'max:255'],
                'approval_reference' => ['nullable', 'string', 'max:255'],
                'waiver_branch_limit' => ['required', 'integer', 'min:0', 'max:100000000'],
                'waiver_finance_limit' => ['required', 'integer', 'min:0', 'max:100000000', 'gte:waiver_branch_limit'],
                'independent_approval_required' => ['required', 'boolean'],
            ],
            'historical-cutover' => [
                'effective_from' => ['nullable', 'date'],
                'source_snapshot_reference' => ['nullable', 'string', 'max:255'],
                'source_snapshot_date' => ['nullable', 'date'],
                'proposed_cutover_date' => ['nullable', 'date'],
                'candidate_hire_count' => ['nullable', 'integer', 'min:0'],
                'candidate_order_count' => ['nullable', 'integer', 'min:0'],
                'opening_receivables' => ['nullable', 'numeric', 'min:0'],
                'opening_deposits' => ['nullable', 'numeric', 'min:0'],
                'opening_refunds' => ['nullable', 'numeric', 'min:0'],
                'unresolved_exceptions' => ['nullable', 'integer', 'min:0'],
                'finance_reviewer' => ['nullable', 'string', 'max:255'],
                'finance_approver_title' => ['nullable', 'string', 'max:255'],
                'finance_approval_reference' => ['nullable', 'string', 'max:255'],
                'finance_approval_date' => ['nullable', 'date'],
                'management_approver' => ['nullable', 'string', 'max:255'],
                'management_approval_reference' => ['nullable', 'string', 'max:255'],
                'management_approval_date' => ['nullable', 'date'],
                'reconciliation_reference' => ['nullable', 'string', 'max:255'],
                'backup_restore_reference' => ['nullable', 'string', 'max:255'],
            ],
            'provider-integrations' => [
                'onfon_endpoint' => ['nullable', 'url', 'starts_with:https://', 'regex:/^https:\/\/api\.onfonmedia\.co\.ke(?:\/.*)?$/', 'max:255'],
                'onfon_client_id' => ['nullable', 'string', 'max:255'],
                'onfon_api_key' => ['nullable', 'string', 'max:2048'],
                'onfon_access_key' => ['nullable', 'string', 'max:2048'],
                'onfon_sender_id' => ['nullable', 'string', 'max:32'],
                'daraja_enabled' => ['required', 'boolean'],
                'daraja_environment' => ['required', Rule::in(['sandbox', 'production'])],
                'daraja_consumer_key' => ['nullable', 'string', 'max:255'],
                'daraja_consumer_secret' => ['nullable', 'string', 'max:2048'],
                'daraja_shortcode' => ['nullable', 'string', 'max:32'],
                'daraja_passkey' => ['nullable', 'string', 'max:2048'],
                'daraja_callback_token' => ['nullable', 'string', 'min:32', 'max:255'],
                'daraja_transaction_type' => ['required', Rule::in(['CustomerPayBillOnline', 'CustomerBuyGoodsOnline'])],
                'onfon_test_reference' => ['nullable', 'string', 'max:255'],
                'onfon_tested_at' => ['nullable', 'date'],
                'daraja_test_reference' => ['nullable', 'string', 'max:255'],
                'daraja_tested_at' => ['nullable', 'date'],
                'daraja_callback_reference' => ['nullable', 'string', 'max:255'],
            ],
            'deployment-profile' => [
                'effective_from' => ['nullable', 'date'],
                'deployment_stage' => ['required', Rule::in(['planned', 'provisioning', 'staging', 'production'])],
                'aws_region' => ['nullable', 'string', 'max:64'],
                'aws_account_alias' => ['nullable', 'string', 'max:128'],
                'ec2_reference' => ['nullable', 'string', 'max:255'],
                'database_endpoint' => ['nullable', 'string', 'max:255'],
                'cache_endpoint' => ['nullable', 'string', 'max:255'],
                'application_domain' => ['nullable', 'string', 'max:255'],
                's3_bucket' => ['nullable', 'string', 'max:255'],
                's3_prefix' => ['nullable', 'string', 'max:255'],
                'mail_provider' => ['nullable', 'string', 'max:255'],
                'mail_from_address' => ['nullable', 'email', 'max:255'],
                'backup_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
                'rpo_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
                'rto_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
                'support_owner' => ['nullable', 'string', 'max:255'],
                'critical_response_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
                'management_release_approver' => ['nullable', 'string', 'max:255'],
                'release_approval_reference' => ['nullable', 'string', 'max:255'],
            ],
            default => ['configuration' => ['prohibited']],
        };
    }
}
