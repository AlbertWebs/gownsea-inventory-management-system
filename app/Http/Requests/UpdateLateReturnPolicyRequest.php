<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLateReturnPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['super_admin', 'management'], true);
    }

    public function rules(): array
    {
        return [
            'per_day_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:365'],
            'cap_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'count_return_day' => ['required', 'boolean'],
        ];
    }
}
