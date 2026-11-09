<?php

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;

class StoreHireDepositApplicationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Payment::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'hire_id' => ['required', 'integer', 'exists:hires,id'],
            'payment_id' => ['required', 'integer', 'exists:payments,id'],
            'amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
