<?php

namespace App\Http\Requests;

use App\Models\CustomOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', CustomOrder::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('is_active', true)],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'institution_id' => ['nullable', 'integer', 'exists:institutions,id'],
            'item_type' => ['required', 'string', 'max:120'], 'size' => ['nullable', 'string', 'max:48'],
            'color' => ['nullable', 'string', 'max:64'], 'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'measurements' => ['nullable', 'array', 'max:20'], 'measurements.*' => ['nullable', 'string', 'max:64'],
            'design_specs' => ['nullable', 'string', 'max:5000'], 'special_requirements' => ['nullable', 'string', 'max:3000'],
            'agreed_total' => ['required', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'deposit_required' => ['nullable', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'order_date' => ['required', 'date'], 'expected_completion_date' => ['required', 'date', 'after_or_equal:order_date'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'delivery_method' => ['required', Rule::in(['collection', 'courier'])],
            'delivery_address' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
