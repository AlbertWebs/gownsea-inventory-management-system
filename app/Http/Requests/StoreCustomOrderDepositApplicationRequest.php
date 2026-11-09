<?php

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomOrderDepositApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Payment::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'custom_order_id' => ['required', 'integer', 'exists:custom_orders,id'],
            'payment_id' => ['required', 'integer', 'exists:payments,id'],
            'amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
