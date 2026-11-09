<?php

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBulkSaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['super_admin', 'management', 'finance'], true)
            && ($this->user()?->can('createRefund', Payment::class) ?? false);
    }

    public function rules(): array
    {
        return [
            'bulk_sale_id' => ['required', 'integer', 'exists:bulk_sales,id'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.condition' => ['required', Rule::in(['good', 'damaged'])],
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'mpesa', 'other'])],
            'provider_reference' => ['nullable', 'required_if:method,mpesa', 'string', 'max:120'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
