<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertLateReturnPolicyOverrideRequest extends FormRequest
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
        return [
            'scope_type' => ['required', Rule::in(['product', 'institution', 'contract'])],
            'scope_id' => [
                'nullable', 'integer',
                'required_if:scope_type,product,institution',
                Rule::when($this->input('scope_type') === 'product', [Rule::exists('products', 'id')->where('is_active', true)]),
                Rule::when($this->input('scope_type') === 'institution', [Rule::exists('institutions', 'id')->where('is_active', true)]),
            ],
            'scope_reference' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._\/-]{0,99}$/', 'required_if:scope_type,contract'],
            'per_day_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:365'],
            'cap_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'count_return_day' => ['required', 'boolean'],
        ];
    }
}
