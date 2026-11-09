<?php

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Payment::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'hire_id' => ['nullable', 'required_without:custom_order_id', 'integer', 'exists:hires,id'],
            'custom_order_id' => ['nullable', 'required_without:hire_id', 'integer', 'exists:custom_orders,id'],
            'amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'mpesa', 'other'])],
            'purpose' => ['required', Rule::in(['service_charge', 'deposit', 'penalty', 'custom_order_charge', 'custom_order_deposit'])],
            'provider_reference' => ['nullable', 'required_if:method,mpesa', 'string', 'max:120'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        if ($this->filled('hire_id') && $this->filled('custom_order_id')) {
            $validator->errors()->add('custom_order_id', 'A receipt can be linked to one hire or one custom order.');
        }
    }
}
