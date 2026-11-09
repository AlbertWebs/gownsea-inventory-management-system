<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerSmsConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['super_admin', 'management', 'sales_crm'], true);
    }

    public function rules(): array
    {
        return ['sms_consent' => ['required', 'boolean']];
    }
}
