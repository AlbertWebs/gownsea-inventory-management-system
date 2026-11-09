<?php

namespace App\Http\Requests;

use App\Models\CustomOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomOrderCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('customOrder');

        return $order instanceof CustomOrder && ($this->user()?->can('update', $order) ?? false);
    }

    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(['materials', 'labor', 'delivery', 'other'])],
            'description' => ['required', 'string', 'max:180'],
            'amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'incurred_on' => ['required', 'date', 'before_or_equal:today'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
