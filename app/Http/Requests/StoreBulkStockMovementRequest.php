<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBulkStockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['super_admin', 'management', 'storekeeper'], true);
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('tracking_type', 'bulk')],
            'movement' => ['required', Rule::in(['receive', 'adjust', 'transfer', 'revalue'])],
            'direction' => ['required_if:movement,adjust', 'nullable', Rule::in(['increase', 'decrease'])],
            'quantity' => [Rule::requiredIf($this->input('movement') !== 'revalue'), 'nullable', 'integer', 'min:1', 'max:1000000'],
            'unit_cost' => [Rule::requiredIf(fn (): bool => $this->input('movement') === 'receive' || ($this->input('movement') === 'adjust' && $this->input('direction') === 'increase')), 'nullable', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'valuation_total' => [Rule::requiredIf($this->input('movement') === 'revalue'), 'nullable', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'location_id' => [Rule::requiredIf(in_array($this->input('movement'), ['receive', 'adjust', 'revalue'], true)), 'nullable', 'integer', 'exists:locations,id'],
            'source_location_id' => ['required_if:movement,transfer', 'nullable', 'integer', 'exists:locations,id'],
            'destination_location_id' => ['required_if:movement,transfer', 'nullable', 'integer', 'exists:locations,id'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
