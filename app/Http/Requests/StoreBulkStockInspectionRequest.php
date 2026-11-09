<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBulkStockInspectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['super_admin', 'management', 'storekeeper'], true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('tracking_type', 'bulk')],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'source' => ['required', Rule::in(['pending_inspection', 'damaged'])],
            'result' => ['required', Rule::in(['available', 'damaged'])],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
