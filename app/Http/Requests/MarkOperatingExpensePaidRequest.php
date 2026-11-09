<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarkOperatingExpensePaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['super_admin', 'management', 'finance'], true);
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'mpesa', 'card', 'other'])],
            'payment_reference' => ['nullable', 'string', 'max:120'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
        ];
    }
}
