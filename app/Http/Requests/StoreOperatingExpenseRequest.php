<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOperatingExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['super_admin', 'management', 'finance'], true);
    }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('is_active', true)],
            'amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'category' => ['required', Rule::in(['transport', 'labor', 'repairs', 'sms', 'delivery', 'other'])],
            'vendor' => ['nullable', 'string', 'max:160'],
            'receipt_reference' => ['nullable', 'string', 'max:120'],
            'incurred_on' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'min:5', 'max:2000'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
