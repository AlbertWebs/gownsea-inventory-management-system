<?php

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartDarajaStkPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Payment::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'hire_id' => ['nullable', 'required_without:custom_order_id', 'integer', 'exists:hires,id'],
            'custom_order_id' => ['nullable', 'required_without:hire_id', 'integer', 'exists:custom_orders,id'],
            'amount' => ['required', 'string', 'regex:/^[1-9]\d{0,7}$/'],
            'purpose' => ['required', Rule::in(['service_charge', 'deposit', 'penalty', 'custom_order_charge', 'custom_order_deposit'])],
            'phone' => ['required', 'string', 'max:24', 'regex:/^\+?(?:254|0)?[17]\d{8}$/'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if ($this->filled('hire_id') && $this->filled('custom_order_id')) {
                $validator->errors()->add('custom_order_id', 'A payment can be linked to one hire or custom order.');
            }
        }];
    }
}
